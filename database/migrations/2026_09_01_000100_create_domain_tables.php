<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain schema of the Secure Web-Based Water Transport DBMS (Oron–Idenua).
 * Normalised to 3NF; surrogate keys; natural keys carried as unique constraints;
 * restrictive deletes so no record referenced by an issued manifest can be orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_operator', function (Blueprint $t) {
            $t->id('operator_id');
            $t->string('operator_name', 150);
            $t->string('licence_no', 40)->unique();
            $t->date('licence_expiry');
            $t->string('phone', 30)->nullable();
            $t->string('address', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('tbl_vessel', function (Blueprint $t) {
            $t->id('vessel_id');
            $t->foreignId('operator_id')->constrained('tbl_operator', 'operator_id')->restrictOnDelete();
            $t->string('vessel_name', 100);
            $t->string('registration_no', 40)->unique();
            $t->enum('vessel_type', ['speedboat', 'passenger_ferry', 'passenger_cargo', 'cargo_boat']);
            $t->string('hull_material', 40)->nullable();
            $t->string('engine_details', 120)->nullable();
            $t->unsignedSmallInteger('passenger_capacity');
            $t->decimal('cargo_capacity_kg', 10, 2);
            $t->enum('status', ['active', 'maintenance', 'suspended', 'decommissioned'])->default('active');
            $t->timestamps();
        });

        Schema::create('tbl_crew', function (Blueprint $t) {
            $t->id('crew_id');
            $t->string('full_name', 120);
            $t->enum('rank', ['master', 'mate', 'engineer', 'deckhand', 'steward']);
            $t->string('competency_cert_no', 40)->unique();
            $t->date('cert_expiry');
            $t->text('phone')->nullable(); // encrypted at rest
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->timestamps();
        });

        Schema::create('tbl_certificate', function (Blueprint $t) {
            $t->id('certificate_id');
            $t->foreignId('vessel_id')->nullable()->constrained('tbl_vessel', 'vessel_id')->restrictOnDelete();
            $t->foreignId('crew_id')->nullable()->constrained('tbl_crew', 'crew_id')->restrictOnDelete();
            $t->enum('cert_type', ['registration', 'survey', 'safety_equipment', 'insurance', 'competency', 'operating_licence']);
            $t->string('cert_no', 50);
            $t->string('issuing_body', 100);
            $t->date('issue_date');
            $t->date('expiry_date');
            $t->timestamps();
            $t->index('expiry_date');
        });

        Schema::create('tbl_maintenance', function (Blueprint $t) {
            $t->id('maintenance_id');
            $t->foreignId('vessel_id')->constrained('tbl_vessel', 'vessel_id')->restrictOnDelete();
            $t->date('maintenance_date');
            $t->enum('maintenance_type', ['routine', 'repair', 'defect', 'survey_prep']);
            $t->string('description', 500);
            $t->decimal('cost', 12, 2)->default(0);
            $t->date('next_due')->nullable();
            $t->timestamps();
        });

        Schema::create('tbl_jetty', function (Blueprint $t) {
            $t->id('jetty_id');
            $t->string('jetty_name', 100);
            $t->string('town', 80);
            $t->char('country_code', 2);
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->timestamps();
        });

        Schema::create('tbl_route', function (Blueprint $t) {
            $t->id('route_id');
            $t->string('route_code', 20)->unique();
            $t->foreignId('origin_jetty_id')->constrained('tbl_jetty', 'jetty_id')->restrictOnDelete();
            $t->foreignId('destination_jetty_id')->constrained('tbl_jetty', 'jetty_id')->restrictOnDelete();
            $t->boolean('is_international')->default(false);
            $t->unsignedSmallInteger('est_duration_min');
            $t->timestamps();
        });

        Schema::create('tbl_voyage', function (Blueprint $t) {
            $t->id('voyage_id');
            $t->string('voyage_ref', 20)->unique();
            $t->foreignId('vessel_id')->constrained('tbl_vessel', 'vessel_id')->restrictOnDelete();
            $t->foreignId('route_id')->constrained('tbl_route', 'route_id')->restrictOnDelete();
            $t->enum('voyage_type', ['scheduled', 'unscheduled', 'charter']);
            $t->dateTime('scheduled_departure');
            $t->dateTime('scheduled_arrival');
            $t->dateTime('actual_departure')->nullable();
            $t->dateTime('actual_arrival')->nullable();
            $t->unsignedSmallInteger('passenger_capacity');
            $t->decimal('cargo_capacity_kg', 10, 2);
            $t->decimal('fare', 10, 2);
            $t->enum('status', ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled', 'incident'])->default('scheduled');
            $t->foreignId('created_by')->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->timestamps();
            $t->index('scheduled_departure');
        });

        Schema::create('tbl_voyage_crew', function (Blueprint $t) {
            $t->id('voyage_crew_id');
            $t->foreignId('voyage_id')->constrained('tbl_voyage', 'voyage_id')->restrictOnDelete();
            $t->foreignId('crew_id')->constrained('tbl_crew', 'crew_id')->restrictOnDelete();
            $t->enum('duty_role', ['master', 'mate', 'engineer', 'deckhand', 'steward']);
            $t->timestamps();
            $t->unique(['voyage_id', 'crew_id']);
        });

        Schema::create('tbl_passenger', function (Blueprint $t) {
            $t->id('passenger_id');
            $t->string('surname', 60);
            $t->string('other_names', 100);
            $t->enum('sex', ['M', 'F']);
            $t->date('date_of_birth');
            $t->string('nationality', 2);
            $t->enum('id_type', ['passport', 'nin', 'ecowas_card', 'national_id', 'voters_card']);
            $t->text('id_number');           // encrypted at rest
            $t->char('id_number_hash', 64);  // keyed hash for duplicate detection without decryption
            $t->text('phone');               // encrypted at rest
            $t->string('next_of_kin_name', 120);
            $t->text('next_of_kin_phone');   // encrypted at rest
            $t->timestamps();
            $t->index('surname');
            $t->index('id_number_hash');
        });

        Schema::create('tbl_ticket', function (Blueprint $t) {
            $t->id('ticket_id');
            $t->string('ticket_ref', 24)->unique();
            $t->foreignId('voyage_id')->constrained('tbl_voyage', 'voyage_id')->restrictOnDelete();
            $t->foreignId('passenger_id')->constrained('tbl_passenger', 'passenger_id')->restrictOnDelete();
            $t->string('seat_no', 6)->nullable();
            $t->decimal('fare_paid', 10, 2);
            $t->enum('status', ['issued', 'boarded', 'cancelled', 'no_show'])->default('issued');
            $t->dateTime('boarded_at')->nullable();
            $t->foreignId('issued_by')->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['voyage_id', 'passenger_id']);
        });

        Schema::create('tbl_shipper', function (Blueprint $t) {
            $t->id('shipper_id');
            $t->string('full_name', 150);
            $t->enum('party_type', ['individual', 'company']);
            $t->string('country_code', 2);
            $t->text('phone');          // encrypted at rest
            $t->string('id_reference', 60)->nullable();
            $t->timestamps();
        });

        Schema::create('tbl_dangerous_goods_class', function (Blueprint $t) {
            $t->id('dg_class_id');
            $t->string('class_code', 6)->unique();
            $t->string('description', 120);
            $t->string('stowage_restriction', 255);
            $t->boolean('passenger_vessel_permitted')->default(true);
            $t->decimal('max_qty_kg_per_voyage', 10, 2)->nullable();
        });

        Schema::create('tbl_consignment', function (Blueprint $t) {
            $t->id('consignment_id');
            $t->string('consignment_ref', 24)->unique();
            $t->foreignId('voyage_id')->constrained('tbl_voyage', 'voyage_id')->restrictOnDelete();
            $t->foreignId('shipper_id')->constrained('tbl_shipper', 'shipper_id')->restrictOnDelete();
            $t->foreignId('consignee_id')->constrained('tbl_shipper', 'shipper_id')->restrictOnDelete();
            $t->string('description', 255);
            $t->string('hs_category', 60);
            $t->string('marks_numbers', 80)->nullable();
            $t->unsignedInteger('package_count');
            $t->decimal('weight_kg', 10, 2);
            $t->decimal('volume_m3', 8, 3)->nullable();
            $t->foreignId('dg_class_id')->nullable()->constrained('tbl_dangerous_goods_class', 'dg_class_id')->restrictOnDelete();
            $t->decimal('declared_value', 12, 2)->default(0);
            $t->decimal('freight_charge', 10, 2)->default(0);
            $t->enum('status', ['accepted', 'loaded', 'discharged', 'delivered', 'cancelled'])->default('accepted');
            $t->foreignId('accepted_by')->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('tbl_manifest', function (Blueprint $t) {
            $t->id('manifest_id');
            $t->string('manifest_ref', 30)->unique();
            $t->foreignId('voyage_id')->constrained('tbl_voyage', 'voyage_id')->restrictOnDelete();
            $t->enum('manifest_type', ['passenger', 'cargo']);
            $t->unsignedSmallInteger('issue_no');
            $t->unsignedInteger('entry_count');
            $t->decimal('total_weight_kg', 12, 2)->nullable();
            $t->longText('content_json');
            $t->char('content_hash', 64);
            $t->string('verification_code', 16);
            $t->boolean('is_final')->default(false);
            $t->foreignId('issued_by')->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->timestamp('issued_at')->useCurrent();
            $t->unique(['voyage_id', 'manifest_type', 'issue_no']);
        });

        Schema::create('tbl_border_declaration', function (Blueprint $t) {
            $t->id('declaration_id');
            $t->string('declaration_ref', 30)->unique();
            $t->foreignId('voyage_id')->constrained('tbl_voyage', 'voyage_id')->restrictOnDelete();
            $t->enum('direction', ['departure', 'arrival']);
            $t->string('recipient_authority', 150);
            $t->char('recipient_country', 2);
            $t->string('lawful_basis', 255);
            $t->json('fields_disclosed');
            $t->enum('status', ['requested', 'approved', 'rejected', 'transmitted'])->default('requested');
            $t->foreignId('requested_by')->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->char('export_hash', 64)->nullable();
            $t->timestamp('transmitted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('tbl_incident', function (Blueprint $t) {
            $t->id('incident_id');
            $t->string('incident_ref', 24)->unique();
            $t->foreignId('voyage_id')->constrained('tbl_voyage', 'voyage_id')->restrictOnDelete();
            $t->enum('incident_type', ['capsize', 'collision', 'engine_failure', 'piracy_abduction', 'fire', 'man_overboard', 'weather', 'other']);
            $t->enum('severity', ['low', 'medium', 'high', 'critical']);
            $t->text('description');
            $t->decimal('last_latitude', 9, 6)->nullable();
            $t->decimal('last_longitude', 9, 6)->nullable();
            $t->text('response_actions')->nullable();
            $t->enum('status', ['open', 'responding', 'closed'])->default('open');
            $t->foreignId('reported_by')->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('tbl_audit_log', function (Blueprint $t) {
            $t->id('audit_id');
            $t->foreignId('user_id')->nullable()->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->string('event', 40);
            $t->string('entity', 40)->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->string('outcome', 12);
            $t->string('ip_address', 45)->nullable();
            $t->string('detail', 500)->nullable();
            $t->char('prev_hash', 64);
            $t->char('row_hash', 64);
            $t->timestamp('created_at', 6)->useCurrent();
            $t->index(['event', 'created_at']);
        });

        Schema::create('tbl_notification', function (Blueprint $t) {
            $t->id('notification_id');
            $t->foreignId('user_id')->nullable()->constrained('tbl_user', 'user_id')->restrictOnDelete();
            $t->enum('category', ['certificate_expiry', 'capacity_breach_attempt', 'incident', 'disclosure', 'security']);
            $t->string('message', 500);
            $t->boolean('is_read')->default(false);
            $t->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                "CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON tbl_audit_log BEGIN SELECT RAISE(ABORT, 'tbl_audit_log is append-only'); END",
                "CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON tbl_audit_log BEGIN SELECT RAISE(ABORT, 'tbl_audit_log is append-only'); END",
                "CREATE TRIGGER trg_manifest_no_update BEFORE UPDATE ON tbl_manifest BEGIN SELECT RAISE(ABORT, 'issued manifests are immutable'); END",
                "CREATE TRIGGER chk_voyage_times_insert BEFORE INSERT ON tbl_voyage WHEN NEW.scheduled_arrival <= NEW.scheduled_departure BEGIN SELECT RAISE(ABORT, 'scheduled arrival must follow departure'); END",
                "CREATE TRIGGER chk_voyage_times_update BEFORE UPDATE ON tbl_voyage WHEN NEW.scheduled_arrival <= NEW.scheduled_departure BEGIN SELECT RAISE(ABORT, 'scheduled arrival must follow departure'); END",
                "CREATE TRIGGER chk_voyage_cap_insert BEFORE INSERT ON tbl_voyage WHEN NEW.passenger_capacity <= 0 OR NEW.cargo_capacity_kg <= 0 OR NEW.fare < 0 BEGIN SELECT RAISE(ABORT, 'voyage capacities must be positive and fare non-negative'); END",
                "CREATE TRIGGER chk_voyage_cap_update BEFORE UPDATE ON tbl_voyage WHEN NEW.passenger_capacity <= 0 OR NEW.cargo_capacity_kg <= 0 OR NEW.fare < 0 BEGIN SELECT RAISE(ABORT, 'voyage capacities must be positive and fare non-negative'); END",
                "CREATE TRIGGER chk_cons_weight_insert BEFORE INSERT ON tbl_consignment WHEN NEW.weight_kg <= 0 OR NEW.package_count <= 0 BEGIN SELECT RAISE(ABORT, 'consignment weight and package count must be positive'); END",
                "CREATE TRIGGER chk_cons_weight_update BEFORE UPDATE ON tbl_consignment WHEN NEW.weight_kg <= 0 OR NEW.package_count <= 0 BEGIN SELECT RAISE(ABORT, 'consignment weight and package count must be positive'); END",
            ] as $trigger) {
                DB::unprepared($trigger);
            }
        } else {
            // Append-only audit trail: UPDATE and DELETE are refused at database level.
            DB::unprepared("CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON tbl_audit_log FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tbl_audit_log is append-only'");
            DB::unprepared("CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON tbl_audit_log FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tbl_audit_log is append-only'");
            // Issued manifests are immutable.
            DB::unprepared("CREATE TRIGGER trg_manifest_no_update BEFORE UPDATE ON tbl_manifest FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'issued manifests are immutable'");
            // Domain constraint: arrival after departure; capacities positive.
            DB::statement('ALTER TABLE tbl_voyage ADD CONSTRAINT chk_voyage_times CHECK (scheduled_arrival > scheduled_departure)');
            DB::statement('ALTER TABLE tbl_voyage ADD CONSTRAINT chk_voyage_cap CHECK (passenger_capacity > 0 AND cargo_capacity_kg > 0 AND fare >= 0)');
            DB::statement('ALTER TABLE tbl_consignment ADD CONSTRAINT chk_cons_weight CHECK (weight_kg > 0 AND package_count > 0)');
        }

        // Restricted, identifier-free analytical view.
        DB::statement('DROP VIEW IF EXISTS vw_voyage_utilisation');
        DB::statement("CREATE VIEW vw_voyage_utilisation AS
            SELECT v.voyage_id, v.voyage_ref, v.scheduled_departure, v.status, ve.vessel_name, r.route_code,
                   v.passenger_capacity,
                   (SELECT COUNT(*) FROM tbl_ticket t WHERE t.voyage_id = v.voyage_id AND t.status IN ('issued','boarded')) AS passengers,
                   v.cargo_capacity_kg,
                   (SELECT COALESCE(SUM(c.weight_kg),0) FROM tbl_consignment c WHERE c.voyage_id = v.voyage_id AND c.status <> 'cancelled') AS cargo_kg
            FROM tbl_voyage v JOIN tbl_vessel ve ON ve.vessel_id = v.vessel_id JOIN tbl_route r ON r.route_id = v.route_id");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS vw_voyage_utilisation');
        foreach (['tbl_notification', 'tbl_audit_log', 'tbl_incident', 'tbl_border_declaration', 'tbl_manifest',
                  'tbl_consignment', 'tbl_dangerous_goods_class', 'tbl_shipper', 'tbl_ticket', 'tbl_passenger',
                  'tbl_voyage_crew', 'tbl_voyage', 'tbl_route', 'tbl_jetty', 'tbl_maintenance', 'tbl_certificate',
                  'tbl_crew', 'tbl_vessel', 'tbl_operator'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
