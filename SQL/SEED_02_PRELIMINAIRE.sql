-- =============================================================================
-- SENTINELLE-CIF_MALI — SEED 02 PRÉLIMINAIRE (flux métier + scénarios mentors)
-- Date: 2026-09-21
--
-- Prérequis : structure BD + SEED_01_REFERENTIEL.sql
-- Usage :
--   mysql -u root -p digi_aml < SEED_02_PRELIMINAIRE.sql
--
-- Contenu (volume maîtrisé, IDs / références PRELIM-*) :
--   Clients KYC complets / PEP / RCA
--   Parties liées, mandats, comptes
--   Transactions scénarisées (CENTIF 15M, mandat, hub, baseline)
--
-- Les TRIGGERS s'exécutent volontairement :
--   - screening à l'INSERT client
--   - AML + CENTIF à l'INSERT transaction
-- =============================================================================

USE digi_aml;

SET NAMES utf8mb4;

-- Évite le screening bulk bruyant pendant le seed clients (listes souvent vides en démo)
SET @skip_client_screening = 1;

SET FOREIGN_KEY_CHECKS = 0;

-- Nettoyage ciblé seed 02 uniquement
DELETE FROM risk_assessments WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%');
DELETE FROM rule_executions WHERE transaction_id IN (
  SELECT id FROM transactions WHERE transaction_reference LIKE 'PRELIM-%'
);
DELETE FROM alerts WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%');
DELETE FROM transactions WHERE transaction_reference LIKE 'PRELIM-%';
DELETE FROM account_mandates WHERE account_id IN (
  SELECT id FROM accounts WHERE account_number LIKE 'PRELIM-%'
);
DELETE FROM accounts WHERE account_number LIKE 'PRELIM-%';
DELETE FROM client_related_parties WHERE client_id IN (
  SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%'
);
DELETE FROM identity_documents WHERE client_id IN (
  SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%'
);
DELETE FROM addresses WHERE client_id IN (
  SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%'
);
DELETE FROM kyc_reviews WHERE client_id IN (
  SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%'
);
DELETE FROM client_individuals WHERE client_id IN (
  SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%'
);
DELETE FROM clients WHERE client_number LIKE 'PRELIM-%';

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- CLIENTS (agency_id = 1 Bamako Hippodrome, risk_level LOW par défaut)
-- =============================================================================

-- C1 — Client standard KYC complet (baseline, pas d'alerte attendue)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, rca_note, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  101, 'PRELIM-STD-001', 'INDIVIDUAL', 'ACTIVE', '+22370010001', 'std001@demo.ml',
  0, 0, NULL, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- C2 — PEP déclaré, approuvé (EDD OK)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, rca_note, kyc_status, kyc_completed_at,
  pep_reviewed_at, pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  102, 'PRELIM-PEP-001', 'INDIVIDUAL', 'ACTIVE', '+22370010002', 'pep001@demo.ml',
  1, 0, NULL, 'COMPLETE', NOW(),
  NOW(), 'APPROVED', 2, 40, 1
);

-- C3 — RCA uniquement (proche de PEP, PAS auto-PEP)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, rca_note, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  103, 'PRELIM-RCA-001', 'INDIVIDUAL', 'ACTIVE', '+22370010003', 'rca001@demo.ml',
  0, 1, 'Conjoint d''un ancien ministre — vigilance relationnelle, pas statut PEP',
  'COMPLETE', NOW(),
  'NOT_REQUIRED', 2, 25, 1
);

-- C4 — Scénario CENTIF 15M (cumul journalier)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  104, 'PRELIM-CENTIF-001', 'INDIVIDUAL', 'ACTIVE', '+22370010004', 'centif001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- C5 — Hub réception (plusieurs émetteurs)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  105, 'PRELIM-HUB-001', 'INDIVIDUAL', 'ACTIVE', '+22370010005', 'hub001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- C6–C8 — Émetteurs vers le hub
INSERT INTO clients (id, client_number, client_type, status, phone, is_pep, is_rca, kyc_status, kyc_completed_at, pep_approval_status, risk_level_id, risk_score, agency_id) VALUES
(106, 'PRELIM-SND-001', 'INDIVIDUAL', 'ACTIVE', '+22370010006', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1),
(107, 'PRELIM-SND-002', 'INDIVIDUAL', 'ACTIVE', '+22370010007', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1),
(108, 'PRELIM-SND-003', 'INDIVIDUAL', 'ACTIVE', '+22370010008', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1);

-- C9 — Titulaire avec mandataire
INSERT INTO clients (
  id, client_number, client_type, status, phone, is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  109, 'PRELIM-MAND-HOLDER', 'INDIVIDUAL', 'ACTIVE', '+22370010009', 0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- C10 — Mandataire (aussi client CIF)
INSERT INTO clients (
  id, client_number, client_type, status, phone, is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  110, 'PRELIM-MAND-AGENT', 'INDIVIDUAL', 'ACTIVE', '+22370010010', 0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- =============================================================================
-- INDIVIDUS + ADRESSES + PIÈCES (liens document_path — pas de BLOB)
-- =============================================================================

INSERT INTO client_individuals (
  client_id, first_name, last_name, gender, marital_status, birth_date, place_of_birth,
  nationality, nina, profession, activity_sector, declared_income, income_source, employer_name, photo_path
) VALUES
(101, 'Amadou', 'TRAORE', 'M', 'MARRIED', '1988-03-12', 'Bamako', 'ML', 'NINA-PRELIM-101',
 'Commerçant', 'Commerce', 450000, 'BUSINESS', 'Boutique Traoré', '/files/kyc/PRELIM-101/photo.jpg'),
(102, 'Fatoumata', 'DIALLO', 'F', 'MARRIED', '1975-07-20', 'Kayes', 'ML', 'NINA-PRELIM-102',
 'Haut fonctionnaire', 'Administration', 2500000, 'SALARY', 'État', '/files/kyc/PRELIM-102/photo.jpg'),
(103, 'Ibrahim', 'DIALLO', 'M', 'MARRIED', '1978-11-05', 'Kayes', 'ML', 'NINA-PRELIM-103',
 'Entrepreneur', 'Commerce', 800000, 'BUSINESS', NULL, '/files/kyc/PRELIM-103/photo.jpg'),
(104, 'Mariam', 'KEITA', 'F', 'SINGLE', '1990-01-30', 'Sikasso', 'ML', 'NINA-PRELIM-104',
 'Négociante', 'Commerce', 600000, 'BUSINESS', NULL, '/files/kyc/PRELIM-104/photo.jpg'),
(105, 'Oumar', 'SANGARE', 'M', 'SINGLE', '1985-06-15', 'Mopti', 'ML', 'NINA-PRELIM-105',
 'Transitaire', 'Transport', 700000, 'BUSINESS', NULL, '/files/kyc/PRELIM-105/photo.jpg'),
(106, 'Aissata', 'COULIBALY', 'F', 'SINGLE', '1992-04-01', 'Bamako', 'ML', 'NINA-PRELIM-106',
 'Employée', 'Services', 250000, 'SALARY', 'Société A', NULL),
(107, 'Bakary', 'TOURE', 'M', 'MARRIED', '1987-09-09', 'Ségou', 'ML', 'NINA-PRELIM-107',
 'Agriculteur', 'Agriculture', 300000, 'BUSINESS', NULL, NULL),
(108, 'Seidina', 'BAMBA', 'M', 'SINGLE', '1995-12-12', 'Bamako', 'ML', 'NINA-PRELIM-108',
 'Chauffeur', 'Transport', 200000, 'SALARY', NULL, NULL),
(109, 'Modibo', 'KONE', 'M', 'MARRIED', '1970-02-18', 'Bamako', 'ML', 'NINA-PRELIM-109',
 'Commerçant', 'Commerce', 900000, 'BUSINESS', NULL, '/files/kyc/PRELIM-109/photo.jpg'),
(110, 'Rokia', 'KONE', 'F', 'MARRIED', '1975-08-22', 'Bamako', 'ML', 'NINA-PRELIM-110',
 'Commerçante', 'Commerce', 400000, 'BUSINESS', NULL, NULL);

INSERT INTO addresses (client_id, country, city, address) VALUES
(101, 'ML', 'Bamako', 'Hippodrome, rue 12'),
(102, 'ML', 'Bamako', 'ACI 2000, lot 45'),
(103, 'ML', 'Bamako', 'ACI 2000, lot 45'),
(104, 'ML', 'Bamako', 'Badalabougou'),
(105, 'ML', 'Bamako', 'Medina Coura'),
(109, 'ML', 'Bamako', 'Kalaban Coura'),
(110, 'ML', 'Bamako', 'Kalaban Coura');

INSERT INTO identity_documents (
  client_id, document_type, document_number, issuing_country, issue_date, expiry_date, is_primary, document_path
) VALUES
(101, 'NINA', 'NINA-PRELIM-101', 'ML', '2020-01-01', '2030-01-01', 1, '/files/kyc/PRELIM-101/nina.pdf'),
(102, 'PASSPORT', 'P-PRELIM-102', 'ML', '2019-05-01', '2029-05-01', 1, '/files/kyc/PRELIM-102/passeport.pdf'),
(103, 'NINA', 'NINA-PRELIM-103', 'ML', '2021-03-01', '2031-03-01', 1, '/files/kyc/PRELIM-103/nina.pdf'),
(104, 'NINA', 'NINA-PRELIM-104', 'ML', '2022-06-01', '2032-06-01', 1, '/files/kyc/PRELIM-104/nina.pdf'),
(105, 'NINA', 'NINA-PRELIM-105', 'ML', '2021-01-01', '2031-01-01', 1, '/files/kyc/PRELIM-105/nina.pdf'),
(109, 'NINA', 'NINA-PRELIM-109', 'ML', '2018-01-01', '2028-01-01', 1, '/files/kyc/PRELIM-109/nina.pdf'),
(110, 'NINA', 'NINA-PRELIM-110', 'ML', '2018-01-01', '2028-01-01', 1, '/files/kyc/PRELIM-110/nina.pdf');

INSERT INTO kyc_reviews (client_id, review_date, review_status, review_comment) VALUES
(101, CURDATE(), 'APPROVED', 'KYC complet — seed préliminaire'),
(102, CURDATE(), 'APPROVED', 'PEP approuvé EDD — seed préliminaire'),
(103, CURDATE(), 'APPROVED', 'RCA documenté — pas de statut PEP'),
(104, CURDATE(), 'APPROVED', 'KYC OK'),
(105, CURDATE(), 'APPROVED', 'KYC OK'),
(109, CURDATE(), 'APPROVED', 'KYC OK + mandat à venir');

-- =============================================================================
-- RCA : C3 lié au PEP C2 (is_pep_link=1) — C3 reste is_pep=0
-- =============================================================================
INSERT INTO client_related_parties (
  client_id, related_client_id, related_full_name, related_nina,
  relation_type, is_pep_link, risk_relevance, status, notes
) VALUES (
  103, 102, 'Fatoumata DIALLO', 'NINA-PRELIM-102',
  'SPOUSE', 1, 'HIGH', 'ACTIVE',
  'Mentor : proche de PEP ≠ PEP automatique'
);

-- =============================================================================
-- COMPTES
-- =============================================================================
INSERT INTO accounts (id, client_id, account_number, account_type, opening_balance, current_balance, currency, status, opened_at, account_manager_id) VALUES
(201, 101, 'PRELIM-ACC-STD-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(202, 102, 'PRELIM-ACC-PEP-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(203, 103, 'PRELIM-ACC-RCA-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(204, 104, 'PRELIM-ACC-CEN-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(205, 105, 'PRELIM-ACC-HUB-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(206, 106, 'PRELIM-ACC-SND-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(207, 107, 'PRELIM-ACC-SND-002', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(208, 108, 'PRELIM-ACC-SND-003', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 6),
(209, 109, 'PRELIM-ACC-MAND-H',  'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(210, 110, 'PRELIM-ACC-MAND-A',  'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4);

-- =============================================================================
-- MANDAT : Rokia (110) mandataire sur compte de Modibo (209)
-- =============================================================================
INSERT INTO account_mandates (
  id, account_id, mandate_client_id, full_name, identity_number, phone,
  mandate_role, powers, status, valid_from, valid_to
) VALUES (
  301, 209, 110, 'Rokia KONE', 'NINA-PRELIM-110', '+22370010010',
  'MANDATAIRE', 'WITHDRAWAL', 'ACTIVE', DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 365 DAY)
);

SET @skip_client_screening = 0;

-- =============================================================================
-- TRANSACTIONS (triggers AML + CENTIF actifs)
-- Dates : JOUR J = CURDATE() pour CENTIF ; historique léger pour baseline
-- =============================================================================

-- Baseline STD : petits dépôts hier (pas de CENTIF)
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-STD-01', 201, 'DEPOSIT', 150000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-STD-02', 201, 'WITHDRAWAL', 50000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 1 DAY), 'COMPLETED', 1, 'HOLDER');

-- PEP : dépôt modéré aujourd'hui
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-PEP-01', 202, 'DEPOSIT', 2000000, 'XOF', 'COUNTER', 'ML',
 NOW(), 'COMPLETED', 1, 'HOLDER');

-- CENTIF : 3 opérations même jour civil ≥ 15M (6+5+5 = 16M)
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-CEN-01', 204, 'DEPOSIT', 6000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 09:15:00'), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CEN-02', 204, 'DEPOSIT', 5000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 11:40:00'), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CEN-03', 204, 'WITHDRAWAL', 5000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 15:20:00'), 'COMPLETED', 1, 'HOLDER');

-- Hub : 3 TRANSFER_OUT des émetteurs → TRANSFER_IN sur hub (counterpart renseigné)
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id,
  initiated_by_type, counterpart_account_id, counterpart_client_id, counterpart_name
) VALUES
('PRELIM-TX-SND-01', 206, 'TRANSFER_OUT', 800000, 'XOF', 'TRANSFER', 'ML',
 DATE_SUB(NOW(), INTERVAL 3 HOUR), 'COMPLETED', 1,
 'HOLDER', 205, 105, 'Oumar SANGARE'),
('PRELIM-TX-SND-02', 207, 'TRANSFER_OUT', 750000, 'XOF', 'TRANSFER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 HOUR), 'COMPLETED', 1,
 'HOLDER', 205, 105, 'Oumar SANGARE'),
('PRELIM-TX-SND-03', 208, 'TRANSFER_OUT', 900000, 'XOF', 'TRANSFER', 'ML',
 DATE_SUB(NOW(), INTERVAL 1 HOUR), 'COMPLETED', 1,
 'HOLDER', 205, 105, 'Oumar SANGARE');

INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id,
  initiated_by_type, counterpart_account_id, counterpart_client_id, counterpart_name
) VALUES
('PRELIM-TX-HUB-01', 205, 'TRANSFER_IN', 800000, 'XOF', 'TRANSFER', 'ML',
 DATE_SUB(NOW(), INTERVAL 3 HOUR), 'COMPLETED', 1,
 'HOLDER', 206, 106, 'Aissata COULIBALY'),
('PRELIM-TX-HUB-02', 205, 'TRANSFER_IN', 750000, 'XOF', 'TRANSFER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 HOUR), 'COMPLETED', 1,
 'HOLDER', 207, 107, 'Bakary TOURE'),
('PRELIM-TX-HUB-03', 205, 'TRANSFER_IN', 900000, 'XOF', 'TRANSFER', 'ML',
 DATE_SUB(NOW(), INTERVAL 1 HOUR), 'COMPLETED', 1,
 'HOLDER', 208, 108, 'Seidina BAMBA');

-- Mandat : retrait initié par le mandataire (Rokia) sur compte Modibo
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id,
  initiated_by_type, initiated_by_mandate_id, initiated_by_client_id
) VALUES
('PRELIM-TX-MAND-01', 209, 'DEPOSIT', 3000000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 5 DAY), 'COMPLETED', 1,
 'HOLDER', NULL, NULL),
('PRELIM-TX-MAND-02', 209, 'WITHDRAWAL', 1200000, 'XOF', 'COUNTER', 'ML',
 NOW(), 'COMPLETED', 1,
 'MANDATE', 301, 110);

-- =============================================================================
-- VÉRIFICATIONS
-- =============================================================================
SELECT 'clients_prelim' AS t, COUNT(*) AS n FROM clients WHERE client_number LIKE 'PRELIM-%'
UNION ALL SELECT 'accounts', COUNT(*) FROM accounts WHERE account_number LIKE 'PRELIM-%'
UNION ALL SELECT 'transactions', COUNT(*) FROM transactions WHERE transaction_reference LIKE 'PRELIM-%'
UNION ALL SELECT 'mandates', COUNT(*) FROM account_mandates WHERE id = 301
UNION ALL SELECT 'rca_links', COUNT(*) FROM client_related_parties WHERE client_id = 103;

-- CENTIF attendu sur client 104
SELECT c.client_number, ra.risk_type, ra.score, LEFT(ra.reason, 120) AS reason
FROM risk_assessments ra
JOIN clients c ON c.id = ra.client_id
WHERE c.client_number = 'PRELIM-CENTIF-001'
ORDER BY ra.id DESC
LIMIT 5;

-- Dual profile
SELECT client_number, is_pep, is_rca, aml_risk_score, centif_hit_count
FROM v_client_dual_risk_profile
WHERE client_number LIKE 'PRELIM-%'
ORDER BY client_number;

-- =============================================================================
-- Guide démo rapide
-- =============================================================================
-- PRELIM-STD-001     → client sain
-- PRELIM-PEP-001     → PEP approuvé (is_pep=1, is_rca=0)
-- PRELIM-RCA-001     → RCA seulement (is_pep=0, is_rca=1) + lien SPOUSE vers PEP
-- PRELIM-CENTIF-001  → cumul jour ≥ 15M → risk_type CENTIF_DAILY_15M
-- PRELIM-HUB-001     → réception multi-émetteurs (counterpart_*)
-- PRELIM-MAND-HOLDER → retrait initié_by MANDATE (mandate_id=301)
-- =============================================================================
