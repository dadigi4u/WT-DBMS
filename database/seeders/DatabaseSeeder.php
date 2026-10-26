<?php

namespace Database\Seeders;

use App\Models\Certificate;
use App\Models\Consignment;
use App\Models\Crew;
use App\Models\DangerousGoodsClass;
use App\Models\Jetty;
use App\Models\Operator;
use App\Models\Passenger;
use App\Models\Role;
use App\Models\Route;
use App\Models\Shipper;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vessel;
use App\Models\Voyage;
use App\Services\AuditService;
use App\Services\ManifestService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Synthetic but realistic dataset for the Oron–Idenua corridor. No real persons are represented.
 */
class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'Oron#Idenua2026';

    public function run(): void
    {
        mt_srand(2026);
        $roles = [
            'admin' => ['System Administrator', ['*']],
            'operator_manager' => ['Operator / Terminal Manager', ['report.view', 'fleet.view', 'fleet.manage', 'crew.manage', 'voyage.view', 'voyage.manage', 'ticket.issue', 'passenger.view', 'cargo.view', 'cargo.accept', 'manifest.view', 'manifest.generate', 'manifest.verify', 'incident.report', 'border.request']],
            'ticket_clerk' => ['Ticketing / Boarding Clerk', ['voyage.view', 'ticket.issue', 'manifest.view']],
            'cargo_clerk' => ['Cargo Acceptance Clerk', ['voyage.view', 'cargo.view', 'cargo.accept', 'manifest.view']],
            'regulator' => ['Regulatory Officer (NIWA / NIMASA)', ['report.view', 'fleet.view', 'voyage.view', 'manifest.view', 'manifest.verify', 'cargo.view', 'audit.view']],
            'border_officer' => ['Border Officer (Immigration / Customs)', ['voyage.view', 'manifest.view', 'manifest.verify', 'border.view', 'cargo.view']],
            'responder' => ['Emergency Responder (SAR / Marine Police)', ['voyage.view', 'emergency.view', 'incident.report']],
            'auditor' => ['Data Protection / Audit Officer', ['audit.view', 'voyage.view', 'report.view', 'border.approve', 'border.view', 'manifest.verify']],
        ];
        $r = [];
        foreach ($roles as $code => [$name, $perms]) {
            $r[$code] = Role::create(['role_code' => $code, 'role_name' => $name, 'permissions' => $perms]);
        }
        $users = [
            ['admin', 'Ekemini Udo', 'admin@wtdbms.ng', 'WT-DBMS Project'],
            ['operator_manager', 'Aniekan Etim', 'manager@oronmarine.ng', 'Oron Marine Transport Ltd'],
            ['ticket_clerk', 'Idara Bassey', 'ticketing@oronmarine.ng', 'Oron Marine Transport Ltd'],
            ['cargo_clerk', 'Uduak Okon', 'cargo@oronmarine.ng', 'Oron Marine Transport Ltd'],
            ['regulator', 'Bassey Effiong', 'inspector@niwa.gov.ng', 'National Inland Waterways Authority'],
            ['border_officer', 'Hauwa Musa', 'oron.post@immigration.gov.ng', 'Nigeria Immigration Service'],
            ['responder', 'Emmanuel Akpan', 'sar@marinepolice.ng', 'Marine Police, Oron'],
            ['auditor', 'Nkoyo Inyang', 'dpo@wtdbms.ng', 'Data Protection Office'],
        ];
        $u = [];
        foreach ($users as [$role, $name, $email, $org]) {
            $u[$role] = User::create(['role_id' => $r[$role]->role_id, 'name' => $name, 'email' => $email, 'organisation' => $org, 'password' => self::PASSWORD, 'status' => 'active']);
        }

        $oron = Jetty::create(['jetty_name' => 'Oron Marine Jetty', 'town' => 'Oron', 'country_code' => 'NG', 'latitude' => 4.8260, 'longitude' => 8.2340]);
        $idenua = Jetty::create(['jetty_name' => 'Idenua Beach Landing', 'town' => 'Idenua', 'country_code' => 'CM', 'latitude' => 4.2480, 'longitude' => 8.9840]);
        $calabar = Jetty::create(['jetty_name' => 'Calabar Marina Jetty', 'town' => 'Calabar', 'country_code' => 'NG', 'latitude' => 4.9510, 'longitude' => 8.3170]);
        $routes = [
            Route::create(['route_code' => 'ORN-IDN', 'origin_jetty_id' => $oron->jetty_id, 'destination_jetty_id' => $idenua->jetty_id, 'is_international' => true, 'est_duration_min' => 240]),
            Route::create(['route_code' => 'IDN-ORN', 'origin_jetty_id' => $idenua->jetty_id, 'destination_jetty_id' => $oron->jetty_id, 'is_international' => true, 'est_duration_min' => 240]),
            Route::create(['route_code' => 'ORN-CAL', 'origin_jetty_id' => $oron->jetty_id, 'destination_jetty_id' => $calabar->jetty_id, 'is_international' => false, 'est_duration_min' => 60]),
        ];

        foreach ([
            ['3', 'Flammable liquids (petrol, diesel, kerosene)', 'Prohibited on passenger-carrying craft; dedicated tanker or cargo boat only', false, null],
            ['2.1', 'Flammable gases (LPG cylinders)', 'On deck, shaded, segregated from passengers and ignition sources', true, 150],
            ['8', 'Corrosive substances (battery acid)', 'On deck, upright, away from foodstuff', true, 200],
            ['9', 'Miscellaneous dangerous goods (lithium batteries)', 'Stow away from heat; max quantity enforced', true, 100],
            ['5.1', 'Oxidising substances (fertiliser)', 'Segregate from Class 3 and combustibles', true, 500],
        ] as [$code, $desc, $stow, $permitted, $max]) {
            DangerousGoodsClass::create(['class_code' => $code, 'description' => $desc, 'stowage_restriction' => $stow, 'passenger_vessel_permitted' => $permitted, 'max_qty_kg_per_voyage' => $max]);
        }

        $ops = [
            Operator::create(['operator_name' => 'Oron Marine Transport Ltd', 'licence_no' => 'NIWA/OP/AK/0142', 'licence_expiry' => now()->addMonths(9), 'phone' => '+2348031112233', 'address' => 'Marine Road, Oron, Akwa Ibom State']),
            Operator::create(['operator_name' => 'Akwa Ibom State Ferry Services', 'licence_no' => 'NIWA/OP/AK/0007', 'licence_expiry' => now()->addMonths(14), 'phone' => '+2348022224455', 'address' => 'Oron Maritime Hub, Oron']),
            Operator::create(['operator_name' => 'Bakassi Coastal Boats', 'licence_no' => 'NIWA/OP/AK/0219', 'licence_expiry' => now()->addMonths(3), 'phone' => '+2348065557788', 'address' => 'Uya Oro, Oron']),
        ];
        $fleet = [
            [0, 'MV Obolo Star', 'AKS/ORN/2019/041', 'passenger_cargo', 'Fibreglass', '2 x 200hp outboard', 40, 3500, 'ok'],
            [0, 'MV Uya Oro Express', 'AKS/ORN/2020/077', 'speedboat', 'Fibreglass', '2 x 115hp outboard', 18, 600, 'ok'],
            [1, 'MV Ibom Pride', 'AKS/ORN/2025/003', 'passenger_cargo', 'Steel', '2 x 450hp inboard diesel', 120, 15000, 'ok'],
            [1, 'MV Atlantic Link', 'AKS/ORN/2024/011', 'passenger_ferry', 'Aluminium', '2 x 300hp inboard diesel', 80, 4000, 'expiring'],
            [2, 'MB Bakassi Queen', 'AKS/ORN/2016/118', 'speedboat', 'Wood', '1 x 75hp outboard', 14, 400, 'lapsed'],
            [2, 'MB Mbo Trader', 'AKS/ORN/2018/090', 'cargo_boat', 'Wood', '1 x 85hp outboard', 0, 6000, 'ok'],
        ];
        $vessels = [];
        foreach ($fleet as [$op, $name, $reg, $type, $hull, $eng, $pax, $cargo, $cert]) {
            $v = Vessel::create(['operator_id' => $ops[$op]->operator_id, 'vessel_name' => $name, 'registration_no' => $reg, 'vessel_type' => $type, 'hull_material' => $hull, 'engine_details' => $eng, 'passenger_capacity' => $pax, 'cargo_capacity_kg' => $cargo, 'status' => 'active']);
            foreach (['registration' => 60, 'survey' => 12, 'safety_equipment' => 12, 'insurance' => 12] as $ct => $months) {
                $issue = now()->subMonths(mt_rand(1, 8));
                $exp = $issue->copy()->addMonths($months);
                if ($cert === 'expiring' && $ct === 'survey') { $exp = now()->addDays(12); }
                if ($cert === 'lapsed' && $ct === 'survey') { $issue = now()->subMonths(14); $exp = now()->subDays(20); }
                Certificate::create(['vessel_id' => $v->vessel_id, 'cert_type' => $ct, 'cert_no' => strtoupper(substr($ct, 0, 3)).'/'.mt_rand(10000, 99999), 'issuing_body' => $ct === 'insurance' ? 'Leadway Assurance' : 'National Inland Waterways Authority', 'issue_date' => $issue, 'expiry_date' => $exp]);
            }
            $vessels[] = $v;
        }

        $crewNames = [['Okon Asuquo', 'master'], ['Etim Edet', 'master'], ['Effiong Ekpo', 'master'], ['Udoh Archibong', 'master'], ['Sunday Ita', 'mate'], ['Gabriel Nsikak', 'mate'], ['Ime Udofia', 'engineer'], ['Mfon Essien', 'engineer'], ['Kufre Akpabio', 'deckhand'], ['Victor Ukpong', 'deckhand'], ['Aniebiet Obot', 'deckhand'], ['Comfort Ekanem', 'steward'], ['Ebong Offiong', 'master'], ['Samuel Eyo', 'engineer']];
        $crew = [];
        foreach ($crewNames as $i => [$n, $rank]) {
            $crew[] = Crew::create(['full_name' => $n, 'rank' => $rank, 'competency_cert_no' => 'NIMASA/COC/'.(4100 + $i), 'cert_expiry' => now()->addMonths(mt_rand(6, 30)), 'phone' => '+23480'.mt_rand(10000000, 99999999), 'status' => 'active']);
        }

        $ngSur = ['Udo', 'Etim', 'Bassey', 'Okon', 'Effiong', 'Akpan', 'Essien', 'Ekpo', 'Inyang', 'Udoh', 'Edet', 'Asuquo', 'Archibong', 'Eyo', 'Ita', 'Obot', 'Umoh', 'Okoro', 'Nwachukwu', 'Adebayo', 'Ibrahim', 'Eze', 'Okafor', 'Ogbonna', 'Ekanem', 'Offiong', 'Antia', 'Ukpong'];
        $ngGiven = ['Aniekan', 'Idara', 'Uduak', 'Nsikak', 'Mfon', 'Ekemini', 'Imaobong', 'Emem', 'Ubong', 'Kufre', 'Blessing', 'Grace', 'Godwin', 'Esther', 'Joseph', 'Patience', 'Victor', 'Comfort', 'Daniel', 'Mercy', 'Samuel', 'Faith', 'Chinedu', 'Ngozi', 'Aisha', 'Tunde'];
        $cmSur = ['Ndive', 'Mbua', 'Ekane', 'Molua', 'Ewane', 'Nkwelle', 'Tabi', 'Mbella', 'Fon', 'Ngoh', 'Epie', 'Litumbe'];
        $cmGiven = ['Ebenezer', 'Nadege', 'Brice', 'Clarisse', 'Herve', 'Mirabelle', 'Rodrigue', 'Stephanie', 'Achille', 'Gisele'];
        $passengers = [];
        for ($i = 0; $i < 420; $i++) {
            $cm = mt_rand(1, 100) <= 22;
            $type = $cm ? 'passport' : (mt_rand(1, 100) <= 70 ? 'ecowas_card' : 'passport');
            $num = $cm ? 'CM'.mt_rand(1000000, 9999999) : ($type === 'passport' ? 'B'.mt_rand(10000000, 99999999) : 'ETC'.mt_rand(1000000, 9999999));
            $passengers[] = Passenger::create([
                'surname' => $cm ? $cmSur[array_rand($cmSur)] : $ngSur[array_rand($ngSur)],
                'other_names' => $cm ? $cmGiven[array_rand($cmGiven)] : $ngGiven[array_rand($ngGiven)].' '.$ngGiven[array_rand($ngGiven)],
                'sex' => mt_rand(0, 1) ? 'M' : 'F', 'date_of_birth' => now()->subYears(mt_rand(18, 70))->subDays(mt_rand(0, 364)),
                'nationality' => $cm ? 'CM' : 'NG', 'id_type' => $type, 'id_number' => $num, 'id_number_hash' => Passenger::idHash($type, $num),
                'phone' => ($cm ? '+2376' : '+23480').mt_rand(10000000, 99999999),
                'next_of_kin_name' => ($cm ? $cmGiven[array_rand($cmGiven)].' ' : $ngGiven[array_rand($ngGiven)].' ').($cm ? $cmSur[array_rand($cmSur)] : $ngSur[array_rand($ngSur)]),
                'next_of_kin_phone' => ($cm ? '+2376' : '+23481').mt_rand(10000000, 99999999),
            ]);
        }
        $shippers = [];
        foreach ([['Mama Iquo Provisions', 'company', 'NG'], ['Effiong Building Materials', 'company', 'NG'], ['Uduak Textiles & Shoes', 'company', 'NG'], ['Grace Okon', 'individual', 'NG'], ['Idenua Commercial Stores', 'company', 'CM'], ['Epie & Sons Trading', 'company', 'CM'], ['Nadege Mbua', 'individual', 'CM'], ['Limbe Hardware Depot', 'company', 'CM'], ['Oron Agro Produce Co-op', 'company', 'NG'], ['Bassey Electronics', 'company', 'NG']] as [$n, $t, $c]) {
            $shippers[] = Shipper::create(['full_name' => $n, 'party_type' => $t, 'country_code' => $c, 'phone' => ($c === 'CM' ? '+2376' : '+23480').mt_rand(10000000, 99999999), 'id_reference' => $t === 'company' ? ($c === 'NG' ? 'RC'.mt_rand(100000, 999999) : 'RCCM-LIM-'.mt_rand(1000, 9999)) : null]);
        }
        $goods = [['Bags of rice (50 kg)', 'foodstuff', 50], ['Cartons of noodles', 'foodstuff', 12], ['Bales of second-hand clothing', 'textiles_footwear', 45], ['Cartons of footwear', 'textiles_footwear', 18], ['Bags of cement', 'building_materials', 50], ['Bundles of iron rods', 'building_materials', 80], ['Cartons of soft drinks', 'beverages', 15], ['Plastic chairs', 'household_goods', 3], ['Sacks of garri', 'agricultural_produce', 40], ['Mobile phone accessories', 'electronics', 10], ['Motorcycle spare parts', 'vehicles_parts', 25]];

        $voyageVessels = [$vessels[0], $vessels[1], $vessels[2], $vessels[3]];
        $masters = array_values(array_filter($crew, fn ($c) => $c->rank === 'master'));
        $others = array_values(array_filter($crew, fn ($c) => $c->rank !== 'master'));
        $manifests = new ManifestService();
        $seq = [];
        for ($d = 21; $d >= -2; $d--) {
            $date = now()->startOfDay()->subDays($d);
            foreach ([7, 13] as $k => $hour) {
                $ves = $voyageVessels[(($d + $k) % 4 + 4) % 4];
                $route = $routes[$k === 0 ? 0 : (mt_rand(0, 3) ? 1 : 2)];
                $dep = $date->copy()->setTime($hour, $k ? 30 : 0);
                $key = $dep->toDateString();
                $seq[$key] = ($seq[$key] ?? 0) + 1;
                $status = $d > 0 ? 'arrived' : ($d === 0 ? ($k === 0 ? 'boarding' : 'scheduled') : 'scheduled');
                $v = Voyage::create([
                    'voyage_ref' => $route->route_code.'-'.$dep->format('ymd').'-'.str_pad($seq[$key], 2, '0', STR_PAD_LEFT),
                    'vessel_id' => $ves->vessel_id, 'route_id' => $route->route_id, 'voyage_type' => 'scheduled',
                    'scheduled_departure' => $dep, 'scheduled_arrival' => $dep->copy()->addMinutes($route->est_duration_min),
                    'actual_departure' => $d > 0 ? $dep->copy()->addMinutes(mt_rand(5, 40)) : null,
                    'actual_arrival' => $d > 0 ? $dep->copy()->addMinutes($route->est_duration_min + mt_rand(0, 50)) : null,
                    'passenger_capacity' => $ves->passenger_capacity, 'cargo_capacity_kg' => $ves->cargo_capacity_kg,
                    'fare' => $route->is_international ? 15000 : 4500, 'status' => $status, 'created_by' => $u['operator_manager']->user_id,
                    'created_at' => $dep->copy()->subDays(3),
                ]);
                $v->crew()->attach($masters[(($d + $k) % count($masters) + count($masters)) % count($masters)]->crew_id, ['duty_role' => 'master']);
                $v->crew()->attach($others[(($d * 2 + $k) % count($others) + count($others)) % count($others)]->crew_id, ['duty_role' => $others[(($d * 2 + $k) % count($others) + count($others)) % count($others)]->rank]);
                $fill = $d === 0 && $k === 0 ? $ves->passenger_capacity - 2 : (int) round($ves->passenger_capacity * mt_rand(45, 100) / 100);
                if ($d < 0) { $fill = (int) round($fill * 0.3); }
                $pool = $passengers;
                shuffle($pool);
                foreach (array_slice($pool, 0, $fill) as $n => $p) {
                    if ($route->is_international && $p->id_type === 'nin') continue;
                    Ticket::create(['ticket_ref' => 'TK'.$dep->format('ymd').strtoupper(Str::random(8)), 'voyage_id' => $v->voyage_id, 'passenger_id' => $p->passenger_id, 'seat_no' => (string) ($n + 1), 'fare_paid' => $v->fare, 'status' => $d > 0 ? 'boarded' : 'issued', 'boarded_at' => $d > 0 ? $dep : null, 'issued_by' => $u['ticket_clerk']->user_id, 'created_at' => $dep->copy()->subHours(mt_rand(1, 30))]);
                }
                $target = $ves->cargo_capacity_kg * mt_rand(30, 92) / 100;
                if ($d < 0) { $target *= 0.3; }
                $load = 0;
                while (true) {
                    $g = $goods[array_rand($goods)];
                    $pk = mt_rand(2, 30);
                    $w = $pk * $g[2];
                    if ($load + $w > $target) break;
                    $outbound = $route->route_code === 'ORN-IDN';
                    $sh = $shippers[$outbound ? mt_rand(0, 3) : mt_rand(4, 7)];
                    $cn = $shippers[$outbound ? mt_rand(4, 7) : mt_rand(0, 3)];
                    Consignment::create(['consignment_ref' => 'CN'.$dep->format('ymd').strtoupper(Str::random(8)), 'voyage_id' => $v->voyage_id, 'shipper_id' => $sh->shipper_id, 'consignee_id' => $cn->shipper_id, 'description' => $g[0], 'hs_category' => $g[1], 'marks_numbers' => strtoupper(substr($sh->full_name, 0, 3)).'/'.mt_rand(100, 999), 'package_count' => $pk, 'weight_kg' => $w, 'declared_value' => $w * mt_rand(800, 2500), 'freight_charge' => $w * 40, 'status' => $d > 0 ? 'delivered' : 'accepted', 'accepted_by' => $u['cargo_clerk']->user_id]);
                    $load += $w;
                }
                if ($d > 0 && mt_rand(1, 3) === 1) {
                    Consignment::create(['consignment_ref' => 'CN'.$dep->format('ymd').strtoupper(Str::random(8)), 'voyage_id' => $v->voyage_id, 'shipper_id' => $shippers[3]->shipper_id, 'consignee_id' => $shippers[6]->shipper_id, 'description' => 'LPG cylinders (12.5 kg)', 'hs_category' => 'household_goods', 'marks_numbers' => 'GAS/'.mt_rand(10, 99), 'package_count' => 4, 'weight_kg' => 100, 'dg_class_id' => 2, 'declared_value' => 120000, 'freight_charge' => 4000, 'status' => 'delivered', 'accepted_by' => $u['cargo_clerk']->user_id]);
                }
                if ($d > 0) {
                    $manifests->issue($v, 'passenger', $u['operator_manager']->user_id, true);
                    $manifests->issue($v, 'cargo', $u['operator_manager']->user_id, true);
                }
            }
        }
        AuditService::log('system_seeded', 'success', null, null, 'Synthetic corridor dataset loaded', $u['admin']->user_id);
    }
}
