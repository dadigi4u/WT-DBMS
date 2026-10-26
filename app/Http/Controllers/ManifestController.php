<?php

namespace App\Http\Controllers;

use App\Models\Manifest;
use App\Models\Voyage;
use App\Services\AuditService;
use App\Services\ManifestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManifestController extends Controller
{
    public function generate(Request $request, Voyage $voyage, ManifestService $svc)
    {
        $d = $request->validate(['type' => ['required', Rule::in(['passenger', 'cargo'])]]);
        $start = hrtime(true);
        $m = $svc->issue($voyage, $d['type'], $request->user()->user_id, false);
        $ms = round((hrtime(true) - $start) / 1e6, 2);
        return response()->json($this->present($m, $request) + ['generation_ms' => $ms], 201);
    }

    public function index(Voyage $voyage)
    {
        return Manifest::where('voyage_id', $voyage->voyage_id)->with('issuer:user_id,name')->orderByDesc('manifest_id')
            ->get(['manifest_id', 'manifest_ref', 'manifest_type', 'issue_no', 'entry_count', 'total_weight_kg', 'verification_code', 'is_final', 'issued_by', 'issued_at']);
    }

    public function show(Request $request, Manifest $manifest)
    {
        AuditService::log('manifest_viewed', 'success', 'tbl_manifest', $manifest->manifest_id);
        return $this->present($manifest, $request);
    }

    public function verify(Request $request, ManifestService $svc)
    {
        $d = $request->validate(['manifest_ref' => ['required', 'regex:/^(PM|CM)-[A-Z0-9\-]{4,26}$/'], 'code' => ['required', 'regex:/^[A-Fa-f0-9]{12}$/']]);
        $r = $svc->verify($d['manifest_ref'], $d['code']);
        AuditService::log('manifest_verified', $r['valid'] ? 'success' : 'failed', 'tbl_manifest', null, $d['manifest_ref']);
        return $r;
    }

    /** Full travel-document numbers are shown only to roles holding border.view; others see masked values. */
    private function present(Manifest $m, Request $request): array
    {
        $content = json_decode($m->content_json, true);
        if ($m->manifest_type === 'passenger' && ! $request->user()->can_('border.view')) {
            foreach ($content['entries'] as &$e) {
                $e['id_number'] = str_repeat('*', max(0, strlen($e['id_number']) - 4)).substr($e['id_number'], -4);
                $e['next_of_kin_phone'] = substr($e['next_of_kin_phone'], 0, 4).'*****'.substr($e['next_of_kin_phone'], -2);
            }
        }
        return [
            'manifest_ref' => $m->manifest_ref, 'manifest_type' => $m->manifest_type, 'issue_no' => $m->issue_no,
            'is_final' => $m->is_final, 'issued_at' => $m->issued_at->toDateTimeString(), 'issued_by' => $m->issuer->name,
            'content_hash' => $m->content_hash, 'verification_code' => $m->verification_code, 'content' => $content,
        ];
    }
}
