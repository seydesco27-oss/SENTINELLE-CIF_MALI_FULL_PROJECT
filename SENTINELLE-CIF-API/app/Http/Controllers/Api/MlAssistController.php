<?php

namespace App\Http\Controllers\Api;

use App\Services\GroqChatClient;
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

    /**
     * POST /api/v1/ml/chat
     * Chat contextuel deterministe pour l'analyste; aucune decision n'est prise.
     */
    public function chat(Request $request): JsonResponse
    {
        $type = strtolower((string) $request->input('object_type', 'alert'));
        $id = (int) $request->input('object_id');
        $message = trim((string) $request->input('message', ''));
        $history = $this->validatedChatHistory($request->input('history', []));

        if ((!in_array($type, ['alert', 'client', 'general'], true) || ($type !== 'general' && $id <= 0)) || $message === '') {
            return response()->json([
                'success' => false,
                'message' => 'message requis; object_type doit être alert, client ou general.',
            ], 422);
        }

        if (mb_strlen($message) > 1000) {
            return response()->json([
                'success' => false,
                'message' => 'Le message ne peut pas dépasser 1 000 caractères.',
            ], 422);
        }

        $normalized = mb_strtolower($message);
        $action = match (true) {
            str_contains($normalized, 'question') || str_contains($normalized, 'diligence') => 'suggest_questions',
            str_contains($normalized, 'brouillon') || str_contains($normalized, 'centif') || str_contains($normalized, 'déclar') => 'draft_centif',
            str_contains($normalized, 'expliqu') || str_contains($normalized, 'pourquoi') => 'explain',
            default => 'summarize',
        };

        $context = $type === 'alert'
            ? DB::table('v_assist_alert_context')->where('alert_id', $id)->first()
            : ($type === 'client'
                ? DB::table('v_assist_client_context')->where('client_id', $id)->first()
                : (object) ['scope' => 'general']);

        if (!$context && $type === 'alert') {
            $context = $this->fallbackAlertContext($id);
        }

        if (!$context) {
            return response()->json([
                'success' => false,
                'message' => 'Contexte introuvable pour ce dossier.',
            ], 404);
        }

        $payload = $type === 'alert'
            ? $this->buildAlertAssist($context, $action)
            : ($type === 'client'
                ? $this->buildClientAssist($context, $action)
                : [
                    'object_type' => 'general',
                    'object_id' => null,
                    'summary' => 'Question générale sans dossier associé.',
                    'sources' => [],
                ]);

        // Le LLM ne reçoit pas les suggestions générées par les templates comme
        // preuve. Il reçoit séparément un dossier de faits, extrait des tables
        // source de la base de données.
        $verifiedFacts = $this->verifiedDossierFacts($type, $context);

        try {
            $llm = $this->askAgent($message, $history, $type, $id);
        } catch (Throwable $e) {
            report($e);
            if ($e->getMessage() === 'GROQ_API_KEY absente.') {
                return response()->json([
                    'success' => false,
                    'message' => 'Le chatbot conversationnel doit être configuré : ajoutez GROQ_API_KEY dans le fichier .env.',
                ], 502);
            }

            // Groq a échoué après retries/modèles de secours : l'analyste
            // conserve une réponse locale plutôt qu'un bandeau d'erreur.
            $llm = [
                'text' => trim((string) ($payload['summary'] ?? 'Analyse contextuelle disponible.'))
                    . "\n\nRéponse locale : le modèle distant n’a pas pu répondre (quota ou réseau). Relancez la question dans une minute.",
                'provider' => 'template',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $llm['text'] ?? $payload['summary'] ?? 'Analyse contextuelle disponible.',
                'intent' => $action,
                'provider' => $llm['provider'] ?? 'template',
                'payload' => $payload,
                'verified_facts' => $verifiedFacts,
                'disclaimer' => $llm
                    ? 'Réponse générée par IA à partir du contexte disponible. Vérifier les faits et conserver la décision humaine.'
                    : 'Réponse contextuelle — vérifier les faits et conserver la décision humaine.',
            ],
        ]);
    }

    /**
     * Retient au plus les derniers tours transmis par le navigateur. Les
     * conversations ne sont volontairement pas persistées côté serveur :
     * elles restent associées au dossier ouvert et à la session navigateur.
     */
    private function validatedChatHistory(mixed $history): array
    {
        if (!is_array($history)) {
            return [];
        }

        $validated = [];
        foreach (array_slice($history, -12) as $turn) {
            if (!is_array($turn)) {
                continue;
            }

            $role = $turn['role'] ?? null;
            $content = trim((string) ($turn['content'] ?? ''));
            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $validated[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 1500),
            ];
        }

        return $validated;
    }

    /**
     * Agent Groq : au plus un tour d'outils puis une synthèse, pour rester
     * sous le quota TPM du plan gratuit. En cas de 429, un autre modèle
     * (quota distinct) reprend toute la boucle.
     */
    private function askAgent(string $question, array $history, string $objectType, int $objectId): array
    {
        $client = app(GroqChatClient::class);
        if ($client->apiKey() === '') {
            throw new \RuntimeException('GROQ_API_KEY absente.');
        }

        $last = null;
        foreach ($client->models() as $model) {
            try {
                return $this->runAgentLoop($client, $model, $question, $history, $objectType, $objectId);
            } catch (Throwable $e) {
                $last = $e;
                if (!str_contains($e->getMessage(), 'HTTP 429')
                    && !str_contains($e->getMessage(), 'HTTP 503')
                    && !str_contains($e->getMessage(), 'HTTP 502')) {
                    throw $e;
                }
            }
        }

        throw $last ?? new \RuntimeException('Réponse fournisseur indisponible.');
    }

    private function runAgentLoop(
        GroqChatClient $client,
        string $model,
        string $question,
        array $history,
        string $objectType,
        int $objectId
    ): array {
        $recentHistory = array_slice($history, -3);
        $messages = [[
            'role' => 'system',
            'content' => $this->agentSystemPrompt($objectType, $objectId),
        ], ...array_map(fn (array $turn) => [
            'role' => $turn['role'],
            'content' => mb_substr($turn['content'], 0, 500),
        ], $recentHistory)];

        if ($recentHistory === []) {
            $messages[] = ['role' => 'user', 'content' => $question];
        }

        $json = $client->complete([
            'temperature' => 0.2,
            'max_tokens' => 400,
            'messages' => $messages,
            'tools' => $this->agentTools(),
            'tool_choice' => 'auto',
        ], $model);

        $assistant = $json['choices'][0]['message'] ?? null;
        if (!is_array($assistant)) {
            throw new \RuntimeException('Réponse agent vide ou non conforme.');
        }

        $toolCalls = $assistant['tool_calls'] ?? [];
        if (!is_array($toolCalls) || $toolCalls === []) {
            $text = trim((string) ($assistant['content'] ?? ''));
            if ($text === '') {
                throw new \RuntimeException('Réponse agent vide ou non conforme.');
            }

            return ['text' => $text, 'provider' => 'groq'];
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $assistant['content'] ?? null,
            'tool_calls' => $toolCalls,
        ];
        foreach ($toolCalls as $call) {
            $name = (string) ($call['function']['name'] ?? '');
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $result = is_array($arguments)
                ? $this->executeAgentTool($name, $arguments)
                : ['error' => 'Arguments d’outil invalides.'];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) ($call['id'] ?? ''),
                'content' => mb_substr((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1500),
            ];
        }

        $final = $client->complete([
            'temperature' => 0.2,
            'max_tokens' => 400,
            'messages' => [...$messages, [
                'role' => 'user',
                'content' => 'Réponds maintenant uniquement à partir des résultats d’outils. N’appelle plus d’outil.',
            ]],
            'tool_choice' => 'none',
        ], $model);

        $text = trim((string) ($final['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Réponse agent finale vide ou non conforme.');
        }

        return ['text' => $text, 'provider' => 'groq'];
    }

    private function agentSystemPrompt(string $objectType, int $objectId): string
    {
        $today = now('Europe/Paris')->locale('fr')->isoFormat('dddd D MMMM YYYY');
        return "Tu es Sentinelle Assist, un assistant conversationnel de conformité CIF. Réponds en français. Nous sommes le {$today}. "
            . "Tu peux répondre naturellement aux questions générales. Pour toute question sur une personne, un client, un compte, "
            . "une alerte ou une transaction CIF, tu ne connais aucun fait par avance : appelle au moins un outil avant de répondre. "
            . "Pour une demande de liste globale d’alertes (par exemple les alertes critiques), appelle obtenir_alertes_globales. Les alertes actives, ouvertes ou en action ont le statut OPEN. Quand un outil retourne un total, cite ce total et précise qu’une liste peut être un aperçu. "
            . "Choisis les outils nécessaires, ne prétends jamais qu’une donnée existe sans résultat d’outil. Si la recherche ne trouve rien, dis-le clairement. "
            . "Si la recherche renvoie plusieurs clients ayant exactement le même nom, ne choisis jamais arbitrairement : demande une référence client ou une référence d’alerte. "
            . "Utilise un texte simple et lisible, sans tableaux Markdown. "
            . "Ne prends pas de décision réglementaire. Le dossier actuellement ouvert est de type {$objectType}, identifiant {$objectId}; "
            . "utilise cet identifiant uniquement s’il est pertinent, mais recherche le client explicitement nommé dans la question.";
    }

    private function agentTools(): array
    {
        $tool = fn (string $name, string $description, array $properties, array $required) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
            ],
        ];

        return [
            $tool('rechercher_client', 'Recherche un client par nom, même approximatif ou dans un ordre inversé.', ['nom' => ['type' => 'string']], ['nom']),
            $tool('obtenir_alertes_globales', 'Liste les alertes de l’ensemble du portefeuille, filtrées par priorité ou statut. Les statuts valides sont OPEN, IN_REVIEW, CLOSED et DISMISSED. Pour active, ouverte ou en action, utiliser OPEN.', ['priorite' => ['type' => 'string', 'enum' => ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW']], 'statut' => ['type' => 'string'], 'limite' => ['type' => 'integer']], []),
            $tool('obtenir_alertes_client', 'Liste les alertes actives ou récentes d’un client.', ['client_id' => ['type' => 'integer']], ['client_id']),
            $tool('obtenir_regles_declenchees', 'Détaille les règles AML déclenchées par une alerte.', ['alert_id' => ['type' => 'integer']], ['alert_id']),
            $tool('verifier_screening_ppe_sanctions', 'Retourne le statut PPE et les correspondances sanctions.', ['client_id' => ['type' => 'integer']], ['client_id']),
            $tool('analyser_reseau_comptes_lies', 'Analyse les comptes reliés par téléphone, document ou agence et calcule le cumul du réseau.', ['client_id' => ['type' => 'integer'], 'periode_jours' => ['type' => 'integer']], ['client_id']),
            $tool('obtenir_historique_transactions', 'Retourne les transactions récentes d’un client.', ['client_id' => ['type' => 'integer'], 'periode_jours' => ['type' => 'integer']], ['client_id']),
            $tool('obtenir_score_ml_et_facteurs', 'Retourne le dernier score ML et ses facteurs explicatifs.', ['client_id' => ['type' => 'integer']], ['client_id']),
        ];
    }

    private function executeAgentTool(string $name, array $args): array
    {
        $clientId = max(0, (int) ($args['client_id'] ?? 0));
        $days = min(365, max(1, (int) ($args['periode_jours'] ?? 30)));
        return match ($name) {
            'rechercher_client' => $this->searchClients((string) ($args['nom'] ?? '')),
            'obtenir_alertes_globales' => $this->globalAlerts((string) ($args['priorite'] ?? ''), (string) ($args['statut'] ?? ''), min(50, max(1, (int) ($args['limite'] ?? 10)))),
            'obtenir_alertes_client' => $this->clientAlerts($clientId),
            'obtenir_regles_declenchees' => $this->alertRules(max(0, (int) ($args['alert_id'] ?? 0))),
            'verifier_screening_ppe_sanctions' => $this->screeningStatus($clientId),
            'analyser_reseau_comptes_lies' => $this->linkedAccountsNetwork($clientId, $days),
            'obtenir_historique_transactions' => $this->transactionHistory($clientId, $days),
            'obtenir_score_ml_et_facteurs' => $this->mlScoreAndFactors($clientId),
            default => ['error' => 'Outil inconnu.'],
        };
    }

    private function searchClients(string $name): array
    {
        $needle = trim($name);
        if ($needle === '') return ['clients' => []];
        $terms = array_values(array_filter(preg_split('/\\s+/u', $needle) ?: []));
        $rows = DB::table('clients as c')
            ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
            ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
            ->select('c.id', 'c.client_number', 'ci.first_name', 'ci.last_name', 'ce.legal_name')
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->where(function ($termQuery) use ($term) {
                        $termQuery->where('ci.first_name', 'like', "%{$term}%")
                            ->orWhere('ci.last_name', 'like', "%{$term}%")
                            ->orWhere('ce.legal_name', 'like', "%{$term}%");
                    });
                }
            })->limit(20)->get();
        if ($rows->isEmpty()) {
            $rows = DB::table('clients as c')->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                ->select('c.id', 'c.client_number', 'ci.first_name', 'ci.last_name', 'ce.legal_name')->limit(5000)->get();
        }
        $wanted = $this->normaliseName($needle);
        return ['clients' => $rows->map(function ($row) use ($wanted) {
            $fullName = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: ($row->legal_name ?? $row->client_number);
            similar_text($wanted, $this->normaliseName($fullName), $score);
            return ['client_id' => $row->id, 'nom_complet' => $fullName, 'reference' => $row->client_number, 'score_similarite' => round($score, 1)];
        })->filter(fn ($row) => $row['score_similarite'] >= 35)->sortByDesc('score_similarite')->take(5)->values()->all()];
    }

    private function clientAlerts(int $clientId): array
    {
        return ['alertes' => DB::table('alerts')->where('client_id', $clientId)->orderByDesc('created_at')->limit(20)
            ->get(['id as alert_id', 'reference', 'priority as niveau', 'status', 'final_score as score', 'created_at as date'])->map(fn ($r) => (array) $r)->all()];
    }

    private function globalAlerts(string $priority, string $status, int $limit): array
    {
        $status = match (strtoupper(trim($status))) {
            'ACTIVE', 'ACTIF', 'ACTIFS', 'OUVERT', 'OUVERTE', 'OUVERTES', 'EN ACTION' => 'OPEN',
            'EN REVUE', 'EN_REVIEW' => 'IN_REVIEW',
            'CLOS', 'CLOTURE', 'CLÔTURÉ', 'CLOSED' => 'CLOSED',
            default => strtoupper(trim($status)),
        };
        $query = DB::table('alerts as a')->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
            ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
            ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
            ->select('a.id as alert_id', 'a.reference', 'a.priority', 'a.status', 'a.final_score as score', 'a.created_at as date',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ci.first_name,''), ' ', COALESCE(ci.last_name,''))), ''), ce.legal_name, c.client_number) as client"));
        if ($priority !== '') $query->where('a.priority', strtoupper($priority));
        if ($status !== '') $query->where('a.status', strtoupper($status));
        $total = (clone $query)->count();
        return ['total' => $total, 'limite_apercu' => $limit, 'statut_applique' => $status ?: null,
            'alertes' => $query->orderByDesc('a.final_score')->orderByDesc('a.created_at')->limit($limit)->get()->map(fn ($r) => (array) $r)->all()];
    }

    private function alertRules(int $alertId): array
    {
        $transactionId = (int) DB::table('alerts')->where('id', $alertId)->value('transaction_id');
        if ($transactionId <= 0) return ['regles' => [], 'message' => 'Cette alerte n’est liée à aucune transaction.'];
        return ['regles' => DB::table('rule_executions as re')->join('aml_rules as ar', 'ar.id', '=', 're.rule_id')
            ->leftJoin('rule_conditions as rc', 'rc.rule_id', '=', 'ar.id')->where('re.transaction_id', $transactionId)
            ->select('ar.rule_code', 'ar.name as rule_label', 'ar.description', 'ar.score as score_contribution', 're.execution_result', DB::raw("GROUP_CONCAT(CONCAT(rc.field_name, ' ', rc.operator, ' ', rc.value) SEPARATOR '; ') as condition_detail"))
            ->groupBy('ar.id', 'ar.rule_code', 'ar.name', 'ar.description', 'ar.score', 're.execution_result')->get()->map(fn ($r) => (array) $r)->all()];
    }

    private function screeningStatus(int $clientId): array
    {
        return ['est_ppe' => (bool) DB::table('clients')->where('id', $clientId)->value('is_pep'),
            'correspondances_ppe' => DB::table('pep_matches')->where('client_id', $clientId)->get(['pep_category', 'position', 'country', 'match_score'])->map(fn ($r) => (array) $r)->all(),
            'correspondances_sanctions' => DB::table('sanction_matches')->where('client_id', $clientId)->get(['sanction_type', 'authority', 'reason', 'match_score'])->map(fn ($r) => (array) $r)->all()];
    }

    private function transactionHistory(int $clientId, int $days): array
    {
        return ['periode_jours' => $days, 'transactions' => DB::table('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id')
            ->where('a.client_id', $clientId)->where('t.transaction_date', '>=', now()->subDays($days))->orderByDesc('t.transaction_date')->limit(50)
            ->get(['t.transaction_reference', 't.transaction_type', 't.amount', 't.currency', 't.channel', 't.country_from', 't.country_to', 't.transaction_date'])->map(fn ($r) => (array) $r)->all()];
    }

    private function linkedAccountsNetwork(int $clientId, int $days): array
    {
        $client = DB::table('clients')->where('id', $clientId)->first(['phone', 'agency_id']);
        if (!$client) return ['comptes_lies' => [], 'montant_cumule_reseau' => 0, 'seuil_depasse' => false];
        $documentNumbers = DB::table('identity_documents')->where('client_id', $clientId)->pluck('document_number')->filter()->all();
        $linked = DB::table('clients as c')->leftJoin('identity_documents as d', 'd.client_id', '=', 'c.id')
            ->where('c.id', '!=', $clientId)->where(function ($q) use ($client, $documentNumbers) {
                if ($client->phone) $q->orWhere('c.phone', $client->phone);
                if ($documentNumbers !== []) $q->orWhereIn('d.document_number', $documentNumbers);
            })->distinct()->limit(100)->pluck('c.id')->all();
        $ids = array_values(array_unique([$clientId, ...$linked]));
        $total = DB::table('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id')->whereIn('a.client_id', $ids)
            ->where('t.transaction_date', '>=', now()->subDays($days))->sum('t.amount');
        return ['periode_jours' => $days, 'comptes_lies' => DB::table('accounts')->whereIn('client_id', $ids)->get(['client_id', 'account_number', 'status'])->map(fn ($r) => (array) $r)->all(),
            'montant_cumule_reseau' => (float) $total, 'seuil_reference' => 10000000, 'seuil_depasse' => (float) $total >= 10000000,
            'criteres_utilises' => ['telephone', 'document_identite']];
    }

    private function mlScoreAndFactors(int $clientId): array
    {
        $prediction = DB::table('predictions')->where('client_id', $clientId)->orderByDesc('predicted_at')->first(['id', 'predicted_risk', 'probability', 'predicted_at']);
        return ['prediction' => $prediction ? (array) $prediction : null,
            'facteurs' => $prediction ? DB::table('prediction_explanations')->where('prediction_id', $prediction->id)->orderByDesc('importance_score')->limit(10)
                ->get(['feature_name', 'feature_value', 'importance_score'])->map(fn ($r) => (array) $r)->all() : []];
    }

    private function normaliseName(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
    }

    /**
     * Consignes partagées par tous les fournisseurs. Elles réduisent le risque
     * d'hallucination, sans remplacer le contrôle humain réglementaire.
     */
    private function groundingInstructions(string $factsJson, bool $isDossierQuestion): string
    {
        $today = now('Europe/Paris')->locale('fr')->isoFormat('dddd D MMMM YYYY');

        $instructions = "Tu es Sentinelle Assist, un assistant conversationnel complet. Réponds naturellement en français. "
            . "Nous sommes le {$today} (fuseau Europe/Paris). Utilise cette date si l'utilisateur demande quel jour nous sommes.\n\n"
            . "Pour une salutation, une question générale, ou une demande ambiguë, réponds directement et de façon naturelle. "
            . "Ne répète pas le dossier, les faits vérifiés ou un avertissement de conformité si la question ne le demande pas. "
            . "Réponds avec du texte simple, sans Markdown et sans rubriques imposées.\n\n";

        if (!$isDossierQuestion) {
            return $instructions;
        }

        return $instructions
            . "L'utilisateur pose une question sur l'alerte, le client, la transaction ou le dossier affiché : utilise exclusivement "
            . "les données contenues dans FAITS_VERIFIES ci-dessous. N'invente aucun fait, aucune raison de déclenchement, aucun lien "
            . "entre deux événements et aucun résultat de screening. Si une information de dossier n'est pas dans ces faits, indique-le. "
            . "Dans ce seul cas, structure la réponse de façon utile entre faits, interprétation prudente et vérifications à effectuer. "
            . "Ne prends jamais de décision réglementaire : la décision appartient à l'analyste humain.\n\n"
            . "FAITS_VERIFIES :\n{$factsJson}";
    }

    private function isDossierQuestion(string $question): bool
    {
        return preg_match(
            '/\\b(alerte|alert|client|transaction|dossier|compte|score|règle|regle|aml|pep|sanction|montant|virement|transfert|opération|operation|signal|risque)\\b/ui',
            $question
        ) === 1;
    }

    /** Données source, structurées et traçables, utilisées pour le chat. */
    private function verifiedDossierFacts(string $type, object $context): array
    {
        $clientId = (int) ($context->client_id ?? 0);
        $transactionId = (int) ($context->transaction_id ?? 0);

        $facts = [
            'scope' => $type,
            'alert' => $type === 'alert' ? [
                'id' => $context->alert_id ?? null,
                'reference' => $context->alert_reference ?? null,
                'type' => $context->alert_type ?? null,
                'title' => $context->alert_title ?? null,
                'priority' => $context->priority ?? null,
                'status' => $context->alert_status ?? null,
                'final_score' => $context->alert_final_score ?? null,
                'created_at' => $context->alert_created_at ?? null,
            ] : null,
            'client' => [
                'id' => $clientId ?: null,
                'reference' => $context->client_number ?? null,
                'risk_level' => $context->client_risk_level ?? $context->risk_level ?? null,
                'risk_score' => $context->client_risk_score ?? $context->risk_score ?? null,
                'pep_indicator' => (bool) ($context->is_pep ?? false),
            ],
            'transaction' => $transactionId > 0 ? [
                'id' => $transactionId,
                'reference' => $context->transaction_reference ?? null,
                'type' => $context->transaction_type ?? null,
                'amount' => $context->transaction_amount ?? null,
                'currency' => $context->currency ?? null,
                'channel' => $context->channel ?? null,
                'date' => $context->transaction_date ?? null,
            ] : null,
        ];

        $facts['rule_executions'] = $transactionId > 0
            ? DB::table('rule_executions as re')
                ->join('aml_rules as ar', 'ar.id', '=', 're.rule_id')
                ->where('re.transaction_id', $transactionId)
                ->orderBy('re.executed_at')
                ->get(['ar.rule_code', 'ar.name', 'ar.description', 'ar.severity', 'ar.score', 're.execution_result', 're.executed_at'])
                ->map(fn ($row) => (array) $row)->all()
            : [];

        $ruleIds = $transactionId > 0
            ? DB::table('rule_executions')->where('transaction_id', $transactionId)->pluck('rule_id')
            : collect();
        $facts['rule_conditions_configuration'] = $ruleIds->isNotEmpty()
            ? DB::table('rule_conditions')->whereIn('rule_id', $ruleIds)->get(['rule_id', 'field_name', 'operator', 'value'])
                ->map(fn ($row) => (array) $row)->all()
            : [];

        $facts['risk_assessments'] = $transactionId > 0
            ? DB::table('risk_assessments')->where('transaction_id', $transactionId)
                ->orderByDesc('created_at')->get(['risk_type', 'score', 'risk_level', 'reason', 'source', 'created_at'])
                ->map(fn ($row) => (array) $row)->all()
            : [];
        $facts['pep_matches'] = $clientId > 0
            ? DB::table('pep_matches')->where('client_id', $clientId)
                ->get(['pep_category', 'position', 'country', 'match_score'])->map(fn ($row) => (array) $row)->all()
            : [];
        $facts['sanction_matches'] = $clientId > 0
            ? DB::table('sanction_matches')->where('client_id', $clientId)
                ->get(['sanction_type', 'authority', 'reason', 'match_score'])->map(fn ($row) => (array) $row)->all()
            : [];

        return $facts;
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
