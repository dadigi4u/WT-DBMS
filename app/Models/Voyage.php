<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voyage extends Model
{
    protected $table = 'tbl_voyage';
    protected $primaryKey = 'voyage_id';
    protected $guarded = ['voyage_id'];

    protected function casts(): array
    {
        return ['scheduled_departure' => 'datetime', 'scheduled_arrival' => 'datetime', 'actual_departure' => 'datetime', 'actual_arrival' => 'datetime', 'cargo_capacity_kg' => 'float', 'fare' => 'float'];
    }

    public function vessel() { return $this->belongsTo(Vessel::class, 'vessel_id'); }
    public function route() { return $this->belongsTo(Route::class, 'route_id'); }
    public function tickets() { return $this->hasMany(Ticket::class, 'voyage_id'); }
    public function consignments() { return $this->hasMany(Consignment::class, 'voyage_id'); }
    public function crew() { return $this->belongsToMany(Crew::class, 'tbl_voyage_crew', 'voyage_id', 'crew_id')->withPivot('duty_role'); }
    public function activeTicketCount(): int { return $this->tickets()->whereIn('status', ['issued', 'boarded'])->count(); }
    public function cargoLoadKg(): float { return (float) $this->consignments()->where('status', '<>', 'cancelled')->sum('weight_kg'); }
    public function isClosed(): bool { return in_array($this->status, ['departed', 'arrived', 'cancelled', 'incident']); }
}
