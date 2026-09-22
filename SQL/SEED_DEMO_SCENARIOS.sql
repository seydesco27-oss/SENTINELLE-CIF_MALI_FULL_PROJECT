-- =============================================================================
-- SENTINELLE-CIF_MALI — SEED DÉMO (ADDITIF, IDEMPOTENT)
-- Date: 2026-09-21
-- Prérequis: migrations 01 → 05 + VUE_v_client_tx_averages_30d
--
-- Scénarios (client_number DEMO-*) :
--   A) CENTIF_DAILY_15M — 3 dépôts fractionnés jour >= 15M
--   B) MANDAT           — compte + mandataire + TX initiée par mandat
--   C) PEP / RCA        — PEP + conjoint RCA (pas d'auto-PEP)
--   D) RÉSEAU / HUB     — 3 émetteurs → 1 hub (counterpart_*)
--
-- Ré-exécutable: purge uniquement DEMO-* puis recrée.
-- =============================================================================

USE digi_aml;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 0. Nettoyage DEMO-*
-- -----------------------------------------------------------------------------

DELETE re FROM rule_executions re
INNER JOIN transactions t ON t.id = re.transaction_id
INNER JOIN accounts a ON a.id = t.account_id
INNER JOIN clients c ON c.id = a.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE ra FROM risk_assessments ra
INNER JOIN clients c ON c.id = ra.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE al FROM alerts al
INNER JOIN clients c ON c.id = al.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE t FROM transactions t
INNER JOIN accounts a ON a.id = t.account_id
INNER JOIN clients c ON c.id = a.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE m FROM account_mandates m
INNER JOIN accounts a ON a.id = m.account_id
INNER JOIN clients c ON c.id = a.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE p FROM client_related_parties p
INNER JOIN clients c ON c.id = p.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE p FROM client_related_parties p
INNER JOIN clients c ON c.id = p.related_client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE a FROM accounts a
INNER JOIN clients c ON c.id = a.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE ci FROM client_individuals ci
INNER JOIN clients c ON c.id = ci.client_id
WHERE c.client_number LIKE 'DEMO-%';

DELETE FROM clients WHERE client_number LIKE 'DEMO-%';


-- -----------------------------------------------------------------------------
-- 1. Contexte
-- -----------------------------------------------------------------------------

SET @agency_id := (SELECT id FROM agencies ORDER BY id LIMIT 1);
SET @risk_low  := (SELECT id FROM risk_levels WHERE code = 'LOW' LIMIT 1);
SET @risk_med  := (SELECT id FROM risk_levels WHERE code = 'MEDIUM' LIMIT 1);
SET @risk_high := (SELECT id FROM risk_levels WHERE code = 'HIGH' LIMIT 1);
SET @today     := CURDATE();
SET @now       := NOW();


-- -----------------------------------------------------------------------------
-- 2. Clients + profils
-- -----------------------------------------------------------------------------

INSERT INTO clients (client_number, client_type, status, phone, is_pep, is_rca, risk_level_id, risk_score, agency_id)
VALUES ('DEMO-CENTIF-01', 'INDIVIDUAL', 'ACTIVE', '+22370000001', 0, 0, @risk_low, 0, @agency_id);
SET @c_centif := LAST_INSERT_ID();

INSERT INTO client_individuals (client_id, first_name, last_name, gender, birth_date, nationality, profession, activity_sector, nina, declared_income, income_source)
VALUES (@c_centif, 'Amadou', 'TRAORE', 'M', '1980-05-12', 'ML', 'Commerçant', 'Commerce', 'NINA-DEMO-CENTIF', 2500000, 'BUSINESS');

INSERT INTO clients (client_number, client_type, status, phone, is_pep, is_rca, risk_level_id, risk_score, agency_id)
VALUES ('DEMO-MANDAT-OWNER', 'INDIVIDUAL', 'ACTIVE', '+22370000002', 0, 0, @risk_low, 0, @agency_id);
SET @c_owner := LAST_INSERT_ID();

INSERT INTO client_individuals (client_id, first_name, last_name, gender, birth_date, nationality, profession, nina)
VALUES (@c_owner, 'Fatoumata', 'DIALLO', 'F', '1975-03-20', 'ML', 'Importatrice', 'NINA-DEMO-OWNER');

INSERT INTO clients (client_number, client_type, status, phone, is_pep, is_rca, risk_level_id, risk_score, agency_id)
VALUES ('DEMO-MANDAT-AGENT', 'INDIVIDUAL', 'ACTIVE', '+22370000003', 0, 0, @risk_low, 0, @agency_id);
SET @c_agent := LAST_INSERT_ID();

INSERT INTO client_individuals (client_id, first_name, last_name, gender, birth_date, nationality, profession, nina)
VALUES (@c_agent, 'Ibrahim', 'KEITA', 'M', '1990-11-02', 'ML', 'Mandataire', 'NINA-DEMO-AGENT');

INSERT INTO clients (client_number, client_type, status, phone, is_pep, is_rca, risk_level_id, risk_score, agency_id, pep_approval_status)
VALUES ('DEMO-PEP-01', 'INDIVIDUAL', 'ACTIVE', '+22370000004', 1, 0, @risk_high, 65, @agency_id, 'APPROVED');
SET @c_pep := LAST_INSERT_ID();

INSERT INTO client_individuals (client_id, first_name, last_name, gender, birth_date, nationality, profession, activity_sector, nina)
VALUES (@c_pep, 'Moussa', 'SANGARE', 'M', '1968-01-15', 'ML', 'Ancien ministre', 'Administration publique', 'NINA-DEMO-PEP');

INSERT INTO clients (client_number, client_type, status, phone, is_pep, is_rca, rca_note, risk_level_id, risk_score, agency_id)
VALUES ('DEMO-RCA-01', 'INDIVIDUAL', 'ACTIVE', '+22370000005', 0, 1, 'Conjoint(e) du PEP DEMO-PEP-01', @risk_med, 25, @agency_id);
SET @c_rca := LAST_INSERT_ID();

INSERT INTO client_individuals (client_id, first_name, last_name, gender, birth_date, nationality, profession, nina)
VALUES (@c_rca, 'Aissata', 'SANGARE', 'F', '1972-07-08', 'ML', 'Commerçante', 'NINA-DEMO-RCA');

INSERT INTO client_related_parties (
  client_id, related_client_id, related_full_name, relation_type, is_pep_link, risk_relevance, status, notes
) VALUES
  (@c_rca, @c_pep, NULL, 'SPOUSE', 1, 'HIGH', 'ACTIVE', 'RCA → PEP (DEMO jury)'),
  (@c_pep, @c_rca, NULL, 'SPOUSE', 0, 'MEDIUM', 'ACTIVE', 'PEP → conjoint RCA cartographié');

INSERT INTO clients (client_number, client_type, status, phone, is_pep, is_rca, risk_level_id, risk_score, agency_id)
VALUES
  ('DEMO-HUB-01', 'INDIVIDUAL', 'ACTIVE', '+22370000010', 0, 0, @risk_med, 40, @agency_id),
  ('DEMO-NET-01', 'INDIVIDUAL', 'ACTIVE', '+22370000011', 0, 0, @risk_low, 0, @agency_id),
  ('DEMO-NET-02', 'INDIVIDUAL', 'ACTIVE', '+22370000012', 0, 0, @risk_low, 0, @agency_id),
  ('DEMO-NET-03', 'INDIVIDUAL', 'ACTIVE', '+22370000013', 0, 0, @risk_low, 0, @agency_id);

SET @c_hub := (SELECT id FROM clients WHERE client_number = 'DEMO-HUB-01');
SET @c_n1  := (SELECT id FROM clients WHERE client_number = 'DEMO-NET-01');
SET @c_n2  := (SELECT id FROM clients WHERE client_number = 'DEMO-NET-02');
SET @c_n3  := (SELECT id FROM clients WHERE client_number = 'DEMO-NET-03');

INSERT INTO client_individuals (client_id, first_name, last_name, gender, nationality, nina) VALUES
  (@c_hub, 'Seydou',  'COULIBALY', 'M', 'ML', 'NINA-DEMO-HUB'),
  (@c_n1,  'Oumar',   'BAMBA',     'M', 'ML', 'NINA-DEMO-N1'),
  (@c_n2,  'Mariama', 'TOURE',     'F', 'ML', 'NINA-DEMO-N2'),
  (@c_n3,  'Bakary',  'CISSE',     'M', 'ML', 'NINA-DEMO-N3');


-- -----------------------------------------------------------------------------
-- 3. Comptes
-- -----------------------------------------------------------------------------

INSERT INTO accounts (client_id, account_number, account_type, opening_balance, current_balance, currency, status, opened_at)
VALUES
  (@c_centif, 'DEMO-ACC-CENTIF', 'SAVINGS', 5000000, 20000000, 'XOF', 'ACTIVE', @today),
  (@c_owner,  'DEMO-ACC-OWNER',  'CURRENT', 2000000, 8000000,  'XOF', 'ACTIVE', @today),
  (@c_agent,  'DEMO-ACC-AGENT',  'SAVINGS', 500000,  1200000,  'XOF', 'ACTIVE', @today),
  (@c_pep,    'DEMO-ACC-PEP',    'CURRENT', 3000000, 9000000,  'XOF', 'ACTIVE', @today),
  (@c_rca,    'DEMO-ACC-RCA',    'SAVINGS', 1000000, 3500000,  'XOF', 'ACTIVE', @today),
  (@c_hub,    'DEMO-ACC-HUB',    'CURRENT', 1000000, 25000000, 'XOF', 'ACTIVE', @today),
  (@c_n1,     'DEMO-ACC-N1',     'SAVINGS', 500000,  2000000,  'XOF', 'ACTIVE', @today),
  (@c_n2,     'DEMO-ACC-N2',     'SAVINGS', 500000,  2000000,  'XOF', 'ACTIVE', @today),
  (@c_n3,     'DEMO-ACC-N3',     'SAVINGS', 500000,  2000000,  'XOF', 'ACTIVE', @today);

SET @a_centif := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-CENTIF');
SET @a_owner  := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-OWNER');
SET @a_hub    := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-HUB');
SET @a_n1     := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-N1');
SET @a_n2     := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-N2');
SET @a_n3     := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-N3');
SET @a_pep    := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-PEP');
SET @a_rca    := (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-RCA');


-- -----------------------------------------------------------------------------
-- 4. Mandat (colonnes alignées migration 01)
-- -----------------------------------------------------------------------------

INSERT INTO account_mandates (
  account_id, mandate_client_id, full_name, identity_number, phone,
  mandate_role, powers, status, valid_from, valid_to
) VALUES (
  @a_owner, @c_agent, 'Ibrahim KEITA', 'NINA-DEMO-AGENT', '+22370000003',
  'MANDATAIRE', 'WITHDRAWAL,TRANSFER', 'ACTIVE',
  DATE_SUB(@today, INTERVAL 30 DAY), DATE_ADD(@today, INTERVAL 365 DAY)
);
SET @mandate_id := LAST_INSERT_ID();


-- -----------------------------------------------------------------------------
-- 5. Transactions
-- -----------------------------------------------------------------------------

-- A) CENTIF 16.5M le même jour
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id
) VALUES
  ('DEMO-TX-CENTIF-1', @a_centif, 'DEPOSIT', 6000000, 'XOF', 'COUNTER', 'ML', CONCAT(@today, ' 09:15:00'), 'COMPLETED', @agency_id),
  ('DEMO-TX-CENTIF-2', @a_centif, 'DEPOSIT', 5500000, 'XOF', 'COUNTER', 'ML', CONCAT(@today, ' 11:40:00'), 'COMPLETED', @agency_id),
  ('DEMO-TX-CENTIF-3', @a_centif, 'DEPOSIT', 5000000, 'XOF', 'COUNTER', 'ML', CONCAT(@today, ' 15:05:00'), 'COMPLETED', @agency_id);

-- B) Retrait par mandataire
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id,
  initiated_by_type, initiated_by_mandate_id, initiated_by_client_id
) VALUES (
  'DEMO-TX-MANDAT-1', @a_owner, 'WITHDRAWAL', 2500000, 'XOF', 'COUNTER', 'ML',
  CONCAT(@today, ' 10:20:00'), 'COMPLETED', @agency_id,
  'MANDATE', @mandate_id, @c_agent
);

-- C) Activité PEP / RCA (modérée)
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id
) VALUES
  ('DEMO-TX-PEP-1', @a_pep, 'DEPOSIT', 1200000, 'XOF', 'COUNTER', 'ML', CONCAT(@today, ' 08:30:00'), 'COMPLETED', @agency_id),
  ('DEMO-TX-RCA-1', @a_rca, 'WITHDRAWAL', 800000, 'XOF', 'COUNTER', 'ML', CONCAT(@today, ' 14:10:00'), 'COMPLETED', @agency_id);

-- D) Réseau → hub
INSERT INTO transactions (
  transaction_reference, account_id, transaction_type, amount, currency, channel,
  country, transaction_date, transaction_status, agency_id,
  counterpart_account_id, counterpart_client_id, counterpart_name
) VALUES
  ('DEMO-TX-NET-1a', @a_n1, 'TRANSFER_OUT', 3000000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 5 DAY), 'COMPLETED', @agency_id, @a_hub, @c_hub, 'Seydou COULIBALY'),
  ('DEMO-TX-NET-1b', @a_n1, 'TRANSFER_OUT', 2500000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 4 DAY), 'COMPLETED', @agency_id, @a_hub, @c_hub, 'Seydou COULIBALY'),
  ('DEMO-TX-NET-2a', @a_n2, 'TRANSFER_OUT', 4000000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 3 DAY), 'COMPLETED', @agency_id, @a_hub, @c_hub, 'Seydou COULIBALY'),
  ('DEMO-TX-NET-2b', @a_n2, 'TRANSFER_OUT', 2000000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 2 DAY), 'COMPLETED', @agency_id, @a_hub, @c_hub, 'Seydou COULIBALY'),
  ('DEMO-TX-NET-3a', @a_n3, 'TRANSFER_OUT', 3500000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 1 DAY), 'COMPLETED', @agency_id, @a_hub, @c_hub, 'Seydou COULIBALY'),
  ('DEMO-TX-NET-3b', @a_n3, 'TRANSFER_OUT', 2800000, 'XOF', 'TRANSFER', 'ML', @now, 'COMPLETED', @agency_id, @a_hub, @c_hub, 'Seydou COULIBALY'),
  ('DEMO-TX-HUB-IN-1', @a_hub, 'TRANSFER_IN', 3000000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 5 DAY), 'COMPLETED', @agency_id, @a_n1, @c_n1, 'Oumar BAMBA'),
  ('DEMO-TX-HUB-IN-2', @a_hub, 'TRANSFER_IN', 2500000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 4 DAY), 'COMPLETED', @agency_id, @a_n1, @c_n1, 'Oumar BAMBA'),
  ('DEMO-TX-HUB-IN-3', @a_hub, 'TRANSFER_IN', 4000000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 3 DAY), 'COMPLETED', @agency_id, @a_n2, @c_n2, 'Mariama TOURE'),
  ('DEMO-TX-HUB-IN-4', @a_hub, 'TRANSFER_IN', 2000000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 2 DAY), 'COMPLETED', @agency_id, @a_n2, @c_n2, 'Mariama TOURE'),
  ('DEMO-TX-HUB-IN-5', @a_hub, 'TRANSFER_IN', 3500000, 'XOF', 'TRANSFER', 'ML', DATE_SUB(@now, INTERVAL 1 DAY), 'COMPLETED', @agency_id, @a_n3, @c_n3, 'Bakary CISSE'),
  ('DEMO-TX-HUB-IN-6', @a_hub, 'TRANSFER_IN', 2800000, 'XOF', 'TRANSFER', 'ML', @now, 'COMPLETED', @agency_id, @a_n3, @c_n3, 'Bakary CISSE');

COMMIT;

-- =============================================================================
-- Post-seed recommandé (si trigger n'a pas tout évalué)
-- =============================================================================
/*
CALL sp_aml_evaluate_transaction(
  (SELECT id FROM transactions WHERE transaction_reference = 'DEMO-TX-CENTIF-3' LIMIT 1)
);

SELECT client_number, is_pep, is_rca, risk_score FROM clients WHERE client_number LIKE 'DEMO-%';
SELECT * FROM account_mandates WHERE account_id = (SELECT id FROM accounts WHERE account_number = 'DEMO-ACC-OWNER');
SELECT risk_type, score, LEFT(reason, 120) AS reason
FROM risk_assessments WHERE risk_type = 'CENTIF_DAILY_15M' ORDER BY id DESC LIMIT 3;
SELECT * FROM v_client_counterparty_links
WHERE source_client_id IN (SELECT id FROM clients WHERE client_number LIKE 'DEMO-NET-%');
*/
