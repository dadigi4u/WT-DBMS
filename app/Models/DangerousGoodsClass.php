<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DangerousGoodsClass extends Model
{
    protected $table = 'tbl_dangerous_goods_class';
    protected $primaryKey = 'dg_class_id';
    protected $guarded = ['dg_class_id'];

    protected function casts(): array
    {
        return ['passenger_vessel_permitted' => 'boolean'];
    }

    public $timestamps = false;
}
