<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Exposition lecture seule des enrichissements conformité BD :
 * PEP/RCA, KYC, moyennes transactionnelles, mandats, assessments AML (dont CENTIF).
 * Ne modifie aucune donnée. S'appuie uniquement sur tables/vues existantes.
 */
class ClientComplianceController extends Controller
{
    /**
     * GET /clients/{id}/compliance/pep-rca
     * Vue v_client_pep_rca_profile + parties liées.
     */
    public function pepRca(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $profile = DB::table('v_client_pep_rca_profile')
                ->where('client_id', $id)
                ->first();

            $related = DB::table('client_related_parties as p')
                ->leftJoin('clients as rc', 'rc.id', '=', 'p.related_client_id')
                ->leftJoin('client_individuals as ri', 'ri.client_id', '=', 'p.related_client_id')
                ->where('p.client_id', $id)
                ->orderByDesc('p.is_pep_link')
                ->orderBy('p.relation_type')
                ->select([
                    'p.id',
                    'p.related_client_id',
                    'p.related_full_name',
                    'p.related_nina',
                    'p.relation_type',
                    'p.is_pep_link',
                    'p.risk_relevance',
                    'p.status',
                    'p.notes',
                    'p.created_at',
                    'rc.client_number as related_client_number',
                    'rc.is_pep as related_is_pep',
                    'rc.is_rca as related_is_rca',
                    'ri.first_name as related_first_name',
                    'ri.last_name as related_last_name',
                ])
                ->get();

            $clientFlags = DB::table('clients')
                ->where('id', $id)
                ->select([
                    'id',
                    'client_number',
                    'is_pep',
                    'is_rca',
                    'rca_note',
                    'pep_approval_status',
                    'pep_reviewed_at',
                    'kyc_status',
                ])
                ->first();

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'flags' => $clientFlags,
                'profile' => $profile,
                'related_parties' => $related,
                'note' => 'RCA n\'est pas un statut PEP automatique. Surveillance proportionnée uniquement.',
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /clients/{id}/compliance/kyc
     */
    public function kyc(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $completeness = DB::table('v_client_kyc_completeness')
                ->where('client_id', $id)
                ->first();

            $documents = DB::table('identity_documents')
                ->where('client_id', $id)
                ->orderByDesc('is_primary')
                ->orderByDesc('id')
                ->get();

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'completeness' => $completeness,
                'documents' => $documents,
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /clients/{id}/compliance/averages
     * Moyennes 30j client (+ comptes si demandé via ?with_accounts=1)
     */
    public function averages(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $clientAvg = DB::table('v_client_tx_averages_30d')
                ->where('client_id', $id)
                ->first();

            $accountAvgs = null;
            if (request()->boolean('with_accounts')) {
                $accountAvgs = DB::table('v_account_tx_averages_30d')
                    ->where('client_id', $id)
                    ->orderBy('account_id')
                    ->get();
            }

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'window_days' => 30,
                'client' => $clientAvg,
                'accounts' => $accountAvgs,
                'note' => 'Indicateurs comportementaux — pas de seuil fixe type moyenne > 500000. UNUSUAL_VOLUME utilise un multiplicateur vs historique.',
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /clients/{id}/compliance/mandates
     * Mandats sur tous les comptes du client.
     */
    public function mandates(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $mandates = DB::table('account_mandates as m')
                ->join('accounts as a', 'a.id', '=', 'm.account_id')
                ->leftJoin('clients as mc', 'mc.id', '=', 'm.mandate_client_id')
                ->leftJoin('client_individuals as mi', 'mi.client_id', '=', 'm.mandate_client_id')
                ->where('a.client_id', $id)
                ->orderByDesc('m.status')
                ->orderByDesc('m.id')
                ->select([
                    'm.id',
                    'm.account_id',
                    'a.account_number',
                    'm.mandate_client_id',
                    'm.full_name',
                    'm.identity_number',
                    'm.phone',
                    'm.mandate_role',
                    'm.powers',
                    'm.status',
                    'm.valid_from',
                    'm.valid_to',
                    'm.created_at',
                    'mc.client_number as mandate_client_number',
                    'mi.first_name as mandate_first_name',
                    'mi.last_name as mandate_last_name',
                    DB::raw(
                        "CASE
                            WHEN m.status = 'ACTIVE'
                             AND (m.valid_from IS NULL OR m.valid_from <= CURDATE())
                             AND (m.valid_to IS NULL OR m.valid_to >= CURDATE())
                            THEN 1 ELSE 0
                         END AS is_currently_active"
                    ),
                ])
                ->get();

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'mandates' => $mandates,
                'active_count' => $mandates->where('is_currently_active', 1)->count(),
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /clients/{id}/compliance/assessments
     * risk_assessments AML (CENTIF, LARGE_AMOUNT, etc.) — couche déterministe.
     */
    public function assessments(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $query = DB::table('risk_assessments')
                ->where('client_id', $id)
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if ($type = request()->query('risk_type')) {
                $query->where('risk_type', $type);
            }

            if ($source = request()->query('source')) {
                $query->where('source', $source);
            }

            $limit = min((int) request()->query('limit', 50), 200);
            $items = $query->limit($limit)->get();

            $centif = $items->where('risk_type', 'CENTIF_DAILY_15M')->values();

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'assessments' => $items,
                'centif_hits' => $centif,
                'layer' => 'AML_RULE_ENGINE',
                'note' => 'Scores déterministes (règles CIF). Distinct des scores ML/predictions.',
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /clients/{id}/compliance/network-links
     * Liens contrepartie récurrents (vue seuilée).
     */
    public function networkLinks(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $asSource = DB::table('v_client_counterparty_links')
                ->where('source_client_id', $id)
                ->orderByDesc('total_amount')
                ->get();

            $asTarget = DB::table('v_client_counterparty_links')
                ->where('target_client_id', $id)
                ->orderByDesc('total_amount')
                ->get();

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'outbound_links' => $asSource,
                'inbound_links' => $asTarget,
                'note' => 'Vue filtrée (seuils anti faux-positifs). Pas d\'alerte automatique ici.',
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /clients/{id}/compliance/summary
     * Agrégat léger pour Client 360 / Assist.
     */
    public function summary(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $pepRca = DB::table('v_client_pep_rca_profile')->where('client_id', $id)->first();
            $kyc = DB::table('v_client_kyc_completeness')->where('client_id', $id)->first();
            $avg = DB::table('v_client_tx_averages_30d')->where('client_id', $id)->first();

            $activeMandates = DB::table('account_mandates as m')
                ->join('accounts as a', 'a.id', '=', 'm.account_id')
                ->where('a.client_id', $id)
                ->where('m.status', 'ACTIVE')
                ->where(function ($q) {
                    $q->whereNull('m.valid_from')->orWhere('m.valid_from', '<=', DB::raw('CURDATE()'));
                })
                ->where(function ($q) {
                    $q->whereNull('m.valid_to')->orWhere('m.valid_to', '>=', DB::raw('CURDATE()'));
                })
                ->count();

            $centifCount = DB::table('risk_assessments')
                ->where('client_id', $id)
                ->where('risk_type', 'CENTIF_DAILY_15M')
                ->count();

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'pep_rca' => $pepRca,
                'kyc' => $kyc,
                'averages_30d' => $avg,
                'active_mandates_count' => $activeMandates,
                'centif_assessment_count' => $centifCount,
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /clients/{id}/compliance/dual-risk
     * Scores AML déterministes et ML séparés — jamais fusionnés côté API.
     */
    public function dualRisk(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->clientExists($request, $id)) {
                return $this->notFound($id);
            }

            $row = DB::table('v_client_dual_risk_profile')
                ->where('client_id', $id)
                ->first();

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil dual introuvable (vue absente ou client sans ligne).',
                    'client_id' => $id,
                    'hint' => 'Vérifier que MIGRATION_07 a créé v_client_dual_risk_profile.',
                ], 404);
            }

            $amlRules = [];
            if (!empty($row->aml_rule_types_30d)) {
                $amlRules = array_values(array_filter(
                    array_map('trim', explode(',', $row->aml_rule_types_30d))
                ));
            }

            return response()->json([
                'success' => true,
                'client_id' => $id,
                'client_number' => $row->client_number,
                'flags' => [
                    'is_pep' => (int) $row->is_pep,
                    'is_rca' => (int) $row->is_rca,
                    'kyc_status' => $row->kyc_status,
                ],
                'aml' => [
                    'layer' => 'AML_RULE_ENGINE',
                    'score' => $row->aml_risk_score !== null
                        ? (float) $row->aml_risk_score
                        : null,
                    'level_code' => $row->aml_risk_level_code,
                    'level_label' => $row->aml_risk_level_label,
                    'assessment_count' => (int) $row->aml_assessment_count,
                    'centif_hit_count' => (int) $row->centif_hit_count,
                    'rule_types_30d' => $amlRules,
                ],
                'ml' => [
                    'layer' => 'ML',
                    'ai_score' => $row->ml_ai_score !== null
                        ? (float) $row->ml_ai_score
                        : null,
                    'pep_score' => $row->ml_pep_score !== null
                        ? (float) $row->ml_pep_score
                        : null,
                    'transaction_score' => $row->ml_transaction_score !== null
                        ? (float) $row->ml_transaction_score
                        : null,
                    'final_score' => $row->ml_final_score !== null
                        ? (float) $row->ml_final_score
                        : null,
                    'risk_decision' => $row->ml_risk_decision,
                    'latest_probability' => $row->ml_latest_probability !== null
                        ? (float) $row->ml_latest_probability
                        : null,
                    'latest_predicted_risk' => $row->ml_latest_predicted_risk,
                ],
                'note' => 'AML (règles CIF/CENTIF) et ML sont volontairement séparés. Ne pas fusionner à l\'affichage.',
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }


    /* ------------------------------------------------------------------ */

    private function clientExists(Request $request, int $id): bool
    {
        return AgencyAccess::canAccessClient($request, $id);
    }

    private function notFound(int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Client introuvable.',
            'client_id' => $id,
        ], 404);
    }

    private function error(Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Erreur conformité client.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
