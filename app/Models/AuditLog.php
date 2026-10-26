<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'tbl_audit_log';
    protected $primaryKey = 'audit_id';
    protected $guarded = ['audit_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public $timestamps = false;
}
