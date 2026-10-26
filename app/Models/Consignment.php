<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consignment extends Model
{
    protected $table = 'tbl_consignment';
    protected $primaryKey = 'consignment_id';
    protected $guarded = ['consignment_id'];

    protected function casts(): array
    {
        return ['weight_kg' => 'float', 'declared_value' => 'float', 'freight_charge' => 'float'];
    }

    public function shipper() { return $this->belongsTo(Shipper::class, 'shipper_id'); }
    public function consignee() { return $this->belongsTo(Shipper::class, 'consignee_id'); }
    public function dgClass() { return $this->belongsTo(DangerousGoodsClass::class, 'dg_class_id'); }
}
