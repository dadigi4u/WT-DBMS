<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $table = 'tbl_incident';
    protected $primaryKey = 'incident_id';
    protected $guarded = ['incident_id'];

    protected function casts(): array
    {
        return [];
    }

}
