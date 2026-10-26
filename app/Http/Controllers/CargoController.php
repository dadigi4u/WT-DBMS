<?php

namespace App\Http\Controllers;

use App\Models\DangerousGoodsClass;
use App\Models\Shipper;
use App\Models\Voyage;
use App\Services\AuditService;
use App\Services\CapacityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CargoController extends Controller
{
    public function shippers()
    {
        return Shipper::orderBy('full_name')->get(['shipper_id', 'full_name', 'party_type', 'country_code']);
    }

    public function storeShipper(Request $request)
    {
        $request->merge(['country_code' => strtoupper((string) $request->input('country_code'))]);
        $d = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\pL0-9\s\.\'\-&]+$/u'],
            'party_type' => ['required', Rule::in(['individual', 'company'])],
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/'],
            'phone' => ['required', 'regex:/^\+?[0-9]{7,15}$/'],
            'id_reference' => ['nullable', 'regex:/^[A-Z0-9\/\-]{4,60}$/'],
        ]);
        $s = Shipper::create($d);
        AuditService::log('shipper_registered', 'success', 'tbl_shipper', $s->shipper_id);
        return response()->json(['shipper_id' => $s->shipper_id, 'full_name' => $s->full_name], 201);
    }

    public function dgClasses() { return DangerousGoodsClass::orderBy('class_code')->get(); }

    public function store(Request $request, Voyage $voyage, CapacityService $capacity)
    {
        $d = $request->validate([
            'shipper_id' => ['required', 'integer', 'exists:tbl_shipper,shipper_id'],
            'consignee_id' => ['required', 'integer', 'exists:tbl_shipper,shipper_id', 'different:shipper_id'],
            'description' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[\pL0-9\s\.\,\-\(\)\/x]+$/u'],
            'hs_category' => ['required', Rule::in(['foodstuff', 'textiles_footwear', 'building_materials', 'petroleum_products', 'household_goods', 'electronics', 'vehicles_parts', 'agricultural_produce', 'beverages', 'other'])],
            'marks_numbers' => ['nullable', 'string', 'max:80', 'regex:/^[A-Z0-9\s\/\-]+$/i'],
            'package_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'weight_kg' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'volume_m3' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'dg_class_id' => ['nullable', 'integer', 'exists:tbl_dangerous_goods_class,dg_class_id'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'freight_charge' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ($d['hs_category'] === 'petroleum_products' && empty($d['dg_class_id'])) {
            return response()->json(['message' => 'Petroleum products must be declared with a dangerous-goods class.', 'errors' => ['dg_class_id' => ['Dangerous-goods class is mandatory for petroleum products.']]], 422);
        }
        $c = $capacity->acceptConsignment($voyage->voyage_id, $d, $request->user()->user_id);
        AuditService::log('consignment_accepted', 'success', 'tbl_consignment', $c->consignment_id, $c->consignment_ref);
        $voyage->refresh();
        return response()->json(['consignment' => $c, 'cargo_kg' => $voyage->cargoLoadKg(), 'capacity_kg' => $voyage->cargo_capacity_kg], 201);
    }

    public function index(Voyage $voyage)
    {
        return $voyage->consignments()->with(['shipper:shipper_id,full_name', 'consignee:shipper_id,full_name', 'dgClass'])->orderBy('consignment_id')->get();
    }
}
