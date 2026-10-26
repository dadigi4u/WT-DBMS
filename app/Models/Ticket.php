<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $table = 'tbl_ticket';
    protected $primaryKey = 'ticket_id';
    protected $guarded = ['ticket_id'];

    protected function casts(): array
    {
        return ['fare_paid' => 'float', 'boarded_at' => 'datetime'];
    }

    public function passenger() { return $this->belongsTo(Passenger::class, 'passenger_id'); }
    public function voyage() { return $this->belongsTo(Voyage::class, 'voyage_id'); }
}
