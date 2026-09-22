-- =============================================================================
-- SENTINELLE-CIF_MALI — SEED 03 SCALE (volume structure cible)
-- Date: 2026-09-21
--
-- Objectifs par défaut :
--   ~5 000 clients  (90% INDIVIDUAL / 10% ENTITY)
--   ~10 000 comptes
--   ~100 000 transactions
--
-- Prérequis : structure + SEED_01 (+ SEED_02 optionnel, coexiste via préfixe SCALE-)
--
-- IMPORTANT PERFORMANCE
--   Les triggers AML temps réel sont DROP puis recréés après chargement.
--   Sans cela, 100k × sp_aml_evaluate_full serait impraticable.
--   Après seed : batch optionnel sur un échantillon (voir fin de fichier).
--
-- Usage :
--   mysql -u root -p digi_aml < SEED_03_SCALE.sql
--   -- durée indicative : quelques minutes selon machine
-- =============================================================================

USE digi_aml;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Paramètres (modifiables)
SET @n_clients := 5000;
SET @n_accounts := 10000;
SET @n_transactions := 100000;
SET @agency_id := 1;
SET @risk_low := 1;

-- -----------------------------------------------------------------------------
-- 0. Désactiver AML temps réel (recréé en fin de script)
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_aml_transaction_realtime;

SET @skip_client_screening := 1;

-- Nettoyage scale précédent uniquement
DELETE FROM risk_assessments WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM rule_executions WHERE transaction_id IN (
  SELECT id FROM transactions WHERE transaction_reference LIKE 'SCALE-%'
);
DELETE FROM alerts WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM transactions WHERE transaction_reference LIKE 'SCALE-%';
DELETE FROM account_mandates WHERE account_id IN (SELECT id FROM accounts WHERE account_number LIKE 'SCALE-%');
DELETE FROM accounts WHERE account_number LIKE 'SCALE-%';
DELETE FROM client_related_parties WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM identity_documents WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM addresses WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM kyc_reviews WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM client_individuals WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM client_entities WHERE client_id IN (SELECT id FROM clients WHERE client_number LIKE 'SCALE-%');
DELETE FROM clients WHERE client_number LIKE 'SCALE-%';

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 1. Procédure de génération
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_seed_scale_generate;

DELIMITER $$

CREATE PROCEDURE sp_seed_scale_generate(
  IN p_n_clients INT,
  IN p_n_accounts INT,
  IN p_n_transactions INT,
  IN p_agency_id BIGINT
)
main_seed:
BEGIN
  DECLARE i INT DEFAULT 1;
  DECLARE v_client_id BIGINT;
  DECLARE v_is_entity TINYINT;
  DECLARE v_is_pep TINYINT;
  DECLARE v_is_rca TINYINT;
  DECLARE v_kyc VARCHAR(30);
  DECLARE v_acc_id BIGINT;
  DECLARE v_acc_per_client INT;
  DECLARE v_extra INT;
  DECLARE v_tx_i INT DEFAULT 1;
  DECLARE v_type VARCHAR(30);
  DECLARE v_amount DECIMAL(18,2);
  DECLARE v_day_offset INT;
  DECLARE v_account_pick BIGINT;
  DECLARE v_max_client_id BIGINT;
  DECLARE v_min_client_id BIGINT;
  DECLARE v_max_account_id BIGINT;
  DECLARE v_min_account_id BIGINT;

  /* ---------- CLIENTS ---------- */
  SET i = 1;
  WHILE i <= p_n_clients DO
    SET v_is_entity = IF(i % 10 = 0, 1, 0);           -- 10% entités
    SET v_is_pep    = IF(i % 97 = 0, 1, 0);           -- ~1% PEP
    SET v_is_rca    = IF(i % 53 = 0 AND v_is_pep = 0, 1, 0); -- RCA hors PEP
    SET v_kyc       = CASE
                        WHEN i % 17 = 0 THEN 'INCOMPLETE'
                        WHEN i % 11 = 0 THEN 'PENDING'
                        ELSE 'COMPLETE'
                      END;

    INSERT INTO clients (
      client_number, client_type, status, phone, email,
      is_pep, is_rca, rca_note, kyc_status, kyc_completed_at,
      pep_approval_status, risk_level_id, risk_score, agency_id
    ) VALUES (
      CONCAT('SCALE-C-', LPAD(i, 6, '0')),
      IF(v_is_entity = 1, 'ENTITY', 'INDIVIDUAL'),
      'ACTIVE',
      CONCAT('+22370', LPAD(i, 6, '0')),
      CONCAT('scale', i, '@demo.ml'),
      v_is_pep,
      v_is_rca,
      IF(v_is_rca = 1, 'Lien RCA synthétique scale', NULL),
      v_kyc,
      IF(v_kyc = 'COMPLETE', NOW(), NULL),
      IF(v_is_pep = 1, IF(i % 2 = 0, 'APPROVED', 'PENDING'), 'NOT_REQUIRED'),
      1,
      0,
      p_agency_id
    );

    SET v_client_id = LAST_INSERT_ID();

    IF v_is_entity = 0 THEN
      INSERT INTO client_individuals (
        client_id, first_name, last_name, gender, marital_status, birth_date,
        place_of_birth, nationality, nina, profession, activity_sector,
        declared_income, income_source, employer_name
      ) VALUES (
        v_client_id,
        ELT(1 + (i % 8), 'Amadou','Fatou','Ibrahim','Mariam','Oumar','Aissata','Bakary','Rokia'),
        ELT(1 + (i % 8), 'TRAORE','DIALLO','KEITA','SANGARE','COULIBALY','TOURE','KONE','BAMBA'),
        IF(i % 2 = 0, 'M', 'F'),
        ELT(1 + (i % 4), 'SINGLE','MARRIED','MARRIED','DIVORCED'),
        DATE_SUB(CURDATE(), INTERVAL (18 + (i % 50)) YEAR),
        ELT(1 + (i % 5), 'Bamako','Ségou','Sikasso','Mopti','Kayes'),
        'ML',
        CONCAT('NINA-SC-', LPAD(i, 8, '0')),
        ELT(1 + (i % 6), 'Commerçant','Agriculteur','Fonctionnaire','Chauffeur','Enseignant','Entrepreneur'),
        ELT(1 + (i % 5), 'Commerce','Agriculture','Administration','Transport','Services'),
        150000 + (i % 50) * 25000,
        ELT(1 + (i % 3), 'SALARY','BUSINESS','OTHER'),
        NULL
      );

      IF v_kyc = 'COMPLETE' THEN
        INSERT INTO identity_documents (
          client_id, document_type, document_number, issuing_country,
          issue_date, expiry_date, is_primary, document_path
        ) VALUES (
          v_client_id, 'NINA', CONCAT('NINA-SC-', LPAD(i, 8, '0')), 'ML',
          DATE_SUB(CURDATE(), INTERVAL 3 YEAR), DATE_ADD(CURDATE(), INTERVAL 7 YEAR),
          1, CONCAT('/files/kyc/SCALE/', LPAD(i, 6, '0'), '/nina.pdf')
        );
      END IF;
    ELSE
      INSERT INTO client_entities (
        client_id, legal_name, entity_type, registration_number,
        activity_sector, nationality, registration_country
      ) VALUES (
        v_client_id,
        CONCAT('ETS SCALE ', LPAD(i, 5, '0')),
        ELT(1 + (i % 3), 'SARL','SA','GIE'),
        CONCAT('RCCM-SC-', LPAD(i, 6, '0')),
        ELT(1 + (i % 4), 'Commerce','BTP','Transport','Services'),
        'ML',
        'ML'
      );
    END IF;

    IF i % 3 = 0 THEN
      INSERT INTO addresses (client_id, country, city, address)
      VALUES (v_client_id, 'ML',
              ELT(1 + (i % 4), 'Bamako','Ségou','Sikasso','Mopti'),
              CONCAT('Quartier synthétique ', i));
    END IF;

    SET i = i + 1;
  END WHILE;

  SELECT MIN(id), MAX(id) INTO v_min_client_id, v_max_client_id
  FROM clients WHERE client_number LIKE 'SCALE-C-%';

  /* ---------- COMPTES (~2 par client en moyenne) ---------- */
  SET i = 1;
  WHILE i <= p_n_clients DO
    SET v_client_id = v_min_client_id + i - 1;
    IF v_client_id > v_max_client_id THEN
      SET i = p_n_clients + 1;
    ELSE
      -- 1 compte minimum, 2e compte pour la moitié environ
      INSERT INTO accounts (
        client_id, account_number, account_type,
        opening_balance, current_balance, currency, status, opened_at, account_manager_id
      ) VALUES (
        v_client_id,
        CONCAT('SCALE-A-', LPAD(i, 6, '0'), '-1'),
        IF(i % 5 = 0, 'CURRENT', 'SAVINGS'),
        0, 0, 'XOF', 'ACTIVE', DATE_SUB(CURDATE(), INTERVAL (i % 400) DAY),
        4 + ((i - 1) % 3)
      );

      IF i <= (p_n_accounts - p_n_clients) THEN
        INSERT INTO accounts (
          client_id, account_number, account_type,
          opening_balance, current_balance, currency, status, opened_at, account_manager_id
        ) VALUES (
          v_client_id,
          CONCAT('SCALE-A-', LPAD(i, 6, '0'), '-2'),
          'SAVINGS',
          0, 0, 'XOF', 'ACTIVE', DATE_SUB(CURDATE(), INTERVAL (i % 200) DAY),
          4 + ((i - 1) % 3)
        );
      END IF;

      SET i = i + 1;
    END IF;
  END WHILE;

  SELECT MIN(id), MAX(id) INTO v_min_account_id, v_max_account_id
  FROM accounts WHERE account_number LIKE 'SCALE-A-%';

  /* Quelques mandats (~2% des comptes) */
  SET i = v_min_account_id;
  WHILE i <= v_max_account_id DO
    IF (i % 50 = 0) THEN
      INSERT INTO account_mandates (
        account_id, full_name, identity_number, phone,
        mandate_role, powers, status, valid_from, valid_to
      ) VALUES (
        i,
        CONCAT('Mandataire Scale ', i),
        CONCAT('NINA-MD-', i),
        CONCAT('+22375', LPAD(i % 1000000, 6, '0')),
        'MANDATAIRE', 'WITHDRAWAL', 'ACTIVE',
        DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 300 DAY)
      );
    END IF;
    SET i = i + 1;
  END WHILE;

  /* ---------- TRANSACTIONS ---------- */
  SET v_tx_i = 1;
  WHILE v_tx_i <= p_n_transactions DO
    SET v_account_pick = v_min_account_id
      + FLOOR(RAND() * (v_max_account_id - v_min_account_id + 1));

    SET v_type = ELT(1 + (v_tx_i % 5),
      'DEPOSIT', 'WITHDRAWAL', 'DEPOSIT', 'TRANSFER_IN', 'TRANSFER_OUT');

    -- Montants : majorité petits, queue de montants élevés
    SET v_amount = CASE
      WHEN v_tx_i % 200 = 0 THEN 8000000 + (v_tx_i % 10) * 500000   -- gros
      WHEN v_tx_i % 40 = 0 THEN 1500000 + (v_tx_i % 20) * 100000
      ELSE 25000 + (v_tx_i % 200) * 3500
    END;

    SET v_day_offset = v_tx_i % 180; -- 6 mois d'historique

    INSERT INTO transactions (
      transaction_reference, account_id, transaction_type, amount, currency,
      channel, country, transaction_date, transaction_status, agency_id,
      initiated_by_type
    ) VALUES (
      CONCAT('SCALE-TX-', LPAD(v_tx_i, 8, '0')),
      v_account_pick,
      v_type,
      v_amount,
      'XOF',
      ELT(1 + (v_tx_i % 4), 'COUNTER', 'ATM', 'TRANSFER', 'MOBILE'),
      'ML',
      DATE_SUB(NOW(), INTERVAL v_day_offset DAY) + INTERVAL (v_tx_i % 86400) SECOND,
      'COMPLETED',
      p_agency_id,
      IF(v_tx_i % 50 = 0, 'MANDATE', 'HOLDER')
    );

    SET v_tx_i = v_tx_i + 1;

    -- Commit par paquets pour limiter les undo logs
    IF v_tx_i % 5000 = 0 THEN
      DO SLEEP(0); -- point de respiration
    END IF;
  END WHILE;

END$$

DELIMITER ;

-- -----------------------------------------------------------------------------
-- 2. Exécution
-- -----------------------------------------------------------------------------
CALL sp_seed_scale_generate(@n_clients, @n_accounts, @n_transactions, @agency_id);

SET @skip_client_screening := 0;

-- -----------------------------------------------------------------------------
-- 3. Recréer le trigger AML temps réel
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_aml_transaction_realtime;

DELIMITER $$

CREATE TRIGGER trg_aml_transaction_realtime
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
  CALL sp_aml_evaluate_transaction_trigger(NEW.id);
END$$

DELIMITER ;

-- -----------------------------------------------------------------------------
-- 4. Échantillon AML post-load (évite 100k évaluations)
--    Évalue les 300 dernières TX SCALE (démo moteur + CENTIF si cumul)
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_seed_scale_evaluate_sample;

DELIMITER $$

CREATE PROCEDURE sp_seed_scale_evaluate_sample(IN p_limit INT)
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_id BIGINT;
  DECLARE cur CURSOR FOR
    SELECT id FROM transactions
    WHERE transaction_reference LIKE 'SCALE-TX-%'
    ORDER BY id DESC
    LIMIT p_limit;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_id;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;
    CALL sp_aml_evaluate_full(v_id);
  END LOOP;
  CLOSE cur;
END$$

DELIMITER ;

CALL sp_seed_scale_evaluate_sample(300);

-- -----------------------------------------------------------------------------
-- 5. Contrôles
-- -----------------------------------------------------------------------------
SELECT 'SCALE clients' AS metric, COUNT(*) AS n FROM clients WHERE client_number LIKE 'SCALE-C-%'
UNION ALL SELECT 'SCALE individuals', COUNT(*) FROM client_individuals ci
  JOIN clients c ON c.id = ci.client_id WHERE c.client_number LIKE 'SCALE-C-%'
UNION ALL SELECT 'SCALE entities', COUNT(*) FROM client_entities ce
  JOIN clients c ON c.id = ce.client_id WHERE c.client_number LIKE 'SCALE-C-%'
UNION ALL SELECT 'SCALE accounts', COUNT(*) FROM accounts WHERE account_number LIKE 'SCALE-A-%'
UNION ALL SELECT 'SCALE transactions', COUNT(*) FROM transactions WHERE transaction_reference LIKE 'SCALE-TX-%'
UNION ALL SELECT 'SCALE PEP', COUNT(*) FROM clients WHERE client_number LIKE 'SCALE-C-%' AND is_pep = 1
UNION ALL SELECT 'SCALE RCA', COUNT(*) FROM clients WHERE client_number LIKE 'SCALE-C-%' AND is_rca = 1
UNION ALL SELECT 'assessments sample', COUNT(*) FROM risk_assessments ra
  JOIN transactions t ON t.id = ra.transaction_id WHERE t.transaction_reference LIKE 'SCALE-TX-%';

-- =============================================================================
-- Notes
-- =============================================================================
-- * Volume généré synthétiquement pour simuler une structure cible.
-- * AML exhaustif sur 100k TX : lancer plus tard
--     CALL sp_seed_scale_evaluate_sample(5000);
--   ou un job nocturne / bouton front « batch historique ».
-- * PRELIM-* (SEED_02) n’est pas effacé — cohabitation des préfixes.
-- =============================================================================
