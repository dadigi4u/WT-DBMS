<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorderDeclaration extends Model
{
    protected $table = 'tbl_border_declaration';
    protected $primaryKey = 'declaration_id';
    protected $guarded = ['declaration_id'];

    protected function casts(): array
    {
        return ['fields_disclosed' => 'array', 'transmitted_at' => 'datetime'];
    }

}
