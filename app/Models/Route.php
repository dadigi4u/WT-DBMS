<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    protected $table = 'tbl_route';
    protected $primaryKey = 'route_id';
    protected $guarded = ['route_id'];

    protected function casts(): array
    {
        return ['is_international' => 'boolean'];
    }

    public function origin() { return $this->belongsTo(Jetty::class, 'origin_jetty_id'); }
    public function destination() { return $this->belongsTo(Jetty::class, 'destination_jetty_id'); }
}
