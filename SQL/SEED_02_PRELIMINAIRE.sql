-- =============================================================================
-- SENTINELLE-CIF_MALI — SEED 02 PRÉLIMINAIRE (flux métier + scénarios démo jury)
-- Date: 2026-09-22 (enrichi)
--
-- Prérequis : structure BD + SEED_01_REFERENTIEL.sql
-- Usage :
--   mysql -u root -p digi_aml < SEED_02_PRELIMINAIRE.sql
--
-- Scénarios couverts (références PRELIM-*) :
--   S0  STD           client sain baseline
--   S1  PEP           PEP approuvé (registre PEP agence)
--   S2  RCA           proche de PEP ≠ auto-PEP
--   S3  CENTIF        cumul journalier ≥ 15M FCFA (DTE)
--   S4  NEAR          retrait 14M juste sous le seuil 15M
--   S5  STRUCT        fractionnement / smurfing (ops < 10M cumulées)
--   S6  LARGE         montant unitaire ≥ seuil interne 10M
--   S7  HUB           réception multi-émetteurs
--   S8  MANDAT        retrait initié par mandataire
--   S9  DORMANT       réactivation après inactivité
--   S10 UNUSUAL       volume inhabituel vs historique
--   S11 NETWORK       tête de réseau rotative (4 amis)
--   S12 CASH          forte activité espèces
--
-- Les TRIGGERS s'exécutent volontairement :
--   - screening à l'INSERT client (désactivé pendant seed clients)
--   - AML + CENTIF à l'INSERT transaction
-- =============================================================================

USE digi_aml;

SET NAMES utf8mb4;

SET @skip_client_screening = 1;

SET FOREIGN_KEY_CHECKS = 0;

-- Nettoyage ciblé seed 02 uniquement (préfixe PRELIM-)
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
-- CLIENTS (agency_id = 1 Bamako Hippodrome)
-- =============================================================================

-- S0 — Client standard KYC complet (baseline, pas d'alerte attendue)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, rca_note, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  101, 'PRELIM-STD-001', 'INDIVIDUAL', 'ACTIVE', '+22370010001', 'std001@demo.ml',
  0, 0, NULL, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S1 — PEP déclaré, approuvé (EDD OK) — registre PEP agence
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, rca_note, kyc_status, kyc_completed_at,
  pep_reviewed_at, pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  102, 'PRELIM-PEP-001', 'INDIVIDUAL', 'ACTIVE', '+22370010002', 'pep001@demo.ml',
  1, 0, NULL, 'COMPLETE', NOW(),
  NOW(), 'APPROVED', 2, 40, 1
);

-- S2 — RCA uniquement (proche de PEP, PAS auto-PEP)
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

-- S3 — CENTIF 15M (cumul journalier)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  104, 'PRELIM-CENTIF-001', 'INDIVIDUAL', 'ACTIVE', '+22370010004', 'centif001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S7 — Hub réception (plusieurs émetteurs)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  105, 'PRELIM-HUB-001', 'INDIVIDUAL', 'ACTIVE', '+22370010005', 'hub001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- Émetteurs vers le hub
INSERT INTO clients (id, client_number, client_type, status, phone, is_pep, is_rca, kyc_status, kyc_completed_at, pep_approval_status, risk_level_id, risk_score, agency_id) VALUES
(106, 'PRELIM-SND-001', 'INDIVIDUAL', 'ACTIVE', '+22370010006', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1),
(107, 'PRELIM-SND-002', 'INDIVIDUAL', 'ACTIVE', '+22370010007', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1),
(108, 'PRELIM-SND-003', 'INDIVIDUAL', 'ACTIVE', '+22370010008', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1);

-- S8 — Titulaire + mandataire
INSERT INTO clients (
  id, client_number, client_type, status, phone, is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  109, 'PRELIM-MAND-HOLDER', 'INDIVIDUAL', 'ACTIVE', '+22370010009', 0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

INSERT INTO clients (
  id, client_number, client_type, status, phone, is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  110, 'PRELIM-MAND-AGENT', 'INDIVIDUAL', 'ACTIVE', '+22370010010', 0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S4 — NEAR THRESHOLD : 14M juste sous 15M (évitement seuil CENTIF)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  111, 'PRELIM-NEAR-001', 'INDIVIDUAL', 'ACTIVE', '+22370010011', 'near001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S5 — STRUCTURING : plusieurs ops sous 10M, cumul élevé sur 24h
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  112, 'PRELIM-STRUCT-001', 'INDIVIDUAL', 'ACTIVE', '+22370010012', 'struct001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S6 — LARGE_AMOUNT : unitaire ≥ 10M (seuil interne, distinct CENTIF)
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  113, 'PRELIM-LARGE-001', 'INDIVIDUAL', 'ACTIVE', '+22370010013', 'large001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S9 — DORMANT : compte longtemps inactif puis grosse opération
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  114, 'PRELIM-DORM-001', 'INDIVIDUAL', 'ACTIVE', '+22370010014', 'dorm001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S10 — UNUSUAL_VOLUME : historique faible puis spike
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  115, 'PRELIM-UNUSUAL-001', 'INDIVIDUAL', 'ACTIVE', '+22370010015', 'unusual001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
  'NOT_REQUIRED', 1, 0, 1
);

-- S11 — Réseau tête tournante (4 amis)
INSERT INTO clients (id, client_number, client_type, status, phone, email, is_pep, is_rca, kyc_status, kyc_completed_at, pep_approval_status, risk_level_id, risk_score, agency_id) VALUES
(116, 'PRELIM-NET-A', 'INDIVIDUAL', 'ACTIVE', '+22370010016', 'neta@demo.ml', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1),
(117, 'PRELIM-NET-B', 'INDIVIDUAL', 'ACTIVE', '+22370010017', 'netb@demo.ml', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1),
(118, 'PRELIM-NET-C', 'INDIVIDUAL', 'ACTIVE', '+22370010018', 'netc@demo.ml', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1),
(119, 'PRELIM-NET-D', 'INDIVIDUAL', 'ACTIVE', '+22370010019', 'netd@demo.ml', 0, 0, 'COMPLETE', NOW(), 'NOT_REQUIRED', 1, 0, 1);

-- S12 — HIGH_CASH : forte proportion espèces
INSERT INTO clients (
  id, client_number, client_type, status, phone, email,
  is_pep, is_rca, kyc_status, kyc_completed_at,
  pep_approval_status, risk_level_id, risk_score, agency_id
) VALUES (
  120, 'PRELIM-CASH-001', 'INDIVIDUAL', 'ACTIVE', '+22370010020', 'cash001@demo.ml',
  0, 0, 'COMPLETE', NOW(),
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
 'Commerçante', 'Commerce', 400000, 'BUSINESS', NULL, NULL),
(111, 'Seydou', 'CAMARA', 'M', 'MARRIED', '1982-05-10', 'Bamako', 'ML', 'NINA-PRELIM-111',
 'Négociant', 'Commerce', 1200000, 'BUSINESS', NULL, '/files/kyc/PRELIM-111/photo.jpg'),
(112, 'Hawa', 'DIARRA', 'F', 'SINGLE', '1989-08-14', 'Bamako', 'ML', 'NINA-PRELIM-112',
 'Commerçante', 'Commerce', 500000, 'BUSINESS', NULL, '/files/kyc/PRELIM-112/photo.jpg'),
(113, 'Moussa', 'FOFANA', 'M', 'MARRIED', '1976-11-22', 'Kati', 'ML', 'NINA-PRELIM-113',
 'Importateur', 'Commerce', 2000000, 'BUSINESS', NULL, '/files/kyc/PRELIM-113/photo.jpg'),
(114, 'Adama', 'SISSOKO', 'M', 'MARRIED', '1968-03-03', 'Bamako', 'ML', 'NINA-PRELIM-114',
 'Retraité', 'Autres', 150000, 'OTHER', NULL, NULL),
(115, 'Kadiatou', 'BERTE', 'F', 'SINGLE', '1991-07-19', 'Ségou', 'ML', 'NINA-PRELIM-115',
 'Épicière', 'Commerce', 180000, 'BUSINESS', NULL, NULL),
(116, 'Youssouf', 'KEITA', 'M', 'MARRIED', '1984-01-08', 'Bamako', 'ML', 'NINA-PRELIM-116',
 'Commerçant', 'Commerce', 400000, 'BUSINESS', NULL, NULL),
(117, 'Aminata', 'TOURE', 'F', 'MARRIED', '1986-04-25', 'Bamako', 'ML', 'NINA-PRELIM-117',
 'Commerçante', 'Commerce', 350000, 'BUSINESS', NULL, NULL),
(118, 'Boubacar', 'SANGARE', 'M', 'SINGLE', '1990-09-30', 'Bamako', 'ML', 'NINA-PRELIM-118',
 'Chauffeur', 'Transport', 220000, 'SALARY', NULL, NULL),
(119, 'Mariam', 'COULIBALY', 'F', 'MARRIED', '1988-12-11', 'Bamako', 'ML', 'NINA-PRELIM-119',
 'Couturière', 'Services', 200000, 'BUSINESS', NULL, NULL),
(120, 'Lassana', 'DIALLO', 'M', 'MARRIED', '1980-06-06', 'Koulikoro', 'ML', 'NINA-PRELIM-120',
 'Grossiste', 'Commerce', 800000, 'BUSINESS', NULL, '/files/kyc/PRELIM-120/photo.jpg');

INSERT INTO addresses (client_id, country, city, address) VALUES
(101, 'ML', 'Bamako', 'Hippodrome, rue 12'),
(102, 'ML', 'Bamako', 'ACI 2000, lot 45'),
(103, 'ML', 'Bamako', 'ACI 2000, lot 45'),
(104, 'ML', 'Bamako', 'Badalabougou'),
(105, 'ML', 'Bamako', 'Medina Coura'),
(109, 'ML', 'Bamako', 'Kalaban Coura'),
(110, 'ML', 'Bamako', 'Kalaban Coura'),
(111, 'ML', 'Bamako', 'Magnambougou'),
(112, 'ML', 'Bamako', 'Lafiabougou'),
(113, 'ML', 'Bamako', 'Niamakoro'),
(114, 'ML', 'Bamako', 'Sabalibougou'),
(115, 'ML', 'Bamako', 'Banconi'),
(116, 'ML', 'Bamako', 'Yirimadio'),
(117, 'ML', 'Bamako', 'Yirimadio'),
(118, 'ML', 'Bamako', 'Sotuba'),
(119, 'ML', 'Bamako', 'Sotuba'),
(120, 'ML', 'Bamako', 'Quinzambougou');

INSERT INTO identity_documents (
  client_id, document_type, document_number, issuing_country, issue_date, expiry_date, is_primary, document_path
) VALUES
(101, 'NINA', 'NINA-PRELIM-101', 'ML', '2020-01-01', '2030-01-01', 1, '/files/kyc/PRELIM-101/nina.pdf'),
(102, 'PASSPORT', 'P-PRELIM-102', 'ML', '2019-05-01', '2029-05-01', 1, '/files/kyc/PRELIM-102/passeport.pdf'),
(103, 'NINA', 'NINA-PRELIM-103', 'ML', '2021-03-01', '2031-03-01', 1, '/files/kyc/PRELIM-103/nina.pdf'),
(104, 'NINA', 'NINA-PRELIM-104', 'ML', '2022-06-01', '2032-06-01', 1, '/files/kyc/PRELIM-104/nina.pdf'),
(105, 'NINA', 'NINA-PRELIM-105', 'ML', '2021-01-01', '2031-01-01', 1, '/files/kyc/PRELIM-105/nina.pdf'),
(109, 'NINA', 'NINA-PRELIM-109', 'ML', '2018-01-01', '2028-01-01', 1, '/files/kyc/PRELIM-109/nina.pdf'),
(110, 'NINA', 'NINA-PRELIM-110', 'ML', '2018-01-01', '2028-01-01', 1, '/files/kyc/PRELIM-110/nina.pdf'),
(111, 'NINA', 'NINA-PRELIM-111', 'ML', '2020-02-01', '2030-02-01', 1, '/files/kyc/PRELIM-111/nina.pdf'),
(112, 'NINA', 'NINA-PRELIM-112', 'ML', '2021-04-01', '2031-04-01', 1, '/files/kyc/PRELIM-112/nina.pdf'),
(113, 'NINA', 'NINA-PRELIM-113', 'ML', '2019-08-01', '2029-08-01', 1, '/files/kyc/PRELIM-113/nina.pdf'),
(114, 'NINA', 'NINA-PRELIM-114', 'ML', '2017-01-01', '2027-01-01', 1, '/files/kyc/PRELIM-114/nina.pdf'),
(115, 'NINA', 'NINA-PRELIM-115', 'ML', '2022-01-01', '2032-01-01', 1, '/files/kyc/PRELIM-115/nina.pdf'),
(116, 'NINA', 'NINA-PRELIM-116', 'ML', '2020-06-01', '2030-06-01', 1, NULL),
(117, 'NINA', 'NINA-PRELIM-117', 'ML', '2020-06-01', '2030-06-01', 1, NULL),
(118, 'NINA', 'NINA-PRELIM-118', 'ML', '2021-09-01', '2031-09-01', 1, NULL),
(119, 'NINA', 'NINA-PRELIM-119', 'ML', '2021-09-01', '2031-09-01', 1, NULL),
(120, 'NINA', 'NINA-PRELIM-120', 'ML', '2019-03-01', '2029-03-01', 1, '/files/kyc/PRELIM-120/nina.pdf');

INSERT INTO kyc_reviews (client_id, review_date, review_status, review_comment) VALUES
(101, CURDATE(), 'APPROVED', 'KYC complet — seed préliminaire'),
(102, CURDATE(), 'APPROVED', 'PEP approuvé EDD — seed préliminaire'),
(103, CURDATE(), 'APPROVED', 'RCA documenté — pas de statut PEP'),
(104, CURDATE(), 'APPROVED', 'KYC OK'),
(105, CURDATE(), 'APPROVED', 'KYC OK'),
(109, CURDATE(), 'APPROVED', 'KYC OK + mandat'),
(111, CURDATE(), 'APPROVED', 'KYC OK — scénario near-threshold'),
(112, CURDATE(), 'APPROVED', 'KYC OK — scénario structuring'),
(113, CURDATE(), 'APPROVED', 'KYC OK — scénario large amount'),
(114, CURDATE(), 'APPROVED', 'KYC OK — scénario dormant'),
(115, CURDATE(), 'APPROVED', 'KYC OK — scénario unusual volume'),
(116, CURDATE(), 'APPROVED', 'KYC OK — réseau A'),
(117, CURDATE(), 'APPROVED', 'KYC OK — réseau B'),
(118, CURDATE(), 'APPROVED', 'KYC OK — réseau C'),
(119, CURDATE(), 'APPROVED', 'KYC OK — réseau D'),
(120, CURDATE(), 'APPROVED', 'KYC OK — scénario cash');

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
(204, 104, 'PRELIM-ACC-CEN-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(205, 105, 'PRELIM-ACC-HUB-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(206, 106, 'PRELIM-ACC-SND-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(207, 107, 'PRELIM-ACC-SND-002', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(208, 108, 'PRELIM-ACC-SND-003', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(209, 109, 'PRELIM-ACC-MAND-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(210, 110, 'PRELIM-ACC-MAND-AGT', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(211, 111, 'PRELIM-ACC-NEAR-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(212, 112, 'PRELIM-ACC-STRUCT-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(213, 113, 'PRELIM-ACC-LARGE-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(214, 114, 'PRELIM-ACC-DORM-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', DATE_SUB(CURDATE(), INTERVAL 200 DAY), 4),
(215, 115, 'PRELIM-ACC-UNUSUAL-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4),
(216, 116, 'PRELIM-ACC-NET-A', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(217, 117, 'PRELIM-ACC-NET-B', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(218, 118, 'PRELIM-ACC-NET-C', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(219, 119, 'PRELIM-ACC-NET-D', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 5),
(220, 120, 'PRELIM-ACC-CASH-001', 'SAVINGS', 0, 0, 'XOF', 'ACTIVE', CURDATE(), 4);

-- Mandat : Rokia (110) mandataire sur compte Modibo (209)
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
-- =============================================================================

-- S0 Baseline STD : petits montants (pas d'alerte)
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-STD-01', 201, 'DEPOSIT', 150000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-STD-02', 201, 'WITHDRAWAL', 50000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 1 DAY), 'COMPLETED', 1, 'HOLDER');

-- S1 PEP : dépôt modéré
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-PEP-01', 202, 'DEPOSIT', 2000000, 'XOF', 'COUNTER', 'ML',
 NOW(), 'COMPLETED', 1, 'HOLDER');

-- S3 CENTIF : 3 opérations même jour civil ≥ 15M (6+5+5 = 16M)
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

-- S4 NEAR THRESHOLD : retrait 14 000 000 (juste sous 15M) + petit dépôt pour solde
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-NEAR-01', 211, 'DEPOSIT', 14500000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 1 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-NEAR-02', 211, 'WITHDRAWAL', 14000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 10:30:00'), 'COMPLETED', 1, 'HOLDER');

-- S5 STRUCTURING : 4 dépôts de 4M (= 16M) chacun < seuil unitaire 10M, même journée
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-STRUCT-01', 212, 'DEPOSIT', 4000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 08:10:00'), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-STRUCT-02', 212, 'DEPOSIT', 4000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 09:25:00'), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-STRUCT-03', 212, 'DEPOSIT', 4000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 11:05:00'), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-STRUCT-04', 212, 'DEPOSIT', 4000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 14:40:00'), 'COMPLETED', 1, 'HOLDER');

-- S6 LARGE_AMOUNT : unitaire 12M ≥ seuil interne 10M (distinct du CENTIF 15M)
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-LARGE-01', 213, 'DEPOSIT', 12000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 13:00:00'), 'COMPLETED', 1, 'HOLDER');

-- S7 Hub : 3 TRANSFER vers hub
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

-- S8 Mandat
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

-- S9 DORMANT : dernière activité il y a > 90 jours, puis grosse op aujourd'hui
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-DORM-OLD', 214, 'DEPOSIT', 200000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 120 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-DORM-NEW', 214, 'DEPOSIT', 8500000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 16:00:00'), 'COMPLETED', 1, 'HOLDER');

-- S10 UNUSUAL : 3 petites ops historiques puis spike 9M (≥ 3× moyenne)
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-UNU-01', 215, 'DEPOSIT', 100000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 20 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-UNU-02', 215, 'DEPOSIT', 120000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 15 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-UNU-03', 215, 'WITHDRAWAL', 80000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 10 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-UNU-SPIKE', 215, 'DEPOSIT', 9000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 12:15:00'), 'COMPLETED', 1, 'HOLDER');

-- S11 NETWORK tête tournante :
-- J-2 : gros dépôt sur A (14M) via amis
-- J-1 : gros dépôt sur B (13M)
-- J   : gros dépôt sur C (12M) — la « tête » change
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id,
  initiated_by_type, counterpart_client_id, counterpart_name
) VALUES
('PRELIM-TX-NET-A1', 216, 'DEPOSIT', 5000000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 DAY), 'COMPLETED', 1, 'HOLDER', 117, 'Aminata TOURE'),
('PRELIM-TX-NET-A2', 216, 'DEPOSIT', 4500000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 DAY), 'COMPLETED', 1, 'HOLDER', 118, 'Boubacar SANGARE'),
('PRELIM-TX-NET-A3', 216, 'DEPOSIT', 4500000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 DAY), 'COMPLETED', 1, 'HOLDER', 119, 'Mariam COULIBALY'),
('PRELIM-TX-NET-B1', 217, 'DEPOSIT', 6500000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 1 DAY), 'COMPLETED', 1, 'HOLDER', 116, 'Youssouf KEITA'),
('PRELIM-TX-NET-B2', 217, 'DEPOSIT', 6500000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 1 DAY), 'COMPLETED', 1, 'HOLDER', 118, 'Boubacar SANGARE'),
('PRELIM-TX-NET-C1', 218, 'DEPOSIT', 6000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 09:00:00'), 'COMPLETED', 1, 'HOLDER', 116, 'Youssouf KEITA'),
('PRELIM-TX-NET-C2', 218, 'DEPOSIT', 6000000, 'XOF', 'COUNTER', 'ML',
 CONCAT(CURDATE(), ' 11:00:00'), 'COMPLETED', 1, 'HOLDER', 117, 'Aminata TOURE');

-- S12 HIGH_CASH : série d'ops guichet (espèces) sur 30j
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id, initiated_by_type
) VALUES
('PRELIM-TX-CASH-01', 220, 'DEPOSIT', 800000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 25 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CASH-02', 220, 'WITHDRAWAL', 600000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 20 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CASH-03', 220, 'DEPOSIT', 900000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 15 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CASH-04', 220, 'WITHDRAWAL', 700000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 10 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CASH-05', 220, 'DEPOSIT', 1100000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 5 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CASH-06', 220, 'WITHDRAWAL', 500000, 'XOF', 'COUNTER', 'ML',
 DATE_SUB(NOW(), INTERVAL 2 DAY), 'COMPLETED', 1, 'HOLDER'),
('PRELIM-TX-CASH-07', 220, 'DEPOSIT', 950000, 'XOF', 'COUNTER', 'ML',
 NOW(), 'COMPLETED', 1, 'HOLDER');

-- =============================================================================
-- VÉRIFICATIONS
-- =============================================================================
SELECT 'clients_prelim' AS t, COUNT(*) AS n FROM clients WHERE client_number LIKE 'PRELIM-%'
UNION ALL SELECT 'accounts', COUNT(*) FROM accounts WHERE account_number LIKE 'PRELIM-%'
UNION ALL SELECT 'transactions', COUNT(*) FROM transactions WHERE transaction_reference LIKE 'PRELIM-%'
UNION ALL SELECT 'mandates', COUNT(*) FROM account_mandates WHERE id = 301
UNION ALL SELECT 'rca_links', COUNT(*) FROM client_related_parties WHERE client_id = 103
UNION ALL SELECT 'alerts_open', COUNT(*) FROM alerts WHERE status = 'OPEN' AND client_id IN (SELECT id FROM clients WHERE client_number LIKE 'PRELIM-%');

-- CENTIF attendu sur client 104
SELECT c.client_number, ra.risk_type, ra.score, LEFT(ra.reason, 120) AS reason
FROM risk_assessments ra
JOIN clients c ON c.id = ra.client_id
WHERE c.client_number = 'PRELIM-CENTIF-001'
ORDER BY ra.id DESC
LIMIT 5;

-- Dual profile tous PRELIM
SELECT client_number, is_pep, is_rca, aml_risk_score, centif_hit_count
FROM v_client_dual_risk_profile
WHERE client_number LIKE 'PRELIM-%'
ORDER BY client_number;

-- =============================================================================
-- GUIDE DÉMO JURY (références à rechercher dans le front)
-- =============================================================================
-- PRELIM-STD-001      → client sain (contrôle négatif)
-- PRELIM-PEP-001      → PEP approuvé — écran Screening + Client 360
-- PRELIM-RCA-001      → RCA seulement + lien SPOUSE vers PEP
-- PRELIM-CENTIF-001   → cumul jour ≥ 15M → DTE / déclarations CENTIF
-- PRELIM-NEAR-001     → retrait 14M juste sous 15M (évitement seuil)
-- PRELIM-STRUCT-001   → 4×4M même jour (fractionnement / smurfing)
-- PRELIM-LARGE-001    → unitaire 12M ≥ seuil interne 10M
-- PRELIM-HUB-001      → réception multi-émetteurs (counterpart_*)
-- PRELIM-MAND-HOLDER  → retrait initié_by MANDATE (mandate_id=301)
-- PRELIM-DORM-001     → réactivation après > 90j d'inactivité
-- PRELIM-UNUSUAL-001  → spike 9M vs historique faible
-- PRELIM-NET-A/B/C/D  → tête de réseau rotative (gros flux qui change de compte)
-- PRELIM-CASH-001     → série d'opérations guichet (espèces)
-- =============================================================================
