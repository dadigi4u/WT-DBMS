<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipper extends Model
{
    protected $table = 'tbl_shipper';
    protected $primaryKey = 'shipper_id';
    protected $guarded = ['shipper_id'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted'];
    }

}
