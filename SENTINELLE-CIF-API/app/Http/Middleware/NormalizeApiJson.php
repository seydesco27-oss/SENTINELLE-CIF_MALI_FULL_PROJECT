<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiJson
{
    /**
     * Normalisation centralisée des réponses JSON de l'API V1.
     *
     * PRINCIPES :
     *
     * 1. Les NULL métier restent NULL.
     *    Exemple :
     *      profession       => null
     *      birth_date       => null
     *      nationality      => null
     *      entity           => null
     *      reason           => null
     *
     * 2. Les compteurs/statistiques numériques absents deviennent 0.
     *    Exemple :
     *      alert_count      => 0
     *      open_alerts      => 0
     *      critical_alerts  => 0
     *      high_alerts      => 0
     *      account_count    => 0
     *      transaction_count => 0
     *
     * 3. Les indicateurs booléens sont toujours true/false.
     *
     * 4. Les identifiants numériques sont toujours des entiers.
     *
     * 5. Les scores, ratios, pourcentages et mesures numériques
     *    sont toujours des nombres.
     *
     * 6. Les montants financiers restent des chaînes décimales
     *    lorsqu'ils proviennent de MySQL afin de préserver la précision.
     *
     * 7. Aucune logique AML, SQL ou métier n'est exécutée ici.
     *    Ce middleware ne fait que normaliser la représentation JSON.
     *
     * 8. Les tableaux restent des tableaux.
     *    Un tableau vide [] n'est jamais transformé en 0/null.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->shouldNormalize($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || $content === '') {
            return $response;
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $response;
        }

        $normalized = $this->normalizeValue($decoded);

        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            return $response;
        }

        $response->setContent($json);

        return $response;
    }

    /**
     * Vérifie que la réponse doit être normalisée.
     */
    private function shouldNormalize(
        Request $request,
        Response $response
    ): bool {
        if (!$request->is('api/*')) {
            return false;
        }

        if ($response->isRedirection()) {
            return false;
        }

        if ($response instanceof JsonResponse) {
            return true;
        }

        $contentType = strtolower(
            (string) $response->headers->get('Content-Type')
        );

        return str_contains($contentType, 'application/json');
    }

    /**
     * Normalise récursivement toute la structure JSON.
     */
    private function normalizeValue(
        mixed $value,
        ?string $key = null
    ): mixed {
        /*
         * Les tableaux sont parcourus récursivement.
         *
         * IMPORTANT :
         * On ne transforme jamais un tableau en valeur scalaire.
         */
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $childKey => $childValue) {
                $normalized[$childKey] = $this->normalizeValue(
                    $childValue,
                    is_string($childKey) ? $childKey : null
                );
            }

            return $normalized;
        }

        /*
         * NULL :
         *
         * On ne convertit QUE les champs dont la nature indique
         * clairement qu'ils représentent une statistique, un compteur,
         * un indicateur numérique ou une mesure.
         *
         * Les NULL métier restent donc NULL.
         */
        if ($value === null) {
            if ($key !== null) {
                if ($this->isDateKey($key)) {
                    return null;
                }

                if ($this->isBooleanKey($key)) {
                    return false;
                }

                if ($this->isIntegerKey($key)) {
                    return 0;
                }

                if ($this->isFloatKey($key)) {
                    return 0.0;
                }

                if ($this->isCounterKey($key)) {
                    return 0;
                }

                if ($this->isMonetaryKey($key)) {
                    return '0.00';
                }
            }

            return null;
        }


        /*
         * BOOLÉENS
         */
        if ($key !== null && $this->isDateKey($key)) {
            return $value;
        }

        if ($key !== null && $this->isBooleanKey($key)) {
            return $this->toBoolean($value);
        }

        if ($key !== null && $this->isIntegerKey($key)) {
            return $this->toInteger($value);
        }

        if ($key !== null && $this->isFloatKey($key)) {
            return $this->toFloat($value);
        }


        /*
         * Les montants non NULL restent volontairement sous leur
         * représentation d'origine, généralement une chaîne MySQL
         * comme "1935013.00".
         */
        return $value;
    }

    /**
     * Champs représentant des booléens.
     */
    private function isBooleanKey(string $key): bool
    {
        $key = strtolower(trim($key));

        return in_array($key, [

            // AML / KYC / PEP
            'is_pep',
            'pep_flag',
            'pep_indicator',
            'pep',
            'is_pep_match',
            'has_pep_match',

            // Sanctions
            'sanctions_match_indicator',
            'has_sanction_match',
            'has_sanctions_match',
            'match_found',
            'has_match',

            // Transactions
            'is_night_transaction',
            'transaction_outside_client_agency',

            // Corridors
            'configured_high_risk_corridor',

            // Analyse AML
            'has_alert',
            'analysis_complete',

            // ML
            'available',
            'label_available',
            'target_alert',

            // Général
            'active',
            'enabled',
            'force',

            // Résultats de screening / matching
            'screening_match',
            'sanction_match',
            'pep_match',

        ], true);
    }

    /**
     * Champs représentant des entiers.
     *
     * On distingue ici les IDs et les statistiques.
     */
    private function isIntegerKey(string $key): bool
    {
        $key = strtolower(trim($key));

        /*
         * Identifiants.
         *
         * Exemple :
         * id
         * client_id
         * transaction_id
         * account_id
         * agency_id
         */
        if ($key === 'id' || str_ends_with($key, '_id')) {
            return true;
        }

        /*
         * Pagination / paramètres numériques.
         */
        if (in_array($key, [
            'count',
            'total',
            'limit',
            'offset',
            'page',
            'per_page',
            'current_page',
            'last_page',
            'total_pages',

            // Analyse AML
            'rules_expected',
            'rules_executed',
            'rules_matched',

            // Features transactionnelles
            'transaction_hour',
            'transaction_day_of_week',

        ], true)) {
            return true;
        }

        /*
         * Tous les champs explicitement terminés par _count.
         *
         * Exemples :
         * alert_count
         * account_count
         * transaction_count
         * risk_assessment_count
         * typology_count
         */
        if (str_ends_with($key, '_count')) {
            return true;
        }

        /*
         * Variantes fréquentes des statistiques de pagination.
         */
        return in_array($key, [
            'items_count',
            'records_count',
            'results_count',
            'rows_count',
            'matches_count',
            'assessments_count',
            'investigations_count',
            'transactions_count',
            'accounts_count',
            'clients_count',
            'alerts_count',
        ], true);
    }

    /**
     * Champs représentant des compteurs métier/statistiques.
     *
     * C'est ici que nous corrigeons notamment :
     *
     * open_alerts      => 0
     * critical_alerts  => 0
     * high_alerts      => 0
     *
     * sans transformer :
     *
     * alerts => []
     *
     * en 0.
     */
    private function isCounterKey(string $key): bool
    {
        $key = strtolower(trim($key));

        /*
         * Compteurs classiques.
         */
        if (str_ends_with($key, '_count')) {
            return true;
        }

        /*
         * Compteurs AML spécifiques.
         *
         * Exemple du problème observé :
         *
         * "statistics": {
         *     "total_alerts": 0,
         *     "open_alerts": 0,
         *     "critical_alerts": 0,
         *     "high_alerts": 0
         * }
         */
        if (in_array($key, [
            'total_alerts',
            'open_alerts',
            'closed_alerts',
            'resolved_alerts',
            'pending_alerts',
            'critical_alerts',
            'high_alerts',
            'medium_alerts',
            'low_alerts',

            'total_transactions',
            'pending_transactions',
            'completed_transactions',
            'failed_transactions',
            'suspicious_transactions',

            'total_accounts',
            'active_accounts',
            'inactive_accounts',

            'total_clients',
            'active_clients',
            'inactive_clients',

            'total_investigations',
            'open_investigations',
            'closed_investigations',

            'total_assessments',
            'total_matches',
            'total_rules',

            'risk_assessment_count',
            'typology_count',

        ], true)) {
            return true;
        }

        /*
         * Compteurs statistiques génériques.
         *
         * On accepte uniquement les préfixes explicitement
         * statistiques afin d'éviter de transformer arbitrairement
         * un champ métier en compteur.
         */
        foreach ([
            'total_',
            'active_',
            'inactive_',
            'open_',
            'closed_',
            'pending_',
            'resolved_',
            'critical_',
            'high_',
            'medium_',
            'low_',
        ] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                foreach ([
                    'alerts',
                    'transactions',
                    'accounts',
                    'clients',
                    'investigations',
                    'assessments',
                    'matches',
                    'rules',
                    'records',
                    'results',
                    'items',
                ] as $suffix) {
                    if ($key === $prefix . $suffix) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Champs représentant des nombres décimaux.
     */
    private function isFloatKey(string $key): bool
    {
        $key = strtolower(trim($key));

        /*
         * Scores AML / ML / risque.
         */
        if (str_contains($key, 'score')) {
            return true;
        }

        /*
         * Ratios.
         */
        if (str_contains($key, 'ratio')) {
            return true;
        }

        /*
         * Pourcentages.
         */
        if (
            str_contains($key, 'percentage')
            || str_contains($key, 'percent')
        ) {
            return true;
        }

        /*
         * Confiance ML.
         */
        if (str_contains($key, 'confidence')) {
            return true;
        }

        /*
         * Autres mesures statistiques explicitement décimales.
         */
        return in_array($key, [
            'average',
            'average_amount',
            'average_transaction_amount',
            'max_risk_score',
            'min_risk_score',
            'alert_score',
            'target_score',
        ], true);
    }

    /**
     * Champs représentant des montants financiers.
     *
     * IMPORTANT :
     * Les montants NON NULL ne sont volontairement pas convertis
     * en float afin d'éviter une perte de précision financière.
     */
    private function isMonetaryKey(string $key): bool
    {
        $key = strtolower(trim($key));

        return str_ends_with($key, '_amount')
            || str_ends_with($key, '_volume')
            || str_ends_with($key, '_balance')
            || str_ends_with($key, '_average')
            || in_array($key, [
                'amount',
                'opening_balance',
                'current_balance',
                'total_current_balance',
                'total_volume',
                'transaction_volume_total',
                'transaction_volume_30d',
                'previous_transaction_volume_30d',
                'previous_transaction_volume_24h',
                'cumulative_amount_24h_including_current',
            ], true);
    }

        /**
     * Champs représentant une date ou une date-heure.
     *
     * RÈGLE DE PRIORITÉ ABSOLUE :
     * Cette catégorie est vérifiée avant toute autre classification
     * (booléen, entier, float, compteur, montant) afin qu'un champ
     * comme declaration_date, created_at, closed_at, birth_date,
     * transaction_date, etc. ne puisse jamais être réinterprété
     * comme un nombre, même par accident de nommage.
     *
     * - NULL reste NULL (une date absente n'est pas "zéro").
     * - Une valeur présente n'est jamais reformatée ici : elle est
     *   renvoyée telle qu'exposée par MySQL/Carbon en amont.
     */
    private function isDateKey(string $key): bool
    {
        $key = strtolower(trim($key));

        if (str_ends_with($key, '_at')) {
            return true;
        }

        if (str_ends_with($key, '_date')) {
            return true;
        }

        return in_array($key, [
            'date',
            'datetime',
            'timestamp',
        ], true);
    }


    /**
     * Conversion robuste vers booléen.
     */
    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            return in_array(
                strtolower(trim($value)),
                [
                    '1',
                    'true',
                    'yes',
                    'on',
                    'oui',
                    'match',
                    'matched',
                    'active',
                    'enabled',
                ],
                true
            );
        }

        return (bool) $value;
    }

    /**
     * Conversion robuste vers entier.
     */
    private function toInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Conversion robuste vers float.
     */
    private function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
