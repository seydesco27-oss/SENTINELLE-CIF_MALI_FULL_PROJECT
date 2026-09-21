<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copilote de conformité — contexte dossier + réponses structurées (templates).
 * Sources : v_assist_alert_context, v_assist_client_context (voir SENTINELLE_VUES_ASSIST_ML.sql)
 * Ne prend aucune décision réglementaire.
 */
class MlAssistController extends \App\Http\Controllers\Controller
{
    /**
     * GET /api/ml/assist/alert/{id}
     * Contexte brut d'une alerte pour le panneau Assist.
     */
    public function alertContext(int $id): JsonResponse
    {
        try {
            $row = DB::table('v_assist_alert_context')
                ->where('alert_id', $id)
                ->first();

            if (!$row) {
                // Fallback si la vue n'est pas encore créée
                $row = $this->fallbackAlertContext($id);
            }

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $row,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de charger le contexte alerte.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/ml/assist/client/{id}
     */
    public function clientContext(int $id): JsonResponse
    {
        try {
            $row = DB::table('v_assist_client_context')
                ->where('client_id', $id)
                ->first();

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable ou vue absente.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $row,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de charger le contexte client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/ml/assist
     * Body: { object_type: alert|client, object_id, action?: summarize|explain|suggest_questions|draft_centif }
     * Réponse template structurée — pas de LLM requis.
     */
    public function assist(Request $request): JsonResponse
    {
        try {
            $type = strtolower((string) $request->input('object_type', 'alert'));
            $id = (int) $request->input('object_id');
            $action = strtolower((string) $request->input('action', 'summarize'));

            if ($id <= 0 || !in_array($type, ['alert', 'client'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'object_type (alert|client) et object_id requis.',
                ], 422);
            }

            if ($type === 'alert') {
                $ctx = DB::table('v_assist_alert_context')->where('alert_id', $id)->first();
                if (!$ctx) {
                    $ctx = $this->fallbackAlertContext($id);
                }
                if (!$ctx) {
                    return response()->json(['success' => false, 'message' => 'Alerte introuvable.'], 404);
                }
                $payload = $this->buildAlertAssist($ctx, $action);
            } else {
                $ctx = DB::table('v_assist_client_context')->where('client_id', $id)->first();
                if (!$ctx) {
                    return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
                }
                $payload = $this->buildClientAssist($ctx, $action);
            }

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Assist indisponible.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildAlertAssist(object $c, string $action): array
    {
        $name = $c->customer_name ?? $c->client_number ?? 'Client';
        $ref = $c->alert_reference ?? ('ALT-' . ($c->alert_id ?? '?'));
        $rules = $c->matched_rule_codes ?: 'aucune règle MATCH listée';
        $amount = $c->transaction_amount !== null
            ? number_format((float) $c->transaction_amount, 0, ',', ' ') . ' ' . ($c->currency ?? 'XOF')
            : 'n/d';
        $pep = !empty($c->has_pep_match) || !empty($c->is_pep) ? 'oui' : 'non';
        $san = !empty($c->has_sanction_match) ? 'oui' : 'non';
        $score = $c->alert_final_score ?? $c->max_risk_assessment_score ?? 'n/d';
        $level = $c->client_risk_level ?? $c->priority ?? 'n/d';

        $summary = sprintf(
            "Alerte %s — %s.\nClient : %s (risque client : %s, score alerte : %s).\nTransaction : %s · %s · type %s.\nRègles déclenchées : %s.\nIndicateurs PEP : %s · sanctions : %s.\nStatut alerte : %s · priorité : %s.",
            $ref,
            $c->alert_title ?? ($c->alert_type ?? 'signal de vigilance'),
            $name,
            $level,
            $score,
            $c->transaction_reference ?? 'n/d',
            $amount,
            $c->transaction_type ?? 'n/d',
            $rules,
            $pep,
            $san,
            $c->alert_status ?? 'n/d',
            $c->priority ?? 'n/d'
        );

        $factors = array_values(array_filter([
            $c->matched_rule_codes ? ['label' => 'Règles AML', 'value' => $c->matched_rule_codes] : null,
            $c->transaction_amount !== null ? ['label' => 'Montant', 'value' => $amount] : null,
            ['label' => 'Score alerte', 'value' => (string) $score],
            ['label' => 'PEP', 'value' => $pep],
            ['label' => 'Sanctions', 'value' => $san],
            $c->channel ? ['label' => 'Canal', 'value' => $c->channel] : null,
        ]));

        $questions = [
            'L’origine des fonds et l’objet économique de l’opération sont-ils documentés et cohérents avec le profil client ?',
            'Existe-t-il d’autres opérations récentes similaires (fractionnement, même contrepartie, même canal) ?',
            'Le statut PEP / sanctions et le niveau de risque client justifient-ils une vigilance renforcée ou une déclaration ?',
        ];

        $draft = null;
        if (in_array($action, ['draft_centif', 'summarize', 'explain'], true)) {
            $draft = sprintf(
                "SYNTHÈSE ANALYSTE (brouillon — à valider)\n\nRéférence alerte : %s\nClient : %s (%s)\nFaits : opération %s d’un montant de %s (canal %s), signalée le %s.\nÉléments de vigilance : règles %s ; score %s ; PEP %s ; sanctions %s.\nAnalyse préliminaire : [compléter par l’analyste]\nMesure proposée : [classer / confirmer / approfondir / déclarer]\n",
                $ref,
                $name,
                $c->client_number ?? '',
                $c->transaction_type ?? 'n/d',
                $amount,
                $c->channel ?? 'n/d',
                $c->alert_created_at ?? 'n/d',
                $rules,
                $score,
                $pep,
                $san
            );
        }

        return [
            'object_type' => 'alert',
            'object_id' => $c->alert_id,
            'summary' => $summary,
            'factors' => $factors,
            'questions' => $questions,
            'draft' => $draft,
            'disclaimer' => 'Assistance analytique — ne constitue pas une décision de conformité. Vérifier les faits du dossier.',
            'sources' => [
                'alert_id' => $c->alert_id,
                'transaction_id' => $c->transaction_id,
                'client_id' => $c->client_id,
                'matched_rule_codes' => $c->matched_rule_codes,
            ],
        ];
    }

    private function buildClientAssist(object $c, string $action): array
    {
        $name = $c->customer_name ?? $c->client_number ?? 'Client';
        $summary = sprintf(
            "Client %s (%s) — type %s, statut %s.\nRisque : %s (score %s).\nActivité 30j : %s opérations · volume %s.\nAlertes : %s ouvertes / %s au total.\nPEP : %s · sanctions : %s.\nRattachement : %s / %s.",
            $name,
            $c->client_number ?? '',
            $c->client_type ?? 'n/d',
            $c->client_status ?? 'n/d',
            $c->risk_level ?? 'n/d',
            $c->risk_score ?? 'n/d',
            $c->tx_count_30d ?? 0,
            number_format((float) ($c->tx_volume_30d ?? 0), 0, ',', ' ') . ' XOF',
            $c->open_alerts ?? 0,
            $c->total_alerts ?? 0,
            !empty($c->has_pep_match) || !empty($c->is_pep) ? 'oui' : 'non',
            !empty($c->has_sanction_match) ? 'oui' : 'non',
            $c->agency_name ?? 'n/d',
            $c->caisse_name ?? 'n/d'
        );

        return [
            'object_type' => 'client',
            'object_id' => $c->client_id,
            'summary' => $summary,
            'factors' => [
                ['label' => 'Niveau de risque', 'value' => (string) ($c->risk_level ?? 'n/d')],
                ['label' => 'Alertes ouvertes', 'value' => (string) ($c->open_alerts ?? 0)],
                ['label' => 'Volume 30j', 'value' => number_format((float) ($c->tx_volume_30d ?? 0), 0, ',', ' ') . ' XOF'],
            ],
            'questions' => [
                'Le profil d’activité des 30 derniers jours est-il cohérent avec la connaissance client (KYC) ?',
                'Les alertes ouvertes ont-elles été traitées ou nécessitent-elles une consolidation ?',
                'Un renforcement de vigilance ou un signalement est-il justifié ?',
            ],
            'draft' => null,
            'disclaimer' => 'Assistance analytique — ne constitue pas une décision de conformité. Vérifier les faits du dossier.',
            'sources' => ['client_id' => $c->client_id],
        ];
    }

    /**
     * Fallback minimal si v_assist_alert_context n'existe pas encore.
     */
    private function fallbackAlertContext(int $id): ?object
    {
        try {
            return DB::table('alerts as a')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                ->leftJoin('transactions as t', 't.id', '=', 'a.transaction_id')
                ->leftJoin('risk_levels as rl', 'rl.id', '=', 'c.risk_level_id')
                ->where('a.id', $id)
                ->select([
                    'a.id as alert_id',
                    'a.reference as alert_reference',
                    'a.alert_type',
                    'a.priority',
                    'a.status as alert_status',
                    'a.final_score as alert_final_score',
                    'a.title as alert_title',
                    'a.description as alert_description',
                    'a.created_at as alert_created_at',
                    'a.client_id',
                    'c.client_number',
                    'c.client_type',
                    'c.is_pep',
                    'c.risk_score as client_risk_score',
                    'rl.code as client_risk_level',
                    'c.client_number as customer_name',
                    'a.transaction_id',
                    't.transaction_reference',
                    't.transaction_type',
                    't.amount as transaction_amount',
                    't.currency',
                    't.channel',
                    't.transaction_date',
                    DB::raw('NULL as matched_rule_codes'),
                    DB::raw('0 as matched_rules_count'),
                    DB::raw('NULL as max_risk_assessment_score'),
                    DB::raw('0 as has_pep_match'),
                    DB::raw('0 as has_sanction_match'),
                ])
                ->first();
        } catch (Throwable $e) {
            return null;
        }
    }
}
