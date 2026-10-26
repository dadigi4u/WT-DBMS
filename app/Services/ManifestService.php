<?php

namespace App\Services;

use App\Models\Manifest;
use App\Models\Voyage;
use Illuminate\Support\Facades\DB;

/**
 * Manifests are never typed: they are composed from the primary ticket and consignment
 * records of a voyage, canonicalised, hashed (SHA-256) and stored immutably.
 */
class ManifestService
{
    public function compose(Voyage $v, string $type): array
    {
        $v->loadMissing(['vessel.operator', 'route.origin', 'route.destination', 'crew']);
        $header = [
            'voyage_ref' => $v->voyage_ref,
            'vessel_name' => $v->vessel->vessel_name,
            'registration_no' => $v->vessel->registration_no,
            'operator' => $v->vessel->operator->operator_name,
            'route' => $v->route->origin->jetty_name.' ('.$v->route->origin->country_code.') to '.$v->route->destination->jetty_name.' ('.$v->route->destination->country_code.')',
            'international' => (bool) $v->route->is_international,
            'scheduled_departure' => $v->scheduled_departure->format('Y-m-d H:i'),
            'master' => optional($v->crew->firstWhere('pivot.duty_role', 'master'))->full_name,
            'crew_count' => $v->crew->count(),
        ];
        if ($type === 'passenger') {
            $rows = $v->tickets()->with('passenger')->whereIn('status', ['issued', 'boarded'])->orderBy('ticket_id')->get()
                ->values()->map(fn ($t, $i) => [
                    'no' => $i + 1,
                    'ticket_ref' => $t->ticket_ref,
                    'surname' => mb_strtoupper($t->passenger->surname),
                    'other_names' => $t->passenger->other_names,
                    'sex' => $t->passenger->sex,
                    'date_of_birth' => $t->passenger->date_of_birth->format('Y-m-d'),
                    'nationality' => $t->passenger->nationality,
                    'id_type' => $t->passenger->id_type,
                    'id_number' => $t->passenger->id_number,
                    'next_of_kin' => $t->passenger->next_of_kin_name,
                    'next_of_kin_phone' => $t->passenger->next_of_kin_phone,
                    'status' => $t->status,
                ])->all();
            $summary = ['passengers' => count($rows), 'certified_capacity' => $v->passenger_capacity];
        } else {
            $rows = $v->consignments()->with(['shipper', 'consignee', 'dgClass'])->where('status', '<>', 'cancelled')->orderBy('consignment_id')->get()
                ->values()->map(fn ($c, $i) => [
                    'no' => $i + 1,
                    'consignment_ref' => $c->consignment_ref,
                    'shipper' => $c->shipper->full_name,
                    'consignee' => $c->consignee->full_name,
                    'description' => $c->description,
                    'marks_numbers' => $c->marks_numbers,
                    'packages' => $c->package_count,
                    'weight_kg' => round($c->weight_kg, 2),
                    'dg_class' => $c->dgClass?->class_code,
                ])->all();
            $summary = ['consignments' => count($rows), 'total_weight_kg' => round(array_sum(array_column($rows, 'weight_kg')), 2),
                        'certified_cargo_capacity_kg' => $v->cargo_capacity_kg,
                        'dangerous_goods_entries' => count(array_filter($rows, fn ($r) => $r['dg_class']))];
        }
        return ['manifest_type' => $type, 'header' => $header, 'entries' => $rows, 'summary' => $summary];
    }

    public static function canonical(array $content): string
    {
        $sort = function ($a) use (&$sort) {
            if (! is_array($a)) return $a;
            if (array_is_list($a)) return array_map($sort, $a);
            ksort($a);
            return array_map($sort, $a);
        };
        return json_encode($sort($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function issue(Voyage $v, string $type, int $userId, bool $final = false): Manifest
    {
        return DB::transaction(function () use ($v, $type, $userId, $final) {
            Voyage::whereKey($v->voyage_id)->lockForUpdate()->first();
            $content = $this->compose($v, $type);
            $json = self::canonical($content);
            $hash = hash('sha256', $json);
            $issueNo = (int) Manifest::where('voyage_id', $v->voyage_id)->where('manifest_type', $type)->max('issue_no') + 1;
            $m = Manifest::create([
                'manifest_ref' => ($type === 'passenger' ? 'PM-' : 'CM-').$v->voyage_ref.'-'.str_pad($issueNo, 2, '0', STR_PAD_LEFT),
                'voyage_id' => $v->voyage_id, 'manifest_type' => $type, 'issue_no' => $issueNo,
                'entry_count' => count($content['entries']),
                'total_weight_kg' => $type === 'cargo' ? $content['summary']['total_weight_kg'] : null,
                'content_json' => $json, 'content_hash' => $hash,
                'verification_code' => strtoupper(substr($hash, 0, 12)),
                'is_final' => $final, 'issued_by' => $userId, 'issued_at' => now(),
            ]);
            AuditService::log('manifest_issued', 'success', 'tbl_manifest', $m->manifest_id, $m->manifest_ref.' hash='.$hash, $userId);
            return $m;
        });
    }

    /** Verifies a printed manifest against the stored record and against live primary records. */
    public function verify(string $ref, string $code): array
    {
        $m = Manifest::where('manifest_ref', $ref)->first();
        if (! $m) return ['valid' => false, 'reason' => 'No manifest with this reference exists.'];
        $storedIntact = hash('sha256', $m->content_json) === $m->content_hash;
        $codeMatches = hash_equals($m->verification_code, strtoupper(trim($code)));
        $live = hash('sha256', self::canonical($this->compose($m->voyage, $m->manifest_type)));
        return [
            'valid' => $storedIntact && $codeMatches,
            'stored_record_intact' => $storedIntact,
            'verification_code_matches' => $codeMatches,
            'consistent_with_live_records' => $live === $m->content_hash,
            'manifest_ref' => $m->manifest_ref, 'issued_at' => $m->issued_at->toDateTimeString(),
            'issued_by' => $m->issuer->name, 'entry_count' => $m->entry_count, 'is_final' => $m->is_final,
        ];
    }
}
