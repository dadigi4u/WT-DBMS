<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Scalability benchmark (run against a dedicated benchmark database).
 * php artisan wt:bench-scale
 */
Artisan::command('wt:bench-scale', function () {
    $svc = app(\App\Services\ManifestService::class);
    $userId = \App\Models\User::where('email', 'manager@oronmarine.ng')->value('user_id');
    $out = ['manifest_size' => [], 'data_volume' => []];
    $now = now();
    $mkPassengers = function (int $n, string $tag) use ($now) {
        $rows = [];
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $num = $tag.str_pad((string) $i, 8, '0', STR_PAD_LEFT);
            $rows[] = ['surname' => 'Sur'.(crc32($tag.$i) % 3000), 'other_names' => 'Passenger '.$i, 'sex' => $i % 2 ? 'M' : 'F', 'date_of_birth' => '1985-01-01', 'nationality' => 'NG', 'id_type' => 'passport',
                'id_number' => encrypt($num, false), 'id_number_hash' => \App\Models\Passenger::idHash('passport', $num), 'phone' => encrypt('+2348030000000', false),
                'next_of_kin_name' => 'Kin '.$i, 'next_of_kin_phone' => encrypt('+2348130000000', false), 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === 1000) { DB::table('tbl_passenger')->insert($rows); $rows = []; }
        }
        if ($rows) DB::table('tbl_passenger')->insert($rows);
        return DB::table('tbl_passenger')->orderByDesc('passenger_id')->limit($n)->pluck('passenger_id')->reverse()->values();
    };
    $mkVoyage = function (int $cap, int $k) use ($userId) {
        $vessel = \App\Models\Vessel::firstOrCreate(['registration_no' => 'BENCH/'.$cap], ['operator_id' => 1, 'vessel_name' => 'Bench Ferry '.$cap, 'vessel_type' => 'passenger_ferry', 'passenger_capacity' => $cap, 'cargo_capacity_kg' => 100000, 'status' => 'active']);
        foreach (\App\Models\Vessel::REQUIRED_CERTIFICATES as $t) {
            \App\Models\Certificate::firstOrCreate(['vessel_id' => $vessel->vessel_id, 'cert_type' => $t], ['cert_no' => 'B/'.$t, 'issuing_body' => 'Bench', 'issue_date' => now()->subDay(), 'expiry_date' => now()->addYear()]);
        }
        $dep = now()->addDays(200 + $k)->setTime(9, 0);
        $v = \App\Models\Voyage::create(['voyage_ref' => 'BENCH-'.$k.'-'.$cap, 'vessel_id' => $vessel->vessel_id, 'route_id' => 1, 'voyage_type' => 'scheduled', 'scheduled_departure' => $dep, 'scheduled_arrival' => $dep->copy()->addHours(4), 'passenger_capacity' => $cap, 'cargo_capacity_kg' => 100000, 'fare' => 15000, 'status' => 'boarding', 'created_by' => $userId]);
        $v->crew()->attach(1, ['duty_role' => 'master']);
        return $v;
    };
    $fill = function ($v, $pids) use ($userId, $now) {
        $rows = [];
        foreach ($pids as $i => $pid) {
            $rows[] = ['ticket_ref' => 'TKB'.$v->voyage_id.'X'.$i, 'voyage_id' => $v->voyage_id, 'passenger_id' => $pid, 'seat_no' => (string) ($i + 1), 'fare_paid' => 15000, 'status' => 'issued', 'issued_by' => $userId, 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === 1000) { DB::table('tbl_ticket')->insert($rows); $rows = []; }
        }
        if ($rows) DB::table('tbl_ticket')->insert($rows);
    };
    $time = function (callable $fn, int $reps) {
        $t = [];
        for ($i = 0; $i < $reps; $i++) { $s = hrtime(true); $fn(); $t[] = (hrtime(true) - $s) / 1e6; }
        sort($t);
        return ['median' => round($t[intdiv(count($t), 2)], 2), 'p95' => round($t[(int) floor(0.95 * (count($t) - 1))], 2), 'mean' => round(array_sum($t) / count($t), 2)];
    };
    // (a) Manifest size scaling
    $k = 0;
    foreach ([25, 50, 100, 200, 300, 400, 500] as $n) {
        $pids = $mkPassengers($n, 'MS'.$n.'X');
        $v = $mkVoyage($n, ++$k);
        $fill($v, $pids);
        $out['manifest_size'][] = ['passengers' => $n] + $time(fn () => $svc->issue($v->fresh(), 'passenger', $userId), 15);
        $this->info("manifest n=$n done");
    }
    // (b) Data volume scaling: fixed 120-passenger voyage, growing database
    $probe = $mkVoyage(120, 900);
    $fill($probe, $mkPassengers(120, 'PRB'));
    $ctl = new \App\Http\Controllers\OperationsController();
    $cap = app(\App\Services\CapacityService::class);
    $target = 0;
    foreach ([10000, 50000, 100000, 200000] as $total) {
        $have = DB::table('tbl_ticket')->count();
        $add = max(0, $total - $have);
        $batchVoyages = (int) ceil($add / 500);
        for ($b = 0; $b < $batchVoyages; $b++) {
            $n = min(500, $add - $b * 500);
            $v = $mkVoyage(500, 1000 + $target++);
            $fill($v, $mkPassengers($n, 'DV'.$target.'X'));
        }
        $tickets = DB::table('tbl_ticket')->count();
        auth()->loginUsingId($userId);
        $row = ['tickets_in_db' => $tickets, 'passengers_in_db' => DB::table('tbl_passenger')->count(), 'voyages_in_db' => DB::table('tbl_voyage')->count()];
        $row['manifest_ms'] = $time(fn () => $svc->issue($probe->fresh(), 'passenger', $userId), 15);
        $row['emergency_ms'] = $time(fn () => $ctl->emergency(request(), $probe->voyage_ref), 15);
        $row['capacity_check_ms'] = $time(function () use ($probe, $cap, $userId) {
            DB::beginTransaction();
            try { $cap->issueTicket($probe->voyage_id, ['surname' => 'Probe', 'other_names' => 'Test', 'sex' => 'M', 'date_of_birth' => '1990-01-01', 'nationality' => 'NG', 'id_type' => 'passport', 'id_number' => 'PROBE'.random_int(100000, 999999), 'phone' => '+2348030000001', 'next_of_kin_name' => 'Kin', 'next_of_kin_phone' => '+2348030000002'], $userId); } catch (\Throwable $e) {}
            DB::rollBack();
        }, 15);
        $row['surname_search_ms'] = $time(fn () => DB::table('tbl_passenger')->where('surname', 'Sur'.random_int(0, 2999))->get(), 15);
        $out['data_volume'][] = $row;
        $this->info("volume $tickets done");
    }
    file_put_contents('/home/claude/results/scale_eval.json', json_encode($out, JSON_PRETTY_PRINT));
    $this->info(json_encode($out, JSON_PRETTY_PRINT));
});
