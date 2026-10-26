<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    protected $table = 'tbl_operator';
    protected $primaryKey = 'operator_id';
    protected $guarded = ['operator_id'];

    protected function casts(): array
    {
        return ['licence_expiry' => 'date'];
    }

}
