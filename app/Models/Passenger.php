<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Passenger extends Model
{
    protected $table = 'tbl_passenger';
    protected $primaryKey = 'passenger_id';
    protected $guarded = ['passenger_id'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'id_number' => 'encrypted', 'phone' => 'encrypted', 'next_of_kin_phone' => 'encrypted'];
    }

    protected $hidden = ['id_number_hash'];
    public static function idHash(string $type, string $number): string
    {
        return hash_hmac('sha256', $type.'|'.strtoupper(preg_replace('/\s+/', '', $number)), config('app.key'));
    }
    public function maskedId(): string { $n = $this->id_number; return str_repeat('*', max(0, strlen($n) - 4)).substr($n, -4); }
}
