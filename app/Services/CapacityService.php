<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Consignment;
use App\Models\DangerousGoodsClass;
use App\Models\Notification;
use App\Models\Passenger;
use App\Models\Ticket;
use App\Models\Voyage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The principal safety control. Every ticket and consignment is admitted inside a
 * transaction that locks the voyage row, so concurrent clerks cannot jointly exceed
 * certified passenger or cargo capacity.
 */
class CapacityService
{
    private function guardVoyage(Voyage $v): void
    {
        if ($v->isClosed()) {
            throw new BusinessRuleException('voyage_closed', "Voyage {$v->voyage_ref} is {$v->status}; its manifests are closed.");
        }
        $lapsed = $v->vessel->lapsedCertificates();
        if ($lapsed->isNotEmpty()) {
            throw new BusinessRuleException('certificate_lapsed', 'Vessel certificate(s) missing or lapsed: '.$lapsed->pluck('cert_type')->implode(', '));
        }
    }

    public function issueTicket(int $voyageId, array $p, int $userId): Ticket
    {
        try {
            return DB::transaction(function () use ($voyageId, $p, $userId) {
                $v = Voyage::with('vessel')->whereKey($voyageId)->lockForUpdate()->firstOrFail();
                $this->guardVoyage($v);
                $count = $v->activeTicketCount();
                if ($count + 1 > $v->passenger_capacity) {
                    throw new BusinessRuleException('passenger_capacity', "Certified passenger capacity reached ({$count}/{$v->passenger_capacity}). Ticket refused.");
                }
                $hash = Passenger::idHash($p['id_type'], $p['id_number']);
                $passenger = Passenger::where('id_number_hash', $hash)->first();
                if (! $passenger) {
                    $passenger = Passenger::create(array_merge(
                        collect($p)->only(['surname', 'other_names', 'sex', 'date_of_birth', 'nationality', 'id_type', 'id_number', 'phone', 'next_of_kin_name', 'next_of_kin_phone'])->all(),
                        ['id_number_hash' => $hash]
                    ));
                } else {
                    $passenger->fill(collect($p)->only(['phone', 'next_of_kin_name', 'next_of_kin_phone'])->all())->save();
                }
                if (Ticket::where('voyage_id', $v->voyage_id)->where('passenger_id', $passenger->passenger_id)->whereIn('status', ['issued', 'boarded'])->exists()) {
                    throw new BusinessRuleException('duplicate_ticket', 'This passenger already holds a ticket for this voyage.', 422);
                }
                return Ticket::create([
                    'ticket_ref' => 'TK'.now()->format('ymd').strtoupper(Str::random(8)),
                    'voyage_id' => $v->voyage_id, 'passenger_id' => $passenger->passenger_id,
                    'seat_no' => (string) ($count + 1), 'fare_paid' => $v->fare,
                    'status' => 'issued', 'issued_by' => $userId,
                ]);
            }, 3);
        } catch (BusinessRuleException $e) {
            $this->recordRefusal($e, 'tbl_voyage', $voyageId, $userId);
            throw $e;
        }
    }

    public function acceptConsignment(int $voyageId, array $c, int $userId): Consignment
    {
        try {
            return DB::transaction(function () use ($voyageId, $c, $userId) {
                $v = Voyage::with('vessel')->whereKey($voyageId)->lockForUpdate()->firstOrFail();
                $this->guardVoyage($v);
                $load = $v->cargoLoadKg();
                if ($load + $c['weight_kg'] > $v->cargo_capacity_kg) {
                    throw new BusinessRuleException('cargo_capacity', sprintf('Certified cargo capacity exceeded: %.2f kg loaded + %.2f kg tendered > %.2f kg limit. Consignment refused.', $load, $c['weight_kg'], $v->cargo_capacity_kg));
                }
                if (! empty($c['dg_class_id'])) {
                    $dg = DangerousGoodsClass::findOrFail($c['dg_class_id']);
                    if (! $dg->passenger_vessel_permitted && $v->passenger_capacity > 0) {
                        throw new BusinessRuleException('dg_prohibited', "Dangerous goods class {$dg->class_code} ({$dg->description}) may not be carried on a passenger-carrying vessel.");
                    }
                    if ($dg->max_qty_kg_per_voyage !== null) {
                        $dgLoad = (float) $v->consignments()->where('dg_class_id', $dg->dg_class_id)->where('status', '<>', 'cancelled')->sum('weight_kg');
                        if ($dgLoad + $c['weight_kg'] > $dg->max_qty_kg_per_voyage) {
                            throw new BusinessRuleException('dg_quantity', "Class {$dg->class_code} limit of {$dg->max_qty_kg_per_voyage} kg per voyage would be exceeded.");
                        }
                    }
                }
                return Consignment::create(array_merge($c, [
                    'consignment_ref' => 'CN'.now()->format('ymd').strtoupper(Str::random(8)),
                    'voyage_id' => $v->voyage_id, 'status' => 'accepted', 'accepted_by' => $userId,
                ]));
            }, 3);
        } catch (BusinessRuleException $e) {
            $this->recordRefusal($e, 'tbl_voyage', $voyageId, $userId);
            throw $e;
        }
    }

    private function recordRefusal(BusinessRuleException $e, string $entity, int $id, int $userId): void
    {
        AuditService::log('rule_refusal', 'refused', $entity, $id, $e->rule.': '.$e->getMessage(), $userId);
        if (in_array($e->rule, ['passenger_capacity', 'cargo_capacity', 'dg_prohibited', 'dg_quantity'])) {
            Notification::create(['user_id' => null, 'category' => 'capacity_breach_attempt', 'message' => $e->getMessage()]);
        }
    }
}
