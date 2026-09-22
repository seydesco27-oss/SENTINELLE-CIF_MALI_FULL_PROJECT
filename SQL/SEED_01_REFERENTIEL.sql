-- =============================================================================
-- SENTINELLE-CIF_MALI — SEED 01 RÉFÉRENTIEL (tables paramètres / organisation)
-- Date: 2026-09-21
--
-- USAGE (base vide structure déjà chargée) :
--   mysql -u root -p digi_aml < SEED_01_REFERENTIEL.sql
--
-- Contenu UNIQUEMENT :
--   roles, risk_levels, caisses, agencies, users (admin démo),
--   aml_rules + aml_rule_parameters, screening_lists, aml_risk_corridors
--
-- PAS de clients / comptes / transactions (→ SEED_02 puis SEED_03)
--
-- Ordre FK strict. IDs fixes pour stabilité des démos et scripts suivants.
-- Idempotent : nettoyage ciblé des lignes seed puis réinsertion.
-- =============================================================================

USE digi_aml;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Nettoyage ciblé (ne touche pas aux tables métier clients/tx)
-- -----------------------------------------------------------------------------
DELETE FROM aml_rule_parameters;
DELETE FROM aml_rules;
DELETE FROM aml_risk_corridors;
DELETE FROM screening_lists;
DELETE FROM users WHERE username IN (
  'admin.test', 'analyste.demo', 'superviseur.demo',
  'agent.bamako.01', 'agent.bamako.02', 'agent.segou.01'
);
DELETE FROM agencies WHERE code LIKE 'AG-SEED-%';
DELETE FROM caisses WHERE code LIKE 'CIF-SEED-%';
DELETE FROM risk_levels;
DELETE FROM roles;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 1. ROLES
-- =============================================================================
INSERT INTO roles (id, name, description) VALUES
(1, 'ADMIN', 'Administrateur plateforme — configuration et supervision globale'),
(2, 'COMPLIANCE_OFFICER', 'Responsable conformité / analyste LBC-FT'),
(3, 'SUPERVISOR', 'Superviseur agence — validation et escalade'),
(4, 'AGENT', 'Agent guichet — consultation limitée');

-- =============================================================================
-- 2. RISK LEVELS (plages 0–100 — alignées moteur M07)
-- =============================================================================
INSERT INTO risk_levels (id, code, label, description, score_min, score_max) VALUES
(1, 'LOW',      'Faible',     'Risque faible — surveillance standard',           0,  29),
(2, 'MEDIUM',   'Moyen',      'Risque moyen — vigilance renforcée',            30,  59),
(3, 'HIGH',     'Élevé',      'Risque élevé — revue prioritaire',              60,  79),
(4, 'CRITICAL', 'Critique',   'Risque critique — escalade / déclaration',      80, 100);

-- =============================================================================
-- 3. CAISSES (CIF)
-- =============================================================================
INSERT INTO caisses (id, code, name, country, city, status) VALUES
(1, 'CIF-SEED-BAM', 'Caisse d''Épargne et de Crédit Bamako Centre', 'Mali', 'Bamako', 'ACTIVE'),
(2, 'CIF-SEED-SGO', 'Caisse d''Épargne et de Crédit Ségou',         'Mali', 'Ségou',  'ACTIVE');

-- =============================================================================
-- 4. AGENCES (dépend caisses)
-- =============================================================================
INSERT INTO agencies (id, code, name, city, caisse_id) VALUES
(1, 'AG-SEED-BAM-01', 'Agence Bamako Hippodrome', 'Bamako', 1),
(2, 'AG-SEED-BAM-02', 'Agence Bamako ACI 2000',   'Bamako', 1),
(3, 'AG-SEED-SGO-01', 'Agence Ségou Centre',      'Ségou',  2);

-- =============================================================================
-- 5. USERS (login API)
-- password en clair pour tous : Admin@2026
-- hash bcrypt généré pour "Admin@2026"
-- Si login échoue : rehash via php artisan tinker / Hash::make('Admin@2026')
-- =============================================================================
INSERT INTO users (id, agency_id, role_id, username, password_hash) VALUES
(1, 1, 1, 'admin.test',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(2, 1, 2, 'analyste.demo',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(3, 1, 3, 'superviseur.demo',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(4, 1, 4, 'agent.bamako.01',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(5, 2, 4, 'agent.bamako.02',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(6, 3, 4, 'agent.segou.01',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
-- Agents 4/5/6 = gestionnaires de compte (account_manager_id).
-- Note: hash Laravel demo ("password").

-- =============================================================================
-- 6. AML RULES (référentiel moteur + CENTIF + réseau documentaire)
-- severity: LOW | MEDIUM | HIGH | CRITICAL
-- =============================================================================
INSERT INTO aml_rules (id, rule_code, name, description, severity, score, active) VALUES
-- Moteur sp_aml_engine_execute
(1,  'LARGE_AMOUNT',
     'Montant unitaire élevé',
     'Transaction unitaire au-dessus du seuil institutionnel interne (paramétrable). Distinct du seuil déclaratif CENTIF journalier.',
     'HIGH', 55.00, 1),
(2,  'STRUCTURING',
     'Fractionnement (smurfing)',
     'Cumul de montants sous-seuil sur fenêtre glissante au niveau CLIENT (tous comptes).',
     'HIGH', 60.00, 1),
(3,  'RAPID_TRANSFER',
     'Transferts rapides',
     'Enchaînement de transferts dans une fenêtre courte.',
     'MEDIUM', 40.00, 1),
(4,  'DORMANT_ACCOUNT',
     'Réactivation compte dormant',
     'Opération après longue période d''inactivité client.',
     'MEDIUM', 35.00, 1),
(5,  'UNUSUAL_VOLUME',
     'Volume inhabituel vs historique',
     'Montant × multiplicateur au-dessus de la moyenne historique client (min historique requis).',
     'HIGH', 50.00, 1),
(6,  'HIGH_CASH_ACTIVITY',
     'Forte activité espèces',
     'Ratio espèces élevé sur fenêtre 30 jours.',
     'MEDIUM', 45.00, 1),
(7,  'HIGH_RISK_CORRIDOR',
     'Corridor géographique à risque',
     'Pays from/to présent dans aml_risk_corridors.',
     'MEDIUM', 45.00, 1),
-- CENTIF (procédure dédiée — pas dans le corps du moteur)
(10, 'CENTIF_DAILY_15M',
     'Seuil déclaratif journalier 15M FCFA',
     'Cumul DEPOSIT+WITHDRAWAL du client sur la journée civile ≥ 15 000 000 FCFA (Loi LBC-FT / pratique CENTIF Mali). Distinct de LARGE_AMOUNT.',
     'CRITICAL', 70.00, 1),
-- Réseau / mandat (référentiel + future exploitation ; score documentaire)
(20, 'RECURRENT_COUNTERPARTY',
     'Contrepartie récurrente anormale',
     'Relations transactionnelles répétées vers les mêmes contreparties (seuils élevés pour limiter les faux positifs).',
     'MEDIUM', 40.00, 0),
(21, 'INCOMING_HUB',
     'Hub de réception multi-émetteurs',
     'Compte recevant des flux de nombreux émetteurs distincts (scénario réseau).',
     'HIGH', 55.00, 0),
(22, 'COORDINATED_WITHDRAWAL',
     'Retraits coordonnés (mandats)',
     'Retraits multiples liés via mandataires / initiateurs sur fenêtre courte.',
     'HIGH', 55.00, 0),
-- KYC / PEP (scoring profil — pas transactionnel temps réel)
(30, 'KYC_INCOMPLETE',
     'KYC incomplet',
     'Dossier client incomplet (NINA, pièce, revenus…).',
     'MEDIUM', 30.00, 1),
(31, 'PEP_EDD_REQUIRED',
     'PEP — diligence renforcée',
     'Client PEP : vigilance renforcée / approbation requise.',
     'HIGH', 50.00, 1);

-- =============================================================================
-- 7. PARAMÈTRES DES RÈGLES (lus par sp_aml_engine_execute)
-- =============================================================================
INSERT INTO aml_rule_parameters
  (rule_id, parameter_code, parameter_name, parameter_type, parameter_value,
   parameter_category, unit, description, source_reference, is_active)
VALUES
-- LARGE_AMOUNT
(1, 'internal_threshold', 'Seuil montant unitaire interne', 'DECIMAL', '10000000',
 'INSTITUTIONAL', 'XOF', 'Seuil interne CIF — distinct du 15M CENTIF journalier', 'Politique interne SENTINELLE', 1),
-- STRUCTURING
(2, 'individual_threshold', 'Seuil unitaire fractionnement', 'DECIMAL', '10000000',
 'INSTITUTIONAL', 'XOF', 'Montant unitaire max pour entrer dans le cumul smurfing', 'Politique interne', 1),
(2, 'window_hours', 'Fenêtre de cumul (heures)', 'INTEGER', '24',
 'INSTITUTIONAL', 'hours', 'Fenêtre glissante de détection du fractionnement', 'Politique interne', 1),
-- RAPID_TRANSFER
(3, 'window_minutes', 'Fenêtre transferts rapides', 'INTEGER', '60',
 'ANALYTICAL', 'minutes', 'Durée de la fenêtre de détection', 'Politique interne', 1),
-- DORMANT
(4, 'inactivity_days', 'Jours d''inactivité', 'INTEGER', '90',
 'ANALYTICAL', 'days', 'Délai sans opération avant signal dormant', 'Politique interne', 1),
-- UNUSUAL_VOLUME
(5, 'minimum_history', 'Histororique minimum (nb TX)', 'INTEGER', '3',
 'ANALYTICAL', 'count', 'Nombre min de TX passées pour calculer une moyenne fiable', 'Politique interne', 1),
(5, 'volume_multiplier', 'Multiplicateur vs moyenne', 'DECIMAL', '3',
 'ANALYTICAL', 'x', 'Montant ≥ moyenne × multiplicateur', 'Politique interne', 1),
-- HIGH_CASH
(6, 'minimum_transactions', 'Nb min opérations fenêtre', 'INTEGER', '5',
 'ANALYTICAL', 'count', 'Volume minimal d''opérations sur 30j', 'Politique interne', 1),
(6, 'cash_ratio', 'Ratio espèces', 'DECIMAL', '0.60',
 'ANALYTICAL', 'ratio', 'Part espèces / total opérations', 'Politique interne', 1),
-- CENTIF (lu par sp_aml_rule_centif_daily_15m si paramétré ; défaut 15M dans la proc)
(10, 'daily_threshold', 'Seuil cumul journalier CENTIF', 'DECIMAL', '15000000',
 'REGULATORY', 'XOF', 'Cumul journalier dépôts+retraits client', 'Pratique CENTIF / Loi LBC-FT Mali', 1);

-- =============================================================================
-- 8. LISTES DE SCREENING (référentiel minimal)
-- =============================================================================
INSERT INTO screening_lists (id, name, type, source_organization, last_update, description) VALUES
(1, 'Sanctions ONU',     'SANCTIONS', 'United Nations',          CURDATE(), 'Liste consolidée ONU'),
(2, 'Sanctions UE',      'SANCTIONS', 'European Union',          CURDATE(), 'Mesures restrictives UE'),
(3, 'OFAC SDN',          'SANCTIONS', 'US Treasury OFAC',        CURDATE(), 'Specially Designated Nationals'),
(4, 'PEP Référentiel',   'PEP',       'Sources multiples / local', CURDATE(), 'Personnes politiquement exposées (réf. démo)');

-- =============================================================================
-- 9. CORRIDORS GÉOGRAPHIQUES (exemples prudents)
-- =============================================================================
INSERT INTO aml_risk_corridors (country_from, country_to, active, risk_score, description) VALUES
('ML', 'XX', 1, 50.00, 'Corridor exemplaire — à calibrer selon politique CIF'),
('XX', 'ML', 1, 50.00, 'Entrée depuis juridiction à risque (placeholder XX)');

-- =============================================================================
-- CONTRÔLES POST-SEED
-- =============================================================================
SELECT 'roles' AS t, COUNT(*) AS n FROM roles
UNION ALL SELECT 'risk_levels', COUNT(*) FROM risk_levels
UNION ALL SELECT 'caisses', COUNT(*) FROM caisses
UNION ALL SELECT 'agencies', COUNT(*) FROM agencies
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'aml_rules', COUNT(*) FROM aml_rules
UNION ALL SELECT 'aml_rule_parameters', COUNT(*) FROM aml_rule_parameters
UNION ALL SELECT 'screening_lists', COUNT(*) FROM screening_lists
UNION ALL SELECT 'aml_risk_corridors', COUNT(*) FROM aml_risk_corridors;

SELECT id, rule_code, active, score FROM aml_rules ORDER BY id;

-- =============================================================================
-- LOGIN TEST (après seed)
-- =============================================================================
-- POST /api/v1/auth/login
-- { "username": "admin.test", "password": "password" }
--   (si hash démo Laravel standard ci-dessus)
-- ou alignez password_hash sur votre secret réel avant la démo jury.
-- =============================================================================
