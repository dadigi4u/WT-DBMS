<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'tbl_role';
    protected $primaryKey = 'role_id';
    protected $guarded = ['role_id'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

}
