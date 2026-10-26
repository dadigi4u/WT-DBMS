<?php

namespace Tests\Feature;

use App\Models\Consignment;
use App\Models\Manifest;
use App\Models\Passenger;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vessel;
use App\Models\Voyage;
use App\Services\AuditService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Functional (F) and security (S) test cases for the Secure Web-Based Water Transport DBMS.
 * Test IDs correspond to Tables 4.x in Chapter Four.
 */
class SystemTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $role): self
    {
        $u = User::whereHas('role', fn ($q) => $q->where('role_code', $role))->firstOrFail();
        return $this->actingAs($u);
    }

    private function pax(array $over = []): array
    {
        static $n = 0;
        $n++;
        return array_merge([
            'surname' => 'Ekanem', 'other_names' => 'Ubong Peter', 'sex' => 'M', 'date_of_birth' => '1990-05-14',
            'nationality' => 'NG', 'id_type' => 'ecowas_card', 'id_number' => 'ETC9'.str_pad((string) $n, 6, '0', STR_PAD_LEFT).rand(10, 99),
            'phone' => '+2348031234567', 'next_of_kin_name' => 'Mary Ekanem', 'next_of_kin_phone' => '+2348097654321',
        ], $over);
    }

    private function openVoyage(int $remaining = null): Voyage
    {
        $v = Voyage::where('status', 'boarding')->firstOrFail();
        if ($remaining !== null) {
            $v->tickets()->delete();
            $v->update(['passenger_capacity' => $remaining]);
        }
        return $v->fresh();
    }

    private function futureVoyagePayload(array $over = []): array
    {
        $vessel = Vessel::where('vessel_name', 'MV Ibom Pride')->first();
        $masters = DB::table('tbl_crew')->where('rank', 'master')->pluck('crew_id');
        return array_merge([
            'vessel_id' => $vessel->vessel_id, 'route_id' => 1, 'voyage_type' => 'scheduled',
            'scheduled_departure' => now()->addDays(10)->setTime(6, 0)->toDateTimeString(), 'fare' => 15000,
            'crew' => [['crew_id' => $masters->last(), 'duty_role' => 'master']],
        ], $over);
    }

    /* ------------------------------ FUNCTIONAL ------------------------------ */

    public function test_F01_login_with_valid_credentials_returns_role_profile()
    {
        $this->postJson('/api/login', ['email' => 'ticketing@oronmarine.ng', 'password' => DatabaseSeeder::PASSWORD])
            ->assertOk()->assertJsonPath('role', 'ticket_clerk')->assertJsonMissing(['password']);
    }

    public function test_F02_register_vessel_in_fleet_register()
    {
        $this->as('operator_manager')->postJson('/api/vessels', [
            'operator_id' => 1, 'vessel_name' => 'MV Test Carrier', 'registration_no' => 'AKS/ORN/2026/999', 'vessel_type' => 'passenger_cargo',
            'passenger_capacity' => 60, 'cargo_capacity_kg' => 5000,
        ])->assertCreated();
        $this->assertDatabaseHas('tbl_vessel', ['registration_no' => 'AKS/ORN/2026/999', 'passenger_capacity' => 60]);
    }

    public function test_F03_record_vessel_certificate()
    {
        $this->as('operator_manager')->postJson('/api/certificates', [
            'vessel_id' => 1, 'cert_type' => 'survey', 'cert_no' => 'SUR/12345', 'issuing_body' => 'National Inland Waterways Authority',
            'issue_date' => now()->subDay()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
        ])->assertCreated();
    }

    public function test_F04_schedule_voyage_with_compliant_vessel_and_master()
    {
        $r = $this->as('operator_manager')->postJson('/api/voyages', $this->futureVoyagePayload())->assertCreated();
        $this->assertStringStartsWith('ORN-IDN-', $r->json('voyage_ref'));
        $this->assertSame(120, Voyage::find($r->json('voyage_id'))->passenger_capacity, 'capacity inherited from certified vessel');
    }

    public function test_F05_refuse_voyage_for_vessel_with_lapsed_certificate()
    {
        $lapsed = Vessel::where('vessel_name', 'MB Bakassi Queen')->first();
        $this->as('operator_manager')->postJson('/api/voyages', $this->futureVoyagePayload(['vessel_id' => $lapsed->vessel_id]))
            ->assertStatus(409)->assertJsonPath('rule', 'certificate_lapsed');
    }

    public function test_F06_refuse_voyage_without_master()
    {
        $deck = DB::table('tbl_crew')->where('rank', 'deckhand')->value('crew_id');
        $this->as('operator_manager')->postJson('/api/voyages', $this->futureVoyagePayload(['crew' => [['crew_id' => $deck, 'duty_role' => 'deckhand']]]))
            ->assertStatus(422)->assertJsonPath('rule', 'no_master');
    }

    public function test_F07_refuse_concurrent_assignment_of_same_crew_member()
    {
        $this->as('operator_manager')->postJson('/api/voyages', $this->futureVoyagePayload())->assertCreated();
        $p = $this->futureVoyagePayload(['vessel_id' => Vessel::where('vessel_name', 'MV Obolo Star')->value('vessel_id'), 'scheduled_departure' => now()->addDays(10)->setTime(7, 0)->toDateTimeString()]);
        $this->postJson('/api/voyages', $p)->assertStatus(409)->assertJsonPath('rule', 'crew_clash');
    }

    public function test_F08_issue_ticket_within_capacity()
    {
        $v = $this->openVoyage(5);
        $this->as('ticket_clerk')->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax())
            ->assertCreated()->assertJsonPath('passengers', 1)->assertJsonPath('capacity', 5);
    }

    public function test_F09_refuse_ticket_once_certified_passenger_capacity_reached()
    {
        $v = $this->openVoyage(2);
        $this->as('ticket_clerk');
        $this->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax())->assertCreated();
        $this->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax())->assertCreated();
        $this->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax())->assertStatus(409)->assertJsonPath('rule', 'passenger_capacity');
        $this->assertSame(2, $v->fresh()->activeTicketCount());
        $this->assertDatabaseHas('tbl_audit_log', ['event' => 'rule_refusal', 'outcome' => 'refused']);
    }

    public function test_F10_refuse_duplicate_ticket_for_same_passenger_and_voyage()
    {
        $v = $this->openVoyage(10);
        $p = $this->pax();
        $this->as('ticket_clerk')->postJson("/api/voyages/{$v->voyage_id}/tickets", $p)->assertCreated();
        $this->postJson("/api/voyages/{$v->voyage_id}/tickets", $p)->assertStatus(422)->assertJsonPath('rule', 'duplicate_ticket');
    }

    public function test_F11_international_crossing_requires_passport_or_ecowas_document()
    {
        $v = $this->openVoyage(10);
        $this->as('ticket_clerk')->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax(['id_type' => 'voters_card', 'id_number' => 'VC12345678']))
            ->assertStatus(422)->assertJsonValidationErrors('id_type');
    }

    private function cargo(array $over = []): array
    {
        return array_merge(['shipper_id' => 1, 'consignee_id' => 5, 'description' => 'Bags of rice (50 kg)', 'hs_category' => 'foodstuff', 'package_count' => 10, 'weight_kg' => 500], $over);
    }

    public function test_F12_accept_consignment_within_cargo_capacity()
    {
        $v = $this->openVoyage();
        $v->consignments()->delete();
        $this->as('cargo_clerk')->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo())->assertCreated()->assertJsonPath('cargo_kg', 500);
    }

    public function test_F13_refuse_consignment_exceeding_certified_cargo_capacity()
    {
        $v = $this->openVoyage();
        $v->consignments()->delete();
        $this->as('cargo_clerk')->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['weight_kg' => $v->cargo_capacity_kg - 100]))->assertCreated();
        $this->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['weight_kg' => 101]))->assertStatus(409)->assertJsonPath('rule', 'cargo_capacity');
        $this->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['weight_kg' => 100]))->assertCreated();
    }

    public function test_F14_refuse_class3_flammable_liquids_on_passenger_vessel()
    {
        $v = $this->openVoyage();
        $this->as('cargo_clerk')->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['description' => 'Drums of petrol', 'hs_category' => 'petroleum_products', 'weight_kg' => 200, 'dg_class_id' => 1]))
            ->assertStatus(409)->assertJsonPath('rule', 'dg_prohibited');
    }

    public function test_F15_enforce_dangerous_goods_quantity_limit_per_voyage()
    {
        $v = $this->openVoyage();
        $v->consignments()->delete();
        $this->as('cargo_clerk')->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['description' => 'LPG cylinders', 'hs_category' => 'household_goods', 'weight_kg' => 100, 'dg_class_id' => 2]))->assertCreated();
        $this->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['description' => 'LPG cylinders', 'hs_category' => 'household_goods', 'weight_kg' => 60, 'dg_class_id' => 2]))->assertStatus(409)->assertJsonPath('rule', 'dg_quantity');
    }

    public function test_F16_petroleum_products_must_carry_dangerous_goods_declaration()
    {
        $v = $this->openVoyage();
        $this->as('cargo_clerk')->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['description' => 'Jerrycans of kerosene', 'hs_category' => 'petroleum_products']))
            ->assertStatus(422)->assertJsonValidationErrors('dg_class_id');
    }

    public function test_F17_passenger_manifest_is_composed_from_ticket_records()
    {
        $v = $this->openVoyage();
        $r = $this->as('operator_manager')->postJson("/api/voyages/{$v->voyage_id}/manifests", ['type' => 'passenger'])->assertCreated();
        $this->assertCount($v->activeTicketCount(), $r->json('content.entries'));
        $this->assertSame(64, strlen($r->json('content_hash')));
        $this->assertSame(strtoupper(substr($r->json('content_hash'), 0, 12)), $r->json('verification_code'));
    }

    public function test_F18_cargo_manifest_total_equals_sum_of_consignments()
    {
        $v = $this->openVoyage();
        $r = $this->as('operator_manager')->postJson("/api/voyages/{$v->voyage_id}/manifests", ['type' => 'cargo'])->assertCreated();
        $this->assertEqualsWithDelta($v->cargoLoadKg(), $r->json('content.summary.total_weight_kg'), 0.01);
        $this->assertCount($v->consignments()->where('status', '<>', 'cancelled')->count(), $r->json('content.entries'));
    }

    public function test_F19_departure_finalises_manifests_and_closes_voyage_to_further_bookings()
    {
        $v = $this->openVoyage(30);
        $this->as('operator_manager')->patchJson("/api/voyages/{$v->voyage_id}/status", ['status' => 'departed'])->assertOk()->assertJsonCount(2, 'final_manifests');
        $this->assertSame(2, Manifest::where('voyage_id', $v->voyage_id)->where('is_final', true)->count());
        $this->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax())->assertStatus(409)->assertJsonPath('rule', 'voyage_closed');
    }

    public function test_F20_printed_manifest_verifies_against_stored_record()
    {
        $m = Manifest::where('is_final', true)->first();
        $this->as('regulator')->postJson('/api/manifests/verify', ['manifest_ref' => $m->manifest_ref, 'code' => $m->verification_code])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('stored_record_intact', true)->assertJsonPath('consistent_with_live_records', true);
    }

    public function test_F21_verification_rejects_forged_code()
    {
        $m = Manifest::where('is_final', true)->first();
        $this->as('regulator')->postJson('/api/manifests/verify', ['manifest_ref' => $m->manifest_ref, 'code' => 'ABCDEF123456'])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('verification_code_matches', false);
    }

    public function test_F22_emergency_retrieval_returns_persons_crew_and_hazards()
    {
        $v = $this->openVoyage();
        $v->consignments()->delete();
        Consignment::create(['consignment_ref' => 'CNTESTDG01', 'voyage_id' => $v->voyage_id, 'shipper_id' => 1, 'consignee_id' => 5, 'description' => 'LPG cylinders', 'hs_category' => 'household_goods', 'package_count' => 4, 'weight_kg' => 50, 'dg_class_id' => 2, 'accepted_by' => 1]);
        $r = $this->as('responder')->getJson("/api/emergency/{$v->voyage_ref}")->assertOk();
        $this->assertSame($v->activeTicketCount() + $v->crew()->count(), $r->json('persons_on_board'));
        $this->assertCount(1, $r->json('dangerous_goods'));
        $this->assertNotEmpty($r->json('passengers.0.next_of_kin_phone'));
        $this->assertDatabaseHas('tbl_audit_log', ['event' => 'emergency_retrieval']);
    }

    public function test_F23_critical_incident_flags_voyage()
    {
        $v = $this->openVoyage();
        $this->as('responder')->postJson("/api/voyages/{$v->voyage_id}/incidents", ['incident_type' => 'capsize', 'severity' => 'critical', 'description' => 'Distress call received; vessel capsized near Bakassi.', 'last_latitude' => 4.5, 'last_longitude' => 8.6])->assertCreated();
        $this->assertSame('incident', $v->fresh()->status);
    }

    public function test_F24_cross_border_disclosure_is_minimised_approved_and_logged()
    {
        $v = $this->openVoyage();
        $d = $this->as('operator_manager')->postJson("/api/voyages/{$v->voyage_id}/border-declarations", [
            'direction' => 'departure', 'recipient_authority' => 'Cameroon Police Frontier Post', 'recipient_country' => 'CM',
            'lawful_basis' => 'Advance passenger list for border control', 'fields' => ['surname', 'nationality', 'id_number'],
        ])->assertCreated();
        $id = $d->json('declaration_id');
        $this->as('auditor')->postJson("/api/border-declarations/{$id}/decision", ['decision' => 'approved'])->assertOk();
        $x = $this->as('border_officer')->getJson("/api/border-declarations/{$id}/export")->assertOk();
        $this->assertEqualsCanonicalizing(['surname', 'nationality', 'id_number'], array_keys($x->json('passengers.0')));
        $this->assertDatabaseHas('tbl_audit_log', ['event' => 'disclosure_transmitted']);
    }

    public function test_F25_dashboard_reports_kpis_and_certificate_alerts()
    {
        $r = $this->as('regulator')->getJson('/api/dashboard')->assertOk();
        $this->assertGreaterThan(0, $r->json('vessels_active'));
        $this->assertTrue(collect($r->json('certificate_alerts'))->contains('lapsed', true));
    }

    public function test_F26_refuse_voyage_for_newly_registered_vessel_without_certificates()
    {
        $id = $this->as('operator_manager')->postJson('/api/vessels', [
            'operator_id' => 1, 'vessel_name' => 'MV Uncertified', 'registration_no' => 'AKS/ORN/2026/998', 'vessel_type' => 'speedboat',
            'passenger_capacity' => 20, 'cargo_capacity_kg' => 500,
        ])->json('vessel_id');
        $this->postJson('/api/voyages', $this->futureVoyagePayload(['vessel_id' => $id]))->assertStatus(409)->assertJsonPath('rule', 'certificate_lapsed');
    }

    /* ------------------------------ SECURITY ------------------------------ */

    public function test_S01_unauthenticated_requests_are_rejected()
    {
        foreach (['/api/vessels', '/api/voyages', '/api/emergency/ORN-IDN-261013-01', '/api/audit'] as $u) {
            $this->getJson($u)->assertStatus(401);
        }
    }

    public function test_S02_ticket_clerk_cannot_register_vessel_and_attempt_is_logged()
    {
        $this->as('ticket_clerk')->postJson('/api/vessels', ['vessel_name' => 'X'])->assertStatus(403);
        $this->assertDatabaseHas('tbl_audit_log', ['event' => 'access_denied']);
    }

    public function test_S03_responder_cannot_issue_tickets()
    {
        $v = $this->openVoyage();
        $this->as('responder')->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax())->assertStatus(403);
    }

    public function test_S04_cargo_clerk_cannot_read_audit_trail_or_emergency_data()
    {
        $this->as('cargo_clerk')->getJson('/api/audit')->assertStatus(403);
        $this->getJson('/api/emergency/ORN-IDN-261013-01')->assertStatus(403);
    }

    public function test_S05_wrong_password_gives_generic_error()
    {
        $this->postJson('/api/login', ['email' => 'admin@wtdbms.ng', 'password' => 'wrong'])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
        $this->postJson('/api/login', ['email' => 'nobody@nowhere.ng', 'password' => 'wrong'])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_S06_account_locks_after_five_failed_attempts()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'cargo@oronmarine.ng', 'password' => 'guess'.$i])->assertStatus(401);
        }
        $this->postJson('/api/login', ['email' => 'cargo@oronmarine.ng', 'password' => DatabaseSeeder::PASSWORD])->assertStatus(423);
        $this->assertDatabaseHas('tbl_audit_log', ['event' => 'account_locked']);
    }

    public function test_S07_passwords_stored_as_adaptive_bcrypt_hashes()
    {
        foreach (DB::table('tbl_user')->pluck('password') as $h) {
            $this->assertMatchesRegularExpression('/^\$2y\$12\$/', $h);
            $this->assertStringNotContainsString(DatabaseSeeder::PASSWORD, $h);
        }
    }

    public function test_S08_sensitive_attributes_encrypted_at_rest()
    {
        $v = $this->openVoyage(10);
        $this->as('ticket_clerk')->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax(['id_number' => 'ETC7777777', 'phone' => '+2348011112222']))->assertCreated();
        $raw = DB::table('tbl_passenger')->orderByDesc('passenger_id')->first();
        $this->assertStringNotContainsString('ETC7777777', $raw->id_number);
        $this->assertStringNotContainsString('8011112222', $raw->phone);
        $this->assertSame('ETC7777777', Passenger::find($raw->passenger_id)->id_number);
        $m = DB::table('tbl_manifest')->first();
        $this->assertStringNotContainsString('next_of_kin', $m->content_json);
    }

    public function test_S09_sql_injection_in_login_does_not_bypass_authentication()
    {
        foreach (["' OR '1'='1", "admin@wtdbms.ng' -- ", "\" OR 1=1 #"] as $payload) {
            $this->postJson('/api/login', ['email' => $payload, 'password' => "' OR '1'='1"])->assertStatus(422);
        }
        $this->postJson('/api/login', ['email' => 'admin@wtdbms.ng', 'password' => "' OR '1'='1"])->assertStatus(401);
    }

    public function test_S10_sql_injection_in_query_parameters_is_rejected()
    {
        $this->as('responder')->getJson('/api/emergency/'.rawurlencode("X' OR 1=1 -- "))->assertStatus(422);
        $this->as('operator_manager')->getJson('/api/voyages?status='.rawurlencode("arrived' OR '1'='1"))->assertStatus(422);
        $this->as('auditor')->getJson('/api/audit?event='.rawurlencode('x; DROP TABLE tbl_user'))->assertStatus(422);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('tbl_user'));
    }

    public function test_S11_sql_injection_in_form_fields_is_refused_by_allow_list()
    {
        $v = $this->openVoyage(10);
        $this->as('ticket_clerk')->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax(['surname' => "Robert'); DROP TABLE tbl_ticket;--"]))
            ->assertStatus(422)->assertJsonValidationErrors('surname');
    }

    public function test_S12_script_injection_in_text_fields_is_refused()
    {
        $v = $this->openVoyage();
        $this->as('cargo_clerk')->postJson("/api/voyages/{$v->voyage_id}/consignments", $this->cargo(['description' => '<script>alert(1)</script>']))
            ->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_S14_audit_trail_rejects_update()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('tbl_audit_log')->where('audit_id', 1)->update(['outcome' => 'tampered']);
    }

    public function test_S15_audit_trail_rejects_delete()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('tbl_audit_log')->where('audit_id', 1)->delete();
    }

    public function test_S16_audit_hash_chain_verifies()
    {
        $this->as('auditor')->getJson('/api/audit/verify')->assertOk()->assertJsonPath('chain_valid', true);
    }

    public function test_S17_issued_manifests_are_immutable()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('tbl_manifest')->where('manifest_id', 1)->update(['entry_count' => 0]);
    }

    public function test_S18_document_numbers_masked_except_for_border_officers()
    {
        $m = Manifest::where('manifest_type', 'passenger')->where('entry_count', '>', 0)->first();
        $masked = $this->as('ticket_clerk')->getJson("/api/manifests/{$m->manifest_id}")->json('content.entries.0.id_number');
        $full = $this->as('border_officer')->getJson("/api/manifests/{$m->manifest_id}")->json('content.entries.0.id_number');
        $this->assertStringStartsWith('*', $masked);
        $this->assertStringNotContainsString('*', $full);
        $this->assertSame(substr($full, -4), substr($masked, -4));
    }

    public function test_S19_requester_cannot_approve_own_disclosure()
    {
        $v = $this->openVoyage();
        $admin = User::where('email', 'admin@wtdbms.ng')->first();
        $id = $this->actingAs($admin)->postJson("/api/voyages/{$v->voyage_id}/border-declarations", ['direction' => 'departure', 'recipient_authority' => 'Cameroon Customs', 'recipient_country' => 'CM', 'lawful_basis' => 'Advance information for border control', 'fields' => ['surname']])->json('declaration_id');
        $this->postJson("/api/border-declarations/{$id}/decision", ['decision' => 'approved'])->assertStatus(403);
    }

    public function test_S20_export_refused_before_approval()
    {
        $v = $this->openVoyage();
        $id = $this->as('operator_manager')->postJson("/api/voyages/{$v->voyage_id}/border-declarations", ['direction' => 'departure', 'recipient_authority' => 'Cameroon Customs', 'recipient_country' => 'CM', 'lawful_basis' => 'Advance information for border control', 'fields' => ['surname']])->json('declaration_id');
        $this->as('border_officer')->getJson("/api/border-declarations/{$id}/export")->assertStatus(403);
    }

    public function test_S21_security_headers_are_sent()
    {
        $r = $this->get('/');
        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Content-Security-Policy', 'Referrer-Policy'] as $h) {
            $r->assertHeader($h);
        }
    }

    public function test_S22_session_identifier_regenerated_on_login()
    {
        $this->startSession();
        $before = session()->getId();
        $this->postJson('/api/login', ['email' => 'admin@wtdbms.ng', 'password' => DatabaseSeeder::PASSWORD])->assertOk();
        $this->assertNotSame($before, session()->getId());
    }

    public function test_S23_mass_assignment_of_protected_fields_is_ignored()
    {
        $v = $this->openVoyage(10);
        $r = $this->as('ticket_clerk')->postJson("/api/voyages/{$v->voyage_id}/tickets", $this->pax(['status' => 'boarded', 'fare_paid' => 0, 'issued_by' => 1]))->assertCreated();
        $t = Ticket::where('ticket_ref', $r->json('ticket.ticket_ref'))->first();
        $this->assertSame('issued', $t->status);
        $this->assertEquals($v->fare, $t->fare_paid);
        $this->assertNotSame(1, $t->issued_by);
    }
}
