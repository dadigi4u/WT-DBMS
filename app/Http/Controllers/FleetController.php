<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Crew;
use App\Models\Maintenance;
use App\Models\Operator;
use App\Models\Vessel;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FleetController extends Controller
{
    public function vessels()
    {
        $today = now()->toDateString();
        return Vessel::with('operator:operator_id,operator_name')->orderBy('vessel_name')->get()->map(function ($v) use ($today) {
            $certs = $v->certificates()->whereNotNull('vessel_id')->get();
            $v->lapsed_certificates = $certs->where('expiry_date', '<', now()->startOfDay())->pluck('cert_type')->values();
            $v->expiring_soon = $certs->filter(fn ($c) => $c->expiry_date->between(now()->startOfDay(), now()->addDays(30)))->pluck('cert_type')->values();
            $v->compliance = $v->lapsed_certificates->isNotEmpty() ? 'non-compliant' : ($v->expiring_soon->isNotEmpty() ? 'expiring' : 'compliant');
            return $v;
        });
    }

    public function vessel(Vessel $vessel)
    {
        return $vessel->load(['operator', 'certificates', 'maintenance' => fn ($q) => $q->orderByDesc('maintenance_date')]);
    }

    public function storeVessel(Request $request)
    {
        $d = $request->validate([
            'operator_id' => ['required', 'integer', 'exists:tbl_operator,operator_id'],
            'vessel_name' => ['required', 'string', 'max:100', 'regex:/^[\pL0-9\s\.\'\-]+$/u'],
            'registration_no' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9\/\-]+$/', 'unique:tbl_vessel,registration_no'],
            'vessel_type' => ['required', Rule::in(['speedboat', 'passenger_ferry', 'passenger_cargo', 'cargo_boat'])],
            'hull_material' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\s\-]+$/u'],
            'engine_details' => ['nullable', 'string', 'max:120', 'regex:/^[\pL0-9\s\.\,\-x\/]+$/u'],
            'passenger_capacity' => ['required', 'integer', 'min:0', 'max:1000'],
            'cargo_capacity_kg' => ['required', 'numeric', 'min:1', 'max:500000'],
        ]);
        $v = Vessel::create($d + ['status' => 'active']);
        AuditService::log('vessel_registered', 'success', 'tbl_vessel', $v->vessel_id, $v->registration_no);
        return response()->json($v, 201);
    }

    public function storeCertificate(Request $request)
    {
        $d = $request->validate([
            'vessel_id' => ['required_without:crew_id', 'nullable', 'integer', 'exists:tbl_vessel,vessel_id'],
            'crew_id' => ['required_without:vessel_id', 'nullable', 'integer', 'exists:tbl_crew,crew_id'],
            'cert_type' => ['required', Rule::in(['registration', 'survey', 'safety_equipment', 'insurance', 'competency', 'operating_licence'])],
            'cert_no' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\/\-]+$/'],
            'issuing_body' => ['required', 'string', 'max:100', 'regex:/^[\pL\s\.\-\(\)]+$/u'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'expiry_date' => ['required', 'date', 'after:issue_date'],
        ]);
        $c = Certificate::create($d);
        AuditService::log('certificate_recorded', 'success', 'tbl_certificate', $c->certificate_id, $c->cert_type);
        return response()->json($c, 201);
    }

    public function storeMaintenance(Request $request)
    {
        $d = $request->validate([
            'vessel_id' => ['required', 'integer', 'exists:tbl_vessel,vessel_id'],
            'maintenance_date' => ['required', 'date', 'before_or_equal:today'],
            'maintenance_type' => ['required', Rule::in(['routine', 'repair', 'defect', 'survey_prep'])],
            'description' => ['required', 'string', 'max:500'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'next_due' => ['nullable', 'date', 'after:maintenance_date'],
        ]);
        $m = Maintenance::create($d);
        AuditService::log('maintenance_recorded', 'success', 'tbl_maintenance', $m->maintenance_id);
        return response()->json($m, 201);
    }

    public function crew()
    {
        return Crew::orderBy('full_name')->get()->map(fn ($c) => [
            'crew_id' => $c->crew_id, 'full_name' => $c->full_name, 'rank' => $c->rank,
            'competency_cert_no' => $c->competency_cert_no, 'cert_expiry' => $c->cert_expiry->toDateString(),
            'cert_valid' => $c->cert_expiry->isFuture(), 'status' => $c->status,
        ]);
    }

    public function storeCrew(Request $request)
    {
        $d = $request->validate([
            'full_name' => ['required', 'string', 'max:120', 'regex:/^[\pL\s\.\'\-]+$/u'],
            'rank' => ['required', Rule::in(['master', 'mate', 'engineer', 'deckhand', 'steward'])],
            'competency_cert_no' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9\/\-]+$/', 'unique:tbl_crew,competency_cert_no'],
            'cert_expiry' => ['required', 'date'],
            'phone' => ['nullable', 'regex:/^\+?[0-9]{7,15}$/'],
        ]);
        $c = Crew::create($d + ['status' => 'active']);
        AuditService::log('crew_registered', 'success', 'tbl_crew', $c->crew_id);
        return response()->json(['crew_id' => $c->crew_id], 201);
    }

    public function operators() { return Operator::orderBy('operator_name')->get(); }
}
