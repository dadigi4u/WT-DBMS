<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Crew;
use App\Models\Route;
use App\Models\Vessel;
use App\Models\Voyage;
use App\Services\AuditService;
use App\Services\ManifestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VoyageController extends Controller
{
    public function index(Request $request)
    {
        $q = Voyage::with(['vessel:vessel_id,vessel_name,registration_no', 'route.origin', 'route.destination'])
            ->withCount(['tickets as passengers' => fn ($t) => $t->whereIn('status', ['issued', 'boarded'])])
            ->withSum(['consignments as cargo_kg' => fn ($c) => $c->where('status', '<>', 'cancelled')], 'weight_kg');
        if ($request->filled('date')) {
            $request->validate(['date' => ['date']]);
            $q->whereDate('scheduled_departure', $request->date);
        }
        if ($request->filled('status')) {
            $request->validate(['status' => [Rule::in(['scheduled', 'boarding', 'departed', 'arrived', 'cancelled', 'incident'])]]);
            $q->where('status', $request->status);
        }
        return $q->orderByDesc('scheduled_departure')->limit(200)->get();
    }

    public function show(Voyage $voyage)
    {
        $voyage->load(['vessel.operator', 'route.origin', 'route.destination', 'crew']);
        return $voyage->toArray() + [
            'passengers' => $voyage->activeTicketCount(),
            'cargo_kg' => $voyage->cargoLoadKg(),
            'lapsed_certificates' => $voyage->vessel->lapsedCertificates()->pluck('cert_type'),
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'vessel_id' => ['required', 'integer', 'exists:tbl_vessel,vessel_id'],
            'route_id' => ['required', 'integer', 'exists:tbl_route,route_id'],
            'voyage_type' => ['required', Rule::in(['scheduled', 'unscheduled', 'charter'])],
            'scheduled_departure' => ['required', 'date', 'after:now'],
            'fare' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'crew' => ['required', 'array', 'min:1', 'max:20'],
            'crew.*.crew_id' => ['required', 'integer', 'distinct', 'exists:tbl_crew,crew_id'],
            'crew.*.duty_role' => ['required', Rule::in(['master', 'mate', 'engineer', 'deckhand', 'steward'])],
        ]);
        $vessel = Vessel::findOrFail($d['vessel_id']);
        $route = Route::findOrFail($d['route_id']);
        $dep = \Carbon\Carbon::parse($d['scheduled_departure']);
        $arr = $dep->copy()->addMinutes($route->est_duration_min);

        try {
            if ($vessel->status !== 'active') {
                throw new BusinessRuleException('vessel_inactive', "Vessel status is {$vessel->status}; it cannot be scheduled.");
            }
            $lapsed = $vessel->lapsedCertificates($dep);
            if ($lapsed->isNotEmpty()) {
                throw new BusinessRuleException('certificate_lapsed', 'Voyage refused: vessel certificate(s) missing or lapsed on departure date: '.$lapsed->pluck('cert_type')->implode(', '));
            }
            if (! collect($d['crew'])->contains('duty_role', 'master')) {
                throw new BusinessRuleException('no_master', 'A certificated master must be assigned to every voyage.', 422);
            }
            foreach ($d['crew'] as $c) {
                $crew = Crew::find($c['crew_id']);
                if ($crew->cert_expiry->lt($dep)) {
                    throw new BusinessRuleException('crew_cert_lapsed', "Competency certificate of {$crew->full_name} lapses before departure.");
                }
                $clash = DB::table('tbl_voyage_crew as vc')->join('tbl_voyage as v', 'v.voyage_id', '=', 'vc.voyage_id')
                    ->where('vc.crew_id', $crew->crew_id)->whereNotIn('v.status', ['arrived', 'cancelled'])
                    ->where('v.scheduled_departure', '<', $arr)->where('v.scheduled_arrival', '>', $dep)->exists();
                if ($clash) {
                    throw new BusinessRuleException('crew_clash', "{$crew->full_name} is already assigned to a concurrent voyage.");
                }
            }
        } catch (BusinessRuleException $e) {
            AuditService::log('rule_refusal', 'refused', 'tbl_vessel', $vessel->vessel_id, $e->rule.': '.$e->getMessage());
            throw $e;
        }

        $voyage = DB::transaction(function () use ($d, $vessel, $route, $dep, $arr, $request) {
            $seq = Voyage::whereDate('scheduled_departure', $dep->toDateString())->count() + 1;
            $v = Voyage::create([
                'voyage_ref' => $route->route_code.'-'.$dep->format('ymd').'-'.str_pad($seq, 2, '0', STR_PAD_LEFT),
                'vessel_id' => $vessel->vessel_id, 'route_id' => $route->route_id, 'voyage_type' => $d['voyage_type'],
                'scheduled_departure' => $dep, 'scheduled_arrival' => $arr,
                'passenger_capacity' => $vessel->passenger_capacity, 'cargo_capacity_kg' => $vessel->cargo_capacity_kg,
                'fare' => $d['fare'], 'status' => 'scheduled', 'created_by' => $request->user()->user_id,
            ]);
            foreach ($d['crew'] as $c) {
                $v->crew()->attach($c['crew_id'], ['duty_role' => $c['duty_role'], 'created_at' => now(), 'updated_at' => now()]);
            }
            return $v;
        });
        AuditService::log('voyage_scheduled', 'success', 'tbl_voyage', $voyage->voyage_id, $voyage->voyage_ref);
        return response()->json($voyage, 201);
    }

    /** Status transitions. Departure closes and finalises both manifests. */
    public function updateStatus(Request $request, Voyage $voyage, ManifestService $manifests)
    {
        $d = $request->validate(['status' => ['required', Rule::in(['boarding', 'departed', 'arrived', 'cancelled'])]]);
        $allowed = ['scheduled' => ['boarding', 'cancelled'], 'boarding' => ['departed', 'cancelled'], 'departed' => ['arrived'], 'incident' => ['arrived']];
        if (! in_array($d['status'], $allowed[$voyage->status] ?? [])) {
            throw new BusinessRuleException('invalid_transition', "Cannot move voyage from {$voyage->status} to {$d['status']}.", 422);
        }
        $voyage->status = $d['status'];
        if ($d['status'] === 'departed') {
            $voyage->actual_departure = now();
        }
        if ($d['status'] === 'arrived') {
            $voyage->actual_arrival = now();
        }
        $voyage->save();
        $final = [];
        if ($d['status'] === 'departed') {
            $final[] = $manifests->issue($voyage, 'passenger', $request->user()->user_id, true)->manifest_ref;
            $final[] = $manifests->issue($voyage, 'cargo', $request->user()->user_id, true)->manifest_ref;
        }
        AuditService::log('voyage_status', 'success', 'tbl_voyage', $voyage->voyage_id, $voyage->voyage_ref.' -> '.$d['status']);
        return ['voyage' => $voyage, 'final_manifests' => $final];
    }

    public function routes() { return Route::with(['origin', 'destination'])->get(); }
}
