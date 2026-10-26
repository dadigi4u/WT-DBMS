<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'tbl_user';
    protected $primaryKey = 'user_id';
    protected $fillable = ['role_id', 'name', 'email', 'organisation', 'password', 'status'];
    protected $hidden = ['password', 'remember_token', 'failed_attempts', 'locked_until'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'locked_until' => 'datetime', 'last_login_at' => 'datetime'];
    }

    public function role() { return $this->belongsTo(Role::class, 'role_id'); }

    public function can_($permission): bool
    {
        $perms = $this->role?->permissions ?? [];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }
}
