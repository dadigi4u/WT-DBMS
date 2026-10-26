<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $table = 'tbl_certificate';
    protected $primaryKey = 'certificate_id';
    protected $guarded = ['certificate_id'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date'];
    }

    public function vessel() { return $this->belongsTo(Vessel::class, 'vessel_id'); }
}
