<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\Voyage;
use App\Services\AuditService;
use App\Services\CapacityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public static function passengerRules(): array
    {
        return [
            'surname' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\'\-\s]+$/u'],
            'other_names' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\pL\'\-\s\.]+$/u'],
            'sex' => ['required', Rule::in(['M', 'F'])],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'nationality' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'id_type' => ['required', Rule::in(['passport', 'nin', 'ecowas_card', 'national_id', 'voters_card'])],
            'id_number' => ['required', 'string', 'regex:/^[A-Z0-9]{6,20}$/'],
            'phone' => ['required', 'regex:/^\+?[0-9]{7,15}$/'],
            'next_of_kin_name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[\pL\'\-\s\.]+$/u'],
            'next_of_kin_phone' => ['required', 'regex:/^\+?[0-9]{7,15}$/', 'different:phone'],
        ];
    }

    public function store(Request $request, Voyage $voyage, CapacityService $capacity)
    {
        $request->merge([
            'nationality' => strtoupper((string) $request->input('nationality')),
            'id_number' => strtoupper(preg_replace('/\s+/', '', (string) $request->input('id_number'))),
        ]);
        $d = $request->validate(self::passengerRules());
        if ($voyage->route->is_international && ! in_array($d['id_type'], ['passport', 'ecowas_card'])) {
            return response()->json(['message' => 'International crossings require a passport or ECOWAS travel certificate.', 'errors' => ['id_type' => ['Passport or ECOWAS card required for this route.']]], 422);
        }
        $t = $capacity->issueTicket($voyage->voyage_id, $d, $request->user()->user_id);
        AuditService::log('ticket_issued', 'success', 'tbl_ticket', $t->ticket_id, $t->ticket_ref);
        $voyage->refresh();
        return response()->json([
            'ticket' => $t->load('passenger:passenger_id,surname,other_names'),
            'passengers' => $voyage->activeTicketCount(), 'capacity' => $voyage->passenger_capacity,
        ], 201);
    }

    public function index(Voyage $voyage)
    {
        return $voyage->tickets()->with('passenger')->orderBy('ticket_id')->get()->map(fn ($t) => [
            'ticket_id' => $t->ticket_id, 'ticket_ref' => $t->ticket_ref, 'seat_no' => $t->seat_no, 'status' => $t->status,
            'name' => mb_strtoupper($t->passenger->surname).', '.$t->passenger->other_names,
            'sex' => $t->passenger->sex, 'nationality' => $t->passenger->nationality,
            'id' => $t->passenger->id_type.' '.$t->passenger->maskedId(),
        ]);
    }

    public function board(Request $request, Ticket $ticket)
    {
        if ($ticket->voyage->isClosed() || $ticket->status !== 'issued') {
            return response()->json(['message' => 'Ticket cannot be boarded in its current state.'], 409);
        }
        $ticket->update(['status' => 'boarded', 'boarded_at' => now()]);
        AuditService::log('passenger_boarded', 'success', 'tbl_ticket', $ticket->ticket_id);
        return $ticket;
    }
}
