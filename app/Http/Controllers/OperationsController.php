<?php

namespace App\Http\Controllers;

use App\Models\BorderDeclaration;
use App\Models\Certificate;
use App\Models\Incident;
use App\Models\Notification;
use App\Models\Voyage;
use App\Services\AuditService;
use App\Services\ManifestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Emergency retrieval, incidents, controlled cross-border disclosure, dashboards and audit. */
class OperationsController extends Controller
{
    public function emergency(Request $request, string $ref)
    {
        abort_unless(preg_match('/^[A-Z0-9\-]{4,20}$/', $ref), 422, 'Invalid voyage reference.');
        $start = hrtime(true);
        $v = Voyage::with(['vessel.operator', 'route.origin', 'route.destination', 'crew'])->where('voyage_ref', $ref)->firstOrFail();
        $persons = $v->tickets()->with('passenger')->whereIn('status', ['issued', 'boarded'])->get()->map(fn ($t) => [
            'name' => mb_strtoupper($t->passenger->surname).', '.$t->passenger->other_names,
            'sex' => $t->passenger->sex, 'age' => $t->passenger->date_of_birth->age,
            'nationality' => $t->passenger->nationality, 'boarded' => $t->status === 'boarded',
            'next_of_kin' => $t->passenger->next_of_kin_name, 'next_of_kin_phone' => $t->passenger->next_of_kin_phone,
        ]);
        $cargo = $v->consignments()->with('dgClass')->where('status', '<>', 'cancelled')->get();
        $out = [
            'voyage_ref' => $v->voyage_ref, 'status' => $v->status,
            'vessel' => ['name' => $v->vessel->vessel_name, 'registration_no' => $v->vessel->registration_no, 'type' => $v->vessel->vessel_type, 'operator' => $v->vessel->operator->operator_name, 'operator_phone' => $v->vessel->operator->phone],
            'route' => $v->route->origin->jetty_name.' to '.$v->route->destination->jetty_name,
            'departed_at' => optional($v->actual_departure)->toDateTimeString() ?? $v->scheduled_departure->toDateTimeString(),
            'expected_arrival' => $v->scheduled_arrival->toDateTimeString(),
            'crew' => $v->crew->map(fn ($c) => ['name' => $c->full_name, 'duty' => $c->pivot->duty_role]),
            'persons_on_board' => $persons->count() + $v->crew->count(),
            'passengers' => $persons,
            'cargo_total_kg' => round($cargo->sum('weight_kg'), 2),
            'dangerous_goods' => $cargo->whereNotNull('dg_class_id')->map(fn ($c) => ['ref' => $c->consignment_ref, 'description' => $c->description, 'class' => $c->dgClass->class_code.' '.$c->dgClass->description, 'weight_kg' => $c->weight_kg, 'stowage' => $c->dgClass->stowage_restriction])->values(),
            'incidents' => Incident::where('voyage_id', $v->voyage_id)->orderByDesc('incident_id')->get(['incident_ref', 'incident_type', 'severity', 'last_latitude', 'last_longitude', 'status', 'created_at']),
        ];
        $out['retrieval_ms'] = round((hrtime(true) - $start) / 1e6, 2);
        AuditService::log('emergency_retrieval', 'success', 'tbl_voyage', $v->voyage_id, $v->voyage_ref.' persons='.$out['persons_on_board']);
        return $out;
    }

    public function reportIncident(Request $request, Voyage $voyage)
    {
        $d = $request->validate([
            'incident_type' => ['required', Rule::in(['capsize', 'collision', 'engine_failure', 'piracy_abduction', 'fire', 'man_overboard', 'weather', 'other'])],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'last_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'last_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $i = Incident::create($d + ['incident_ref' => 'IN'.now()->format('ymd').strtoupper(Str::random(6)), 'voyage_id' => $voyage->voyage_id, 'status' => 'open', 'reported_by' => $request->user()->user_id]);
        if (in_array($d['severity'], ['high', 'critical'])) {
            $voyage->update(['status' => 'incident']);
        }
        Notification::create(['category' => 'incident', 'message' => strtoupper($d['severity'])." {$d['incident_type']} reported on voyage {$voyage->voyage_ref}"]);
        AuditService::log('incident_reported', 'success', 'tbl_incident', $i->incident_id, $i->incident_ref);
        return response()->json($i, 201);
    }

    public const DISCLOSABLE = ['surname', 'other_names', 'sex', 'date_of_birth', 'nationality', 'id_type', 'id_number'];

    public function requestDisclosure(Request $request, Voyage $voyage)
    {
        $d = $request->validate([
            'direction' => ['required', Rule::in(['departure', 'arrival'])],
            'recipient_authority' => ['required', 'string', 'max:150', 'regex:/^[\pL\s\.\,\-\(\)]+$/u'],
            'recipient_country' => ['required', 'regex:/^[A-Z]{2}$/'],
            'lawful_basis' => ['required', 'string', 'min:10', 'max:255'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', Rule::in(self::DISCLOSABLE)],
        ]);
        abort_unless($voyage->route->is_international, 422, 'Cross-border disclosure applies only to international voyages.');
        $b = BorderDeclaration::create([
            'declaration_ref' => 'BD'.now()->format('ymd').strtoupper(Str::random(6)), 'voyage_id' => $voyage->voyage_id,
            'direction' => $d['direction'], 'recipient_authority' => $d['recipient_authority'], 'recipient_country' => $d['recipient_country'],
            'lawful_basis' => $d['lawful_basis'], 'fields_disclosed' => array_values(array_unique($d['fields'])), 'status' => 'requested',
            'requested_by' => $request->user()->user_id,
        ]);
        AuditService::log('disclosure_requested', 'success', 'tbl_border_declaration', $b->declaration_id, $b->recipient_authority);
        return response()->json($b, 201);
    }

    public function approveDisclosure(Request $request, BorderDeclaration $declaration)
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        if ($declaration->status !== 'requested') {
            return response()->json(['message' => 'Declaration already decided.'], 409);
        }
        if ($declaration->requested_by === $request->user()->user_id) {
            AuditService::log('disclosure_self_approval', 'denied', 'tbl_border_declaration', $declaration->declaration_id);
            return response()->json(['message' => 'Separation of duties: the requester cannot approve their own disclosure.'], 403);
        }
        $declaration->update(['status' => $d['decision'], 'approved_by' => $request->user()->user_id]);
        AuditService::log('disclosure_'.$d['decision'], 'success', 'tbl_border_declaration', $declaration->declaration_id);
        return $declaration;
    }

    /** Minimised export: only approved fields of passengers on the voyage; hash recorded. */
    public function exportDisclosure(Request $request, BorderDeclaration $declaration)
    {
        if (! in_array($declaration->status, ['approved', 'transmitted'])) {
            return response()->json(['message' => 'Disclosure has not been approved.'], 403);
        }
        $fields = $declaration->fields_disclosed;
        $v = Voyage::findOrFail($declaration->voyage_id);
        $rows = $v->tickets()->with('passenger')->whereIn('status', ['issued', 'boarded'])->get()->map(function ($t) use ($fields) {
            $p = $t->passenger;
            $all = ['surname' => $p->surname, 'other_names' => $p->other_names, 'sex' => $p->sex, 'date_of_birth' => $p->date_of_birth->toDateString(), 'nationality' => $p->nationality, 'id_type' => $p->id_type, 'id_number' => $p->id_number];
            return array_intersect_key($all, array_flip($fields));
        })->values();
        $payload = ['declaration_ref' => $declaration->declaration_ref, 'voyage_ref' => $v->voyage_ref, 'recipient' => $declaration->recipient_authority, 'fields' => $fields, 'passengers' => $rows];
        $hash = hash('sha256', ManifestService::canonical($payload));
        $declaration->update(['status' => 'transmitted', 'export_hash' => $hash, 'transmitted_at' => now()]);
        AuditService::log('disclosure_transmitted', 'success', 'tbl_border_declaration', $declaration->declaration_id, count($rows).' records, fields='.implode(',', $fields).' hash='.$hash);
        return $payload + ['export_hash' => $hash];
    }

    public function declarations()
    {
        return BorderDeclaration::orderByDesc('declaration_id')->limit(50)->get();
    }

    public function dashboard()
    {
        $today = now()->toDateString();
        $util = DB::table('vw_voyage_utilisation')->whereDate('scheduled_departure', '>=', now()->subDays(30))->get();
        return [
            'vessels_active' => DB::table('tbl_vessel')->where('status', 'active')->count(),
            'voyages_today' => DB::table('tbl_voyage')->whereDate('scheduled_departure', $today)->count(),
            'passengers_today' => DB::table('tbl_ticket as t')->join('tbl_voyage as v', 'v.voyage_id', '=', 't.voyage_id')->whereDate('v.scheduled_departure', $today)->whereIn('t.status', ['issued', 'boarded'])->count(),
            'cargo_kg_today' => (float) DB::table('tbl_consignment as c')->join('tbl_voyage as v', 'v.voyage_id', '=', 'c.voyage_id')->whereDate('v.scheduled_departure', $today)->where('c.status', '<>', 'cancelled')->sum('c.weight_kg'),
            'open_incidents' => DB::table('tbl_incident')->where('status', '<>', 'closed')->count(),
            'refusals_30d' => DB::table('tbl_audit_log')->where('event', 'rule_refusal')->where('created_at', '>=', now()->subDays(30))->count(),
            'avg_pax_utilisation_30d' => round($util->where('passenger_capacity', '>', 0)->avg(fn ($u) => $u->passengers / $u->passenger_capacity * 100) ?? 0, 1),
            'avg_cargo_utilisation_30d' => round($util->avg(fn ($u) => $u->cargo_kg / $u->cargo_capacity_kg * 100) ?? 0, 1),
            'certificate_alerts' => Certificate::with('vessel:vessel_id,vessel_name')->whereNotNull('vessel_id')
                ->where('expiry_date', '<=', now()->addDays(30))->orderBy('expiry_date')->limit(10)->get()
                ->map(fn ($c) => ['vessel' => $c->vessel->vessel_name, 'cert_type' => $c->cert_type, 'expiry_date' => $c->expiry_date->toDateString(), 'lapsed' => $c->expiry_date->isPast()]),
            'notifications' => Notification::orderByDesc('notification_id')->limit(8)->get(['category', 'message', 'created_at']),
        ];
    }

    public function utilisation()
    {
        return DB::table('vw_voyage_utilisation')->orderByDesc('scheduled_departure')->limit(100)->get();
    }

    public function audit(Request $request)
    {
        $q = DB::table('tbl_audit_log as a')->leftJoin('tbl_user as u', 'u.user_id', '=', 'a.user_id')
            ->select('a.audit_id', 'a.created_at', 'u.name as user', 'a.event', 'a.entity', 'a.entity_id', 'a.outcome', 'a.ip_address', 'a.detail', 'a.row_hash');
        if ($request->filled('event')) {
            $request->validate(['event' => ['regex:/^[a-z_]{3,40}$/']]);
            $q->where('a.event', $request->event);
        }
        return $q->orderByDesc('a.audit_id')->limit(100)->get();
    }

    public function auditVerify()
    {
        [$ok, $n, $broken] = \App\Services\AuditService::verifyChain();
        return ['chain_valid' => $ok, 'rows_checked' => $n, 'first_broken_row' => $broken];
    }
}
