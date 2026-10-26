<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jetty extends Model
{
    protected $table = 'tbl_jetty';
    protected $primaryKey = 'jetty_id';
    protected $guarded = ['jetty_id'];

    protected function casts(): array
    {
        return [];
    }

}
