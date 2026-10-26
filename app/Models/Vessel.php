<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vessel extends Model
{
    protected $table = 'tbl_vessel';
    protected $primaryKey = 'vessel_id';
    protected $guarded = ['vessel_id'];

    protected function casts(): array
    {
        return ['cargo_capacity_kg' => 'float'];
    }

    public function operator() { return $this->belongsTo(Operator::class, 'operator_id'); }
    public function certificates() { return $this->hasMany(Certificate::class, 'vessel_id'); }
    public function maintenance() { return $this->hasMany(Maintenance::class, 'vessel_id'); }
    public const REQUIRED_CERTIFICATES = ['registration', 'survey', 'safety_equipment', 'insurance'];

    /** Required certificate types that are missing or lapsed on the given date (returned as cert_type rows). */
    public function lapsedCertificates($onDate = null)
    {
        $d = ($onDate ?? now())->toDateString();
        $valid = $this->certificates()->whereIn('cert_type', self::REQUIRED_CERTIFICATES)
            ->where('expiry_date', '>=', $d)->pluck('cert_type')->unique();
        return collect(self::REQUIRED_CERTIFICATES)->diff($valid)->values()->map(fn ($t) => (object) ['cert_type' => $t]);
    }
}
