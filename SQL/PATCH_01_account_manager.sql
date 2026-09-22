-- =============================================================================
-- SENTINELLE-CIF_MALI — PATCH_01_account_manager.sql
-- Date : 2026-09-22
-- Objectif : Ajouter gestionnaire de compte (account_manager_id) NON DESTRUCTIF
-- Decision : reutiliser users (role AGENT), pas de table staff separee
-- =============================================================================

USE digi_aml;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'account_manager_id'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `accounts`
     ADD COLUMN `account_manager_id` BIGINT NULL DEFAULT NULL
       COMMENT ''Gestionnaire de compte (users.id — role AGENT/SUPERVISOR)''
       AFTER `opened_at`,
     ADD KEY `idx_accounts_manager` (`account_manager_id`),
     ADD CONSTRAINT `fk_accounts_manager`
       FOREIGN KEY (`account_manager_id`) REFERENCES `users` (`id`)
       ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT ''accounts.account_manager_id deja present — skip ALTER'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS `sp_account_open`;

DELIMITER $$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_account_open` (
  IN  `p_client_id`           BIGINT,
  IN  `p_account_number`      VARCHAR(50),
  IN  `p_account_type`        VARCHAR(50),
  IN  `p_opening_balance`     DECIMAL(18,2),
  IN  `p_currency`            CHAR(3),
  IN  `p_account_manager_id`  BIGINT,
  OUT `p_account_id`          BIGINT,
  OUT `p_status`              VARCHAR(30),
  OUT `p_message`             VARCHAR(500)
)
main_acc:
BEGIN
  DECLARE v_client_status VARCHAR(30);
  DECLARE v_pep           TINYINT DEFAULT 0;
  DECLARE v_pep_approval  VARCHAR(30);
  DECLARE v_mgr_ok        INT DEFAULT 0;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_account_id = NULL;
    SET p_status     = 'ERROR';
    SET p_message    = 'Echec ouverture compte.';
  END;

  SET p_account_id = NULL;
  SET p_status     = 'ERROR';

  IF p_client_id IS NULL OR p_account_number IS NULL THEN
    SET p_message = 'client_id et account_number obligatoires.';
    LEAVE main_acc;
  END IF;

  SELECT status, COALESCE(is_pep, 0), pep_approval_status
  INTO v_client_status, v_pep, v_pep_approval
  FROM clients WHERE id = p_client_id LIMIT 1;

  IF v_client_status IS NULL THEN
    SET p_message = 'Client introuvable.';
    LEAVE main_acc;
  END IF;

  IF v_client_status IN ('CLOSED', 'BLOCKED') THEN
    SET p_message = CONCAT('Client non eligible (status=', v_client_status, ').');
    LEAVE main_acc;
  END IF;

  IF EXISTS (SELECT 1 FROM accounts WHERE account_number = p_account_number) THEN
    SET p_message = 'account_number deja utilise.';
    LEAVE main_acc;
  END IF;

  IF p_account_manager_id IS NOT NULL THEN
    SELECT COUNT(*) INTO v_mgr_ok FROM users u WHERE u.id = p_account_manager_id;
    IF v_mgr_ok = 0 THEN
      SET p_message = 'account_manager_id introuvable dans users.';
      LEAVE main_acc;
    END IF;
  END IF;

  START TRANSACTION;

  INSERT INTO accounts (
    client_id, account_number, account_type,
    opening_balance, current_balance, currency, status, opened_at, account_manager_id
  ) VALUES (
    p_client_id, p_account_number, COALESCE(p_account_type, 'SAVINGS'),
    COALESCE(p_opening_balance, 0), COALESCE(p_opening_balance, 0),
    COALESCE(p_currency, 'XOF'), 'ACTIVE', CURDATE(), p_account_manager_id
  );

  SET p_account_id = LAST_INSERT_ID();
  COMMIT;

  SET p_status = 'SUCCESS';
  SET p_message = CONCAT(
    'Compte ouvert id=', p_account_id,
    IF(v_pep = 1 AND COALESCE(v_pep_approval, '') <> 'APPROVED',
       ' | ATTENTION: PEP sans pep_approval_status=APPROVED', '')
  );
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'PATCH_01_account_manager applique avec succes' AS status;
