<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Maintenance extends Model
{
    protected $table = 'tbl_maintenance';
    protected $primaryKey = 'maintenance_id';
    protected $guarded = ['maintenance_id'];

    protected function casts(): array
    {
        return ['maintenance_date' => 'date', 'next_due' => 'date'];
    }

}
