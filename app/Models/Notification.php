<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'tbl_notification';
    protected $primaryKey = 'notification_id';
    protected $guarded = ['notification_id'];

    protected function casts(): array
    {
        return ['is_read' => 'boolean'];
    }

}
