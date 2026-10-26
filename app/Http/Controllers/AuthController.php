<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:150'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $key = 'login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            AuditService::log('login_rate_limited', 'denied', null, null, $data['email']);
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429);
        }
        RateLimiter::hit($key, 60);

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            AuditService::log('login_locked', 'denied', 'tbl_user', $user->user_id, null, $user->user_id);
            return response()->json(['message' => 'Account temporarily locked after repeated failed attempts.'], 423);
        }
        // Constant-work verification so response time does not reveal whether the account exists.
        $ok = $user ? Hash::check($data['password'], $user->password) : Hash::check($data['password'], '$2y$12$uc1XuJ3BPjS4EDKn5KixoOdKoX.rHCUrIoiHP15Idkx6/KTeYkc1S');
        if (! $ok || $user->status !== 'active') {
            if ($user) {
                $user->failed_attempts = $user->failed_attempts + 1;
                if ($user->failed_attempts >= self::MAX_ATTEMPTS) {
                    $user->locked_until = now()->addMinutes(self::LOCK_MINUTES);
                    $user->failed_attempts = 0;
                    AuditService::log('account_locked', 'denied', 'tbl_user', $user->user_id, null, $user->user_id);
                }
                $user->save();
            }
            AuditService::log('login_failed', 'denied', 'tbl_user', $user?->user_id, $data['email'], $user?->user_id);
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        AuditService::log('login_success', 'success', 'tbl_user', $user->user_id);
        return $this->me($request);
    }

    public function me(Request $request)
    {
        $u = $request->user()->load('role');
        return ['user_id' => $u->user_id, 'name' => $u->name, 'email' => $u->email, 'organisation' => $u->organisation,
                'role' => $u->role->role_code, 'role_name' => $u->role->role_name, 'permissions' => $u->role->permissions];
    }

    public function logout(Request $request)
    {
        AuditService::log('logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return ['message' => 'Signed out.'];
    }
}
