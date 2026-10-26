<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Crew extends Model
{
    protected $table = 'tbl_crew';
    protected $primaryKey = 'crew_id';
    protected $guarded = ['crew_id'];

    protected function casts(): array
    {
        return ['cert_expiry' => 'date', 'phone' => 'encrypted'];
    }

}
