<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Manifest extends Model
{
    protected $table = 'tbl_manifest';
    protected $primaryKey = 'manifest_id';
    protected $guarded = ['manifest_id'];

    protected function casts(): array
    {
        return ['content_json' => 'encrypted', 'is_final' => 'boolean', 'issued_at' => 'datetime', 'total_weight_kg' => 'float'];
    }

    public $timestamps = false;
    public function voyage() { return $this->belongsTo(Voyage::class, 'voyage_id'); }
    public function issuer() { return $this->belongsTo(User::class, 'issued_by'); }
}
