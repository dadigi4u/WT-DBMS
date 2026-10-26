<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Append-only, hash-chained audit trail. Each row stores the hash of the previous row,
 * so any out-of-band alteration breaks the chain and is detected by verifyChain().
 */
class AuditService
{
    public static function log(string $event, string $outcome = 'success', ?string $entity = null, $entityId = null, ?string $detail = null, $userId = null): void
    {
        $userId = $userId ?? auth()->id();
        $ip = request()?->ip();
        DB::transaction(function () use ($event, $outcome, $entity, $entityId, $detail, $userId, $ip) {
            $prev = DB::table('tbl_audit_log')->orderByDesc('audit_id')->lockForUpdate()->value('row_hash') ?? str_repeat('0', 64);
            $ts = now()->format('Y-m-d H:i:s.u');
            $detail = $detail !== null ? mb_substr($detail, 0, 500) : null;
            $hash = hash('sha256', implode('|', [$prev, $userId, $event, $entity, $entityId, $outcome, $ip, $detail, $ts]));
            DB::table('tbl_audit_log')->insert([
                'user_id' => $userId, 'event' => $event, 'entity' => $entity, 'entity_id' => $entityId,
                'outcome' => $outcome, 'ip_address' => $ip, 'detail' => $detail,
                'prev_hash' => $prev, 'row_hash' => $hash, 'created_at' => $ts,
            ]);
        }, 5); // retried automatically if two writers deadlock on the chain head
    }

    /** Returns [bool valid, int rowsChecked, ?int firstBrokenId]. */
    public static function verifyChain(): array
    {
        $prev = str_repeat('0', 64);
        $n = 0;
        foreach (DB::table('tbl_audit_log')->orderBy('audit_id')->cursor() as $r) {
            $n++;
            $ts = \Carbon\Carbon::parse($r->created_at)->format('Y-m-d H:i:s.u');
            $calc = hash('sha256', implode('|', [$prev, $r->user_id, $r->event, $r->entity, $r->entity_id, $r->outcome, $r->ip_address, $r->detail, $ts]));
            if ($r->prev_hash !== $prev || $r->row_hash !== $calc) {
                return [false, $n, $r->audit_id];
            }
            $prev = $r->row_hash;
        }
        return [true, $n, null];
    }
}
