-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 20, 2026 at 07:43 PM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `digi_aml`
--
CREATE DATABASE IF NOT EXISTS `digi_aml` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `digi_aml`;

DELIMITER $$
--
-- Procedures
--
DROP PROCEDURE IF EXISTS `sp_aml_engine_execute`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_aml_engine_execute` (IN `p_transaction_id` BIGINT)   main_proc:
BEGIN

    /* ========================================================
       1. TRANSACTION
       ======================================================== */

    DECLARE v_account_id BIGINT DEFAULT NULL;
    DECLARE v_client_id BIGINT DEFAULT NULL;

    DECLARE v_amount DECIMAL(18,2) DEFAULT 0;

    DECLARE v_transaction_type VARCHAR(50) DEFAULT NULL;
    DECLARE v_channel VARCHAR(50) DEFAULT NULL;

    DECLARE v_country VARCHAR(100) DEFAULT NULL;
    DECLARE v_country_from VARCHAR(100) DEFAULT NULL;
    DECLARE v_country_to VARCHAR(100) DEFAULT NULL;

    DECLARE v_transaction_date DATETIME DEFAULT NULL;
    DECLARE v_transaction_status VARCHAR(50) DEFAULT NULL;


    /* ========================================================
       2. PARAMETRES AML
       ======================================================== */

    DECLARE v_large_threshold DECIMAL(18,2) DEFAULT 10000000;

    DECLARE v_structuring_threshold DECIMAL(18,2) DEFAULT 10000000;
    DECLARE v_structuring_window_hours INT DEFAULT 24;

    DECLARE v_rapid_window_minutes INT DEFAULT 60;

    DECLARE v_dormant_days INT DEFAULT 90;

    DECLARE v_unusual_min_history INT DEFAULT 3;
    DECLARE v_unusual_multiplier DECIMAL(10,4) DEFAULT 3;

    DECLARE v_cash_min_transactions INT DEFAULT 5;
    DECLARE v_cash_ratio DECIMAL(10,4) DEFAULT 0.60;


    /* ========================================================
       3. SCORES DES REGLES
       ======================================================== */

    DECLARE v_score_large DECIMAL(5,2) DEFAULT 0;
    DECLARE v_score_structuring DECIMAL(5,2) DEFAULT 0;
    DECLARE v_score_rapid DECIMAL(5,2) DEFAULT 0;
    DECLARE v_score_dormant DECIMAL(5,2) DEFAULT 0;
    DECLARE v_score_unusual DECIMAL(5,2) DEFAULT 0;
    DECLARE v_score_cash DECIMAL(5,2) DEFAULT 0;
    DECLARE v_score_corridor DECIMAL(5,2) DEFAULT 0;


    /* ========================================================
       4. VARIABLES DE CALCUL
       ======================================================== */

    DECLARE v_previous_count INT DEFAULT 0;

    DECLARE v_previous_volume DECIMAL(18,2) DEFAULT 0;
    DECLARE v_previous_average DECIMAL(18,2) DEFAULT 0;

    DECLARE v_structuring_volume DECIMAL(18,2) DEFAULT 0;

    DECLARE v_rapid_count INT DEFAULT 0;

    DECLARE v_cash_count INT DEFAULT 0;
    DECLARE v_total_count INT DEFAULT 0;

    DECLARE v_last_transaction_date DATETIME DEFAULT NULL;

    DECLARE v_corridor_score DECIMAL(5,2) DEFAULT 0;


    /* ========================================================
       5. MATCH DES REGLES
       ======================================================== */

    DECLARE v_match_large TINYINT DEFAULT 0;
    DECLARE v_match_structuring TINYINT DEFAULT 0;
    DECLARE v_match_rapid TINYINT DEFAULT 0;
    DECLARE v_match_dormant TINYINT DEFAULT 0;
    DECLARE v_match_unusual TINYINT DEFAULT 0;
    DECLARE v_match_cash TINYINT DEFAULT 0;
    DECLARE v_match_corridor TINYINT DEFAULT 0;


    /* ========================================================
       6. SCORE FINAL
       ======================================================== */

    DECLARE v_risk_score DECIMAL(5,2) DEFAULT 0;
    DECLARE v_risk_level VARCHAR(30) DEFAULT 'LOW';

    DECLARE v_risk_level_id BIGINT DEFAULT NULL;


    /* ========================================================
       7. RECUPERATION TRANSACTION
       ======================================================== */

    SELECT
        t.account_id,
        a.client_id,
        COALESCE(t.amount,0),
        t.transaction_type,
        t.channel,
        t.country,
        t.country_from,
        t.country_to,
        t.transaction_date,
        t.transaction_status

    INTO
        v_account_id,
        v_client_id,
        v_amount,
        v_transaction_type,
        v_channel,
        v_country,
        v_country_from,
        v_country_to,
        v_transaction_date,
        v_transaction_status

    FROM transactions t

    INNER JOIN accounts a
        ON a.id = t.account_id

    WHERE t.id = p_transaction_id

    LIMIT 1;


    /* ========================================================
       TRANSACTION INTROUVABLE
       ======================================================== */

    IF v_account_id IS NULL
       OR v_client_id IS NULL
    THEN
        LEAVE main_proc;
    END IF;


    /* ========================================================
       TRANSACTION INELIGIBLE
       ======================================================== */

    IF UPPER(
        COALESCE(v_transaction_status,'COMPLETED')
       )
       IN
       (
           'CANCELLED',
           'CANCELED',
           'REVERSED',
           'VOID'
       )
    THEN
        LEAVE main_proc;
    END IF;


    /* ========================================================
       8. CHARGEMENT DES PARAMETRES
       
       UNE SEULE LECTURE DU REFERENTIEL.
       ======================================================== */

    SELECT

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'LARGE_AMOUNT'
                     AND arp.parameter_code = 'internal_threshold'
                    THEN CAST(arp.parameter_value AS DECIMAL(18,2))
                END
            ),
            10000000
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'STRUCTURING'
                     AND arp.parameter_code = 'individual_threshold'
                    THEN CAST(arp.parameter_value AS DECIMAL(18,2))
                END
            ),
            10000000
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'STRUCTURING'
                     AND arp.parameter_code = 'window_hours'
                    THEN CAST(arp.parameter_value AS UNSIGNED)
                END
            ),
            24
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'RAPID_TRANSFER'
                     AND arp.parameter_code = 'window_minutes'
                    THEN CAST(arp.parameter_value AS UNSIGNED)
                END
            ),
            60
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'DORMANT_ACCOUNT'
                     AND arp.parameter_code = 'inactivity_days'
                    THEN CAST(arp.parameter_value AS UNSIGNED)
                END
            ),
            90
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'UNUSUAL_VOLUME'
                     AND arp.parameter_code = 'minimum_history'
                    THEN CAST(arp.parameter_value AS UNSIGNED)
                END
            ),
            3
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'UNUSUAL_VOLUME'
                     AND arp.parameter_code = 'volume_multiplier'
                    THEN CAST(arp.parameter_value AS DECIMAL(10,4))
                END
            ),
            3
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'HIGH_CASH_ACTIVITY'
                     AND arp.parameter_code = 'minimum_transactions'
                    THEN CAST(arp.parameter_value AS UNSIGNED)
                END
            ),
            5
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN ar.rule_code = 'HIGH_CASH_ACTIVITY'
                     AND arp.parameter_code = 'cash_ratio'
                    THEN CAST(arp.parameter_value AS DECIMAL(10,4))
                END
            ),
            0.60
        )

    INTO

        v_large_threshold,
        v_structuring_threshold,
        v_structuring_window_hours,
        v_rapid_window_minutes,
        v_dormant_days,
        v_unusual_min_history,
        v_unusual_multiplier,
        v_cash_min_transactions,
        v_cash_ratio

    FROM aml_rules ar

    LEFT JOIN aml_rule_parameters arp
        ON arp.rule_id = ar.id
       AND arp.is_active = 1

    WHERE ar.active = 1;


    /* ========================================================
       9. CHARGEMENT DES SCORES
       ======================================================== */

    SELECT

        COALESCE(
            MAX(
                CASE
                    WHEN rule_code = 'LARGE_AMOUNT'
                    THEN score
                END
            ),
            0
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN rule_code = 'STRUCTURING'
                    THEN score
                END
            ),
            0
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN rule_code = 'RAPID_TRANSFER'
                    THEN score
                END
            ),
            0
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN rule_code = 'DORMANT_ACCOUNT'
                    THEN score
                END
            ),
            0
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN rule_code = 'UNUSUAL_VOLUME'
                    THEN score
                END
            ),
            0
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN rule_code = 'HIGH_CASH_ACTIVITY'
                    THEN score
                END
            ),
            0
        ),

        COALESCE(
            MAX(
                CASE
                    WHEN rule_code = 'HIGH_RISK_CORRIDOR'
                    THEN score
                END
            ),
            0
        )

    INTO

        v_score_large,
        v_score_structuring,
        v_score_rapid,
        v_score_dormant,
        v_score_unusual,
        v_score_cash,
        v_score_corridor

    FROM aml_rules

    WHERE active = 1;


    /* ========================================================
       10. LARGE_AMOUNT
       ======================================================== */

    SELECT
        IF(
            EXISTS
            (
                SELECT 1
                FROM aml_rules
                WHERE rule_code = 'LARGE_AMOUNT'
                  AND active = 1
            )
            AND v_amount >= v_large_threshold,
            1,
            0
        )
    INTO v_match_large;


    /* ========================================================
       11. STRUCTURING
       
       Le cumul porte sur les opérations précédentes du CLIENT
       via tous ses comptes.
       
       Cela exploite correctement le fait qu'un client peut
       posséder plusieurs comptes.
       ======================================================== */

    SELECT
        COALESCE(SUM(t.amount),0)

    INTO
        v_structuring_volume

    FROM transactions t

    INNER JOIN accounts a
        ON a.id = t.account_id

    WHERE a.client_id = v_client_id

      AND t.id <> p_transaction_id

      AND t.transaction_date >=
          DATE_SUB(
              v_transaction_date,
              INTERVAL v_structuring_window_hours HOUR
          )

      AND t.transaction_date <= v_transaction_date

      AND t.amount < v_structuring_threshold

      AND UPPER(
          COALESCE(t.transaction_status,'COMPLETED')
      ) NOT IN
      (
          'CANCELLED',
          'CANCELED',
          'REVERSED',
          'VOID'
      );


    SET v_match_structuring =
        IF(
            EXISTS
            (
                SELECT 1
                FROM aml_rules
                WHERE rule_code = 'STRUCTURING'
                  AND active = 1
            )
            AND v_amount < v_structuring_threshold
            AND
            (
                v_structuring_volume + v_amount
            ) >= v_structuring_threshold,
            1,
            0
        );


    /* ========================================================
       12. RAPID_TRANSFER
       ======================================================== */

    SELECT
        COUNT(*)

    INTO
        v_rapid_count

    FROM transactions t

    INNER JOIN accounts a
        ON a.id = t.account_id

    WHERE a.client_id = v_client_id

      AND t.id <> p_transaction_id

      AND t.transaction_date >=
          DATE_SUB(
              v_transaction_date,
              INTERVAL v_rapid_window_minutes MINUTE
          )

      AND t.transaction_date <= v_transaction_date

      AND UPPER(
          COALESCE(t.transaction_type,'')
      )
      IN
      (
          'TRANSFER_IN',
          'TRANSFER_OUT'
      )

      AND UPPER(
          COALESCE(v_transaction_type,'')
      )
      IN
      (
          'TRANSFER_IN',
          'TRANSFER_OUT'
      )

      AND UPPER(
          COALESCE(t.transaction_status,'COMPLETED')
      ) NOT IN
      (
          'CANCELLED',
          'CANCELED',
          'REVERSED',
          'VOID'
      );


    SET v_match_rapid =
        IF(
            EXISTS
            (
                SELECT 1
                FROM aml_rules
                WHERE rule_code = 'RAPID_TRANSFER'
                  AND active = 1
            )
            AND v_rapid_count > 0,
            1,
            0
        );


    /* ========================================================
       13. DORMANT_ACCOUNT
       
       On cherche la dernière activité réelle du client
       sur l'ensemble de ses comptes.
       ======================================================== */

    SELECT
        MAX(t.transaction_date)

    INTO
        v_last_transaction_date

    FROM transactions t

    INNER JOIN accounts a
        ON a.id = t.account_id

    WHERE a.client_id = v_client_id

      AND t.transaction_date < v_transaction_date

      AND UPPER(
          COALESCE(t.transaction_status,'COMPLETED')
      ) NOT IN
      (
          'CANCELLED',
          'CANCELED',
          'REVERSED',
          'VOID'
      );


    SET v_match_dormant =
        IF(
            EXISTS
            (
                SELECT 1
                FROM aml_rules
                WHERE rule_code = 'DORMANT_ACCOUNT'
                  AND active = 1
            )
            AND
            (
                v_last_transaction_date IS NULL
                OR v_last_transaction_date <=
                   DATE_SUB(
                       v_transaction_date,
                       INTERVAL v_dormant_days DAY
                   )
            ),
            1,
            0
        );


    /* ========================================================
       14. UNUSUAL_VOLUME
       ======================================================== */

    SELECT
        COUNT(*),
        COALESCE(AVG(t.amount),0)

    INTO
        v_previous_count,
        v_previous_average

    FROM transactions t

    INNER JOIN accounts a
        ON a.id = t.account_id

    WHERE a.client_id = v_client_id

      AND t.transaction_date < v_transaction_date

      AND t.transaction_date >=
          DATE_SUB(
              v_transaction_date,
              INTERVAL 30 DAY
          )

      AND UPPER(
          COALESCE(t.transaction_status,'COMPLETED')
      ) NOT IN
      (
          'CANCELLED',
          'CANCELED',
          'REVERSED',
          'VOID'
      );


    SET v_match_unusual =
        IF(
            EXISTS
            (
                SELECT 1
                FROM aml_rules
                WHERE rule_code = 'UNUSUAL_VOLUME'
                  AND active = 1
            )
            AND v_previous_count >= v_unusual_min_history
            AND v_previous_average > 0
            AND v_amount >=
                (
                    v_previous_average *
                    v_unusual_multiplier
                ),
            1,
            0
        );


    /* ========================================================
       15. HIGH_CASH_ACTIVITY
       ======================================================== */

    SELECT

        COUNT(*),

        COALESCE(
            SUM(
                CASE
                    WHEN
                        UPPER(COALESCE(t.channel,'')) IN
                        (
                            'CASH',
                            'ATM',
                            'AGENCY'
                        )

                        OR

                        UPPER(
                            COALESCE(t.transaction_type,'')
                        )
                        IN
                        (
                            'CASH_DEPOSIT',
                            'CASH_WITHDRAWAL',
                            'DEPOSIT',
                            'WITHDRAWAL'
                        )

                    THEN 1
                    ELSE 0
                END
            ),
            0
        )

    INTO
        v_total_count,
        v_cash_count

    FROM transactions t

    INNER JOIN accounts a
        ON a.id = t.account_id

    WHERE a.client_id = v_client_id

      AND t.transaction_date <= v_transaction_date

      AND t.transaction_date >=
          DATE_SUB(
              v_transaction_date,
              INTERVAL 30 DAY
          )

      AND UPPER(
          COALESCE(t.transaction_status,'COMPLETED')
      ) NOT IN
      (
          'CANCELLED',
          'CANCELED',
          'REVERSED',
          'VOID'
      );


    SET v_match_cash =
        IF(
            EXISTS
            (
                SELECT 1
                FROM aml_rules
                WHERE rule_code = 'HIGH_CASH_ACTIVITY'
                  AND active = 1
            )
            AND v_total_count >= v_cash_min_transactions
            AND v_total_count > 0
            AND
            (
                v_cash_count / v_total_count
            ) >= v_cash_ratio,
            1,
            0
        );


    /* ========================================================
       16. HIGH_RISK_CORRIDOR
       
       Aucune supposition sur le pays.
       Le corridor doit exister explicitement dans
       aml_risk_corridors.
       ======================================================== */

    SELECT
        COALESCE(
            MAX(c.risk_score),
            0
        )

    INTO
        v_corridor_score

    FROM aml_risk_corridors c

    WHERE c.active = 1

      AND UPPER(TRIM(c.country_from))
          =
          UPPER(
              TRIM(
                  COALESCE(
                      v_country_from,
                      v_country
                  )
              )
          )

      AND UPPER(TRIM(c.country_to))
          =
          UPPER(
              TRIM(
                  COALESCE(
                      v_country_to,
                      v_country
                  )
              )
          );


    SET v_match_corridor =
        IF(
            EXISTS
            (
                SELECT 1
                FROM aml_rules
                WHERE rule_code = 'HIGH_RISK_CORRIDOR'
                  AND active = 1
            )
            AND v_corridor_score > 0,
            1,
            0
        );


    /* ========================================================
       17. SCORE DE CHAQUE REGLE
       
       UPDATE/INSERT :
       - MATCH => assessment présente
       - NO_MATCH => ancienne assessment AML supprimée
       
       Source strictement AML_RULE_ENGINE.
       ======================================================== */


    /* --------------------------------------------------------
       LARGE_AMOUNT
       -------------------------------------------------------- */

    INSERT INTO rule_executions
    (
        rule_id,
        transaction_id,
        execution_result
    )

    SELECT
        ar.id,
        p_transaction_id,
        IF(v_match_large = 1,'MATCH','NO_MATCH')

    FROM aml_rules ar

    WHERE ar.rule_code = 'LARGE_AMOUNT'
      AND ar.active = 1

    ON DUPLICATE KEY UPDATE
        execution_result =
            VALUES(execution_result),
        executed_at =
            CURRENT_TIMESTAMP;


    IF v_match_large = 1 THEN

        INSERT INTO risk_assessments
        (
            client_id,
            transaction_id,
            risk_type,
            score,
            risk_level,
            reason,
            source
        )

        SELECT
            v_client_id,
            p_transaction_id,
            'LARGE_AMOUNT',
            LEAST(100,v_score_large),

            CASE
                WHEN v_score_large >= 80 THEN 'CRITICAL'
                WHEN v_score_large >= 60 THEN 'HIGH'
                WHEN v_score_large >= 30 THEN 'MEDIUM'
                ELSE 'LOW'
            END,

            CONCAT(
                'Transaction de montant ',
                FORMAT(v_amount,2),
                ' XOF supérieure ou égale au seuil configuré de ',
                FORMAT(v_large_threshold,2),
                ' XOF.'
            ),

            'AML_RULE_ENGINE'

        FROM aml_rules ar

        WHERE ar.rule_code = 'LARGE_AMOUNT'
          AND ar.active = 1

        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            risk_level = VALUES(risk_level),
            reason = VALUES(reason);

    ELSE

        DELETE FROM risk_assessments

        WHERE transaction_id = p_transaction_id
          AND risk_type = 'LARGE_AMOUNT'
          AND source = 'AML_RULE_ENGINE';

    END IF;


    /* --------------------------------------------------------
       STRUCTURING
       -------------------------------------------------------- */

    INSERT INTO rule_executions
    (
        rule_id,
        transaction_id,
        execution_result
    )

    SELECT
        ar.id,
        p_transaction_id,
        IF(v_match_structuring = 1,'MATCH','NO_MATCH')

    FROM aml_rules ar

    WHERE ar.rule_code = 'STRUCTURING'
      AND ar.active = 1

    ON DUPLICATE KEY UPDATE
        execution_result = VALUES(execution_result),
        executed_at = CURRENT_TIMESTAMP;


    IF v_match_structuring = 1 THEN

        INSERT INTO risk_assessments
        (
            client_id,
            transaction_id,
            risk_type,
            score,
            risk_level,
            reason,
            source
        )

        VALUES
        (
            v_client_id,
            p_transaction_id,
            'STRUCTURING',
            LEAST(100,v_score_structuring),

            CASE
                WHEN v_score_structuring >= 80 THEN 'CRITICAL'
                WHEN v_score_structuring >= 60 THEN 'HIGH'
                WHEN v_score_structuring >= 30 THEN 'MEDIUM'
                ELSE 'LOW'
            END,

            CONCAT(
                'Fractionnement détecté : cumul client de ',
                FORMAT(
                    v_structuring_volume + v_amount,
                    2
                ),
                ' XOF sur ',
                v_structuring_window_hours,
                ' heures, avec opérations individuelles sous ',
                FORMAT(v_structuring_threshold,2),
                ' XOF.'
            ),

            'AML_RULE_ENGINE'
        )

        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            risk_level = VALUES(risk_level),
            reason = VALUES(reason);

    ELSE

        DELETE FROM risk_assessments

        WHERE transaction_id = p_transaction_id
          AND risk_type = 'STRUCTURING'
          AND source = 'AML_RULE_ENGINE';

    END IF;


    /* --------------------------------------------------------
       RAPID_TRANSFER
       -------------------------------------------------------- */

    INSERT INTO rule_executions
    (
        rule_id,
        transaction_id,
        execution_result
    )

    SELECT
        ar.id,
        p_transaction_id,
        IF(v_match_rapid = 1,'MATCH','NO_MATCH')

    FROM aml_rules ar

    WHERE ar.rule_code = 'RAPID_TRANSFER'
      AND ar.active = 1

    ON DUPLICATE KEY UPDATE
        execution_result = VALUES(execution_result),
        executed_at = CURRENT_TIMESTAMP;


    IF v_match_rapid = 1 THEN

        INSERT INTO risk_assessments
        (
            client_id,
            transaction_id,
            risk_type,
            score,
            risk_level,
            reason,
            source
        )

        VALUES
        (
            v_client_id,
            p_transaction_id,
            'RAPID_TRANSFER',
            LEAST(100,v_score_rapid),

            CASE
                WHEN v_score_rapid >= 80 THEN 'CRITICAL'
                WHEN v_score_rapid >= 60 THEN 'HIGH'
                WHEN v_score_rapid >= 30 THEN 'MEDIUM'
                ELSE 'LOW'
            END,

            CONCAT(
                v_rapid_count,
                ' transfert(s) détecté(s) dans une fenêtre de ',
                v_rapid_window_minutes,
                ' minutes.'
            ),

            'AML_RULE_ENGINE'
        )

        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            risk_level = VALUES(risk_level),
            reason = VALUES(reason);

    ELSE

        DELETE FROM risk_assessments

        WHERE transaction_id = p_transaction_id
          AND risk_type = 'RAPID_TRANSFER'
          AND source = 'AML_RULE_ENGINE';

    END IF;


    /* --------------------------------------------------------
       DORMANT_ACCOUNT
       -------------------------------------------------------- */

    INSERT INTO rule_executions
    (
        rule_id,
        transaction_id,
        execution_result
    )

    SELECT
        ar.id,
        p_transaction_id,
        IF(v_match_dormant = 1,'MATCH','NO_MATCH')

    FROM aml_rules ar

    WHERE ar.rule_code = 'DORMANT_ACCOUNT'
      AND ar.active = 1

    ON DUPLICATE KEY UPDATE
        execution_result = VALUES(execution_result),
        executed_at = CURRENT_TIMESTAMP;


    IF v_match_dormant = 1 THEN

        INSERT INTO risk_assessments
        (
            client_id,
            transaction_id,
            risk_type,
            score,
            risk_level,
            reason,
            source
        )

        VALUES
        (
            v_client_id,
            p_transaction_id,
            'DORMANT_ACCOUNT',
            LEAST(100,v_score_dormant),

            CASE
                WHEN v_score_dormant >= 80 THEN 'CRITICAL'
                WHEN v_score_dormant >= 60 THEN 'HIGH'
                WHEN v_score_dormant >= 30 THEN 'MEDIUM'
                ELSE 'LOW'
            END,

            CONCAT(
                'Réactivation après ',
                v_dormant_days,
                ' jours ou plus sans activité précédente.'
            ),

            'AML_RULE_ENGINE'
        )

        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            risk_level = VALUES(risk_level),
            reason = VALUES(reason);

    ELSE

        DELETE FROM risk_assessments

        WHERE transaction_id = p_transaction_id
          AND risk_type = 'DORMANT_ACCOUNT'
          AND source = 'AML_RULE_ENGINE';

    END IF;


    /* --------------------------------------------------------
       UNUSUAL_VOLUME
       -------------------------------------------------------- */

    INSERT INTO rule_executions
    (
        rule_id,
        transaction_id,
        execution_result
    )

    SELECT
        ar.id,
        p_transaction_id,
        IF(v_match_unusual = 1,'MATCH','NO_MATCH')

    FROM aml_rules ar

    WHERE ar.rule_code = 'UNUSUAL_VOLUME'
      AND ar.active = 1

    ON DUPLICATE KEY UPDATE
        execution_result = VALUES(execution_result),
        executed_at = CURRENT_TIMESTAMP;


    IF v_match_unusual = 1 THEN

        INSERT INTO risk_assessments
        (
            client_id,
            transaction_id,
            risk_type,
            score,
            risk_level,
            reason,
            source
        )

        VALUES
        (
            v_client_id,
            p_transaction_id,
            'UNUSUAL_VOLUME',
            LEAST(100,v_score_unusual),

            CASE
                WHEN v_score_unusual >= 80 THEN 'CRITICAL'
                WHEN v_score_unusual >= 60 THEN 'HIGH'
                WHEN v_score_unusual >= 30 THEN 'MEDIUM'
                ELSE 'LOW'
            END,

            CONCAT(
                'Montant de ',
                FORMAT(v_amount,2),
                ' XOF supérieur ou égal à ',
                v_unusual_multiplier,
                ' fois la moyenne historique de ',
                FORMAT(v_previous_average,2),
                ' XOF sur 30 jours.'
            ),

            'AML_RULE_ENGINE'
        )

        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            risk_level = VALUES(risk_level),
            reason = VALUES(reason);

    ELSE

        DELETE FROM risk_assessments

        WHERE transaction_id = p_transaction_id
          AND risk_type = 'UNUSUAL_VOLUME'
          AND source = 'AML_RULE_ENGINE';

    END IF;


    /* --------------------------------------------------------
       HIGH_CASH_ACTIVITY
       -------------------------------------------------------- */

    INSERT INTO rule_executions
    (
        rule_id,
        transaction_id,
        execution_result
    )

    SELECT
        ar.id,
        p_transaction_id,
        IF(v_match_cash = 1,'MATCH','NO_MATCH')

    FROM aml_rules ar

    WHERE ar.rule_code = 'HIGH_CASH_ACTIVITY'
      AND ar.active = 1

    ON DUPLICATE KEY UPDATE
        execution_result = VALUES(execution_result),
        executed_at = CURRENT_TIMESTAMP;


    IF v_match_cash = 1 THEN

        INSERT INTO risk_assessments
        (
            client_id,
            transaction_id,
            risk_type,
            score,
            risk_level,
            reason,
            source
        )

        VALUES
        (
            v_client_id,
            p_transaction_id,
            'HIGH_CASH_ACTIVITY',
            LEAST(100,v_score_cash),

            CASE
                WHEN v_score_cash >= 80 THEN 'CRITICAL'
                WHEN v_score_cash >= 60 THEN 'HIGH'
                WHEN v_score_cash >= 30 THEN 'MEDIUM'
                ELSE 'LOW'
            END,

            CONCAT(
                'Activité espèces élevée : ',
                v_cash_count,
                ' opération(s) assimilée(s) au cash sur ',
                v_total_count,
                ' transaction(s), soit ',
                ROUND(
                    (v_cash_count / v_total_count) * 100,
                    2
                ),
                ' %.'
            ),

            'AML_RULE_ENGINE'
        )

        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            risk_level = VALUES(risk_level),
            reason = VALUES(reason);

    ELSE

        DELETE FROM risk_assessments

        WHERE transaction_id = p_transaction_id
          AND risk_type = 'HIGH_CASH_ACTIVITY'
          AND source = 'AML_RULE_ENGINE';

    END IF;


    /* --------------------------------------------------------
       HIGH_RISK_CORRIDOR
       -------------------------------------------------------- */

    INSERT INTO rule_executions
    (
        rule_id,
        transaction_id,
        execution_result
    )

    SELECT
        ar.id,
        p_transaction_id,
        IF(v_match_corridor = 1,'MATCH','NO_MATCH')

    FROM aml_rules ar

    WHERE ar.rule_code = 'HIGH_RISK_CORRIDOR'
      AND ar.active = 1

    ON DUPLICATE KEY UPDATE
        execution_result = VALUES(execution_result),
        executed_at = CURRENT_TIMESTAMP;


    IF v_match_corridor = 1 THEN

        INSERT INTO risk_assessments
        (
            client_id,
            transaction_id,
            risk_type,
            score,
            risk_level,
            reason,
            source
        )

        VALUES
        (
            v_client_id,
            p_transaction_id,
            'HIGH_RISK_CORRIDOR',

            LEAST(
                100,
                CASE
                    WHEN v_corridor_score > 0
                    THEN v_corridor_score
                    ELSE v_score_corridor
                END
            ),

            CASE
                WHEN
                    LEAST(
                        100,
                        CASE
                            WHEN v_corridor_score > 0
                            THEN v_corridor_score
                            ELSE v_score_corridor
                        END
                    ) >= 80
                THEN 'CRITICAL'

                WHEN
                    LEAST(
                        100,
                        CASE
                            WHEN v_corridor_score > 0
                            THEN v_corridor_score
                            ELSE v_score_corridor
                        END
                    ) >= 60
                THEN 'HIGH'

                WHEN
                    LEAST(
                        100,
                        CASE
                            WHEN v_corridor_score > 0
                            THEN v_corridor_score
                            ELSE v_score_corridor
                        END
                    ) >= 30
                THEN 'MEDIUM'

                ELSE 'LOW'
            END,

            CONCAT(
                'Corridor géographique configuré comme sensible : ',
                COALESCE(v_country_from,v_country),
                ' -> ',
                COALESCE(v_country_to,v_country),
                '.'
            ),

            'AML_RULE_ENGINE'
        )

        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            risk_level = VALUES(risk_level),
            reason = VALUES(reason);

    ELSE

        DELETE FROM risk_assessments

        WHERE transaction_id = p_transaction_id
          AND risk_type = 'HIGH_RISK_CORRIDOR'
          AND source = 'AML_RULE_ENGINE';

    END IF;


    /* ========================================================
       18. SCORE GLOBAL
       
       IMPORTANT :
       LE SCORE EST CALCULE PUIS PLAFONNE A 100.
       ======================================================== */

    SELECT
        LEAST(
            100,
            COALESCE(
                SUM(score),
                0
            )
        )

    INTO
        v_risk_score

    FROM risk_assessments

    WHERE transaction_id = p_transaction_id

      AND source = 'AML_RULE_ENGINE';


    /* ========================================================
       19. NIVEAU DE RISQUE
       ======================================================== */

    IF v_risk_score >= 80 THEN

        SET v_risk_level = 'CRITICAL';

    ELSEIF v_risk_score >= 60 THEN

        SET v_risk_level = 'HIGH';

    ELSEIF v_risk_score >= 30 THEN

        SET v_risk_level = 'MEDIUM';

    ELSE

        SET v_risk_level = 'LOW';

    END IF;


    /* ========================================================
       20. ID NIVEAU
       ======================================================== */

    SELECT
        id

    INTO
        v_risk_level_id

    FROM risk_levels

    WHERE code = v_risk_level

    LIMIT 1;


    /* ========================================================
       21. ALERTE AML
       
       Une alerte existante n'est jamais supprimée :
       elle constitue une trace d'investigation.

       Mais si elle existe encore et que le moteur confirme
       le risque, son score est actualisé.
       ======================================================== */

    IF v_risk_score >= 30 THEN

        INSERT INTO alerts
        (
            reference,
            client_id,
            transaction_id,
            alert_type,
            priority,
            status,
            final_score,
            title,
            description
        )

        VALUES
        (
            CONCAT(
                'AML-',
                p_transaction_id
            ),

            v_client_id,

            p_transaction_id,

            'AML_RULE_ENGINE',

            CASE
                WHEN v_risk_score >= 80 THEN 'CRITICAL'
                WHEN v_risk_score >= 60 THEN 'HIGH'
                ELSE 'MEDIUM'
            END,

            'OPEN',

            LEAST(100,v_risk_score),

            CONCAT(
                'Alerte AML - risque ',
                v_risk_level
            ),

            CONCAT(
                'Evaluation automatique du moteur AML. ',
                'Score plafonné : ',
                v_risk_score,
                '/100.'
            )
        )

        ON DUPLICATE KEY UPDATE

            final_score =
                LEAST(
                    100,
                    VALUES(final_score)
                ),

            priority =
                VALUES(priority),

            title =
                VALUES(title),

            description =
                VALUES(description);

    END IF;


    /* ========================================================
       22. MISE A JOUR DU RISQUE CLIENT
       
       On conserve ici le niveau correspondant au PLUS HAUT
       score transactionnel AML observé pour le client.

       Cela évite qu'une transaction faible fasse disparaître
       un risque historique plus élevé.
       ======================================================== */

    UPDATE clients

    SET

        risk_score =
            LEAST(
                100,
                COALESCE(
                    (
                        SELECT
                            MAX(
                                LEAST(
                                    100,
                                    ra.score
                                )
                            )

                        FROM risk_assessments ra

                        WHERE ra.client_id = v_client_id
                    ),
                    0
                )
            ),

        risk_level_id =
        (
            SELECT
                rl.id

            FROM risk_levels rl

            WHERE rl.code =
            CASE

                WHEN
                    LEAST(
                        100,
                        COALESCE(
                            (
                                SELECT
                                    MAX(
                                        LEAST(
                                            100,
                                            ra2.score
                                        )
                                    )

                                FROM risk_assessments ra2

                                WHERE ra2.client_id =
                                      v_client_id
                            ),
                            0
                        )
                    ) >= 80
                THEN 'CRITICAL'

                WHEN
                    LEAST(
                        100,
                        COALESCE(
                            (
                                SELECT
                                    MAX(
                                        LEAST(
                                            100,
                                            ra3.score
                                        )
                                    )

                                FROM risk_assessments ra3

                                WHERE ra3.client_id =
                                      v_client_id
                            ),
                            0
                        )
                    ) >= 60
                THEN 'HIGH'

                WHEN
                    LEAST(
                        100,
                        COALESCE(
                            (
                                SELECT
                                    MAX(
                                        LEAST(
                                            100,
                                            ra4.score
                                        )
                                    )

                                FROM risk_assessments ra4

                                WHERE ra4.client_id =
                                      v_client_id
                            ),
                            0
                        )
                    ) >= 30
                THEN 'MEDIUM'

                ELSE 'LOW'

            END

            LIMIT 1
        )

    WHERE id = v_client_id;


END$$

DROP PROCEDURE IF EXISTS `sp_aml_evaluate_transaction`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_aml_evaluate_transaction` (IN `p_transaction_id` BIGINT)   BEGIN

    CALL sp_aml_engine_execute(
        p_transaction_id
    );

    SELECT

        'SUCCESS' AS status,

        t.id AS transaction_id,

        a.client_id,

        t.amount,

        LEAST(
            100,
            COALESCE(
                (
                    SELECT
                        SUM(ra.score)

                    FROM risk_assessments ra

                    WHERE ra.transaction_id = t.id

                      AND ra.source =
                          'AML_RULE_ENGINE'
                ),
                0
            )
        ) AS risk_score,

        CASE

            WHEN
                LEAST(
                    100,
                    COALESCE(
                        (
                            SELECT
                                SUM(ra.score)

                            FROM risk_assessments ra

                            WHERE ra.transaction_id = t.id

                              AND ra.source =
                                  'AML_RULE_ENGINE'
                        ),
                        0
                    )
                ) >= 80
            THEN 'CRITICAL'

            WHEN
                LEAST(
                    100,
                    COALESCE(
                        (
                            SELECT
                                SUM(ra.score)

                            FROM risk_assessments ra

                            WHERE ra.transaction_id = t.id

                              AND ra.source =
                                  'AML_RULE_ENGINE'
                        ),
                        0
                    )
                ) >= 60
            THEN 'HIGH'

            WHEN
                LEAST(
                    100,
                    COALESCE(
                        (
                            SELECT
                                SUM(ra.score)

                            FROM risk_assessments ra

                            WHERE ra.transaction_id = t.id

                              AND ra.source =
                                  'AML_RULE_ENGINE'
                        ),
                        0
                    )
                ) >= 30
            THEN 'MEDIUM'

            ELSE 'LOW'

        END AS risk_level,

        (
            SELECT COUNT(*)
            FROM rule_executions re
            WHERE re.transaction_id = t.id
        ) AS rule_executions,

        (
            SELECT COUNT(*)
            FROM risk_assessments ra
            WHERE ra.transaction_id = t.id
              AND ra.source = 'AML_RULE_ENGINE'
        ) AS risk_assessments,

        (
            SELECT COUNT(*)
            FROM alerts al
            WHERE al.transaction_id = t.id
              AND al.alert_type = 'AML_RULE_ENGINE'
        ) AS alerts

    FROM transactions t

    INNER JOIN accounts a
        ON a.id = t.account_id

    WHERE t.id = p_transaction_id

    LIMIT 1;

END$$

DROP PROCEDURE IF EXISTS `sp_aml_evaluate_transaction_silent`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_aml_evaluate_transaction_silent` (IN `p_transaction_id` BIGINT)   main_proc:
BEGIN

-- ========================================================
-- VARIABLES TRANSACTION
-- ========================================================

DECLARE v_client_id BIGINT DEFAULT NULL;
DECLARE v_account_id BIGINT DEFAULT NULL;

DECLARE v_amount DECIMAL(18,2) DEFAULT 0;
DECLARE v_transaction_type VARCHAR(50) DEFAULT NULL;
DECLARE v_channel VARCHAR(50) DEFAULT NULL;

DECLARE v_country VARCHAR(100) DEFAULT NULL;
DECLARE v_country_from VARCHAR(100) DEFAULT NULL;
DECLARE v_country_to VARCHAR(100) DEFAULT NULL;

DECLARE v_transaction_date DATETIME DEFAULT NULL;
DECLARE v_transaction_status VARCHAR(50) DEFAULT NULL;

-- ========================================================
-- VARIABLES REGLE
-- ========================================================

DECLARE v_rule_id BIGINT DEFAULT NULL;
DECLARE v_rule_score DECIMAL(5,2) DEFAULT 0;
DECLARE v_rule_severity VARCHAR(30) DEFAULT 'MEDIUM';

DECLARE v_match INT DEFAULT 0;

DECLARE v_existing_execution INT DEFAULT 0;
DECLARE v_existing_assessment INT DEFAULT 0;
DECLARE v_existing_alert INT DEFAULT 0;

-- ========================================================
-- VARIABLES CALCULS AML
-- ========================================================

DECLARE v_previous_count INT DEFAULT 0;
DECLARE v_previous_volume DECIMAL(18,2) DEFAULT 0;

DECLARE v_cash_count INT DEFAULT 0;
DECLARE v_total_count INT DEFAULT 0;

DECLARE v_structuring_volume DECIMAL(18,2) DEFAULT 0;

DECLARE v_risk_score DECIMAL(5,2) DEFAULT 0;
DECLARE v_risk_level VARCHAR(30) DEFAULT 'LOW';

DECLARE v_risk_level_id BIGINT DEFAULT NULL;

-- ========================================================
-- 1. RECUPERATION DE LA TRANSACTION
-- ========================================================

SELECT
t.account_id,
a.client_id,
t.amount,
t.transaction_type,
t.channel,
t.country,
t.country_from,
t.country_to,
t.transaction_date,
t.transaction_status

INTO
v_account_id,
v_client_id,
v_amount,
v_transaction_type,
v_channel,
v_country,
v_country_from,
v_country_to,
v_transaction_date,
v_transaction_status

FROM transactions t

INNER JOIN accounts a
ON a.id = t.account_id

WHERE t.id = p_transaction_id

LIMIT 1;

-- ========================================================
-- 2. TRANSACTION INTROUVABLE
-- ========================================================

IF v_client_id IS NULL THEN

SELECT
'ERROR' AS status,
'Transaction introuvable' AS message,
p_transaction_id AS transaction_id;

LEAVE main_proc;

END IF;

-- ========================================================
-- 3. TRANSACTION ANNULEE / REVOQUEE
-- ========================================================
--

-- On conserve la transaction dans l'historique.
-- Mais elle ne doit pas générer une nouvelle analyse AML.
--

-- Les valeurs NULL sont considérées comme valides pour
-- préserver la compatibilité avec l'historique existant.
-- ========================================================

IF UPPER(COALESCE(v_transaction_status, 'VALID'))
IN ('CANCELLED', 'CANCELED', 'REVERSED', 'VOID')
THEN

SELECT
'SKIPPED' AS status,
'Transaction annulée ou révoquée - aucune analyse AML' AS message,
p_transaction_id AS transaction_id;

LEAVE main_proc;

END IF;

-- ========================================================
-- 4. LARGE_AMOUNT
-- ========================================================
--

-- Condition référentielle :
-- amount >= 5 000 000 XOF
-- ========================================================

SET v_rule_id = NULL;
SET v_rule_score = 0;
SET v_match = 0;

SELECT
id,
score,
severity

INTO
v_rule_id,
v_rule_score,
v_rule_severity

FROM aml_rules

WHERE rule_code = 'LARGE_AMOUNT'
AND active = TRUE

LIMIT 1;

IF v_rule_id IS NOT NULL THEN

SET v_match =
IF(v_amount >= 5000000, 1, 0);

SELECT COUNT(*)
INTO v_existing_execution

FROM rule_executions

WHERE rule_id = v_rule_id
AND transaction_id = p_transaction_id;

IF v_existing_execution = 0 THEN

INSERT INTO rule_executions
(
rule_id,
transaction_id,
execution_result
)

VALUES
(
v_rule_id,
p_transaction_id,
IF(v_match = 1, 'MATCH', 'NO_MATCH')
);

END IF;

IF v_match = 1 THEN

SELECT COUNT(*)
INTO v_existing_assessment

FROM risk_assessments

WHERE transaction_id = p_transaction_id
AND risk_type = 'LARGE_AMOUNT';

IF v_existing_assessment = 0 THEN

INSERT INTO risk_assessments
(
client_id,
transaction_id,
risk_type,
score,
risk_level,
reason,
source
)

VALUES
(
v_client_id,
p_transaction_id,
'LARGE_AMOUNT',
v_rule_score,

CASE
WHEN v_rule_score >= 80 THEN 'CRITICAL'
WHEN v_rule_score >= 60 THEN 'HIGH'
WHEN v_rule_score >= 30 THEN 'MEDIUM'
ELSE 'LOW'
END,

CONCAT(
'Transaction de montant élevé : ',
FORMAT(v_amount, 2),
' XOF.'
),

'AML_RULE_ENGINE'
);

END IF;

END IF;

END IF;

-- ========================================================
-- 5. STRUCTURING
-- ========================================================
--

-- Condition référentielle :
--

-- cumul des transactions sur 24h >= 5 000 000 XOF
--

-- ET transaction individuelle < 5 000 000 XOF
--

-- L'idée est de détecter le fractionnement :
-- plusieurs opérations sous le seuil individuel dont
-- le cumul devient significatif.
-- ========================================================

SET v_rule_id = NULL;
SET v_rule_score = 0;
SET v_match = 0;
SET v_structuring_volume = 0;

SELECT
id,
score,
severity

INTO
v_rule_id,
v_rule_score,
v_rule_severity

FROM aml_rules

WHERE rule_code = 'STRUCTURING'
AND active = TRUE

LIMIT 1;

IF v_rule_id IS NOT NULL THEN

SELECT
COALESCE(SUM(amount), 0)

INTO
v_structuring_volume

FROM transactions

WHERE account_id = v_account_id

AND transaction_date <= v_transaction_date

AND transaction_date >=
DATE_SUB(
v_transaction_date,
INTERVAL 24 HOUR
)

AND amount < 5000000

AND UPPER(
COALESCE(transaction_status, 'VALID')
) NOT IN
(
'CANCELLED',
'CANCELED',
'REVERSED',
'VOID'
);

SET v_match =
IF(
v_amount < 5000000
AND v_structuring_volume >= 5000000,
1,
0
);

SELECT COUNT(*)
INTO v_existing_execution

FROM rule_executions

WHERE rule_id = v_rule_id
AND transaction_id = p_transaction_id;

IF v_existing_execution = 0 THEN

INSERT INTO rule_executions
(
rule_id,
transaction_id,
execution_result
)

VALUES
(
v_rule_id,
p_transaction_id,
IF(v_match = 1, 'MATCH', 'NO_MATCH')
);

END IF;

IF v_match = 1 THEN

SELECT COUNT(*)
INTO v_existing_assessment

FROM risk_assessments

WHERE transaction_id = p_transaction_id
AND risk_type = 'STRUCTURING';

IF v_existing_assessment = 0 THEN

INSERT INTO risk_assessments
(
client_id,
transaction_id,
risk_type,
score,
risk_level,
reason,
source
)

VALUES
(
v_client_id,
p_transaction_id,
'STRUCTURING',
v_rule_score,

CASE
WHEN v_rule_score >= 80 THEN 'CRITICAL'
WHEN v_rule_score >= 60 THEN 'HIGH'
WHEN v_rule_score >= 30 THEN 'MEDIUM'
ELSE 'LOW'
END,

CONCAT(
'Fractionnement détecté : cumul de ',
FORMAT(v_structuring_volume, 2),
' XOF sur 24 heures avec des transactions individuelles sous 5 000 000 XOF.'
),

'AML_RULE_ENGINE'
);

END IF;

END IF;

END IF;

-- ========================================================
-- 6. RAPID_TRANSFER
-- ========================================================
--

-- Condition référentielle :
-- deux mouvements TRANSFER_IN / TRANSFER_OUT
-- rapprochés de 60 minutes ou moins.
-- ========================================================

SET v_rule_id = NULL;
SET v_rule_score = 0;
SET v_match = 0;

SELECT
id,
score,
severity

INTO
v_rule_id,
v_rule_score,
v_rule_severity

FROM aml_rules

WHERE rule_code = 'RAPID_TRANSFER'
AND active = TRUE

LIMIT 1;

IF v_rule_id IS NOT NULL THEN

SELECT COUNT(*)

INTO v_previous_count

FROM transactions

WHERE account_id = v_account_id

AND transaction_date < v_transaction_date

AND transaction_date >=
DATE_SUB(
v_transaction_date,
INTERVAL 60 MINUTE
)

AND transaction_type IN
(
'TRANSFER_IN',
'TRANSFER_OUT'
)

AND UPPER(
COALESCE(transaction_status, 'VALID')
) NOT IN
(
'CANCELLED',
'CANCELED',
'REVERSED',
'VOID'
);

SET v_match =
IF(
v_previous_count >= 1
AND v_transaction_type IN
(
'TRANSFER_IN',
'TRANSFER_OUT'
),
1,
0
);

SELECT COUNT(*)
INTO v_existing_execution

FROM rule_executions

WHERE rule_id = v_rule_id
AND transaction_id = p_transaction_id;

IF v_existing_execution = 0 THEN

INSERT INTO rule_executions
(
rule_id,
transaction_id,
execution_result
)

VALUES
(
v_rule_id,
p_transaction_id,
IF(v_match = 1, 'MATCH', 'NO_MATCH')
);

END IF;

IF v_match = 1 THEN

SELECT COUNT(*)
INTO v_existing_assessment

FROM risk_assessments

WHERE transaction_id = p_transaction_id
AND risk_type = 'RAPID_TRANSFER';

IF v_existing_assessment = 0 THEN

INSERT INTO risk_assessments
(
client_id,
transaction_id,
risk_type,
score,
risk_level,
reason,
source
)

VALUES
(
v_client_id,
p_transaction_id,
'RAPID_TRANSFER',
v_rule_score,

CASE
WHEN v_rule_score >= 80 THEN 'CRITICAL'
WHEN v_rule_score >= 60 THEN 'HIGH'
WHEN v_rule_score >= 30 THEN 'MEDIUM'
ELSE 'LOW'
END,

'Mouvement de transfert détecté dans les 60 minutes précédant la transaction.',

'AML_RULE_ENGINE'
);

END IF;

END IF;

END IF;

-- ========================================================
-- 7. DORMANT_ACCOUNT
-- ========================================================
--

-- Condition :
-- aucune transaction précédente sur les 90 derniers jours.
-- ========================================================

SET v_rule_id = NULL;
SET v_rule_score = 0;
SET v_match = 0;

SELECT
id,
score,
severity

INTO
v_rule_id,
v_rule_score,
v_rule_severity

FROM aml_rules

WHERE rule_code = 'DORMANT_ACCOUNT'
AND active = TRUE

LIMIT 1;

IF v_rule_id IS NOT NULL THEN

SELECT COUNT(*)

INTO v_previous_count

FROM transactions

WHERE account_id = v_account_id

AND transaction_date < v_transaction_date

AND transaction_date >=
DATE_SUB(
v_transaction_date,
INTERVAL 90 DAY
)

AND UPPER(
COALESCE(transaction_status, 'VALID')
) NOT IN
(
'CANCELLED',
'CANCELED',
'REVERSED',
'VOID'
);

SET v_match =
IF(v_previous_count = 0, 1, 0);

SELECT COUNT(*)
INTO v_existing_execution

FROM rule_executions

WHERE rule_id = v_rule_id
AND transaction_id = p_transaction_id;

IF v_existing_execution = 0 THEN

INSERT INTO rule_executions
(
rule_id,
transaction_id,
execution_result
)

VALUES
(
v_rule_id,
p_transaction_id,
IF(v_match = 1, 'MATCH', 'NO_MATCH')
);

END IF;

IF v_match = 1 THEN

SELECT COUNT(*)
INTO v_existing_assessment

FROM risk_assessments

WHERE transaction_id = p_transaction_id
AND risk_type = 'DORMANT_ACCOUNT';

IF v_existing_assessment = 0 THEN

INSERT INTO risk_assessments
(
client_id,
transaction_id,
risk_type,
score,
risk_level,
reason,
source
)

VALUES
(
v_client_id,
p_transaction_id,
'DORMANT_ACCOUNT',
v_rule_score,

CASE
WHEN v_rule_score >= 80 THEN 'CRITICAL'
WHEN v_rule_score >= 60 THEN 'HIGH'
WHEN v_rule_score >= 30 THEN 'MEDIUM'
ELSE 'LOW'
END,

'Transaction réalisée après une période de 90 jours sans activité détectée.',

'AML_RULE_ENGINE'
);

END IF;

END IF;

END IF;

-- ========================================================
-- 8. UNUSUAL_VOLUME
-- ========================================================
--

-- Condition référentielle :
-- historique = 30 jours
-- minimum = 3 transactions
-- transaction actuelle >= 3 x moyenne historique
-- ========================================================

SET v_rule_id = NULL;
SET v_rule_score = 0;
SET v_match = 0;

SET v_previous_count = 0;
SET v_previous_volume = 0;

SELECT
id,
score,
severity

INTO
v_rule_id,
v_rule_score,
v_rule_severity

FROM aml_rules

WHERE rule_code = 'UNUSUAL_VOLUME'
AND active = TRUE

LIMIT 1;

IF v_rule_id IS NOT NULL THEN

SELECT
COUNT(*),
COALESCE(SUM(amount), 0)

INTO
v_previous_count,
v_previous_volume

FROM transactions

WHERE account_id = v_account_id

AND transaction_date < v_transaction_date

AND transaction_date >=
DATE_SUB(
v_transaction_date,
INTERVAL 30 DAY
)

AND UPPER(
COALESCE(transaction_status, 'VALID')
) NOT IN
(
'CANCELLED',
'CANCELED',
'REVERSED',
'VOID'
);

SET v_match =
IF(
v_previous_count >= 3
AND v_amount >=
(
v_previous_volume
/ v_previous_count
) * 3,
1,
0
);

SELECT COUNT(*)
INTO v_existing_execution

FROM rule_executions

WHERE rule_id = v_rule_id
AND transaction_id = p_transaction_id;

IF v_existing_execution = 0 THEN

INSERT INTO rule_executions
(
rule_id,
transaction_id,
execution_result
)

VALUES
(
v_rule_id,
p_transaction_id,
IF(v_match = 1, 'MATCH', 'NO_MATCH')
);

END IF;

IF v_match = 1 THEN

SELECT COUNT(*)
INTO v_existing_assessment

FROM risk_assessments

WHERE transaction_id = p_transaction_id
AND risk_type = 'UNUSUAL_VOLUME';

IF v_existing_assessment = 0 THEN

INSERT INTO risk_assessments
(
client_id,
transaction_id,
risk_type,
score,
risk_level,
reason,
source
)

VALUES
(
v_client_id,
p_transaction_id,
'UNUSUAL_VOLUME',
v_rule_score,

CASE
WHEN v_rule_score >= 80 THEN 'CRITICAL'
WHEN v_rule_score >= 60 THEN 'HIGH'
WHEN v_rule_score >= 30 THEN 'MEDIUM'
ELSE 'LOW'
END,

CONCAT(
'Montant actuel supérieur ou égal à trois fois la moyenne des ',
v_previous_count,
' transactions précédentes sur 30 jours.'
),

'AML_RULE_ENGINE'
);

END IF;

END IF;

END IF;

-- ========================================================
-- 9. HIGH_CASH_ACTIVITY
-- ========================================================
--

-- Condition référentielle :
-- canaux ATM / AGENCY.
--

-- On conserve la logique historique sur une fenêtre de
-- 30 jours et un minimum de 5 transactions.
-- ========================================================

SET v_rule_id = NULL;
SET v_rule_score = 0;
SET v_match = 0;

SET v_total_count = 0;
SET v_cash_count = 0;

SELECT
id,
score,
severity

INTO
v_rule_id,
v_rule_score,
v_rule_severity

FROM aml_rules

WHERE rule_code = 'HIGH_CASH_ACTIVITY'
AND active = TRUE

LIMIT 1;

IF v_rule_id IS NOT NULL THEN

SELECT
COUNT(*),

COALESCE(
SUM(
CASE
WHEN UPPER(channel) IN
(
'ATM',
'AGENCY'
)
THEN 1
ELSE 0
END
),
0
)

INTO
v_total_count,
v_cash_count

FROM transactions

WHERE account_id = v_account_id

AND transaction_date <= v_transaction_date

AND transaction_date >=
DATE_SUB(
v_transaction_date,
INTERVAL 30 DAY
)

AND UPPER(
COALESCE(transaction_status, 'VALID')
) NOT IN
(
'CANCELLED',
'CANCELED',
'REVERSED',
'VOID'
);

SET v_match =
IF(
v_total_count >= 5
AND (
v_cash_count / v_total_count
) >= 0.60,
1,
0
);

SELECT COUNT(*)
INTO v_existing_execution

FROM rule_executions

WHERE rule_id = v_rule_id
AND transaction_id = p_transaction_id;

IF v_existing_execution = 0 THEN

INSERT INTO rule_executions
(
rule_id,
transaction_id,
execution_result
)

VALUES
(
v_rule_id,
p_transaction_id,
IF(v_match = 1, 'MATCH', 'NO_MATCH')
);

END IF;

IF v_match = 1 THEN

SELECT COUNT(*)
INTO v_existing_assessment

FROM risk_assessments

WHERE transaction_id = p_transaction_id
AND risk_type = 'HIGH_CASH_ACTIVITY';

IF v_existing_assessment = 0 THEN

INSERT INTO risk_assessments
(
client_id,
transaction_id,
risk_type,
score,
risk_level,
reason,
source
)

VALUES
(
v_client_id,
p_transaction_id,
'HIGH_CASH_ACTIVITY',
v_rule_score,

CASE
WHEN v_rule_score >= 80 THEN 'CRITICAL'
WHEN v_rule_score >= 60 THEN 'HIGH'
WHEN v_rule_score >= 30 THEN 'MEDIUM'
ELSE 'LOW'
END,

'Proportion élevée de transactions réalisées via ATM ou agence sur les 30 derniers jours.',

'AML_RULE_ENGINE'
);

END IF;

END IF;

END IF;

-- ========================================================
-- 10. HIGH_RISK_CORRIDOR
-- ========================================================
--

-- Le corridor n'est PAS déduit arbitrairement d'un pays.
--

-- Il doit être explicitement présent dans :
-- aml_risk_corridors
--

-- country_from + country_to doivent correspondre à une
-- configuration active.
--

-- Pour les anciennes transactions :
-- country_from = country
-- country_to = country
--

-- Elles ne deviennent donc pas artificiellement suspectes.
-- ========================================================

SET v_rule_id = NULL;
SET v_rule_score = 0;
SET v_match = 0;

SELECT
id,
score,
severity

INTO
v_rule_id,
v_rule_score,
v_rule_severity

FROM aml_rules

WHERE rule_code = 'HIGH_RISK_CORRIDOR'
AND active = TRUE

LIMIT 1;

IF v_rule_id IS NOT NULL THEN

SELECT COUNT(*)

INTO v_match

FROM aml_risk_corridors c

WHERE c.active = TRUE

AND UPPER(TRIM(c.country_from))
=
UPPER(TRIM(COALESCE(
v_country_from,
v_country
)))

AND UPPER(TRIM(c.country_to))
=
UPPER(TRIM(COALESCE(
v_country_to,
v_country
)));

IF v_match > 0 THEN
SET v_match = 1;
ELSE
SET v_match = 0;
END IF;

SELECT COUNT(*)
INTO v_existing_execution

FROM rule_executions

WHERE rule_id = v_rule_id
AND transaction_id = p_transaction_id;

IF v_existing_execution = 0 THEN

INSERT INTO rule_executions
(
rule_id,
transaction_id,
execution_result
)

VALUES
(
v_rule_id,
p_transaction_id,
IF(v_match = 1, 'MATCH', 'NO_MATCH')
);

END IF;

IF v_match = 1 THEN

SELECT COUNT(*)
INTO v_existing_assessment

FROM risk_assessments

WHERE transaction_id = p_transaction_id
AND risk_type = 'HIGH_RISK_CORRIDOR';

IF v_existing_assessment = 0 THEN

INSERT INTO risk_assessments
(
client_id,
transaction_id,
risk_type,
score,
risk_level,
reason,
source
)

VALUES
(
v_client_id,
p_transaction_id,
'HIGH_RISK_CORRIDOR',
v_rule_score,

CASE
WHEN v_rule_score >= 80 THEN 'CRITICAL'
WHEN v_rule_score >= 60 THEN 'HIGH'
WHEN v_rule_score >= 30 THEN 'MEDIUM'
ELSE 'LOW'
END,

CONCAT(
'Corridor géographique configuré comme sensible : ',
COALESCE(v_country_from, v_country),
' -> ',
COALESCE(v_country_to, v_country),
'.'
),

'AML_RULE_ENGINE'
);

END IF;

END IF;

END IF;

-- ========================================================
-- 11. CALCUL DU SCORE GLOBAL
-- ========================================================

SELECT
COALESCE(SUM(score), 0)

INTO
v_risk_score

FROM risk_assessments

WHERE transaction_id = p_transaction_id;

-- ========================================================
-- 12. NIVEAU DE RISQUE GLOBAL
-- ========================================================

IF v_risk_score >= 80 THEN

SET v_risk_level = 'CRITICAL';

ELSEIF v_risk_score >= 60 THEN

SET v_risk_level = 'HIGH';

ELSEIF v_risk_score >= 30 THEN

SET v_risk_level = 'MEDIUM';

ELSE

SET v_risk_level = 'LOW';

END IF;

-- ========================================================
-- 13. RESOLUTION DE L'ID DU NIVEAU
-- ========================================================

SELECT id

INTO v_risk_level_id

FROM risk_levels

WHERE code = v_risk_level

LIMIT 1;

-- ========================================================
-- 14. CREATION / CONSERVATION DE L'ALERTE
-- ========================================================
--

-- Une seule alerte AML_RULE_ENGINE par transaction.
-- ========================================================

IF v_risk_score >= 30 THEN

SELECT COUNT(*)

INTO v_existing_alert

FROM alerts

WHERE transaction_id = p_transaction_id
AND alert_type = 'AML_RULE_ENGINE';

IF v_existing_alert = 0 THEN

INSERT INTO alerts
(
reference,
client_id,
transaction_id,
alert_type,
priority,
status,
final_score,
title,
description
)

VALUES
(
CONCAT(
'AML-',
p_transaction_id
),

v_client_id,

p_transaction_id,

'AML_RULE_ENGINE',

CASE
WHEN v_risk_score >= 80 THEN 'CRITICAL'
WHEN v_risk_score >= 60 THEN 'HIGH'
ELSE 'MEDIUM'
END,

'OPEN',

v_risk_score,

CONCAT(
'Alerte AML - risque ',
v_risk_level
),

CONCAT(
'Evaluation automatique du moteur AML. ',
'Score cumulé : ',
v_risk_score,
'.'
)
);

END IF;

END IF;

-- ========================================================
-- 15. MISE A JOUR DU RISQUE CLIENT
-- ========================================================
--

-- Le client conserve le niveau correspondant au risque
-- transactionnel le plus élevé observé.
-- ========================================================

UPDATE clients

SET
risk_score =
LEAST(
100,
COALESCE(
(
SELECT MAX(score)

FROM risk_assessments

WHERE client_id = v_client_id
),
0
)
),

risk_level_id = v_risk_level_id

WHERE id = v_client_id;



END$$

DROP PROCEDURE IF EXISTS `sp_aml_evaluate_transaction_trigger`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_aml_evaluate_transaction_trigger` (IN `p_transaction_id` BIGINT)   BEGIN

    CALL sp_aml_engine_execute(
        p_transaction_id
    );

END$$

DROP PROCEDURE IF EXISTS `sp_aml_process_batch`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_aml_process_batch` (IN `p_batch_size` INT)   main_proc:
BEGIN

    DECLARE v_done INT DEFAULT 0;
    DECLARE v_transaction_id BIGINT DEFAULT NULL;

    DECLARE v_processed INT DEFAULT 0;
    DECLARE v_before INT DEFAULT 0;
    DECLARE v_after INT DEFAULT 0;
    DECLARE v_remaining INT DEFAULT 0;

    DECLARE v_active_rules INT DEFAULT 0;


    /* ========================================================
       CURSEUR
       --------------------------------------------------------
       Une transaction est à retraiter si elle ne possède pas
       encore une exécution pour chacune des règles AML actives.
       ======================================================== */

    DECLARE cur_transactions CURSOR FOR

        SELECT
            t.id

        FROM transactions t

        WHERE t.transaction_status = 'COMPLETED'

          AND
          (
              SELECT COUNT(*)

              FROM rule_executions re

              INNER JOIN aml_rules ar
                  ON ar.id = re.rule_id

              WHERE re.transaction_id = t.id

                AND ar.active = 1
          )
          <
          v_active_rules

        ORDER BY t.id

        LIMIT p_batch_size;


    DECLARE CONTINUE HANDLER FOR NOT FOUND
        SET v_done = 1;


    /* ========================================================
       VALIDATION
       ======================================================== */

    IF p_batch_size IS NULL
       OR p_batch_size <= 0
    THEN

        SELECT
            'ERROR' AS status,
            'La taille du batch doit être supérieure à 0.'
            AS message;

        LEAVE main_proc;

    END IF;


    /* ========================================================
       REGLES ACTIVES
       ======================================================== */

    SELECT
        COUNT(*)

    INTO
        v_active_rules

    FROM aml_rules

    WHERE active = 1;


    /* ========================================================
       AVANT
       ======================================================== */

    SELECT
        COUNT(*)

    INTO
        v_before

    FROM transactions t

    WHERE t.transaction_status = 'COMPLETED'

      AND
      (
          SELECT COUNT(*)

          FROM rule_executions re

          INNER JOIN aml_rules ar
              ON ar.id = re.rule_id

          WHERE re.transaction_id = t.id

            AND ar.active = 1
      )
      <
      v_active_rules;


    /* ========================================================
       TRAITEMENT
       ======================================================== */

    OPEN cur_transactions;


    process_loop:
    LOOP

        FETCH cur_transactions
        INTO v_transaction_id;


        IF v_done = 1 THEN
            LEAVE process_loop;
        END IF;


        CALL sp_aml_engine_execute(
            v_transaction_id
        );


        SET v_processed =
            v_processed + 1;

    END LOOP;


    CLOSE cur_transactions;


    /* ========================================================
       APRES
       ======================================================== */

    SELECT
        COUNT(*)

    INTO
        v_after

    FROM transactions t

    WHERE t.transaction_status = 'COMPLETED'

      AND
      (
          SELECT COUNT(*)

          FROM rule_executions re

          INNER JOIN aml_rules ar
              ON ar.id = re.rule_id

          WHERE re.transaction_id = t.id

            AND ar.active = 1
      )
      <
      v_active_rules;


    SET v_remaining =
        v_after;


    /* ========================================================
       RESULTAT UNIQUE DU BATCH
       ======================================================== */

    SELECT

        'SUCCESS' AS status,

        p_batch_size AS requested_batch_size,

        v_processed AS processed_transactions,

        v_before AS pending_before,

        v_after AS pending_after,

        (
            v_before - v_after
        ) AS transactions_completed_by_batch,

        v_remaining AS remaining_transactions,

        v_active_rules AS active_rules,

        NOW() AS execution_time;

END$$

DROP PROCEDURE IF EXISTS `sp_customer_profile`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_customer_profile` (IN `p_client` BIGINT)   BEGIN


SELECT *

FROM v_customer_compliance_profile

WHERE id=p_client;


END$$

DROP PROCEDURE IF EXISTS `sp_insert_sanctioned_clients`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_insert_sanctioned_clients` (IN `p_quantity` INT)   main_proc:
BEGIN

    DECLARE v_done INT DEFAULT 0;

    DECLARE v_sanction_id BIGINT;
    DECLARE v_client_id BIGINT;

    DECLARE v_primary_name VARCHAR(500);
    DECLARE v_entity_type VARCHAR(100);

    DECLARE v_first_name VARCHAR(100);
    DECLARE v_last_name VARCHAR(100);

    DECLARE v_nationality VARCHAR(255);
    DECLARE v_gender VARCHAR(50);

    DECLARE v_birth_date DATE;

    DECLARE v_client_number VARCHAR(50);

    DECLARE v_inserted INT DEFAULT 0;
    DECLARE v_skipped INT DEFAULT 0;




    /* =========================================================
       CURSEUR
       
       PERSON -> client_individuals
       Tout autre entity_type -> client_entities
       ========================================================= */

    DECLARE cur_sanctions CURSOR FOR

        SELECT
            se.id,
            se.primary_name,
            se.entity_type,
            se.nationality,
            se.gender,
            sbd.birth_date

        FROM sanctions_entities se

        LEFT JOIN sanctions_birth_details sbd
            ON sbd.sanctions_entity_id = se.id

        WHERE se.is_active = 1

          AND se.entity_type IS NOT NULL
          AND TRIM(se.entity_type) <> ''

          /* -------------------------------------------------
             Aucun client du même type + même nom logique.
             ------------------------------------------------- */

          AND NOT EXISTS
          (
              SELECT 1

              FROM clients c

              WHERE UPPER(TRIM(c.client_type))
                    =
                    UPPER(TRIM(se.entity_type))

                AND
                (
                    /* PERSON */

                    (
                        UPPER(TRIM(se.entity_type)) = 'PERSON'

                        AND EXISTS
                        (
                            SELECT 1
                            FROM client_individuals ci
                            WHERE ci.client_id = c.id

                              AND UPPER(
                                  TRIM(
                                      REGEXP_REPLACE(
                                          CONCAT(
                                              COALESCE(ci.last_name,''),
                                              CASE
                                                  WHEN ci.first_name IS NOT NULL
                                                       AND TRIM(ci.first_name) <> ''
                                                  THEN CONCAT(', ',ci.first_name)
                                                  ELSE ''
                                              END
                                          ),
                                          '[[:space:]]+',
                                          ' '
                                      )
                                  )
                              )
                              =
                              UPPER(
                                  TRIM(
                                      REGEXP_REPLACE(
                                          se.primary_name,
                                          '[[:space:]]+',
                                          ' '
                                      )
                                  )
                              )
                        )

                    )

                    OR

                    /* ENTITY / ENTERPRISE / VESSEL / AIRCRAFT... */

                    (
                        UPPER(TRIM(se.entity_type)) <> 'PERSON'

                        AND EXISTS
                        (
                            SELECT 1
                            FROM client_entities ce
                            WHERE ce.client_id = c.id

                              AND UPPER(
                                  TRIM(
                                      REGEXP_REPLACE(
                                          ce.legal_name,
                                          '[[:space:]]+',
                                          ' '
                                      )
                                  )
                              )
                              =
                              UPPER(
                                  TRIM(
                                      REGEXP_REPLACE(
                                          se.primary_name,
                                          '[[:space:]]+',
                                          ' '
                                      )
                                  )
                              )
                        )
                    )
                )
          )

        GROUP BY
            se.id,
            se.primary_name,
            se.entity_type,
            se.nationality,
            se.gender,
            sbd.birth_date

        ORDER BY se.id;


    DECLARE CONTINUE HANDLER FOR NOT FOUND
        SET v_done = 1;


    /* =========================================================
       VALIDATION
       ========================================================= */

    IF p_quantity IS NULL
       OR p_quantity <= 0
    THEN

        SELECT
            'ERROR' AS status,
            0 AS requested,
            0 AS inserted,
            0 AS skipped,
            'Le nombre demandé doit être supérieur à zéro.' AS message;

        LEAVE main_proc;

    END IF;
    
    /* =========================================================
       CURSEUR
       ========================================================= */

    OPEN cur_sanctions;


    insertion_loop:
    LOOP

        FETCH cur_sanctions

        INTO
            v_sanction_id,
            v_primary_name,
            v_entity_type,
            v_nationality,
            v_gender,
            v_birth_date;


        IF v_done = 1 THEN
            LEAVE insertion_loop;
        END IF;


        /* =====================================================
           ARRET QUAND LA QUANTITE DEMANDEE EST ATTEINTE
           ===================================================== */

        IF v_inserted >= p_quantity THEN
            LEAVE insertion_loop;
        END IF;


        /* =====================================================
           PREPARATION DU NUMERO CLIENT
           ===================================================== */

        SET v_client_number =
            CONCAT(
                'SAN-',
                UPPER(
                    LEFT(
                        REPLACE(
                            COALESCE(v_entity_type,'UNK'),
                            ' ',
                            ''
                        ),
                        10
                    )
                ),
                '-',
                v_sanction_id
            );


        /* =====================================================
           SECURITE SUPPLEMENTAIRE
           ===================================================== */

        IF EXISTS
        (
            SELECT 1
            FROM clients
            WHERE client_number = v_client_number
        )
        THEN

            SET v_skipped = v_skipped + 1;

            ITERATE insertion_loop;

        END IF;


        /* =====================================================
           INSERTION CLIENT PARENT
           
           IMPORTANT :
           client_type = entity_type EXACTEMENT.
           ===================================================== */

        INSERT INTO clients
        (
            client_number,
            client_type,
            is_pep,
            risk_score,
            created_at
        )

        VALUES
        (
            v_client_number,
            v_entity_type,
            0,
            0.00,
            CURRENT_TIMESTAMP
        );


        SET v_client_id = LAST_INSERT_ID();


        /* =====================================================
           INSERTION PERSONNE
           ===================================================== */

        IF UPPER(TRIM(v_entity_type)) = 'PERSON'
        THEN

            /*
               primary_name est généralement sous la forme :

               NOM, Prénom(s)
            */

            IF INSTR(v_primary_name, ',') > 0 THEN

                SET v_last_name =
                    TRIM(
                        SUBSTRING_INDEX(
                            v_primary_name,
                            ',',
                            1
                        )
                    );

                SET v_first_name =
                    TRIM(
                        SUBSTRING(
                            v_primary_name,
                            INSTR(v_primary_name, ',') + 1
                        )
                    );

            ELSE

                SET v_last_name =
                    TRIM(v_primary_name);

                SET v_first_name = NULL;

            END IF;


            INSERT INTO client_individuals
            (
                client_id,
                first_name,
                last_name,
                gender,
                birth_date,
                nationality,
                created_at,
                updated_at
            )

            VALUES
            (
                v_client_id,
                v_first_name,
                v_last_name,
                v_gender,
                v_birth_date,
                v_nationality,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            );


        ELSE

            /* =================================================
               TOUS LES AUTRES TYPES
               
               ENTITY
               ENTERPRISE
               VESSEL
               AIRCRAFT
               etc.
               ================================================= */

            INSERT INTO client_entities
            (
                client_id,
                legal_name,
                entity_type,
                nationality,
                activity_sector,
                created_at,
                updated_at
            )

            VALUES
            (
                v_client_id,
                v_primary_name,
                v_entity_type,
                v_nationality,
                NULL,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            );

        END IF;


        SET v_inserted = v_inserted + 1;
        CALL sp_screen_client(v_client_id, 1);


    END LOOP;


    CLOSE cur_sanctions;


    /* =========================================================
       RESULTAT FINAL
       
       Le trigger AFTER INSERT sur clients a déjà déclenché :
       
       clients
          ↓
       sp_screen_client
          ↓
       sp_match_client_to_sanction
          ↓
       sanction_matches
       
       On vérifie donc directement les résultats.
       ========================================================= */

    SELECT

        'SUCCESS' AS status,

        p_quantity AS requested,

        v_inserted AS inserted,

        v_skipped AS skipped,

        COUNT(DISTINCT c.id) AS inserted_clients_with_screening,

        COUNT(
            DISTINCT
            CASE
                WHEN s.match_found = 1
                THEN c.id
            END
        ) AS clients_with_match,

        COUNT(DISTINCT sm.id) AS sanction_matches_created

    FROM clients c

    LEFT JOIN screenings s
        ON s.client_id = c.id

    LEFT JOIN sanction_matches sm
        ON sm.client_id = c.id

    WHERE c.client_number LIKE 'SAN-%'

      AND c.created_at >= CURRENT_DATE;


END$$

DROP PROCEDURE IF EXISTS `sp_match_client_sanctions`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_match_client_sanctions` (IN `p_client_id` BIGINT, IN `p_screening_id` BIGINT, IN `p_screening_list_id` BIGINT)   main_proc:
BEGIN

    /* =========================================================
       VARIABLES
       ========================================================= */

    DECLARE v_done INT DEFAULT 0;
    DECLARE v_entity_id BIGINT;

    DECLARE v_match_score DECIMAL(5,2) DEFAULT 0;
    DECLARE v_match_status VARCHAR(30) DEFAULT 'CLEAR';

    DECLARE v_name_match TINYINT DEFAULT 0;
    DECLARE v_alias_match TINYINT DEFAULT 0;
    DECLARE v_birth_date_match TINYINT DEFAULT 0;
    DECLARE v_birth_year_match TINYINT DEFAULT 0;
    DECLARE v_nationality_match TINYINT DEFAULT 0;
    DECLARE v_gender_match TINYINT DEFAULT 0;

    DECLARE v_match_reason TEXT;

    DECLARE v_best_score DECIMAL(5,2) DEFAULT 0;
    DECLARE v_match_count INT DEFAULT 0;


  


    /* =========================================================
       CURSEUR DES ENTITES DE SANCTIONS
       ========================================================= */

    DECLARE cur_entities CURSOR FOR

        SELECT DISTINCT se.id

        FROM sanctions_entities se

        LEFT JOIN sanctions_aliases sa
            ON sa.sanctions_entity_id = se.id

        WHERE se.screening_list_id = p_screening_list_id
          AND se.is_active = 1

          AND
          (

              /* =================================================
                 INDIVIDU :
                 LAST_NAME, FIRST_NAME
                 ================================================= */

              EXISTS (
                  SELECT 1
                  FROM client_individuals ci

                  WHERE ci.client_id = p_client_id

                    AND
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                se.primary_name,
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
                    =
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                CONCAT(
                                    ci.last_name,
                                    ', ',
                                    ci.first_name
                                ),
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
              )


              OR


              /* =================================================
                 SOCIETE :
                 LEGAL_NAME
                 ================================================= */

              EXISTS (
                  SELECT 1
                  FROM client_entities ce

                  WHERE ce.client_id = p_client_id

                    AND
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                se.primary_name,
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
                    =
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                ce.legal_name,
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
              )


              OR


              /* =================================================
                 ALIAS INDIVIDU
                 ================================================= */

              EXISTS (
                  SELECT 1
                  FROM client_individuals ci

                  WHERE ci.client_id = p_client_id

                    AND
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                sa.alias_name,
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
                    =
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                CONCAT(
                                    ci.last_name,
                                    ', ',
                                    ci.first_name
                                ),
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
              )


              OR


              /* =================================================
                 ALIAS SOCIETE
                 ================================================= */

              EXISTS (
                  SELECT 1
                  FROM client_entities ce

                  WHERE ce.client_id = p_client_id

                    AND
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                sa.alias_name,
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
                    =
                    UPPER(
                        TRIM(
                            REGEXP_REPLACE(
                                ce.legal_name,
                                '[[:space:]]+',
                                ' '
                            )
                        )
                    )
              )

          );


    DECLARE CONTINUE HANDLER FOR NOT FOUND
        SET v_done = 1;
  /* =========================================================
       VALIDATION DU CLIENT
       ========================================================= */

    IF NOT EXISTS (
        SELECT 1
        FROM clients
        WHERE id = p_client_id
    ) THEN

        LEAVE main_proc;

    END IF;


    /* =========================================================
       VALIDATION DU SOUS-TYPE
       ========================================================= */

    IF NOT EXISTS (
        SELECT 1
        FROM client_individuals
        WHERE client_id = p_client_id
    )
    AND NOT EXISTS (
        SELECT 1
        FROM client_entities
        WHERE client_id = p_client_id
    ) THEN

        LEAVE main_proc;

    END IF;

    /* =========================================================
       PARCOURS DES CANDIDATS
       ========================================================= */

    OPEN cur_entities;


    entity_loop:
    LOOP

        FETCH cur_entities
        INTO v_entity_id;


        IF v_done = 1 THEN
            LEAVE entity_loop;
        END IF;


        /* =====================================================
           RESET
           ===================================================== */

        SET v_match_score = 0;
        SET v_match_status = 'CLEAR';

        SET v_name_match = 0;
        SET v_alias_match = 0;
        SET v_birth_date_match = 0;
        SET v_birth_year_match = 0;
        SET v_nationality_match = 0;
        SET v_gender_match = 0;

        SET v_match_reason = '';


        /* =====================================================
           MOTEUR CENTRAL DE MATCHING
           ===================================================== */

        CALL sp_match_client_to_sanction(

            p_client_id,
            v_entity_id,

            v_match_score,
            v_match_status,

            v_name_match,
            v_alias_match,
            v_birth_date_match,
            v_birth_year_match,
            v_nationality_match,
            v_gender_match,

            v_match_reason
        );


        /* =====================================================
           MEILLEUR SCORE
           ===================================================== */

        IF v_match_score > v_best_score THEN

            SET v_best_score = v_match_score;

        END IF;


        /* =====================================================
           ENREGISTREMENT DU MATCH
           ===================================================== */

        IF v_match_score >= 50 THEN

            INSERT INTO sanction_matches
            (
                client_id,
                screening_id,
                sanction_type,
                authority,
                reason,
                match_score
            )

            SELECT
                p_client_id,
                p_screening_id,
                COALESCE(
                    se.entity_type,
                    'SANCTIONS'
                ),
                se.source,

                CONCAT(
                    'Entity ID=', se.id,
                    ' ; ',
                    v_match_reason,
                    ' ; Primary name=',
                    se.primary_name
                ),

                v_match_score

            FROM sanctions_entities se

            WHERE se.id = v_entity_id

              AND NOT EXISTS (
                  SELECT 1
                  FROM sanction_matches sm

                  WHERE sm.client_id = p_client_id
                    AND sm.screening_id = p_screening_id
                    AND sm.reason LIKE
                        CONCAT(
                            '%Entity ID=',
                            se.id,
                            '%'
                        )
              );

        END IF;


    END LOOP;


    CLOSE cur_entities;


    /* =========================================================
       RESULTAT DU SCREENING
       ========================================================= */

    SELECT COUNT(*)
    INTO v_match_count

    FROM sanction_matches
    WHERE client_id = p_client_id
      AND screening_id = p_screening_id;


    UPDATE screenings

    SET
        status =
            CASE

                WHEN v_best_score >= 85
                    THEN 'CONFIRMED_MATCH'

                WHEN v_best_score >= 70
                    THEN 'HIGH_CONFIDENCE_MATCH'

                WHEN v_best_score >= 50
                    THEN 'POTENTIAL_MATCH'

                ELSE 'CLEAR'

            END,

        match_found =
            CASE
                WHEN v_best_score >= 50
                    THEN 1
                ELSE 0
            END,

        confidence_score =
            v_best_score

    WHERE id = p_screening_id;


END$$

DROP PROCEDURE IF EXISTS `sp_match_client_to_sanction`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_match_client_to_sanction` (IN `p_client_id` BIGINT, IN `p_sanctions_entity_id` BIGINT, OUT `p_match_score` DECIMAL(5,2), OUT `p_match_status` VARCHAR(30), OUT `p_name_match` TINYINT, OUT `p_alias_match` TINYINT, OUT `p_birth_date_match` TINYINT, OUT `p_birth_year_match` TINYINT, OUT `p_nationality_match` TINYINT, OUT `p_gender_match` TINYINT, OUT `p_match_reason` TEXT)   main_proc:
BEGIN

    /* =========================================================
       VARIABLES CLIENT
       ========================================================= */

    DECLARE v_client_type VARCHAR(100) DEFAULT NULL;

    DECLARE v_client_name VARCHAR(500) DEFAULT NULL;
    DECLARE v_client_name_alt1 VARCHAR(500) DEFAULT NULL;
    DECLARE v_client_name_alt2 VARCHAR(500) DEFAULT NULL;

    DECLARE v_first_name VARCHAR(100) DEFAULT NULL;
    DECLARE v_last_name VARCHAR(100) DEFAULT NULL;

    DECLARE v_birth_date DATE DEFAULT NULL;
    DECLARE v_nationality VARCHAR(255) DEFAULT NULL;
    DECLARE v_gender VARCHAR(50) DEFAULT NULL;

    DECLARE v_legal_name VARCHAR(500) DEFAULT NULL;


    /* =========================================================
       VARIABLES SANCTION
       ========================================================= */

    DECLARE v_primary_name VARCHAR(500) DEFAULT NULL;
    DECLARE v_entity_type VARCHAR(100) DEFAULT NULL;

    DECLARE v_sanction_nationality VARCHAR(255) DEFAULT NULL;
    DECLARE v_sanction_gender VARCHAR(50) DEFAULT NULL;


    /* =========================================================
       NAISSANCE SANCTION
       ========================================================= */

    DECLARE v_birth_date_sanction DATE DEFAULT NULL;
    DECLARE v_birth_year_sanction INT DEFAULT NULL;


    /* =========================================================
       SCORES
       ========================================================= */

    DECLARE v_name_score DECIMAL(5,2) DEFAULT 0;
    DECLARE v_birth_score DECIMAL(5,2) DEFAULT 0;
    DECLARE v_nationality_score DECIMAL(5,2) DEFAULT 0;
    DECLARE v_gender_score DECIMAL(5,2) DEFAULT 0;


    /* =========================================================
       INITIALISATION
       ========================================================= */

    SET p_match_score = 0;
    SET p_match_status = 'CLEAR';

    SET p_name_match = 0;
    SET p_alias_match = 0;

    SET p_birth_date_match = 0;
    SET p_birth_year_match = 0;

    SET p_nationality_match = 0;
    SET p_gender_match = 0;

    SET p_match_reason = '';


    /* =========================================================
       1. VERIFICATION CLIENT
       ========================================================= */

    IF NOT EXISTS
    (
        SELECT 1
        FROM clients
        WHERE id = p_client_id
    )
    THEN

        SET p_match_reason =
            'Client introuvable.';

        LEAVE main_proc;

    END IF;


    /* =========================================================
       2. TYPE CLIENT
       ========================================================= */

    SELECT
        client_type
    INTO
        v_client_type
    FROM clients
    WHERE id = p_client_id
    LIMIT 1;


    IF v_client_type IS NULL
       OR TRIM(v_client_type) = ''
    THEN

        SET p_match_reason =
            'Type client absent.';

        LEAVE main_proc;

    END IF;


    /* =========================================================
       3. RECUPERATION DES DONNEES DU CLIENT
       
       PERSON / INDIVIDUAL
       -------------------
       
       On construit maintenant 3 formats :

       1. LAST_NAME, FIRST_NAME
       2. LAST_NAME FIRST_NAME
       3. FIRST_NAME LAST_NAME

       Ainsi le moteur ne dépend plus du format utilisé
       par la source de sanctions.
       ========================================================= */

    IF UPPER(TRIM(v_client_type)) IN ('PERSON','INDIVIDUAL')
    THEN

        SELECT
            first_name,
            last_name,
            gender,
            birth_date,
            nationality

        INTO
            v_first_name,
            v_last_name,
            v_gender,
            v_birth_date,
            v_nationality

        FROM client_individuals

        WHERE client_id = p_client_id

        LIMIT 1;


        /* =====================================================
           FORMAT 1
           LAST_NAME, FIRST_NAME
           ===================================================== */

        SET v_client_name =
            TRIM(
                CONCAT(
                    COALESCE(v_last_name, ''),
                    CASE
                        WHEN v_first_name IS NOT NULL
                             AND TRIM(v_first_name) <> ''
                        THEN CONCAT(', ', v_first_name)
                        ELSE ''
                    END
                )
            );


        /* =====================================================
           FORMAT 2
           LAST_NAME FIRST_NAME
           ===================================================== */

        SET v_client_name_alt1 =
            TRIM(
                CONCAT(
                    COALESCE(v_last_name, ''),
                    CASE
                        WHEN v_first_name IS NOT NULL
                             AND TRIM(v_first_name) <> ''
                        THEN CONCAT(' ', v_first_name)
                        ELSE ''
                    END
                )
            );


        /* =====================================================
           FORMAT 3
           FIRST_NAME LAST_NAME
           ===================================================== */

        SET v_client_name_alt2 =
            TRIM(
                CONCAT(
                    COALESCE(v_first_name, ''),
                    CASE
                        WHEN v_last_name IS NOT NULL
                             AND TRIM(v_last_name) <> ''
                        THEN CONCAT(' ', v_last_name)
                        ELSE ''
                    END
                )
            );

    ELSE

        /* =====================================================
           ENTITY / ENTERPRISE / VESSEL / AIRCRAFT / ETC.
           
           Pour une entité, le legal_name est conservé tel quel.
           ===================================================== */

        SELECT
            legal_name,
            nationality,
            activity_sector

        INTO
            v_legal_name,
            v_nationality,
            @v_unused_activity_sector

        FROM client_entities

        WHERE client_id = p_client_id

        LIMIT 1;


        SET v_client_name =
            TRIM(v_legal_name);

        SET v_client_name_alt1 =
            v_client_name;

        SET v_client_name_alt2 =
            v_client_name;

    END IF;


    /* =========================================================
       4. VALIDATION NOM
       ========================================================= */

    IF v_client_name IS NULL
       OR TRIM(v_client_name) = ''
    THEN

        SET p_match_reason =
            'Nom client inexploitable.';

        LEAVE main_proc;

    END IF;


    /* =========================================================
       5. RECUPERATION SANCTION
       ========================================================= */

    SELECT
        primary_name,
        entity_type,
        nationality,
        gender

    INTO
        v_primary_name,
        v_entity_type,
        v_sanction_nationality,
        v_sanction_gender

    FROM sanctions_entities

    WHERE id = p_sanctions_entity_id

    LIMIT 1;


    IF v_primary_name IS NULL
       OR TRIM(v_primary_name) = ''
    THEN

        SET p_match_reason =
            'Primary name sanction inexploitable.';

        LEAVE main_proc;

    END IF;


    /* =========================================================
       6. CONTROLE DU TYPE
       
       Le type doit rester cohérent entre client et sanction.
       ========================================================= */

    IF v_entity_type IS NOT NULL
       AND TRIM(v_entity_type) <> ''
       AND UPPER(TRIM(v_entity_type))
           <>
           UPPER(TRIM(v_client_type))
    THEN

        SET p_match_reason =
            CONCAT(
                'Type incompatible. Client=',
                v_client_type,
                ' ; Sanction=',
                v_entity_type
            );

        LEAVE main_proc;

    END IF;


    /* =========================================================
       7. MATCH PRIMARY_NAME
       
       Trois formats pour les personnes.
       Un seul format pour les entités.
       
       La normalisation ici :
       - supprime les différences de casse ;
       - réduit les espaces multiples ;
       - neutralise donc notamment la différence
         entre "NOM, Prenom" et "NOM,   Prenom".
       ========================================================= */

    IF
        UPPER(
            TRIM(
                REGEXP_REPLACE(
                    v_primary_name,
                    '[[:space:]]+',
                    ' '
                )
            )
        )
        =
        UPPER(
            TRIM(
                REGEXP_REPLACE(
                    v_client_name,
                    '[[:space:]]+',
                    ' '
                )
            )
        )

        OR

        UPPER(
            TRIM(
                REGEXP_REPLACE(
                    v_primary_name,
                    '[[:space:]]+',
                    ' '
                )
            )
        )
        =
        UPPER(
            TRIM(
                REGEXP_REPLACE(
                    v_client_name_alt1,
                    '[[:space:]]+',
                    ' '
                )
            )
        )

        OR

        UPPER(
            TRIM(
                REGEXP_REPLACE(
                    v_primary_name,
                    '[[:space:]]+',
                    ' '
                )
            )
        )
        =
        UPPER(
            TRIM(
                REGEXP_REPLACE(
                    v_client_name_alt2,
                    '[[:space:]]+',
                    ' '
                )
            )
        )

    THEN

        SET p_name_match = 1;
        SET v_name_score = 50;

    END IF;


    /* =========================================================
       8. MATCH ALIAS
       
       Les alias sont également testés avec les 3 formats
       possibles du client.
       ========================================================= */

    IF p_name_match = 0 THEN

        SELECT
            CASE
                WHEN EXISTS
                (
                    SELECT 1

                    FROM sanctions_aliases sa

                    WHERE sa.sanctions_entity_id =
                          p_sanctions_entity_id

                      AND
                      (
                          /* FORMAT 1 */

                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      sa.alias_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )
                          =
                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      v_client_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )

                          OR

                          /* FORMAT 2 */

                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      sa.alias_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )
                          =
                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      v_client_name_alt1,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )

                          OR

                          /* FORMAT 3 */

                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      sa.alias_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )
                          =
                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      v_client_name_alt2,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )
                      )
                )

                THEN 1
                ELSE 0

            END

        INTO p_alias_match;


        IF p_alias_match = 1 THEN

            SET v_name_score = 50;

        ELSE

            SET p_match_reason =
                CONCAT(
                    'Aucun match primary_name/alias. ',
                    'Formats client=[',
                    v_client_name,
                    ' | ',
                    v_client_name_alt1,
                    ' | ',
                    v_client_name_alt2,
                    '] ; Sanction=',
                    v_primary_name
                );

            LEAVE main_proc;

        END IF;

    END IF;


    /* =========================================================
       9. DONNEES DE NAISSANCE
       
       Uniquement pour PERSON.
       ========================================================= */

    IF UPPER(TRIM(v_client_type))
       IN ('PERSON','INDIVIDUAL')
    THEN

        SELECT
            MAX(sbd.birth_date),

            MAX(
                CASE
                    WHEN sbd.birth_year REGEXP '^[0-9]{4}$'
                    THEN CAST(sbd.birth_year AS UNSIGNED)
                    ELSE NULL
                END
            )

        INTO
            v_birth_date_sanction,
            v_birth_year_sanction

        FROM sanctions_birth_details sbd

        WHERE sbd.sanctions_entity_id =
              p_sanctions_entity_id;


        /* =====================================================
           DATE COMPLETE
           ===================================================== */

        IF v_birth_date IS NOT NULL
           AND v_birth_date_sanction IS NOT NULL
        THEN

            IF v_birth_date =
               v_birth_date_sanction
            THEN

                SET p_birth_date_match = 1;
                SET v_birth_score = 30;

            END IF;


        /* =====================================================
           ANNEE SEULE
           ===================================================== */

        ELSEIF v_birth_date IS NOT NULL
               AND v_birth_year_sanction IS NOT NULL
        THEN

            IF YEAR(v_birth_date) =
               v_birth_year_sanction
            THEN

                SET p_birth_year_match = 1;
                SET v_birth_score = 20;

            END IF;

        END IF;

    END IF;


    /* =========================================================
       10. NATIONALITE
       ========================================================= */

    IF v_nationality IS NOT NULL
       AND v_sanction_nationality IS NOT NULL

       AND TRIM(v_nationality) <> ''
       AND TRIM(v_sanction_nationality) <> ''

       AND UPPER(TRIM(v_nationality))
           =
           UPPER(TRIM(v_sanction_nationality))

    THEN

        SET p_nationality_match = 1;
        SET v_nationality_score = 10;

    END IF;


    /* =========================================================
       11. SEXE
       ========================================================= */

    IF UPPER(TRIM(v_client_type))
       IN ('PERSON','INDIVIDUAL')
    THEN

        IF v_gender IS NOT NULL
           AND v_sanction_gender IS NOT NULL

           AND TRIM(v_gender) <> ''
           AND TRIM(v_sanction_gender) <> ''

           AND UPPER(TRIM(v_gender))
               =
               UPPER(TRIM(v_sanction_gender))
        THEN

            SET p_gender_match = 1;
            SET v_gender_score = 5;

        END IF;

    END IF;


    /* =========================================================
       12. SCORE FINAL
       ========================================================= */

    SET p_match_score =
        LEAST(
            100,
            v_name_score
            + v_birth_score
            + v_nationality_score
            + v_gender_score
        );


    /* =========================================================
       13. STATUT
       ========================================================= */

    IF p_match_score >= 85 THEN

        SET p_match_status =
            'CONFIRMED_MATCH';

    ELSEIF p_match_score >= 70 THEN

        SET p_match_status =
            'HIGH_CONFIDENCE_MATCH';

    ELSEIF p_match_score >= 50 THEN

        SET p_match_status =
            'POTENTIAL_MATCH';

    ELSE

        SET p_match_status =
            'CLEAR';

    END IF;


    /* =========================================================
       14. RAISON DETAILLEE
       ========================================================= */

    SET p_match_reason =
        CONCAT(
            'Type client=', v_client_type,
            ' ; Type sanction=', COALESCE(v_entity_type,'NULL'),

            ' ; Primary name=', v_primary_name,

            ' ; Formats client=[',
            v_client_name,
            ' | ',
            v_client_name_alt1,
            ' | ',
            v_client_name_alt2,
            ']',

            ' ; name_match=', p_name_match,
            ' ; alias_match=', p_alias_match,

            ' ; birth_date_match=', p_birth_date_match,
            ' ; birth_year_match=', p_birth_year_match,

            ' ; nationality_match=', p_nationality_match,
            ' ; gender_match=', p_gender_match
        );


END$$

DROP PROCEDURE IF EXISTS `sp_screen_client`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_screen_client` (IN `p_client_id` BIGINT, IN `p_force` TINYINT)   main_proc:
BEGIN

    DECLARE v_done INT DEFAULT 0;

    DECLARE v_list_id BIGINT;
    DECLARE v_screening_id BIGINT;
    DECLARE v_entity_id BIGINT;

    DECLARE v_client_type VARCHAR(100);

    DECLARE v_client_name VARCHAR(500);
    DECLARE v_client_name_alt1 VARCHAR(500);
    DECLARE v_client_name_alt2 VARCHAR(500);

    DECLARE v_match_score DECIMAL(5,2) DEFAULT 0;
    DECLARE v_match_status VARCHAR(30) DEFAULT 'CLEAR';

    DECLARE v_name_match TINYINT DEFAULT 0;
    DECLARE v_alias_match TINYINT DEFAULT 0;

    DECLARE v_birth_date_match TINYINT DEFAULT 0;
    DECLARE v_birth_year_match TINYINT DEFAULT 0;

    DECLARE v_nationality_match TINYINT DEFAULT 0;
    DECLARE v_gender_match TINYINT DEFAULT 0;

    DECLARE v_match_reason TEXT;

    DECLARE v_best_score DECIMAL(5,2) DEFAULT 0;


    /* =========================================================
       CURSEUR LISTES
       ========================================================= */

    DECLARE cur_lists CURSOR FOR

        SELECT id

        FROM screening_lists

        WHERE UPPER(
                  COALESCE(type,'')
              ) = 'SANCTIONS'

        ORDER BY id;


    DECLARE CONTINUE HANDLER FOR NOT FOUND
        SET v_done = 1;


    /* =========================================================
       1. CLIENT
       ========================================================= */

    SELECT
        client_type

    INTO
        v_client_type

    FROM clients

    WHERE id = p_client_id

    LIMIT 1;


    IF v_client_type IS NULL THEN
        LEAVE main_proc;
    END IF;


    /* =========================================================
       2. FORMATION DES FORMATS DE NOM
       
       PERSON / INDIVIDUAL :
       
       1. LAST_NAME, FIRST_NAME
       2. LAST_NAME FIRST_NAME
       3. FIRST_NAME LAST_NAME
       
       ENTITY :
       
       legal_name tel quel.
       ========================================================= */

    IF UPPER(TRIM(v_client_type))
       IN ('PERSON','INDIVIDUAL')
    THEN

        SELECT

            TRIM(
                CONCAT(
                    COALESCE(last_name,''),

                    CASE
                        WHEN first_name IS NOT NULL
                             AND TRIM(first_name) <> ''
                        THEN CONCAT(', ',first_name)
                        ELSE ''
                    END
                )
            ),

            TRIM(
                CONCAT(
                    COALESCE(last_name,''),

                    CASE
                        WHEN first_name IS NOT NULL
                             AND TRIM(first_name) <> ''
                        THEN CONCAT(' ',first_name)
                        ELSE ''
                    END
                )
            ),

            TRIM(
                CONCAT(
                    COALESCE(first_name,''),

                    CASE
                        WHEN last_name IS NOT NULL
                             AND TRIM(last_name) <> ''
                        THEN CONCAT(' ',last_name)
                        ELSE ''
                    END
                )
            )

        INTO
            v_client_name,
            v_client_name_alt1,
            v_client_name_alt2

        FROM client_individuals

        WHERE client_id = p_client_id

        LIMIT 1;


    ELSE

        SELECT
            TRIM(legal_name)

        INTO
            v_client_name

        FROM client_entities

        WHERE client_id = p_client_id

        LIMIT 1;


        SET v_client_name_alt1 =
            v_client_name;

        SET v_client_name_alt2 =
            v_client_name;

    END IF;


    /* =========================================================
       VALIDATION
       ========================================================= */

    IF v_client_name IS NULL
       OR TRIM(v_client_name) = ''
    THEN

        LEAVE main_proc;

    END IF;


    /* =========================================================
       3. PARCOURS DES LISTES
       ========================================================= */

    OPEN cur_lists;


    screening_loop:
    LOOP

        FETCH cur_lists
        INTO v_list_id;


        IF v_done = 1 THEN
            LEAVE screening_loop;
        END IF;


        SET v_screening_id = NULL;
        SET v_best_score = 0;


        /* =====================================================
           4. IDEMPOTENCE
           ===================================================== */

        IF COALESCE(p_force,0) = 0 THEN

            SELECT id

            INTO v_screening_id

            FROM screenings

            WHERE client_id = p_client_id

              AND screening_list_id = v_list_id

              AND screening_date >= CURDATE()

              AND screening_date <
                  DATE_ADD(
                      CURDATE(),
                      INTERVAL 1 DAY
                  )

            ORDER BY id DESC

            LIMIT 1;

        END IF;


        /* =====================================================
           5. CREATION SCREENING
           ===================================================== */

        IF v_screening_id IS NULL THEN

            INSERT INTO screenings
            (
                client_id,
                screening_list_id,
                screening_date,
                status,
                match_found,
                confidence_score
            )

            VALUES
            (
                p_client_id,
                v_list_id,
                NOW(),
                'IN_PROGRESS',
                0,
                0
            );


            SET v_screening_id =
                LAST_INSERT_ID();


            /* =================================================
               6. CANDIDATS
               
               IMPORTANT :
               le filtre utilise maintenant les 3 formats.

               On exploite également les alias avec les 3 formats.

               Ainsi :
               
               OFAC :
               DOE, JOHN
               
               EU :
               JOHN DOE
               
               Autre source :
               DOE JOHN
               
               peuvent tous atteindre le moteur central.
               ================================================= */

            BEGIN

                DECLARE v_entity_done INT DEFAULT 0;


                DECLARE cur_entities CURSOR FOR

                    SELECT
                        se.id

                    FROM sanctions_entities se

                    WHERE se.screening_list_id =
                          v_list_id

                      AND se.is_active = 1


                      /* =======================================
                         TYPE
                         ======================================= */

                      AND
                      (
                          se.entity_type IS NULL

                          OR

                          UPPER(TRIM(se.entity_type))
                          =
                          UPPER(TRIM(v_client_type))
                      )


                      /* =======================================
                         NOM / ALIAS
                         ======================================= */

                      AND
                      (
                          /* -----------------------------------
                             PRIMARY NAME — FORMAT 1
                             ----------------------------------- */

                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      se.primary_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )
                          =
                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      v_client_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )


                          OR


                          /* -----------------------------------
                             PRIMARY NAME — FORMAT 2
                             ----------------------------------- */

                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      se.primary_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )
                          =
                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      v_client_name_alt1,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )


                          OR


                          /* -----------------------------------
                             PRIMARY NAME — FORMAT 3
                             ----------------------------------- */

                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      se.primary_name,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )
                          =
                          UPPER(
                              TRIM(
                                  REGEXP_REPLACE(
                                      v_client_name_alt2,
                                      '[[:space:]]+',
                                      ' '
                                  )
                              )
                          )


                          OR


                          /* -----------------------------------
                             ALIAS
                             ----------------------------------- */

                          EXISTS
                          (
                              SELECT 1

                              FROM sanctions_aliases sa

                              WHERE sa.sanctions_entity_id =
                                    se.id

                                AND
                                (
                                    /* ALIAS FORMAT 1 */

                                    UPPER(
                                        TRIM(
                                            REGEXP_REPLACE(
                                                sa.alias_name,
                                                '[[:space:]]+',
                                                ' '
                                            )
                                        )
                                    )
                                    =
                                    UPPER(
                                        TRIM(
                                            REGEXP_REPLACE(
                                                v_client_name,
                                                '[[:space:]]+',
                                                ' '
                                            )
                                        )
                                    )


                                    OR


                                    /* ALIAS FORMAT 2 */

                                    UPPER(
                                        TRIM(
                                            REGEXP_REPLACE(
                                                sa.alias_name,
                                                '[[:space:]]+',
                                                ' '
                                            )
                                        )
                                    )
                                    =
                                    UPPER(
                                        TRIM(
                                            REGEXP_REPLACE(
                                                v_client_name_alt1,
                                                '[[:space:]]+',
                                                ' '
                                            )
                                        )
                                    )


                                    OR


                                    /* ALIAS FORMAT 3 */

                                    UPPER(
                                        TRIM(
                                            REGEXP_REPLACE(
                                                sa.alias_name,
                                                '[[:space:]]+',
                                                ' '
                                            )
                                        )
                                    )
                                    =
                                    UPPER(
                                        TRIM(
                                            REGEXP_REPLACE(
                                                v_client_name_alt2,
                                                '[[:space:]]+',
                                                ' '
                                            )
                                        )
                                    )
                                )
                          )
                      )

                    GROUP BY se.id;


                DECLARE CONTINUE HANDLER FOR NOT FOUND
                    SET v_entity_done = 1;


                OPEN cur_entities;


                entity_loop:
                LOOP

                    FETCH cur_entities

                    INTO v_entity_id;


                    IF v_entity_done = 1 THEN
                        LEAVE entity_loop;
                    END IF;


                    /* =========================================
                       RESET
                       ========================================= */

                    SET v_match_score = 0;
                    SET v_match_status = 'CLEAR';

                    SET v_name_match = 0;
                    SET v_alias_match = 0;

                    SET v_birth_date_match = 0;
                    SET v_birth_year_match = 0;

                    SET v_nationality_match = 0;
                    SET v_gender_match = 0;

                    SET v_match_reason = '';


                    /* =========================================
                       MOTEUR CENTRAL
                       ========================================= */

                    CALL sp_match_client_to_sanction(

                        p_client_id,
                        v_entity_id,

                        v_match_score,
                        v_match_status,

                        v_name_match,
                        v_alias_match,

                        v_birth_date_match,
                        v_birth_year_match,

                        v_nationality_match,
                        v_gender_match,

                        v_match_reason
                    );


                    /* =========================================
                       MEILLEUR SCORE
                       ========================================= */

                    IF v_match_score > v_best_score THEN

                        SET v_best_score =
                            v_match_score;

                    END IF;


                    /* =========================================
                       ENREGISTREMENT MATCH
                       ========================================= */

                    IF v_match_score >= 50 THEN

                        INSERT INTO sanction_matches
                        (
                            client_id,
                            screening_id,
                            sanction_type,
                            authority,
                            reason,
                            match_score
                        )

                        SELECT

                            p_client_id,
                            v_screening_id,

                            COALESCE(
                                se.entity_type,
                                'SANCTIONS'
                            ),

                            se.source,

                            CONCAT(
                                'Entity ID=',
                                se.id,
                                ' ; Primary name=',
                                se.primary_name,
                                ' ; ',
                                v_match_reason
                            ),

                            v_match_score

                        FROM sanctions_entities se

                        WHERE se.id = v_entity_id

                          AND NOT EXISTS
                          (
                              SELECT 1

                              FROM sanction_matches sm

                              WHERE sm.client_id =
                                    p_client_id

                                AND sm.screening_id =
                                    v_screening_id

                                AND sm.reason LIKE
                                    CONCAT(
                                        '%Entity ID=',
                                        se.id,
                                        ' %'
                                    )
                          );

                    END IF;


                END LOOP;


                CLOSE cur_entities;

            END;


            /* =================================================
               7. RESULTAT SCREENING
               ================================================= */

            UPDATE screenings

            SET

                status =
                    CASE

                        WHEN v_best_score >= 85
                        THEN 'CONFIRMED_MATCH'

                        WHEN v_best_score >= 70
                        THEN 'HIGH_CONFIDENCE_MATCH'

                        WHEN v_best_score >= 50
                        THEN 'POTENTIAL_MATCH'

                        ELSE 'CLEAR'

                    END,


                match_found =
                    CASE

                        WHEN v_best_score >= 50
                        THEN 1

                        ELSE 0

                    END,


                confidence_score =
                    v_best_score

            WHERE id =
                  v_screening_id;

        END IF;


    END LOOP;


    CLOSE cur_lists;


END$$

DROP PROCEDURE IF EXISTS `sp_screen_clients_batch`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_screen_clients_batch` (IN `p_batch_size` INT, IN `p_force` TINYINT)   main_proc:
BEGIN

    DECLARE v_done INT DEFAULT 0;
    DECLARE v_client_id BIGINT;

    DECLARE v_processed INT DEFAULT 0;
    DECLARE v_before INT DEFAULT 0;
    DECLARE v_after INT DEFAULT 0;

    DECLARE v_screening_count INT DEFAULT 0;
    DECLARE v_match_count INT DEFAULT 0;


    /* --------------------------------------------------------
       Curseur clients
       -------------------------------------------------------- */

    DECLARE cur_clients CURSOR FOR

        SELECT c.id

        FROM clients c

        WHERE

        (
            COALESCE(p_force, 0) = 1

            OR

            NOT EXISTS
            (
                SELECT 1

                FROM screenings s

                WHERE s.client_id = c.id

                  AND s.screening_date >= CURDATE()

                  AND s.screening_date <
                      DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            )
        )

        ORDER BY c.id

        LIMIT p_batch_size;


    DECLARE CONTINUE HANDLER FOR NOT FOUND
        SET v_done = 1;


    /* ========================================================
       VALIDATION
       ======================================================== */

    IF p_batch_size IS NULL
       OR p_batch_size <= 0 THEN

        SELECT
            'ERROR' AS status,
            'La taille du batch doit être supérieure à 0.'
            AS message;

        LEAVE main_proc;

    END IF;


    /* ========================================================
       AVANT
       ======================================================== */

    SELECT COUNT(*)
    INTO v_before

    FROM clients c

    WHERE

    (
        COALESCE(p_force, 0) = 1

        OR

        NOT EXISTS
        (
            SELECT 1

            FROM screenings s

            WHERE s.client_id = c.id

              AND s.screening_date >= CURDATE()

              AND s.screening_date <
                  DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        )
    );


    /* ========================================================
       TRAITEMENT
       ======================================================== */

    OPEN cur_clients;


    client_loop:
    LOOP

        FETCH cur_clients
        INTO v_client_id;


        IF v_done = 1 THEN
            LEAVE client_loop;
        END IF;


        CALL sp_screen_client(
            v_client_id,
            COALESCE(p_force, 0)
        );


        SET v_processed = v_processed + 1;

    END LOOP;


    CLOSE cur_clients;


    /* ========================================================
       APRES
       ======================================================== */

    SELECT COUNT(*)
    INTO v_after

    FROM clients c

    WHERE

    (
        COALESCE(p_force, 0) = 1

        OR

        NOT EXISTS
        (
            SELECT 1

            FROM screenings s

            WHERE s.client_id = c.id

              AND s.screening_date >= CURDATE()

              AND s.screening_date <
                  DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        )
    );


    /* ========================================================
       STATISTIQUES
       ======================================================== */

    SELECT COUNT(*)
    INTO v_screening_count

    FROM screenings

    WHERE screening_date >= CURDATE()
      AND screening_date <
          DATE_ADD(CURDATE(), INTERVAL 1 DAY);


    SELECT COUNT(*)
    INTO v_match_count

    FROM screenings

    WHERE screening_date >= CURDATE()
      AND screening_date <
          DATE_ADD(CURDATE(), INTERVAL 1 DAY)

      AND match_found = 1;


    /* ========================================================
       RESULTAT UNIQUE
       ======================================================== 

    SELECT

        'SUCCESS' AS status,

        p_batch_size AS requested_batch_size,

        v_processed AS processed_clients,

        v_before AS pending_before,

        v_after AS pending_after,

        v_screening_count AS screenings_today,

        v_match_count AS screenings_with_match,

        NOW() AS execution_time; */

END$$

DROP PROCEDURE IF EXISTS `sp_screen_client_v1`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_screen_client_v1` (IN `p_client_id` BIGINT, IN `p_force` TINYINT)   main_proc:
BEGIN

    /* =========================================================
       VALIDATION CLIENT
       ========================================================= */

    IF NOT EXISTS (
        SELECT 1
        FROM clients
        WHERE id = p_client_id
    ) THEN

        SELECT
            'ERROR' AS status,
            'Client introuvable' AS message,
            p_client_id AS client_id;

        LEAVE main_proc;

    END IF;


    /* =========================================================
       VALIDATION SOUS-TYPE
       ========================================================= */

    IF NOT EXISTS (
        SELECT 1
        FROM client_individuals
        WHERE client_id = p_client_id
    )
    AND NOT EXISTS (
        SELECT 1
        FROM client_entities
        WHERE client_id = p_client_id
    ) THEN

        SELECT
            'ERROR' AS status,
            'Le client ne possède aucun profil individual/entity.' AS message,
            p_client_id AS client_id;

        LEAVE main_proc;

    END IF;


    /* =========================================================
       DELEGATION AU SCREENING PRINCIPAL
       ========================================================= */

    CALL sp_screen_client(
        p_client_id,
        p_force
    );


    /* =========================================================
       RESULTAT SYNTHETIQUE
       ========================================================= */

    SELECT

        'SUCCESS' AS status,

        p_client_id AS client_id,

        COUNT(DISTINCT s.id) AS screenings,

        COUNT(
            DISTINCT
            CASE
                WHEN s.match_found = 1
                THEN s.id
            END
        ) AS screenings_with_match,

        COUNT(DISTINCT sm.id) AS sanction_matches,

        COALESCE(
            MAX(s.confidence_score),
            0
        ) AS best_confidence_score,

        CASE

            WHEN COUNT(DISTINCT sm.id) > 0
                THEN 'MATCH'

            ELSE 'CLEAR'

        END AS final_screening_status

    FROM screenings s

    LEFT JOIN sanction_matches sm
        ON sm.screening_id = s.id

    WHERE s.client_id = p_client_id

      AND s.screening_date >= CURDATE()

      AND s.screening_date <
          DATE_ADD(
              CURDATE(),
              INTERVAL 1 DAY
          );

END$$

DROP PROCEDURE IF EXISTS `sp_update_client_risk`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_update_client_risk` (IN `p_client` BIGINT)   BEGIN


UPDATE clients

SET risk_score =

(
SELECT COALESCE(SUM(score),0)

FROM risk_assessments

WHERE client_id=p_client

)

WHERE id=p_client;


END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `account_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opening_balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `current_balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'XOF',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opened_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_number` (`account_number`),
  KEY `fk_accounts_client` (`client_id`),
  KEY `idx_accounts_currency` (`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `accounts`
--
DROP TRIGGER IF EXISTS `trg_account_initialize_balance`;
DELIMITER $$
CREATE TRIGGER `trg_account_initialize_balance` BEFORE INSERT ON `accounts` FOR EACH ROW BEGIN

    SET NEW.opening_balance =
        COALESCE(
            NEW.opening_balance,
            0.00
        );

    SET NEW.current_balance =
        NEW.opening_balance;

    SET NEW.currency =
        COALESCE(
            NULLIF(TRIM(NEW.currency), ''),
            'XOF'
        );

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `addresses`
--

DROP TABLE IF EXISTS `addresses`;
CREATE TABLE IF NOT EXISTS `addresses` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `agencies`
--

DROP TABLE IF EXISTS `agencies`;
CREATE TABLE IF NOT EXISTS `agencies` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `caisse_id` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_agencies_caisse` (`caisse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_models`
--

DROP TABLE IF EXISTS `ai_models`;
CREATE TABLE IF NOT EXISTS `ai_models` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `model_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `algorithm` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accuracy` decimal(5,2) DEFAULT NULL,
  `training_date` date DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

DROP TABLE IF EXISTS `alerts`;
CREATE TABLE IF NOT EXISTS `alerts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_id` bigint DEFAULT NULL,
  `transaction_id` bigint DEFAULT NULL,
  `alert_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'OPEN',
  `final_score` decimal(5,2) DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  UNIQUE KEY `uq_alert_transaction_type` (`transaction_id`,`alert_type`),
  KEY `idx_alert_status` (`status`,`priority`),
  KEY `idx_alert_transaction` (`transaction_id`),
  KEY `idx_alert_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `alert_actions`
--

DROP TABLE IF EXISTS `alert_actions`;
CREATE TABLE IF NOT EXISTS `alert_actions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `alert_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_alert_actions_alert` (`alert_id`),
  KEY `fk_alert_actions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `aml_risk_corridors`
--

DROP TABLE IF EXISTS `aml_risk_corridors`;
CREATE TABLE IF NOT EXISTS `aml_risk_corridors` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `country_from` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_to` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `risk_score` decimal(5,2) NOT NULL DEFAULT '45.00',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aml_corridor` (`country_from`,`country_to`),
  KEY `idx_corridor_from` (`country_from`),
  KEY `idx_corridor_to` (`country_to`),
  KEY `idx_corridor_active` (`active`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `aml_rules`
--

DROP TABLE IF EXISTS `aml_rules`;
CREATE TABLE IF NOT EXISTS `aml_rules` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `rule_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `severity` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rule_code` (`rule_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `aml_rule_parameters`
--

DROP TABLE IF EXISTS `aml_rule_parameters`;
CREATE TABLE IF NOT EXISTS `aml_rule_parameters` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `rule_id` bigint NOT NULL,
  `parameter_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parameter_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parameter_type` enum('INTEGER','DECIMAL','BOOLEAN','STRING') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DECIMAL',
  `parameter_value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parameter_category` enum('REGULATORY','INSTITUTIONAL','ANALYTICAL') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aml_rule_parameter` (`rule_id`,`parameter_code`),
  KEY `idx_aml_rule_parameters_rule` (`rule_id`),
  KEY `idx_aml_rule_parameters_active` (`is_active`),
  KEY `idx_aml_rule_parameters_category` (`parameter_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint DEFAULT NULL,
  `old_data` json DEFAULT NULL,
  `new_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `hash_previous` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hash_current` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_audit_logs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `beneficial_owners`
--

DROP TABLE IF EXISTS `beneficial_owners`;
CREATE TABLE IF NOT EXISTS `beneficial_owners` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `owner_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ownership_percentage` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_beneficial_owners_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
CREATE TABLE IF NOT EXISTS `cache_locks` (
  `key` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `caisses`
--

DROP TABLE IF EXISTS `caisses`;
CREATE TABLE IF NOT EXISTS `caisses` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Mali',
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','SUSPENDED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_caisses_code` (`code`),
  KEY `idx_caisses_status` (`status`),
  KEY `idx_caisses_country` (`country`),
  KEY `idx_caisses_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `centif_declarations`
--

DROP TABLE IF EXISTS `centif_declarations`;
CREATE TABLE IF NOT EXISTS `centif_declarations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `reference` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `alert_id` bigint NOT NULL,
  `client_id` bigint NOT NULL,
  `declared_by` bigint NOT NULL,
  `declaration_date` datetime DEFAULT NULL,
  `content_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transmission_status` enum('DRAFT','TRANSMITTED','ACKNOWLEDGED','OPPOSED','CLOSED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DRAFT',
  `centif_opposition_until` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `centif_declarations_reference_unique` (`reference`),
  KEY `fk_centif_declarations_alert` (`alert_id`),
  KEY `fk_centif_declarations_client` (`client_id`),
  KEY `fk_centif_declarations_user` (`declared_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
CREATE TABLE IF NOT EXISTS `clients` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('ACTIVE','SUSPENDED','BLOCKED','CLOSED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_pep` tinyint(1) DEFAULT '0',
  `risk_level_id` bigint DEFAULT NULL,
  `risk_score` decimal(5,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `agency_id` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_number` (`client_number`),
  KEY `idx_client_risk` (`risk_score`),
  KEY `fk_clients_risk_level` (`risk_level_id`),
  KEY `idx_clients_agency` (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `clients`
--
DROP TRIGGER IF EXISTS `trg_client_audit`;
DELIMITER $$
CREATE TRIGGER `trg_client_audit` AFTER UPDATE ON `clients` FOR EACH ROW BEGIN


INSERT INTO audit_logs

(
action,
entity,
entity_id
)

VALUES

(
'UPDATE',
'CLIENT',
NEW.id
);


END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_client_risk_update`;
DELIMITER $$
CREATE TRIGGER `trg_client_risk_update` AFTER UPDATE ON `clients` FOR EACH ROW BEGIN


IF OLD.risk_score <> NEW.risk_score THEN


INSERT INTO client_risk_history

(
client_id,
old_score,
new_score,
reason
)

VALUES

(
NEW.id,
OLD.risk_score,
NEW.risk_score,
'Automatic risk modification'
);


END IF;


END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_client_screening_after_insert`;
DELIMITER $$
CREATE TRIGGER `trg_client_screening_after_insert` AFTER INSERT ON `clients` FOR EACH ROW BEGIN

    IF COALESCE(@skip_client_screening, 0) = 0 THEN

        CALL sp_screen_client(
            NEW.id,
            0
        );

    END IF;

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `clients_backup_before_entity_migration`
--

DROP TABLE IF EXISTS `clients_backup_before_entity_migration`;
CREATE TABLE IF NOT EXISTS `clients_backup_before_entity_migration` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `nationality` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profession` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activity_sector` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_pep` tinyint(1) DEFAULT '0',
  `risk_level_id` bigint DEFAULT NULL,
  `risk_score` decimal(5,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_number` (`client_number`),
  KEY `idx_client_search` (`last_name`,`first_name`,`phone`),
  KEY `idx_client_risk` (`risk_score`),
  KEY `fk_clients_risk_level` (`risk_level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_aliases`
--

DROP TABLE IF EXISTS `client_aliases`;
CREATE TABLE IF NOT EXISTS `client_aliases` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint NOT NULL,
  `alias_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alias_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client_alias_name` (`alias_name`),
  KEY `fk_client_aliases_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_entities`
--

DROP TABLE IF EXISTS `client_entities`;
CREATE TABLE IF NOT EXISTS `client_entities` (
  `client_id` bigint NOT NULL,
  `legal_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_number` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_identification_number` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activity_sector` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`),
  KEY `idx_entity_legal_name` (`legal_name`),
  KEY `idx_entity_type` (`entity_type`),
  KEY `idx_entity_activity` (`activity_sector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_individuals`
--

DROP TABLE IF EXISTS `client_individuals`;
CREATE TABLE IF NOT EXISTS `client_individuals` (
  `client_id` bigint NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `nationality` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profession` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activity_sector` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`),
  KEY `idx_individual_name` (`last_name`,`first_name`),
  KEY `idx_individual_birth_date` (`birth_date`),
  KEY `idx_individual_nationality` (`nationality`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_risk_history`
--

DROP TABLE IF EXISTS `client_risk_history`;
CREATE TABLE IF NOT EXISTS `client_risk_history` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `old_score` decimal(5,2) DEFAULT NULL,
  `new_score` decimal(5,2) DEFAULT NULL,
  `old_level` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_level` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dataset_versions`
--

DROP TABLE IF EXISTS `dataset_versions`;
CREATE TABLE IF NOT EXISTS `dataset_versions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `dataset_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `generation_date` datetime NOT NULL,
  `record_count` bigint DEFAULT '0',
  `generator_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seed_value` bigint DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dataset_version` (`dataset_name`,`version`),
  KEY `idx_dv_name` (`dataset_name`),
  KEY `idx_dv_generation` (`generation_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `data_source_imports`
--

DROP TABLE IF EXISTS `data_source_imports`;
CREATE TABLE IF NOT EXISTS `data_source_imports` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `source_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_format` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `retrieved_at` datetime NOT NULL,
  `source_last_updated_at` datetime DEFAULT NULL,
  `record_count` bigint DEFAULT '0',
  `file_hash_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_status` enum('PENDING','RUNNING','SUCCESS','PARTIAL','FAILED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dsi_source` (`source_name`),
  KEY `idx_dsi_retrieved` (`retrieved_at`),
  KEY `idx_dsi_status` (`import_status`),
  KEY `idx_dsi_hash` (`file_hash_sha256`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `identity_documents`
--

DROP TABLE IF EXISTS `identity_documents`;
CREATE TABLE IF NOT EXISTS `identity_documents` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `document_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `fk_identity_documents_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `investigations`
--

DROP TABLE IF EXISTS `investigations`;
CREATE TABLE IF NOT EXISTS `investigations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `alert_id` bigint DEFAULT NULL,
  `assigned_user` bigint DEFAULT NULL,
  `decision` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_investigations_alert` (`alert_id`),
  KEY `fk_investigations_user` (`assigned_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
CREATE TABLE IF NOT EXISTS `job_batches` (
  `id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kyc_document_checks`
--

DROP TABLE IF EXISTS `kyc_document_checks`;
CREATE TABLE IF NOT EXISTS `kyc_document_checks` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `document_id` bigint DEFAULT NULL,
  `verification_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verification_result` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence_score` decimal(5,2) DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_kyc_document_checks_document` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kyc_reviews`
--

DROP TABLE IF EXISTS `kyc_reviews`;
CREATE TABLE IF NOT EXISTS `kyc_reviews` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `review_date` date DEFAULT NULL,
  `review_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `fk_kyc_reviews_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ml_customer_features`
--

DROP TABLE IF EXISTS `ml_customer_features`;
CREATE TABLE IF NOT EXISTS `ml_customer_features` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `transaction_count_30d` int DEFAULT NULL,
  `transaction_volume_30d` decimal(18,2) DEFAULT NULL,
  `average_transaction_amount` decimal(18,2) DEFAULT NULL,
  `cash_ratio` decimal(5,2) DEFAULT NULL,
  `night_transaction_ratio` decimal(5,2) DEFAULT NULL,
  `country_count` int DEFAULT NULL,
  `previous_alert_count` int DEFAULT NULL,
  `pep_flag` tinyint(1) DEFAULT NULL,
  `risk_label` tinyint(1) DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ml_customer_features_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_feedback`
--

DROP TABLE IF EXISTS `model_feedback`;
CREATE TABLE IF NOT EXISTS `model_feedback` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `prediction_id` bigint DEFAULT NULL,
  `analyst_decision` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_model_feedback_prediction` (`prediction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pep_matches`
--

DROP TABLE IF EXISTS `pep_matches`;
CREATE TABLE IF NOT EXISTS `pep_matches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `screening_id` bigint DEFAULT NULL,
  `pep_category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `match_score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pep_matches_client` (`client_id`),
  KEY `fk_pep_matches_screening` (`screening_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `predictions`
--

DROP TABLE IF EXISTS `predictions`;
CREATE TABLE IF NOT EXISTS `predictions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `model_id` bigint DEFAULT NULL,
  `client_id` bigint DEFAULT NULL,
  `transaction_id` bigint DEFAULT NULL,
  `predicted_risk` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `probability` decimal(5,2) DEFAULT NULL,
  `predicted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_predictions_model` (`model_id`),
  KEY `fk_predictions_client` (`client_id`),
  KEY `fk_predictions_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prediction_explanations`
--

DROP TABLE IF EXISTS `prediction_explanations`;
CREATE TABLE IF NOT EXISTS `prediction_explanations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `prediction_id` bigint DEFAULT NULL,
  `feature_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feature_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `importance_score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_prediction_explanations_prediction` (`prediction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `risk_assessments`
--

DROP TABLE IF EXISTS `risk_assessments`;
CREATE TABLE IF NOT EXISTS `risk_assessments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `transaction_id` bigint DEFAULT NULL,
  `risk_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `risk_level` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_risk_assessment_transaction_type` (`transaction_id`,`risk_type`),
  UNIQUE KEY `uq_risk_transaction_type_source` (`transaction_id`,`risk_type`,`source`),
  KEY `idx_risk_assessment_client` (`client_id`),
  KEY `idx_risk_assessment_type` (`risk_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `risk_levels`
--

DROP TABLE IF EXISTS `risk_levels`;
CREATE TABLE IF NOT EXISTS `risk_levels` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `score_min` int NOT NULL DEFAULT '0',
  `score_max` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `risk_scores`
--

DROP TABLE IF EXISTS `risk_scores`;
CREATE TABLE IF NOT EXISTS `risk_scores` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `pep_score` decimal(5,2) DEFAULT '0.00',
  `transaction_score` decimal(5,2) DEFAULT '0.00',
  `ai_score` decimal(5,2) DEFAULT '0.00',
  `final_score` decimal(5,2) DEFAULT NULL,
  `risk_decision` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `explanation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_risk_scores_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `risk_typologies`
--

DROP TABLE IF EXISTS `risk_typologies`;
CREATE TABLE IF NOT EXISTS `risk_typologies` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `default_score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rule_conditions`
--

DROP TABLE IF EXISTS `rule_conditions`;
CREATE TABLE IF NOT EXISTS `rule_conditions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `rule_id` bigint DEFAULT NULL,
  `field_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `value` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_rule_conditions_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rule_executions`
--

DROP TABLE IF EXISTS `rule_executions`;
CREATE TABLE IF NOT EXISTS `rule_executions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `rule_id` bigint DEFAULT NULL,
  `transaction_id` bigint DEFAULT NULL,
  `execution_result` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule_transaction` (`rule_id`,`transaction_id`),
  KEY `idx_rule_execution_transaction` (`transaction_id`),
  KEY `idx_rule_execution_result` (`execution_result`),
  KEY `idx_rule_executions_transaction_rule` (`transaction_id`,`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanctions_addresses`
--

DROP TABLE IF EXISTS `sanctions_addresses`;
CREATE TABLE IF NOT EXISTS `sanctions_addresses` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `sanctions_entity_id` bigint NOT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_address_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `place` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `po_box` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_iso2` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_info` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `as_at_listing_time` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_saddr_entity` (`sanctions_entity_id`),
  KEY `idx_saddr_country` (`country_iso2`),
  KEY `idx_saddr_city` (`city`),
  KEY `idx_saddr_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanctions_aliases`
--

DROP TABLE IF EXISTS `sanctions_aliases`;
CREATE TABLE IF NOT EXISTS `sanctions_aliases` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `sanctions_entity_id` bigint NOT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_alias_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alias_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `alias_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alias_language` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alias_quality` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `function_description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sa_entity` (`sanctions_entity_id`),
  KEY `idx_sa_name` (`alias_name`(191)),
  KEY `idx_sa_source` (`source`),
  KEY `idx_sa_type` (`alias_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanctions_birth_details`
--

DROP TABLE IF EXISTS `sanctions_birth_details`;
CREATE TABLE IF NOT EXISTS `sanctions_birth_details` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `sanctions_entity_id` bigint NOT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_birth_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_day` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_month` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_year` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_year_from` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_year_to` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `circa` tinyint(1) DEFAULT '0',
  `calendar_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_place` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_country_iso2` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_country` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sbd_entity` (`sanctions_entity_id`),
  KEY `idx_sbd_date` (`birth_date`),
  KEY `idx_sbd_country` (`birth_country_iso2`),
  KEY `idx_sbd_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanctions_entities`
--

DROP TABLE IF EXISTS `sanctions_entities`;
CREATE TABLE IF NOT EXISTS `sanctions_entities` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `screening_list_id` bigint NOT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_list` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_entity_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primary_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_entity_type_raw` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation_date` date DEFAULT NULL,
  `programme` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_or_function` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_original_script` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `un_list_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interpol_link` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vessel_call_sign` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vessel_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vessel_tonnage` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vessel_grt` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vessel_flag` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vessel_owner` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_un_reference_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_original_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_reviewed_on` date DEFAULT NULL,
  `source_last_updated_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sanctions_entity_source` (`source`,`source_entity_id`),
  KEY `fk_sanctions_entity_list` (`screening_list_id`),
  KEY `idx_se_name` (`primary_name`(191)),
  KEY `idx_se_normalized_name` (`normalized_name`(191)),
  KEY `idx_se_source` (`source`),
  KEY `idx_se_type` (`entity_type`),
  KEY `idx_se_active` (`is_active`),
  KEY `idx_se_reference` (`source_reference`(191)),
  KEY `idx_se_programme` (`programme`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanctions_identifiers`
--

DROP TABLE IF EXISTS `sanctions_identifiers`;
CREATE TABLE IF NOT EXISTS `sanctions_identifiers` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `sanctions_entity_id` bigint NOT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_identifier_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identifier_number` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identifier_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identifier_type_description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issuing_country` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issuing_place` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_by` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `name_on_document` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diplomatic` tinyint(1) DEFAULT NULL,
  `known_expired` tinyint(1) DEFAULT NULL,
  `known_false` tinyint(1) DEFAULT NULL,
  `reported_lost` tinyint(1) DEFAULT NULL,
  `revoked_by_issuer` tinyint(1) DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_si_entity` (`sanctions_entity_id`),
  KEY `idx_si_number` (`identifier_number`(191)),
  KEY `idx_si_type` (`identifier_type`),
  KEY `idx_si_country` (`issuing_country`),
  KEY `idx_si_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanctions_import_batches`
--

DROP TABLE IF EXISTS `sanctions_import_batches`;
CREATE TABLE IF NOT EXISTS `sanctions_import_batches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_file_hash_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_version` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `retrieved_at` datetime DEFAULT NULL,
  `imported_at` datetime DEFAULT NULL,
  `record_count_raw` bigint DEFAULT '0',
  `record_count_imported` bigint DEFAULT '0',
  `import_status` enum('PENDING','RUNNING','SUCCESS','PARTIAL','FAILED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sib_source` (`source`),
  KEY `idx_sib_status` (`import_status`),
  KEY `idx_sib_hash` (`source_file_hash_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanctions_programs_designations`
--

DROP TABLE IF EXISTS `sanctions_programs_designations`;
CREATE TABLE IF NOT EXISTS `sanctions_programs_designations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `sanctions_entity_id` bigint NOT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `programme` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `regulation_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `organisation_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `publication_date` date DEFAULT NULL,
  `entry_into_force_date` date DEFAULT NULL,
  `number_title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `publication_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_spd_entity` (`sanctions_entity_id`),
  KEY `idx_spd_programme` (`programme`(191)),
  KEY `idx_spd_designation` (`designation`(191)),
  KEY `idx_spd_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sanction_matches`
--

DROP TABLE IF EXISTS `sanction_matches`;
CREATE TABLE IF NOT EXISTS `sanction_matches` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `screening_id` bigint DEFAULT NULL,
  `sanction_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `authority` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `match_score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sanction_matches_client` (`client_id`),
  KEY `fk_sanction_matches_screening` (`screening_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `screenings`
--

DROP TABLE IF EXISTS `screenings`;
CREATE TABLE IF NOT EXISTS `screenings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `client_id` bigint DEFAULT NULL,
  `screening_list_id` bigint DEFAULT NULL,
  `screening_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `match_found` tinyint(1) DEFAULT '0',
  `confidence_score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_screenings_client` (`client_id`),
  KEY `fk_screenings_list` (`screening_list_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `screening_lists`
--

DROP TABLE IF EXISTS `screening_lists`;
CREATE TABLE IF NOT EXISTS `screening_lists` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_organization` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_update` date DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `screening_list_entries`
--

DROP TABLE IF EXISTS `screening_list_entries`;
CREATE TABLE IF NOT EXISTS `screening_list_entries` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `screening_list_id` bigint NOT NULL,
  `external_reference` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `primary_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aliases` json DEFAULT NULL,
  `nationality` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `program` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation_date` date DEFAULT NULL,
  `source_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_updated_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_screening_entry_name` (`primary_name`(250)),
  KEY `idx_screening_entry_normalized` (`normalized_name`(250)),
  KEY `idx_screening_entry_reference` (`external_reference`),
  KEY `idx_screening_entry_active` (`is_active`),
  KEY `fk_screening_entries_list` (`screening_list_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_sanctions_addresses`
--

DROP TABLE IF EXISTS `stg_sanctions_addresses`;
CREATE TABLE IF NOT EXISTS `stg_sanctions_addresses` (
  `source` varchar(20) DEFAULT NULL,
  `source_entity_id` varchar(255) DEFAULT NULL,
  `source_address_id` varchar(255) DEFAULT NULL,
  `street` varchar(500) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state_province` varchar(255) DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `place` varchar(255) DEFAULT NULL,
  `postal_code` varchar(100) DEFAULT NULL,
  `po_box` varchar(100) DEFAULT NULL,
  `country_iso2` varchar(10) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `contact_info` varchar(500) DEFAULT NULL,
  `as_at_listing_time` varchar(255) DEFAULT NULL,
  `remark` text,
  KEY `idx_stg_addr_entity` (`source`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_sanctions_aliases`
--

DROP TABLE IF EXISTS `stg_sanctions_aliases`;
CREATE TABLE IF NOT EXISTS `stg_sanctions_aliases` (
  `source` varchar(20) DEFAULT NULL,
  `source_entity_id` varchar(255) DEFAULT NULL,
  `source_alias_id` varchar(255) DEFAULT NULL,
  `alias_name` varchar(500) DEFAULT NULL,
  `alias_type` varchar(100) DEFAULT NULL,
  `alias_language` varchar(100) DEFAULT NULL,
  `alias_quality` varchar(100) DEFAULT NULL,
  `gender` varchar(50) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `function_description` varchar(500) DEFAULT NULL,
  `source_note` text,
  KEY `idx_stg_alias_entity` (`source`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_sanctions_birth_details`
--

DROP TABLE IF EXISTS `stg_sanctions_birth_details`;
CREATE TABLE IF NOT EXISTS `stg_sanctions_birth_details` (
  `source` varchar(20) DEFAULT NULL,
  `source_entity_id` varchar(255) DEFAULT NULL,
  `source_birth_id` varchar(255) DEFAULT NULL,
  `birth_date` varchar(50) DEFAULT NULL,
  `birth_day` varchar(20) DEFAULT NULL,
  `birth_month` varchar(20) DEFAULT NULL,
  `birth_year` varchar(20) DEFAULT NULL,
  `birth_year_from` varchar(20) DEFAULT NULL,
  `birth_year_to` varchar(20) DEFAULT NULL,
  `circa` varchar(20) DEFAULT NULL,
  `calendar_type` varchar(100) DEFAULT NULL,
  `date_type_raw` varchar(100) DEFAULT NULL,
  `birth_place` varchar(500) DEFAULT NULL,
  `birth_city` varchar(255) DEFAULT NULL,
  `birth_state_province` varchar(255) DEFAULT NULL,
  `birth_country_iso2` varchar(10) DEFAULT NULL,
  `birth_country` varchar(255) DEFAULT NULL,
  `remark` text,
  KEY `idx_stg_birth_entity` (`source`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_sanctions_entities`
--

DROP TABLE IF EXISTS `stg_sanctions_entities`;
CREATE TABLE IF NOT EXISTS `stg_sanctions_entities` (
  `source` varchar(20) DEFAULT NULL,
  `source_list` varchar(150) DEFAULT NULL,
  `source_entity_id` varchar(255) DEFAULT NULL,
  `source_reference` varchar(255) DEFAULT NULL,
  `primary_name` varchar(500) DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `designation_date` varchar(50) DEFAULT NULL,
  `programme` varchar(500) DEFAULT NULL,
  `source_remarks` text,
  `source_comments` text,
  `source_original_file` varchar(255) DEFAULT NULL,
  `nationality` varchar(255) DEFAULT NULL,
  `gender` varchar(50) DEFAULT NULL,
  `title_or_function` varchar(500) DEFAULT NULL,
  `name_original_script` text,
  `un_list_type` varchar(255) DEFAULT NULL,
  `interpol_link` varchar(500) DEFAULT NULL,
  `vessel_call_sign` varchar(255) DEFAULT NULL,
  `vessel_type` varchar(255) DEFAULT NULL,
  `vessel_tonnage` varchar(100) DEFAULT NULL,
  `vessel_grt` varchar(100) DEFAULT NULL,
  `vessel_flag` varchar(255) DEFAULT NULL,
  `vessel_owner` varchar(500) DEFAULT NULL,
  `entity_un_reference_id` varchar(255) DEFAULT NULL,
  `last_reviewed_on` varchar(50) DEFAULT NULL,
  `last_day_updated` varchar(100) DEFAULT NULL,
  KEY `idx_stg_ent_source_id` (`source`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_sanctions_identifiers`
--

DROP TABLE IF EXISTS `stg_sanctions_identifiers`;
CREATE TABLE IF NOT EXISTS `stg_sanctions_identifiers` (
  `source` varchar(20) DEFAULT NULL,
  `source_entity_id` varchar(255) DEFAULT NULL,
  `source_identifier_id` varchar(255) DEFAULT NULL,
  `identifier_number` varchar(500) DEFAULT NULL,
  `identifier_type` varchar(255) DEFAULT NULL,
  `identifier_type_description` varchar(500) DEFAULT NULL,
  `issuing_country` varchar(255) DEFAULT NULL,
  `issuing_country_iso2` varchar(10) DEFAULT NULL,
  `issuing_place` varchar(500) DEFAULT NULL,
  `issued_by` varchar(500) DEFAULT NULL,
  `issued_date` varchar(50) DEFAULT NULL,
  `valid_from` varchar(50) DEFAULT NULL,
  `valid_to` varchar(50) DEFAULT NULL,
  `name_on_document` varchar(500) DEFAULT NULL,
  `diplomatic` varchar(20) DEFAULT NULL,
  `known_expired` varchar(20) DEFAULT NULL,
  `known_false` varchar(20) DEFAULT NULL,
  `reported_lost` varchar(20) DEFAULT NULL,
  `revoked_by_issuer` varchar(20) DEFAULT NULL,
  `remark` text,
  KEY `idx_stg_identifier_entity` (`source`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_sanctions_programs_designations`
--

DROP TABLE IF EXISTS `stg_sanctions_programs_designations`;
CREATE TABLE IF NOT EXISTS `stg_sanctions_programs_designations` (
  `source` varchar(20) DEFAULT NULL,
  `source_entity_id` varchar(255) DEFAULT NULL,
  `programme` varchar(500) DEFAULT NULL,
  `designation` varchar(500) DEFAULT NULL,
  `regulation_type` varchar(255) DEFAULT NULL,
  `organisation_type` varchar(255) DEFAULT NULL,
  `publication_date` varchar(50) DEFAULT NULL,
  `entry_into_force_date` varchar(50) DEFAULT NULL,
  `number_title` varchar(500) DEFAULT NULL,
  `publication_url` varchar(1000) DEFAULT NULL,
  `source_note` text,
  KEY `idx_stg_program_entity` (`source`,`source_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_synthetic_accounts`
--

DROP TABLE IF EXISTS `stg_synthetic_accounts`;
CREATE TABLE IF NOT EXISTS `stg_synthetic_accounts` (
  `account_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opened_date` date DEFAULT NULL,
  `synthetic_record` tinyint DEFAULT NULL,
  PRIMARY KEY (`account_id`),
  KEY `idx_stg_account_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_synthetic_clients`
--

DROP TABLE IF EXISTS `stg_synthetic_clients`;
CREATE TABLE IF NOT EXISTS `stg_synthetic_clients` (
  `client_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pep_flag` tinyint DEFAULT NULL,
  `synthetic_record` tinyint DEFAULT NULL,
  PRIMARY KEY (`client_id`),
  KEY `idx_stg_client_number` (`client_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stg_synthetic_transactions`
--

DROP TABLE IF EXISTS `stg_synthetic_transactions`;
CREATE TABLE IF NOT EXISTS `stg_synthetic_transactions` (
  `transaction_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_date` datetime DEFAULT NULL,
  `transaction_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_xof` decimal(18,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_from` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_to` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scenario_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_risk_level` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_alert` tinyint DEFAULT NULL,
  `synthetic_record` tinyint DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  UNIQUE KEY `uq_stg_transaction_reference` (`transaction_reference`),
  KEY `idx_stg_tx_account` (`account_id`),
  KEY `idx_stg_tx_client` (`client_id`),
  KEY `idx_stg_tx_scenario` (`scenario_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sync_queue`
--

DROP TABLE IF EXISTS `sync_queue`;
CREATE TABLE IF NOT EXISTS `sync_queue` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint DEFAULT NULL,
  `operation` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `sync_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'PENDING',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `synthetic_scenarios`
--

DROP TABLE IF EXISTS `synthetic_scenarios`;
CREATE TABLE IF NOT EXISTS `synthetic_scenarios` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `scenario_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scenario_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `expected_risk_level` enum('LOW','MEDIUM','HIGH','CRITICAL') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LOW',
  `expected_alert` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scenario_code` (`scenario_code`),
  KEY `idx_ss_category` (`category`),
  KEY `idx_ss_risk` (`expected_risk_level`),
  KEY `idx_ss_alert` (`expected_alert`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `transaction_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` bigint DEFAULT NULL,
  `transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PAYMENT',
  `amount` decimal(18,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'XOF',
  `channel` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_from` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_to` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_date` datetime DEFAULT NULL,
  `transaction_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'COMPLETED',
  `status_changed_at` datetime DEFAULT NULL,
  `cancellation_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reversal_of_transaction_id` bigint DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `agency_id` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_reference` (`transaction_reference`),
  KEY `idx_transaction_monitoring` (`account_id`,`transaction_date`),
  KEY `idx_aml_tx_type_date` (`transaction_type`,`transaction_date`),
  KEY `idx_transaction_status` (`transaction_status`),
  KEY `idx_transaction_status_date` (`transaction_status`,`transaction_date`),
  KEY `idx_transaction_reversal` (`reversal_of_transaction_id`),
  KEY `idx_transactions_country_from` (`country_from`),
  KEY `idx_transactions_country_to` (`country_to`),
  KEY `idx_transactions_account_type_date` (`account_id`,`transaction_type`,`transaction_date`),
  KEY `idx_transactions_account_channel_date` (`account_id`,`channel`,`transaction_date`),
  KEY `idx_transactions_agency` (`agency_id`),
  KEY `idx_transactions_agency_date` (`agency_id`,`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `transactions`
--
DROP TRIGGER IF EXISTS `trg_account_balance_after_transaction_delete`;
DELIMITER $$
CREATE TRIGGER `trg_account_balance_after_transaction_delete` AFTER DELETE ON `transactions` FOR EACH ROW BEGIN

    IF UPPER(
        COALESCE(
            OLD.transaction_status,
            'COMPLETED'
        )
    ) = 'COMPLETED' THEN

        UPDATE accounts
        SET current_balance =
            current_balance
            -
            CASE

                WHEN OLD.transaction_type IN
                (
                    'DEPOSIT',
                    'TRANSFER_IN'
                )
                THEN COALESCE(OLD.amount,0)

                WHEN OLD.transaction_type IN
                (
                    'PAYMENT',
                    'TRANSFER_OUT',
                    'WITHDRAWAL'
                )
                THEN -COALESCE(OLD.amount,0)

                ELSE 0

            END

        WHERE id = OLD.account_id;

    END IF;

END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_account_balance_after_transaction_insert`;
DELIMITER $$
CREATE TRIGGER `trg_account_balance_after_transaction_insert` AFTER INSERT ON `transactions` FOR EACH ROW BEGIN

    IF UPPER(
        COALESCE(
            NEW.transaction_status,
            'COMPLETED'
        )
    ) = 'COMPLETED' THEN

        UPDATE accounts
        SET current_balance =
            current_balance
            +
            CASE

                WHEN NEW.transaction_type IN
                (
                    'DEPOSIT',
                    'TRANSFER_IN'
                )
                THEN COALESCE(NEW.amount,0)

                WHEN NEW.transaction_type IN
                (
                    'PAYMENT',
                    'TRANSFER_OUT',
                    'WITHDRAWAL'
                )
                THEN -COALESCE(NEW.amount,0)

                ELSE 0

            END

        WHERE id = NEW.account_id;

    END IF;

END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_account_balance_after_transaction_update`;
DELIMITER $$
CREATE TRIGGER `trg_account_balance_after_transaction_update` AFTER UPDATE ON `transactions` FOR EACH ROW BEGIN

    /* --------------------------------------------------------
       1. Retrait de l'ancien impact
       -------------------------------------------------------- */

    IF UPPER(
        COALESCE(
            OLD.transaction_status,
            'COMPLETED'
        )
    ) = 'COMPLETED' THEN

        UPDATE accounts
        SET current_balance =
            current_balance
            -
            CASE

                WHEN OLD.transaction_type IN
                (
                    'DEPOSIT',
                    'TRANSFER_IN'
                )
                THEN COALESCE(OLD.amount,0)

                WHEN OLD.transaction_type IN
                (
                    'PAYMENT',
                    'TRANSFER_OUT',
                    'WITHDRAWAL'
                )
                THEN -COALESCE(OLD.amount,0)

                ELSE 0

            END

        WHERE id = OLD.account_id;

    END IF;


    /* --------------------------------------------------------
       2. Application du nouvel impact
       -------------------------------------------------------- */

    IF UPPER(
        COALESCE(
            NEW.transaction_status,
            'COMPLETED'
        )
    ) = 'COMPLETED' THEN

        UPDATE accounts
        SET current_balance =
            current_balance
            +
            CASE

                WHEN NEW.transaction_type IN
                (
                    'DEPOSIT',
                    'TRANSFER_IN'
                )
                THEN COALESCE(NEW.amount,0)

                WHEN NEW.transaction_type IN
                (
                    'PAYMENT',
                    'TRANSFER_OUT',
                    'WITHDRAWAL'
                )
                THEN -COALESCE(NEW.amount,0)

                ELSE 0

            END

        WHERE id = NEW.account_id;

    END IF;

END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_aml_transaction_realtime`;
DELIMITER $$
CREATE TRIGGER `trg_aml_transaction_realtime` AFTER INSERT ON `transactions` FOR EACH ROW BEGIN

    CALL sp_aml_evaluate_transaction_trigger(
        NEW.id
    );

END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_transaction_status_audit`;
DELIMITER $$
CREATE TRIGGER `trg_transaction_status_audit` AFTER UPDATE ON `transactions` FOR EACH ROW BEGIN

IF OLD.transaction_status <> NEW.transaction_status THEN

INSERT INTO audit_logs
(
action,
entity,
entity_id,
old_data,
new_data
)
VALUES
(
'STATUS_CHANGE',
'TRANSACTION',
NEW.id,

JSON_OBJECT(
'transaction_status',
OLD.transaction_status
),

JSON_OBJECT(
'transaction_status',
NEW.transaction_status,
'status_changed_at',
NEW.status_changed_at,
'cancellation_reason',
NEW.cancellation_reason,
'reversal_of_transaction_id',
NEW.reversal_of_transaction_id
)
);

END IF;

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `agency_id` bigint DEFAULT NULL,
  `role_id` bigint DEFAULT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `fk_users_agency` (`agency_id`),
  KEY `fk_users_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_aml_dashboard`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_aml_dashboard`;
CREATE TABLE IF NOT EXISTS `v_aml_dashboard` (
`open_alerts` bigint
,`risky_clients` bigint
,`total_alerts` bigint
,`total_clients` bigint
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_assist_alert_context`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_assist_alert_context`;
CREATE TABLE IF NOT EXISTS `v_assist_alert_context` (
`alert_created_at` timestamp
,`alert_description` text
,`alert_final_score` decimal(5,2)
,`alert_id` bigint
,`alert_reference` varchar(100)
,`alert_status` varchar(30)
,`alert_title` varchar(255)
,`alert_type` varchar(100)
,`channel` varchar(50)
,`client_id` bigint
,`client_number` varchar(50)
,`client_risk_level` varchar(30)
,`client_risk_score` decimal(5,2)
,`client_status` enum('ACTIVE','SUSPENDED','BLOCKED','CLOSED')
,`client_type` varchar(50)
,`country_from` varchar(100)
,`country_to` varchar(100)
,`currency` varchar(10)
,`customer_name` varchar(500)
,`has_pep_match` int
,`has_sanction_match` int
,`is_pep` tinyint(1)
,`matched_rule_codes` text
,`matched_rules_count` bigint
,`max_risk_assessment_score` decimal(5,2)
,`priority` varchar(30)
,`transaction_amount` decimal(18,2)
,`transaction_date` datetime
,`transaction_id` bigint
,`transaction_reference` varchar(100)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_assist_client_context`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_assist_client_context`;
CREATE TABLE IF NOT EXISTS `v_assist_client_context` (
`accounts_count` bigint
,`agency_id` bigint
,`agency_name` varchar(150)
,`caisse_name` varchar(150)
,`client_id` bigint
,`client_number` varchar(50)
,`client_status` enum('ACTIVE','SUSPENDED','BLOCKED','CLOSED')
,`client_type` varchar(50)
,`customer_name` varchar(500)
,`has_pep_match` int
,`has_sanction_match` int
,`is_pep` tinyint(1)
,`open_alerts` bigint
,`risk_level` varchar(30)
,`risk_score` decimal(5,2)
,`total_alerts` bigint
,`tx_count_30d` bigint
,`tx_volume_30d` decimal(40,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_customer_aml_profile`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_customer_aml_profile`;
CREATE TABLE IF NOT EXISTS `v_customer_aml_profile` (
`account_count` bigint
,`active_account_count` bigint
,`alert_count` bigint
,`client_agency_city` varchar(100)
,`client_agency_code` varchar(20)
,`client_agency_id` bigint
,`client_agency_name` varchar(150)
,`client_caisse_city` varchar(100)
,`client_caisse_code` varchar(20)
,`client_caisse_id` bigint
,`client_caisse_name` varchar(150)
,`client_id` bigint
,`client_number` varchar(50)
,`client_type` varchar(10)
,`customer_name` varchar(500)
,`is_pep` tinyint(1)
,`risk_level` varchar(30)
,`risk_score` decimal(5,2)
,`total_current_balance` decimal(40,2)
,`total_volume` decimal(40,2)
,`transaction_count` bigint
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_customer_compliance_profile`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_customer_compliance_profile`;
CREATE TABLE IF NOT EXISTS `v_customer_compliance_profile` (
`account_count` bigint
,`active_account_count` bigint
,`alerts` bigint
,`client_agency_city` varchar(100)
,`client_agency_code` varchar(20)
,`client_agency_id` bigint
,`client_agency_name` varchar(150)
,`client_caisse_city` varchar(100)
,`client_caisse_code` varchar(20)
,`client_caisse_id` bigint
,`client_caisse_name` varchar(150)
,`client_number` varchar(50)
,`client_type` varchar(10)
,`customer_name` varchar(500)
,`id` bigint
,`is_pep` tinyint(1)
,`risk_level` varchar(30)
,`risk_score` decimal(5,2)
,`total_current_balance` decimal(40,2)
,`transactions` bigint
);

-- --------------------------------------------------------

--
-- Table structure for table `v_management_dashboard`
--

DROP TABLE IF EXISTS `v_management_dashboard`;
CREATE TABLE IF NOT EXISTS `v_management_dashboard` (
  `total_clients` bigint DEFAULT NULL,
  `risky_clients` decimal(23,0) DEFAULT NULL,
  `alerts` bigint DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_customer_features_current`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_customer_features_current`;
CREATE TABLE IF NOT EXISTS `v_ml_customer_features_current` (
`account_count` bigint
,`active_account_count` bigint
,`activity_sector` varchar(150)
,`average_transaction_amount` decimal(22,6)
,`birth_date` date
,`cash_ratio` decimal(27,4)
,`client_id` bigint
,`client_number` varchar(50)
,`client_type` varchar(10)
,`country_count` bigint
,`existing_ml_risk_label` int
,`first_name` varchar(100)
,`gender` varchar(20)
,`last_name` varchar(100)
,`nationality` varchar(100)
,`night_transaction_ratio` decimal(27,4)
,`pep_flag` int
,`pep_match_count` bigint
,`previous_alert_count` bigint
,`primary_name` varchar(500)
,`profession` varchar(150)
,`sanction_match_count` bigint
,`total_current_balance` decimal(40,2)
,`transaction_count_30d` bigint
,`transaction_count_total` bigint
,`transaction_volume_30d` decimal(40,2)
,`transaction_volume_total` decimal(40,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_dataset_behavioral`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_dataset_behavioral`;
CREATE TABLE IF NOT EXISTS `v_ml_dataset_behavioral` (
`account_id` bigint
,`account_number` varchar(50)
,`account_status` varchar(30)
,`account_type` varchar(50)
,`activity_sector` varchar(150)
,`amount` decimal(18,2)
,`average_transaction_amount_30d` decimal(22,6)
,`birth_date` date
,`cash_ratio_30d` decimal(27,4)
,`cash_transaction_count_30d` bigint
,`channel` varchar(50)
,`client_agency_city` varchar(100)
,`client_agency_code` varchar(20)
,`client_agency_id` bigint
,`client_agency_name` varchar(150)
,`client_caisse_city` varchar(100)
,`client_caisse_code` varchar(20)
,`client_caisse_id` bigint
,`client_caisse_name` varchar(150)
,`client_id` bigint
,`client_number` varchar(50)
,`client_type` varchar(50)
,`country` varchar(100)
,`country_count_30d` bigint
,`country_from` varchar(100)
,`country_to` varchar(100)
,`currency` varchar(10)
,`gender` varchar(20)
,`is_pep` tinyint(1)
,`max_sanction_match_score` decimal(5,2)
,`max_screening_confidence` decimal(5,2)
,`nationality` varchar(100)
,`pep_flag` int
,`previous_transaction_count_24h` bigint
,`previous_transaction_count_30d` bigint
,`previous_transaction_count_60m` bigint
,`previous_volume_24h` decimal(40,2)
,`previous_volume_30d` decimal(40,2)
,`profession` varchar(150)
,`sanction_match_count` bigint
,`sanction_match_flag` int
,`screening_count` bigint
,`screening_match_count` bigint
,`total_transaction_count_30d` bigint
,`transaction_agency_city` varchar(100)
,`transaction_agency_code` varchar(20)
,`transaction_agency_id` bigint
,`transaction_agency_name` varchar(150)
,`transaction_caisse_city` varchar(100)
,`transaction_caisse_code` varchar(20)
,`transaction_caisse_id` bigint
,`transaction_caisse_name` varchar(150)
,`transaction_date` datetime
,`transaction_day_of_week` int
,`transaction_hour` int
,`transaction_id` bigint
,`transaction_outside_client_agency` int
,`transaction_reference` varchar(100)
,`transaction_status` varchar(30)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_dataset_hybrid`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_dataset_hybrid`;
CREATE TABLE IF NOT EXISTS `v_ml_dataset_hybrid` (
`account_id` bigint
,`account_number` varchar(50)
,`account_status` varchar(30)
,`account_type` varchar(50)
,`activity_sector` varchar(150)
,`aml_alert_flag` int
,`aml_alert_priority` varchar(30)
,`aml_alert_score` decimal(5,2)
,`aml_risk_level` varchar(8)
,`aml_rule_execution_count` bigint
,`aml_rule_match_count` bigint
,`aml_rule_score` decimal(27,2)
,`amount` decimal(18,2)
,`average_transaction_amount_30d` decimal(22,6)
,`birth_date` date
,`cash_ratio_30d` decimal(27,4)
,`cash_transaction_count_30d` bigint
,`channel` varchar(50)
,`client_agency_city` varchar(100)
,`client_agency_code` varchar(20)
,`client_agency_id` bigint
,`client_agency_name` varchar(150)
,`client_caisse_city` varchar(100)
,`client_caisse_code` varchar(20)
,`client_caisse_id` bigint
,`client_caisse_name` varchar(150)
,`client_id` bigint
,`client_number` varchar(50)
,`client_type` varchar(50)
,`country` varchar(100)
,`country_count_30d` bigint
,`country_from` varchar(100)
,`country_to` varchar(100)
,`currency` varchar(10)
,`gender` varchar(20)
,`is_pep` tinyint(1)
,`max_sanction_match_score` decimal(5,2)
,`max_screening_confidence` decimal(5,2)
,`nationality` varchar(100)
,`pep_flag` int
,`previous_transaction_count_24h` bigint
,`previous_transaction_count_30d` bigint
,`previous_transaction_count_60m` bigint
,`previous_volume_24h` decimal(40,2)
,`previous_volume_30d` decimal(40,2)
,`profession` varchar(150)
,`risk_assessment_count` bigint
,`sanction_match_count` bigint
,`sanction_match_flag` int
,`screening_count` bigint
,`screening_match_count` bigint
,`total_transaction_count_30d` bigint
,`transaction_agency_city` varchar(100)
,`transaction_agency_code` varchar(20)
,`transaction_agency_id` bigint
,`transaction_agency_name` varchar(150)
,`transaction_caisse_city` varchar(100)
,`transaction_caisse_code` varchar(20)
,`transaction_caisse_id` bigint
,`transaction_caisse_name` varchar(150)
,`transaction_date` datetime
,`transaction_day_of_week` int
,`transaction_hour` int
,`transaction_id` bigint
,`transaction_outside_client_agency` int
,`transaction_reference` varchar(100)
,`transaction_status` varchar(30)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_dataset_master`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_dataset_master`;
CREATE TABLE IF NOT EXISTS `v_ml_dataset_master` (
`account_id` bigint
,`account_number` varchar(50)
,`account_status` varchar(30)
,`account_type` varchar(50)
,`activity_sector` varchar(150)
,`aml_alert_flag` int
,`aml_alert_priority` varchar(30)
,`aml_alert_score` decimal(5,2)
,`aml_risk_level` varchar(8)
,`aml_rule_execution_count` bigint
,`aml_rule_match_count` bigint
,`aml_rule_score` decimal(27,2)
,`amount` decimal(18,2)
,`average_transaction_amount_30d` decimal(22,6)
,`birth_date` date
,`cash_ratio_30d` decimal(27,4)
,`cash_transaction_count_30d` bigint
,`channel` varchar(50)
,`client_agency_city` varchar(100)
,`client_agency_code` varchar(20)
,`client_agency_id` bigint
,`client_agency_name` varchar(150)
,`client_caisse_city` varchar(100)
,`client_caisse_code` varchar(20)
,`client_caisse_id` bigint
,`client_caisse_name` varchar(150)
,`client_id` bigint
,`client_number` varchar(50)
,`client_type` varchar(50)
,`country` varchar(100)
,`country_count_30d` bigint
,`country_from` varchar(100)
,`country_to` varchar(100)
,`currency` varchar(10)
,`gender` varchar(20)
,`is_pep` tinyint(1)
,`max_sanction_match_score` decimal(5,2)
,`max_screening_confidence` decimal(5,2)
,`nationality` varchar(100)
,`pep_flag` int
,`previous_transaction_count_24h` bigint
,`previous_transaction_count_30d` bigint
,`previous_transaction_count_60m` bigint
,`previous_volume_24h` decimal(40,2)
,`previous_volume_30d` decimal(40,2)
,`profession` varchar(150)
,`risk_assessment_count` bigint
,`sanction_match_count` bigint
,`sanction_match_flag` int
,`screening_count` bigint
,`screening_match_count` bigint
,`total_transaction_count_30d` bigint
,`transaction_agency_city` varchar(100)
,`transaction_agency_code` varchar(20)
,`transaction_agency_id` bigint
,`transaction_agency_name` varchar(150)
,`transaction_caisse_city` varchar(100)
,`transaction_caisse_code` varchar(20)
,`transaction_caisse_id` bigint
,`transaction_caisse_name` varchar(150)
,`transaction_date` datetime
,`transaction_day_of_week` int
,`transaction_hour` int
,`transaction_id` bigint
,`transaction_outside_client_agency` int
,`transaction_reference` varchar(100)
,`transaction_status` varchar(30)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_transaction_dataset`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_transaction_dataset`;
CREATE TABLE IF NOT EXISTS `v_ml_transaction_dataset` (
`account_id` bigint
,`account_status` varchar(30)
,`account_type` varchar(50)
,`activity_sector` varchar(150)
,`amount` decimal(18,2)
,`cash_ratio_30d` decimal(27,4)
,`channel` varchar(50)
,`client_id` bigint
,`client_type` varchar(50)
,`configured_corridor_score` decimal(5,2)
,`configured_high_risk_corridor` int
,`country` varchar(100)
,`country_from` varchar(100)
,`country_to` varchar(100)
,`cumulative_amount_24h_including_current` decimal(40,2)
,`currency` varchar(10)
,`is_night_transaction` int
,`is_pep` tinyint(1)
,`label_available` int
,`label_source` varchar(25)
,`nationality` varchar(100)
,`pep_indicator` int
,`previous_cash_transaction_count_30d` bigint
,`previous_country_count_30d` bigint
,`previous_transaction_average_30d` decimal(22,6)
,`previous_transaction_count_24h` bigint
,`previous_transaction_count_30d` bigint
,`previous_transaction_volume_24h` decimal(40,2)
,`previous_transaction_volume_30d` decimal(40,2)
,`previous_transfer_count_24h` bigint
,`previous_transfer_count_60m` bigint
,`profession` varchar(150)
,`sanctions_match_indicator` int
,`sanctions_match_score` decimal(5,2)
,`target_alert` int
,`target_risk_level` varchar(8)
,`target_scenario_code` varchar(50)
,`target_score` decimal(5,2)
,`target_typology_count` bigint
,`transaction_date` datetime
,`transaction_day_of_week` int
,`transaction_hour` int
,`transaction_id` bigint
,`transaction_reference` varchar(100)
,`transaction_status` varchar(30)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_transaction_features`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_transaction_features`;
CREATE TABLE IF NOT EXISTS `v_ml_transaction_features` (
`account_id` bigint
,`account_status` varchar(30)
,`account_type` varchar(50)
,`activity_sector` varchar(150)
,`amount` decimal(18,2)
,`cash_ratio_30d` decimal(27,4)
,`channel` varchar(50)
,`client_agency_city` varchar(100)
,`client_agency_code` varchar(20)
,`client_agency_id` bigint
,`client_agency_name` varchar(150)
,`client_caisse_city` varchar(100)
,`client_caisse_code` varchar(20)
,`client_caisse_id` bigint
,`client_caisse_name` varchar(150)
,`client_id` bigint
,`client_type` varchar(50)
,`configured_corridor_score` decimal(5,2)
,`configured_high_risk_corridor` int
,`country` varchar(100)
,`country_from` varchar(100)
,`country_to` varchar(100)
,`cumulative_amount_24h_including_current` decimal(40,2)
,`currency` varchar(10)
,`is_night_transaction` int
,`is_pep` tinyint(1)
,`nationality` varchar(100)
,`pep_indicator` int
,`previous_cash_transaction_count_30d` bigint
,`previous_country_count_30d` bigint
,`previous_transaction_average_30d` decimal(22,6)
,`previous_transaction_count_24h` bigint
,`previous_transaction_count_30d` bigint
,`previous_transaction_volume_24h` decimal(40,2)
,`previous_transaction_volume_30d` decimal(40,2)
,`previous_transfer_count_24h` bigint
,`previous_transfer_count_60m` bigint
,`profession` varchar(150)
,`sanctions_match_indicator` int
,`sanctions_match_score` decimal(5,2)
,`transaction_agency_city` varchar(100)
,`transaction_agency_code` varchar(20)
,`transaction_agency_id` bigint
,`transaction_agency_name` varchar(150)
,`transaction_caisse_city` varchar(100)
,`transaction_caisse_code` varchar(20)
,`transaction_caisse_id` bigint
,`transaction_caisse_name` varchar(150)
,`transaction_date` datetime
,`transaction_day_of_week` int
,`transaction_hour` int
,`transaction_id` bigint
,`transaction_outside_client_agency` int
,`transaction_reference` varchar(100)
,`transaction_status` varchar(30)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_transaction_labels`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_transaction_labels`;
CREATE TABLE IF NOT EXISTS `v_ml_transaction_labels` (
`label_available` int
,`label_source` varchar(25)
,`target_alert` int
,`target_risk_level` varchar(8)
,`target_scenario_code` varchar(50)
,`target_score` decimal(5,2)
,`target_typology_count` bigint
,`transaction_id` bigint
,`transaction_reference` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ml_tx_feature_proxy`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_ml_tx_feature_proxy`;
CREATE TABLE IF NOT EXISTS `v_ml_tx_feature_proxy` (
`aml_match_count` bigint
,`channel` varchar(50)
,`client_id` bigint
,`has_alert` int
,`jour_semaine` int
,`montant_cumule_7j` decimal(40,2)
,`montant_fcfa` decimal(18,2)
,`montant_log` double
,`montant_moyen_compte` decimal(22,6)
,`nb_transactions_7j` bigint
,`nb_transactions_compte` bigint
,`proche_seuil` int
,`ratio_seuil_10m` decimal(23,6)
,`transaction_date` datetime
,`transaction_id` bigint
,`transaction_reference` varchar(100)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_sanctions_entities`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_sanctions_entities`;
CREATE TABLE IF NOT EXISTS `v_sanctions_entities` (
`designation_date` date
,`entity_status` varchar(8)
,`entity_type` varchar(50)
,`gender` varchar(50)
,`id` bigint
,`is_active` tinyint(1)
,`nationality` varchar(255)
,`normalized_name` varchar(500)
,`primary_name` varchar(500)
,`programme` varchar(500)
,`screening_list_name` varchar(150)
,`source` varchar(20)
,`source_entity_id` varchar(255)
,`source_list` varchar(150)
,`source_organization` varchar(150)
,`source_reference` varchar(255)
,`title_or_function` varchar(500)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_sanctions_screening_names`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_sanctions_screening_names`;
CREATE TABLE IF NOT EXISTS `v_sanctions_screening_names` (
`name_type` varchar(7)
,`normalized_name` varchar(500)
,`sanctions_entity_id` bigint
,`screening_name` varchar(500)
,`source` varchar(20)
,`source_entity_id` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_sanctions_source_stats`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_sanctions_source_stats`;
CREATE TABLE IF NOT EXISTS `v_sanctions_source_stats` (
`active_entity_count` decimal(23,0)
,`distinct_source_entities` bigint
,`entity_count` bigint
,`source` varchar(20)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_suspicious_transactions`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_suspicious_transactions`;
CREATE TABLE IF NOT EXISTS `v_suspicious_transactions` (
`account_current_balance` decimal(18,2)
,`account_id` bigint
,`activity_sector` varchar(150)
,`agency_code` varchar(20)
,`agency_id` bigint
,`agency_name` varchar(150)
,`alert_count` bigint
,`aml_risk_level` varchar(8)
,`aml_risk_score` decimal(27,2)
,`aml_rule_match_count` bigint
,`amount` decimal(18,2)
,`birth_date` date
,`caisse_code` varchar(20)
,`caisse_id` bigint
,`caisse_name` varchar(150)
,`channel` varchar(50)
,`client_id` bigint
,`client_number` varchar(50)
,`client_total_current_balance` decimal(40,2)
,`client_type` varchar(10)
,`country` varchar(100)
,`country_from` varchar(100)
,`country_to` varchar(100)
,`currency` varchar(10)
,`gender` varchar(20)
,`is_pep` tinyint(1)
,`nationality` varchar(100)
,`pep_indicator` int
,`profession` varchar(150)
,`sanctions_match_indicator` int
,`sanctions_match_score` decimal(5,2)
,`screening_score` decimal(5,2)
,`transaction_date` datetime
,`transaction_id` bigint
,`transaction_reference` varchar(100)
,`transaction_status` varchar(30)
,`transaction_type` enum('DEPOSIT','PAYMENT','TRANSFER_IN','TRANSFER_OUT','WITHDRAWAL')
);

-- --------------------------------------------------------

--
-- Structure for view `v_aml_dashboard`
--
DROP TABLE IF EXISTS `v_aml_dashboard`;

DROP VIEW IF EXISTS `v_aml_dashboard`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY INVOKER VIEW `v_aml_dashboard`  AS SELECT (select count(0) from `clients` `c`) AS `total_clients`, (select count(0) from (`clients` `c` join `risk_levels` `rl` on((`rl`.`id` = `c`.`risk_level_id`))) where (`rl`.`code` in ('HIGH','CRITICAL'))) AS `risky_clients`, (select count(0) from `alerts` `a`) AS `total_alerts`, (select count(0) from `alerts` `a` where (`a`.`status` = 'OPEN')) AS `open_alerts` ;

-- --------------------------------------------------------

--
-- Structure for view `v_assist_alert_context`
--
DROP TABLE IF EXISTS `v_assist_alert_context`;

DROP VIEW IF EXISTS `v_assist_alert_context`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_assist_alert_context`  AS SELECT `a`.`id` AS `alert_id`, `a`.`reference` AS `alert_reference`, `a`.`alert_type` AS `alert_type`, `a`.`priority` AS `priority`, `a`.`status` AS `alert_status`, `a`.`final_score` AS `alert_final_score`, `a`.`title` AS `alert_title`, `a`.`description` AS `alert_description`, `a`.`created_at` AS `alert_created_at`, `a`.`client_id` AS `client_id`, `c`.`client_number` AS `client_number`, `c`.`client_type` AS `client_type`, `c`.`status` AS `client_status`, `c`.`is_pep` AS `is_pep`, `c`.`risk_score` AS `client_risk_score`, `rl`.`code` AS `client_risk_level`, (case when (`ci`.`client_id` is not null) then trim(concat(coalesce(`ci`.`first_name`,''),' ',coalesce(`ci`.`last_name`,''))) when (`ce`.`client_id` is not null) then `ce`.`legal_name` else `c`.`client_number` end) AS `customer_name`, `a`.`transaction_id` AS `transaction_id`, `t`.`transaction_reference` AS `transaction_reference`, `t`.`transaction_type` AS `transaction_type`, `t`.`amount` AS `transaction_amount`, `t`.`currency` AS `currency`, `t`.`channel` AS `channel`, `t`.`country_from` AS `country_from`, `t`.`country_to` AS `country_to`, `t`.`transaction_date` AS `transaction_date`, (select group_concat(distinct `ar`.`rule_code` order by `ar`.`rule_code` ASC separator ', ') from (`rule_executions` `re` join `aml_rules` `ar` on((`ar`.`id` = `re`.`rule_id`))) where ((`re`.`transaction_id` = `a`.`transaction_id`) and (upper(coalesce(`re`.`execution_result`,'')) in ('MATCH','HIT','TRIGGERED','TRUE','1')))) AS `matched_rule_codes`, (select count(0) from `rule_executions` `re` where ((`re`.`transaction_id` = `a`.`transaction_id`) and (upper(coalesce(`re`.`execution_result`,'')) in ('MATCH','HIT','TRIGGERED','TRUE','1')))) AS `matched_rules_count`, (select max(`ra`.`score`) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `a`.`transaction_id`)) AS `max_risk_assessment_score`, (case when exists(select 1 from `pep_matches` `pm` where (`pm`.`client_id` = `a`.`client_id`)) then 1 else 0 end) AS `has_pep_match`, (case when exists(select 1 from `sanction_matches` `sm` where (`sm`.`client_id` = `a`.`client_id`)) then 1 else 0 end) AS `has_sanction_match` FROM (((((`alerts` `a` left join `clients` `c` on((`c`.`id` = `a`.`client_id`))) left join `risk_levels` `rl` on((`rl`.`id` = `c`.`risk_level_id`))) left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) left join `transactions` `t` on((`t`.`id` = `a`.`transaction_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_assist_client_context`
--
DROP TABLE IF EXISTS `v_assist_client_context`;

DROP VIEW IF EXISTS `v_assist_client_context`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_assist_client_context`  AS SELECT `c`.`id` AS `client_id`, `c`.`client_number` AS `client_number`, `c`.`client_type` AS `client_type`, `c`.`status` AS `client_status`, `c`.`is_pep` AS `is_pep`, `c`.`risk_score` AS `risk_score`, `rl`.`code` AS `risk_level`, `c`.`agency_id` AS `agency_id`, `ag`.`name` AS `agency_name`, `ca`.`name` AS `caisse_name`, (case when (`ci`.`client_id` is not null) then trim(concat(coalesce(`ci`.`first_name`,''),' ',coalesce(`ci`.`last_name`,''))) when (`ce`.`client_id` is not null) then `ce`.`legal_name` else `c`.`client_number` end) AS `customer_name`, (select count(0) from `alerts` `al` where (`al`.`client_id` = `c`.`id`)) AS `total_alerts`, (select count(0) from `alerts` `al` where ((`al`.`client_id` = `c`.`id`) and (`al`.`status` = 'OPEN'))) AS `open_alerts`, (select count(0) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)) AS `accounts_count`, (select count(0) from (`transactions` `t` join `accounts` `ac` on((`ac`.`id` = `t`.`account_id`))) where ((`ac`.`client_id` = `c`.`id`) and (`t`.`transaction_date` >= (now() - interval 30 day)))) AS `tx_count_30d`, (select coalesce(sum(`t`.`amount`),0) from (`transactions` `t` join `accounts` `ac` on((`ac`.`id` = `t`.`account_id`))) where ((`ac`.`client_id` = `c`.`id`) and (`t`.`transaction_date` >= (now() - interval 30 day)))) AS `tx_volume_30d`, (case when exists(select 1 from `pep_matches` `pm` where (`pm`.`client_id` = `c`.`id`)) then 1 else 0 end) AS `has_pep_match`, (case when exists(select 1 from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) then 1 else 0 end) AS `has_sanction_match` FROM (((((`clients` `c` left join `risk_levels` `rl` on((`rl`.`id` = `c`.`risk_level_id`))) left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) left join `agencies` `ag` on((`ag`.`id` = `c`.`agency_id`))) left join `caisses` `ca` on((`ca`.`id` = `ag`.`caisse_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_customer_aml_profile`
--
DROP TABLE IF EXISTS `v_customer_aml_profile`;

DROP VIEW IF EXISTS `v_customer_aml_profile`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_customer_aml_profile`  AS SELECT `c`.`id` AS `client_id`, `c`.`client_number` AS `client_number`, (case when (`ci`.`client_id` is not null) then 'INDIVIDUAL' when (`ce`.`client_id` is not null) then 'ENTITY' else 'UNKNOWN' end) AS `client_type`, (case when (`ci`.`client_id` is not null) then trim(concat(coalesce(`ci`.`first_name`,''),' ',coalesce(`ci`.`last_name`,''))) when (`ce`.`client_id` is not null) then `ce`.`legal_name` else concat('CLIENT #',`c`.`id`) end) AS `customer_name`, `c`.`is_pep` AS `is_pep`, `rl`.`code` AS `risk_level`, least(100,coalesce(`c`.`risk_score`,0)) AS `risk_score`, (select count(0) from `alerts` `a` where (`a`.`client_id` = `c`.`id`)) AS `alert_count`, (select count(0) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)) AS `transaction_count`, coalesce((select sum(`t`.`amount`) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where (`ac`.`client_id` = `c`.`id`)),0) AS `total_volume`, (select count(0) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)) AS `account_count`, coalesce((select sum(`ac`.`current_balance`) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)),0.00) AS `total_current_balance`, (select count(0) from `accounts` `ac` where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`ac`.`status`,'')) = 'ACTIVE'))) AS `active_account_count`, `c`.`agency_id` AS `client_agency_id`, `ag`.`code` AS `client_agency_code`, `ag`.`name` AS `client_agency_name`, `ag`.`city` AS `client_agency_city`, `ag`.`caisse_id` AS `client_caisse_id`, `ca`.`code` AS `client_caisse_code`, `ca`.`name` AS `client_caisse_name`, `ca`.`city` AS `client_caisse_city` FROM (((((`clients` `c` left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) left join `risk_levels` `rl` on((`rl`.`id` = `c`.`risk_level_id`))) left join `agencies` `ag` on((`ag`.`id` = `c`.`agency_id`))) left join `caisses` `ca` on((`ca`.`id` = `ag`.`caisse_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_customer_compliance_profile`
--
DROP TABLE IF EXISTS `v_customer_compliance_profile`;

DROP VIEW IF EXISTS `v_customer_compliance_profile`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_customer_compliance_profile`  AS SELECT `c`.`id` AS `id`, `c`.`client_number` AS `client_number`, (case when (`ci`.`client_id` is not null) then 'INDIVIDUAL' when (`ce`.`client_id` is not null) then 'ENTITY' else 'UNKNOWN' end) AS `client_type`, (case when (`ci`.`client_id` is not null) then trim(concat(coalesce(`ci`.`first_name`,''),' ',coalesce(`ci`.`last_name`,''))) when (`ce`.`client_id` is not null) then `ce`.`legal_name` else concat('CLIENT #',`c`.`id`) end) AS `customer_name`, `c`.`is_pep` AS `is_pep`, `rl`.`code` AS `risk_level`, least(100,coalesce(`c`.`risk_score`,0)) AS `risk_score`, (select count(0) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where (`ac`.`client_id` = `c`.`id`)) AS `transactions`, (select count(0) from `alerts` `a` where (`a`.`client_id` = `c`.`id`)) AS `alerts`, (select count(0) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)) AS `account_count`, coalesce((select sum(`ac`.`current_balance`) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)),0.00) AS `total_current_balance`, (select count(0) from `accounts` `ac` where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`ac`.`status`,'')) = 'ACTIVE'))) AS `active_account_count`, `c`.`agency_id` AS `client_agency_id`, `ag`.`code` AS `client_agency_code`, `ag`.`name` AS `client_agency_name`, `ag`.`city` AS `client_agency_city`, `ag`.`caisse_id` AS `client_caisse_id`, `ca`.`code` AS `client_caisse_code`, `ca`.`name` AS `client_caisse_name`, `ca`.`city` AS `client_caisse_city` FROM (((((`clients` `c` left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) left join `risk_levels` `rl` on((`rl`.`id` = `c`.`risk_level_id`))) left join `agencies` `ag` on((`ag`.`id` = `c`.`agency_id`))) left join `caisses` `ca` on((`ca`.`id` = `ag`.`caisse_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_customer_features_current`
--
DROP TABLE IF EXISTS `v_ml_customer_features_current`;

DROP VIEW IF EXISTS `v_ml_customer_features_current`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_customer_features_current`  AS SELECT `c`.`id` AS `client_id`, `c`.`client_number` AS `client_number`, (case when (`ci`.`client_id` is not null) then 'INDIVIDUAL' when (`ce`.`client_id` is not null) then 'ENTITY' else 'UNKNOWN' end) AS `client_type`, (case when (`ci`.`client_id` is not null) then trim(concat(coalesce(`ci`.`last_name`,''),(case when ((`ci`.`last_name` is not null) and (`ci`.`first_name` is not null) and (`ci`.`last_name` <> '') and (`ci`.`first_name` <> '')) then ', ' else '' end),coalesce(`ci`.`first_name`,''))) when (`ce`.`client_id` is not null) then `ce`.`legal_name` else NULL end) AS `primary_name`, `ci`.`first_name` AS `first_name`, `ci`.`last_name` AS `last_name`, `ci`.`gender` AS `gender`, `ci`.`birth_date` AS `birth_date`, `ci`.`profession` AS `profession`, (case when (`ci`.`client_id` is not null) then `ci`.`nationality` when (`ce`.`client_id` is not null) then `ce`.`nationality` else NULL end) AS `nationality`, (case when (`ci`.`client_id` is not null) then `ci`.`activity_sector` when (`ce`.`client_id` is not null) then `ce`.`activity_sector` else NULL end) AS `activity_sector`, coalesce(`c`.`is_pep`,0) AS `pep_flag`, (select count(0) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)) AS `account_count`, coalesce((select sum(`ac`.`current_balance`) from `accounts` `ac` where (`ac`.`client_id` = `c`.`id`)),0.00) AS `total_current_balance`, (select count(0) from `accounts` `ac` where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`ac`.`status`,'')) = 'ACTIVE'))) AS `active_account_count`, (select count(0) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED'))) AS `transaction_count_total`, coalesce((select sum(`t`.`amount`) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED'))),0) AS `transaction_volume_total`, (select count(0) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED') and (`t`.`transaction_date` >= ((select max(`transactions`.`transaction_date`) from `transactions`) - interval 30 day)))) AS `transaction_count_30d`, coalesce((select sum(`t`.`amount`) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED') and (`t`.`transaction_date` >= ((select max(`transactions`.`transaction_date`) from `transactions`) - interval 30 day)))),0) AS `transaction_volume_30d`, coalesce((select avg(`t`.`amount`) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED'))),0) AS `average_transaction_amount`, coalesce((select (sum((case when (upper(coalesce(`t`.`channel`,'')) in ('CASH','ATM','AGENCY')) then 1 else 0 end)) / nullif(count(0),0)) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED'))),0) AS `cash_ratio`, coalesce((select (sum((case when ((hour(`t`.`transaction_date`) < 6) or (hour(`t`.`transaction_date`) >= 22)) then 1 else 0 end)) / nullif(count(0),0)) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED'))),0) AS `night_transaction_ratio`, (select count(distinct coalesce(`t`.`country_to`,`t`.`country_from`,`t`.`country`)) from (`accounts` `ac` join `transactions` `t` on((`t`.`account_id` = `ac`.`id`))) where ((`ac`.`client_id` = `c`.`id`) and (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED') and (coalesce(`t`.`country_to`,`t`.`country_from`,`t`.`country`) is not null))) AS `country_count`, (select count(0) from `alerts` `a` where (`a`.`client_id` = `c`.`id`)) AS `previous_alert_count`, (select count(0) from `pep_matches` `pm` where (`pm`.`client_id` = `c`.`id`)) AS `pep_match_count`, (select count(0) from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) AS `sanction_match_count`, (select `mcf`.`risk_label` from `ml_customer_features` `mcf` where (`mcf`.`client_id` = `c`.`id`) order by `mcf`.`generated_at` desc,`mcf`.`id` desc limit 1) AS `existing_ml_risk_label` FROM ((`clients` `c` left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_dataset_behavioral`
--
DROP TABLE IF EXISTS `v_ml_dataset_behavioral`;

DROP VIEW IF EXISTS `v_ml_dataset_behavioral`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_dataset_behavioral`  AS SELECT `t`.`id` AS `transaction_id`, `t`.`transaction_reference` AS `transaction_reference`, `c`.`id` AS `client_id`, `c`.`client_number` AS `client_number`, `c`.`client_type` AS `client_type`, `ac`.`id` AS `account_id`, `ac`.`account_number` AS `account_number`, `ac`.`account_type` AS `account_type`, `ac`.`status` AS `account_status`, `ci`.`gender` AS `gender`, `ci`.`birth_date` AS `birth_date`, (case when (`ci`.`client_id` is not null) then `ci`.`nationality` when (`ce`.`client_id` is not null) then `ce`.`nationality` else NULL end) AS `nationality`, `ci`.`profession` AS `profession`, (case when (`ci`.`client_id` is not null) then `ci`.`activity_sector` when (`ce`.`client_id` is not null) then `ce`.`activity_sector` else NULL end) AS `activity_sector`, `c`.`is_pep` AS `is_pep`, `t`.`transaction_type` AS `transaction_type`, `t`.`amount` AS `amount`, `t`.`currency` AS `currency`, `t`.`channel` AS `channel`, `t`.`country` AS `country`, `t`.`country_from` AS `country_from`, `t`.`country_to` AS `country_to`, `t`.`transaction_date` AS `transaction_date`, `t`.`transaction_status` AS `transaction_status`, hour(`t`.`transaction_date`) AS `transaction_hour`, dayofweek(`t`.`transaction_date`) AS `transaction_day_of_week`, (select count(0) from `transactions` `h60` where ((`h60`.`account_id` = `t`.`account_id`) and (`h60`.`transaction_date` < `t`.`transaction_date`) and (`h60`.`transaction_date` >= (`t`.`transaction_date` - interval 60 minute)) and (upper(coalesce(`h60`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_count_60m`, (select count(0) from `transactions` `h24` where ((`h24`.`account_id` = `t`.`account_id`) and (`h24`.`transaction_date` < `t`.`transaction_date`) and (`h24`.`transaction_date` >= (`t`.`transaction_date` - interval 24 hour)) and (upper(coalesce(`h24`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_count_24h`, (select coalesce(sum(`h24v`.`amount`),0) from `transactions` `h24v` where ((`h24v`.`account_id` = `t`.`account_id`) and (`h24v`.`transaction_date` < `t`.`transaction_date`) and (`h24v`.`transaction_date` >= (`t`.`transaction_date` - interval 24 hour)) and (upper(coalesce(`h24v`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_volume_24h`, (select count(0) from `transactions` `h30` where ((`h30`.`account_id` = `t`.`account_id`) and (`h30`.`transaction_date` < `t`.`transaction_date`) and (`h30`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h30`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_count_30d`, (select coalesce(sum(`h30v`.`amount`),0) from `transactions` `h30v` where ((`h30v`.`account_id` = `t`.`account_id`) and (`h30v`.`transaction_date` < `t`.`transaction_date`) and (`h30v`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h30v`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_volume_30d`, (select coalesce(avg(`h30a`.`amount`),0) from `transactions` `h30a` where ((`h30a`.`account_id` = `t`.`account_id`) and (`h30a`.`transaction_date` < `t`.`transaction_date`) and (`h30a`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h30a`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `average_transaction_amount_30d`, (select count(0) from `transactions` `hc` where ((`hc`.`account_id` = `t`.`account_id`) and (`hc`.`transaction_date` < `t`.`transaction_date`) and (`hc`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`hc`.`channel`,'')) in ('ATM','AGENCY')) and (upper(coalesce(`hc`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `cash_transaction_count_30d`, (select count(0) from `transactions` `ht` where ((`ht`.`account_id` = `t`.`account_id`) and (`ht`.`transaction_date` < `t`.`transaction_date`) and (`ht`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`ht`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `total_transaction_count_30d`, (select (case when (count(0) = 0) then 0 else (sum((case when (upper(coalesce(`hcr`.`channel`,'')) in ('ATM','AGENCY')) then 1 else 0 end)) / count(0)) end) from `transactions` `hcr` where ((`hcr`.`account_id` = `t`.`account_id`) and (`hcr`.`transaction_date` < `t`.`transaction_date`) and (`hcr`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`hcr`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `cash_ratio_30d`, (select count(0) from (select coalesce(`hp`.`country_to`,`hp`.`country`) AS `country_value` from `transactions` `hp` where ((`hp`.`account_id` = `t`.`account_id`) and (`hp`.`transaction_date` < `t`.`transaction_date`) and (`hp`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`hp`.`transaction_status`,'COMPLETED')) not in ('CANCELLED','CANCELED','REVERSED','VOID'))) group by coalesce(`hp`.`country_to`,`hp`.`country`)) `countries_30d`) AS `country_count_30d`, (select count(0) from `screenings` `s` where (`s`.`client_id` = `c`.`id`)) AS `screening_count`, (select count(0) from `screenings` `s` where ((`s`.`client_id` = `c`.`id`) and (`s`.`match_found` = 1))) AS `screening_match_count`, (select max(coalesce(`s`.`confidence_score`,0)) from `screenings` `s` where (`s`.`client_id` = `c`.`id`)) AS `max_screening_confidence`, (select count(0) from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) AS `sanction_match_count`, (select max(coalesce(`sm`.`match_score`,0)) from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) AS `max_sanction_match_score`, (case when exists(select 1 from `sanction_matches` `sm2` where (`sm2`.`client_id` = `c`.`id`)) then 1 else 0 end) AS `sanction_match_flag`, (case when (`c`.`is_pep` = 1) then 1 else 0 end) AS `pep_flag`, `t`.`agency_id` AS `transaction_agency_id`, `ag`.`code` AS `transaction_agency_code`, `ag`.`name` AS `transaction_agency_name`, `ag`.`city` AS `transaction_agency_city`, `ag`.`caisse_id` AS `transaction_caisse_id`, `ca`.`code` AS `transaction_caisse_code`, `ca`.`name` AS `transaction_caisse_name`, `ca`.`city` AS `transaction_caisse_city`, `c`.`agency_id` AS `client_agency_id`, `agc`.`code` AS `client_agency_code`, `agc`.`name` AS `client_agency_name`, `agc`.`city` AS `client_agency_city`, `agc`.`caisse_id` AS `client_caisse_id`, `cac`.`code` AS `client_caisse_code`, `cac`.`name` AS `client_caisse_name`, `cac`.`city` AS `client_caisse_city`, (case when (`c`.`agency_id` = `t`.`agency_id`) then 0 else 1 end) AS `transaction_outside_client_agency` FROM ((((((((`transactions` `t` join `accounts` `ac` on((`ac`.`id` = `t`.`account_id`))) join `clients` `c` on((`c`.`id` = `ac`.`client_id`))) left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) left join `agencies` `ag` on((`ag`.`id` = `t`.`agency_id`))) left join `caisses` `ca` on((`ca`.`id` = `ag`.`caisse_id`))) left join `agencies` `agc` on((`agc`.`id` = `c`.`agency_id`))) left join `caisses` `cac` on((`cac`.`id` = `agc`.`caisse_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_dataset_hybrid`
--
DROP TABLE IF EXISTS `v_ml_dataset_hybrid`;

DROP VIEW IF EXISTS `v_ml_dataset_hybrid`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_dataset_hybrid`  AS SELECT `b`.`transaction_id` AS `transaction_id`, `b`.`transaction_reference` AS `transaction_reference`, `b`.`client_id` AS `client_id`, `b`.`client_number` AS `client_number`, `b`.`client_type` AS `client_type`, `b`.`account_id` AS `account_id`, `b`.`account_number` AS `account_number`, `b`.`account_type` AS `account_type`, `b`.`account_status` AS `account_status`, `b`.`gender` AS `gender`, `b`.`birth_date` AS `birth_date`, `b`.`nationality` AS `nationality`, `b`.`profession` AS `profession`, `b`.`activity_sector` AS `activity_sector`, `b`.`is_pep` AS `is_pep`, `b`.`transaction_type` AS `transaction_type`, `b`.`amount` AS `amount`, `b`.`currency` AS `currency`, `b`.`channel` AS `channel`, `b`.`country` AS `country`, `b`.`country_from` AS `country_from`, `b`.`country_to` AS `country_to`, `b`.`transaction_date` AS `transaction_date`, `b`.`transaction_status` AS `transaction_status`, `b`.`transaction_hour` AS `transaction_hour`, `b`.`transaction_day_of_week` AS `transaction_day_of_week`, `b`.`previous_transaction_count_60m` AS `previous_transaction_count_60m`, `b`.`previous_transaction_count_24h` AS `previous_transaction_count_24h`, `b`.`previous_volume_24h` AS `previous_volume_24h`, `b`.`previous_transaction_count_30d` AS `previous_transaction_count_30d`, `b`.`previous_volume_30d` AS `previous_volume_30d`, `b`.`average_transaction_amount_30d` AS `average_transaction_amount_30d`, `b`.`cash_transaction_count_30d` AS `cash_transaction_count_30d`, `b`.`total_transaction_count_30d` AS `total_transaction_count_30d`, `b`.`cash_ratio_30d` AS `cash_ratio_30d`, `b`.`country_count_30d` AS `country_count_30d`, `b`.`screening_count` AS `screening_count`, `b`.`screening_match_count` AS `screening_match_count`, `b`.`max_screening_confidence` AS `max_screening_confidence`, `b`.`sanction_match_count` AS `sanction_match_count`, `b`.`max_sanction_match_score` AS `max_sanction_match_score`, `b`.`sanction_match_flag` AS `sanction_match_flag`, `b`.`pep_flag` AS `pep_flag`, `b`.`transaction_agency_id` AS `transaction_agency_id`, `b`.`transaction_agency_code` AS `transaction_agency_code`, `b`.`transaction_agency_name` AS `transaction_agency_name`, `b`.`transaction_agency_city` AS `transaction_agency_city`, `b`.`transaction_caisse_id` AS `transaction_caisse_id`, `b`.`transaction_caisse_code` AS `transaction_caisse_code`, `b`.`transaction_caisse_name` AS `transaction_caisse_name`, `b`.`transaction_caisse_city` AS `transaction_caisse_city`, `b`.`client_agency_id` AS `client_agency_id`, `b`.`client_agency_code` AS `client_agency_code`, `b`.`client_agency_name` AS `client_agency_name`, `b`.`client_agency_city` AS `client_agency_city`, `b`.`client_caisse_id` AS `client_caisse_id`, `b`.`client_caisse_code` AS `client_caisse_code`, `b`.`client_caisse_name` AS `client_caisse_name`, `b`.`client_caisse_city` AS `client_caisse_city`, `b`.`transaction_outside_client_agency` AS `transaction_outside_client_agency`, (select count(0) from (`rule_executions` `re` join `aml_rules` `ar` on((`ar`.`id` = `re`.`rule_id`))) where ((`re`.`transaction_id` = `b`.`transaction_id`) and (`re`.`execution_result` = 'MATCH'))) AS `aml_rule_match_count`, (select count(0) from `rule_executions` `re3` where (`re3`.`transaction_id` = `b`.`transaction_id`)) AS `aml_rule_execution_count`, least(100,(select coalesce(sum(`ra`.`score`),0) from `risk_assessments` `ra` where ((`ra`.`transaction_id` = `b`.`transaction_id`) and (`ra`.`source` = 'AML_RULE_ENGINE')))) AS `aml_rule_score`, (case when ((select coalesce(sum(`ra2`.`score`),0) from `risk_assessments` `ra2` where ((`ra2`.`transaction_id` = `b`.`transaction_id`) and (`ra2`.`source` = 'AML_RULE_ENGINE'))) >= 80) then 'CRITICAL' when ((select coalesce(sum(`ra3`.`score`),0) from `risk_assessments` `ra3` where ((`ra3`.`transaction_id` = `b`.`transaction_id`) and (`ra3`.`source` = 'AML_RULE_ENGINE'))) >= 60) then 'HIGH' when ((select coalesce(sum(`ra4`.`score`),0) from `risk_assessments` `ra4` where ((`ra4`.`transaction_id` = `b`.`transaction_id`) and (`ra4`.`source` = 'AML_RULE_ENGINE'))) >= 30) then 'MEDIUM' else 'LOW' end) AS `aml_risk_level`, (select count(0) from `risk_assessments` `ra5` where ((`ra5`.`transaction_id` = `b`.`transaction_id`) and (`ra5`.`source` = 'AML_RULE_ENGINE'))) AS `risk_assessment_count`, (case when exists(select 1 from `alerts` `al` where ((`al`.`transaction_id` = `b`.`transaction_id`) and (`al`.`alert_type` = 'AML_RULE_ENGINE'))) then 1 else 0 end) AS `aml_alert_flag`, least(100,(select max(`a2`.`final_score`) from `alerts` `a2` where ((`a2`.`transaction_id` = `b`.`transaction_id`) and (`a2`.`alert_type` = 'AML_RULE_ENGINE')))) AS `aml_alert_score`, (select max(`a3`.`priority`) from `alerts` `a3` where ((`a3`.`transaction_id` = `b`.`transaction_id`) and (`a3`.`alert_type` = 'AML_RULE_ENGINE'))) AS `aml_alert_priority` FROM `v_ml_dataset_behavioral` AS `b` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_dataset_master`
--
DROP TABLE IF EXISTS `v_ml_dataset_master`;

DROP VIEW IF EXISTS `v_ml_dataset_master`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_dataset_master`  AS SELECT `h`.`transaction_id` AS `transaction_id`, `h`.`transaction_reference` AS `transaction_reference`, `h`.`client_id` AS `client_id`, `h`.`client_number` AS `client_number`, `h`.`client_type` AS `client_type`, `h`.`account_id` AS `account_id`, `h`.`account_number` AS `account_number`, `h`.`account_type` AS `account_type`, `h`.`account_status` AS `account_status`, `h`.`gender` AS `gender`, `h`.`birth_date` AS `birth_date`, `h`.`nationality` AS `nationality`, `h`.`profession` AS `profession`, `h`.`activity_sector` AS `activity_sector`, `h`.`is_pep` AS `is_pep`, `h`.`transaction_type` AS `transaction_type`, `h`.`amount` AS `amount`, `h`.`currency` AS `currency`, `h`.`channel` AS `channel`, `h`.`country` AS `country`, `h`.`country_from` AS `country_from`, `h`.`country_to` AS `country_to`, `h`.`transaction_date` AS `transaction_date`, `h`.`transaction_status` AS `transaction_status`, `h`.`transaction_hour` AS `transaction_hour`, `h`.`transaction_day_of_week` AS `transaction_day_of_week`, `h`.`previous_transaction_count_60m` AS `previous_transaction_count_60m`, `h`.`previous_transaction_count_24h` AS `previous_transaction_count_24h`, `h`.`previous_volume_24h` AS `previous_volume_24h`, `h`.`previous_transaction_count_30d` AS `previous_transaction_count_30d`, `h`.`previous_volume_30d` AS `previous_volume_30d`, `h`.`average_transaction_amount_30d` AS `average_transaction_amount_30d`, `h`.`cash_transaction_count_30d` AS `cash_transaction_count_30d`, `h`.`total_transaction_count_30d` AS `total_transaction_count_30d`, `h`.`cash_ratio_30d` AS `cash_ratio_30d`, `h`.`country_count_30d` AS `country_count_30d`, `h`.`screening_count` AS `screening_count`, `h`.`screening_match_count` AS `screening_match_count`, `h`.`max_screening_confidence` AS `max_screening_confidence`, `h`.`sanction_match_count` AS `sanction_match_count`, `h`.`max_sanction_match_score` AS `max_sanction_match_score`, `h`.`sanction_match_flag` AS `sanction_match_flag`, `h`.`pep_flag` AS `pep_flag`, `h`.`transaction_agency_id` AS `transaction_agency_id`, `h`.`transaction_agency_code` AS `transaction_agency_code`, `h`.`transaction_agency_name` AS `transaction_agency_name`, `h`.`transaction_agency_city` AS `transaction_agency_city`, `h`.`transaction_caisse_id` AS `transaction_caisse_id`, `h`.`transaction_caisse_code` AS `transaction_caisse_code`, `h`.`transaction_caisse_name` AS `transaction_caisse_name`, `h`.`transaction_caisse_city` AS `transaction_caisse_city`, `h`.`client_agency_id` AS `client_agency_id`, `h`.`client_agency_code` AS `client_agency_code`, `h`.`client_agency_name` AS `client_agency_name`, `h`.`client_agency_city` AS `client_agency_city`, `h`.`client_caisse_id` AS `client_caisse_id`, `h`.`client_caisse_code` AS `client_caisse_code`, `h`.`client_caisse_name` AS `client_caisse_name`, `h`.`client_caisse_city` AS `client_caisse_city`, `h`.`transaction_outside_client_agency` AS `transaction_outside_client_agency`, `h`.`aml_rule_match_count` AS `aml_rule_match_count`, `h`.`aml_rule_execution_count` AS `aml_rule_execution_count`, `h`.`aml_rule_score` AS `aml_rule_score`, `h`.`aml_risk_level` AS `aml_risk_level`, `h`.`risk_assessment_count` AS `risk_assessment_count`, `h`.`aml_alert_flag` AS `aml_alert_flag`, `h`.`aml_alert_score` AS `aml_alert_score`, `h`.`aml_alert_priority` AS `aml_alert_priority` FROM `v_ml_dataset_hybrid` AS `h` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_transaction_dataset`
--
DROP TABLE IF EXISTS `v_ml_transaction_dataset`;

DROP VIEW IF EXISTS `v_ml_transaction_dataset`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_transaction_dataset`  AS SELECT `f`.`transaction_id` AS `transaction_id`, `f`.`transaction_reference` AS `transaction_reference`, `f`.`account_id` AS `account_id`, `f`.`client_id` AS `client_id`, `f`.`client_type` AS `client_type`, `f`.`transaction_type` AS `transaction_type`, `f`.`amount` AS `amount`, `f`.`currency` AS `currency`, `f`.`channel` AS `channel`, `f`.`country_from` AS `country_from`, `f`.`country_to` AS `country_to`, `f`.`country` AS `country`, `f`.`transaction_date` AS `transaction_date`, `f`.`transaction_status` AS `transaction_status`, `f`.`is_pep` AS `is_pep`, `f`.`nationality` AS `nationality`, `f`.`profession` AS `profession`, `f`.`activity_sector` AS `activity_sector`, `f`.`account_type` AS `account_type`, `f`.`account_status` AS `account_status`, `f`.`transaction_hour` AS `transaction_hour`, `f`.`transaction_day_of_week` AS `transaction_day_of_week`, `f`.`is_night_transaction` AS `is_night_transaction`, `f`.`previous_transaction_count_30d` AS `previous_transaction_count_30d`, `f`.`previous_transaction_volume_30d` AS `previous_transaction_volume_30d`, `f`.`previous_transaction_average_30d` AS `previous_transaction_average_30d`, `f`.`previous_transaction_count_24h` AS `previous_transaction_count_24h`, `f`.`previous_transaction_volume_24h` AS `previous_transaction_volume_24h`, `f`.`previous_transfer_count_60m` AS `previous_transfer_count_60m`, `f`.`previous_transfer_count_24h` AS `previous_transfer_count_24h`, `f`.`previous_cash_transaction_count_30d` AS `previous_cash_transaction_count_30d`, `f`.`cash_ratio_30d` AS `cash_ratio_30d`, `f`.`cumulative_amount_24h_including_current` AS `cumulative_amount_24h_including_current`, `f`.`previous_country_count_30d` AS `previous_country_count_30d`, `f`.`configured_high_risk_corridor` AS `configured_high_risk_corridor`, `f`.`configured_corridor_score` AS `configured_corridor_score`, `f`.`pep_indicator` AS `pep_indicator`, `f`.`sanctions_match_indicator` AS `sanctions_match_indicator`, `f`.`sanctions_match_score` AS `sanctions_match_score`, `l`.`label_available` AS `label_available`, least(100,`l`.`target_score`) AS `target_score`, `l`.`target_risk_level` AS `target_risk_level`, `l`.`target_alert` AS `target_alert`, `l`.`target_scenario_code` AS `target_scenario_code`, `l`.`target_typology_count` AS `target_typology_count`, `l`.`label_source` AS `label_source` FROM (`v_ml_transaction_features` `f` join `v_ml_transaction_labels` `l` on((`l`.`transaction_id` = `f`.`transaction_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_transaction_features`
--
DROP TABLE IF EXISTS `v_ml_transaction_features`;

DROP VIEW IF EXISTS `v_ml_transaction_features`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_transaction_features`  AS SELECT `t`.`id` AS `transaction_id`, `t`.`transaction_reference` AS `transaction_reference`, `t`.`account_id` AS `account_id`, `a`.`client_id` AS `client_id`, `c`.`client_type` AS `client_type`, `t`.`transaction_type` AS `transaction_type`, `t`.`amount` AS `amount`, `t`.`currency` AS `currency`, `t`.`channel` AS `channel`, `t`.`country_from` AS `country_from`, `t`.`country_to` AS `country_to`, `t`.`country` AS `country`, `t`.`transaction_date` AS `transaction_date`, `t`.`transaction_status` AS `transaction_status`, `c`.`is_pep` AS `is_pep`, (case when (`ci`.`client_id` is not null) then `ci`.`nationality` when (`ce`.`client_id` is not null) then `ce`.`nationality` else NULL end) AS `nationality`, `ci`.`profession` AS `profession`, (case when (`ci`.`client_id` is not null) then `ci`.`activity_sector` when (`ce`.`client_id` is not null) then `ce`.`activity_sector` else NULL end) AS `activity_sector`, `a`.`account_type` AS `account_type`, `a`.`status` AS `account_status`, hour(`t`.`transaction_date`) AS `transaction_hour`, dayofweek(`t`.`transaction_date`) AS `transaction_day_of_week`, (case when ((hour(`t`.`transaction_date`) >= 22) or (hour(`t`.`transaction_date`) < 6)) then 1 else 0 end) AS `is_night_transaction`, (select count(0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_count_30d`, (select coalesce(sum(`h`.`amount`),0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_volume_30d`, (select coalesce(avg(`h`.`amount`),0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_average_30d`, (select count(0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 24 hour)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_count_24h`, (select coalesce(sum(`h`.`amount`),0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 24 hour)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transaction_volume_24h`, (select count(0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 60 minute)) and (`h`.`transaction_type` = 'TRANSFER') and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transfer_count_60m`, (select count(0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 24 hour)) and (`h`.`transaction_type` = 'TRANSFER') and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_transfer_count_24h`, (select count(0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h`.`channel`,'')) in ('ATM','AGENCY')) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `previous_cash_transaction_count_30d`, (select (case when (count(0) = 0) then 0 else (sum((case when (upper(coalesce(`h`.`channel`,'')) in ('ATM','AGENCY')) then 1 else 0 end)) / count(0)) end) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `cash_ratio_30d`, (select coalesce(sum(`h`.`amount`),0) from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` <= `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 24 hour)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')))) AS `cumulative_amount_24h_including_current`, (select count(0) from (select coalesce(`h`.`country_to`,`h`.`country`) AS `country_value` from `transactions` `h` where ((`h`.`account_id` = `t`.`account_id`) and (`h`.`transaction_date` < `t`.`transaction_date`) and (`h`.`transaction_date` >= (`t`.`transaction_date` - interval 30 day)) and (upper(coalesce(`h`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID'))) group by coalesce(`h`.`country_to`,`h`.`country`)) `countries`) AS `previous_country_count_30d`, (case when exists(select 1 from `aml_risk_corridors` `rc` where ((`rc`.`active` = 1) and (upper(trim(`rc`.`country_from`)) = upper(trim(coalesce(`t`.`country_from`,`t`.`country`)))) and (upper(trim(`rc`.`country_to`)) = upper(trim(coalesce(`t`.`country_to`,`t`.`country`)))))) then 1 else 0 end) AS `configured_high_risk_corridor`, (select max(`rc`.`risk_score`) from `aml_risk_corridors` `rc` where ((`rc`.`active` = 1) and (upper(trim(`rc`.`country_from`)) = upper(trim(coalesce(`t`.`country_from`,`t`.`country`)))) and (upper(trim(`rc`.`country_to`)) = upper(trim(coalesce(`t`.`country_to`,`t`.`country`)))))) AS `configured_corridor_score`, (case when (`c`.`is_pep` = 1) then 1 else 0 end) AS `pep_indicator`, (case when exists(select 1 from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) then 1 else 0 end) AS `sanctions_match_indicator`, (select max(`sm`.`match_score`) from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) AS `sanctions_match_score`, `t`.`agency_id` AS `transaction_agency_id`, `ag`.`code` AS `transaction_agency_code`, `ag`.`name` AS `transaction_agency_name`, `ag`.`city` AS `transaction_agency_city`, `ag`.`caisse_id` AS `transaction_caisse_id`, `ca`.`code` AS `transaction_caisse_code`, `ca`.`name` AS `transaction_caisse_name`, `ca`.`city` AS `transaction_caisse_city`, `c`.`agency_id` AS `client_agency_id`, `agc`.`code` AS `client_agency_code`, `agc`.`name` AS `client_agency_name`, `agc`.`city` AS `client_agency_city`, `agc`.`caisse_id` AS `client_caisse_id`, `cac`.`code` AS `client_caisse_code`, `cac`.`name` AS `client_caisse_name`, `cac`.`city` AS `client_caisse_city`, (case when (`c`.`agency_id` = `t`.`agency_id`) then 0 else 1 end) AS `transaction_outside_client_agency` FROM ((((((((`transactions` `t` join `accounts` `a` on((`a`.`id` = `t`.`account_id`))) join `clients` `c` on((`c`.`id` = `a`.`client_id`))) left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) left join `agencies` `ag` on((`ag`.`id` = `t`.`agency_id`))) left join `caisses` `ca` on((`ca`.`id` = `ag`.`caisse_id`))) left join `agencies` `agc` on((`agc`.`id` = `c`.`agency_id`))) left join `caisses` `cac` on((`cac`.`id` = `agc`.`caisse_id`))) WHERE (upper(coalesce(`t`.`transaction_status`,'VALID')) not in ('CANCELLED','CANCELED','REVERSED','VOID')) ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_transaction_labels`
--
DROP TABLE IF EXISTS `v_ml_transaction_labels`;

DROP VIEW IF EXISTS `v_ml_transaction_labels`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_transaction_labels`  AS SELECT `t`.`id` AS `transaction_id`, `t`.`transaction_reference` AS `transaction_reference`, (case when (exists(select 1 from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`)) or exists(select 1 from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`))) then 1 else 0 end) AS `label_available`, (case when exists(select 1 from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)) then (select max(`al`.`final_score`) from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)) else (select max(`ra`.`score`) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`)) end) AS `target_score`, (case when (coalesce((select max(`al`.`final_score`) from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)),(select max(`ra`.`score`) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`))) >= 80) then 'CRITICAL' when (coalesce((select max(`al`.`final_score`) from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)),(select max(`ra`.`score`) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`))) >= 60) then 'HIGH' when (coalesce((select max(`al`.`final_score`) from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)),(select max(`ra`.`score`) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`))) >= 30) then 'MEDIUM' when (coalesce((select max(`al`.`final_score`) from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)),(select max(`ra`.`score`) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`))) is not null) then 'LOW' else NULL end) AS `target_risk_level`, (case when exists(select 1 from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)) then 1 when exists(select 1 from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`)) then 1 else 0 end) AS `target_alert`, coalesce((select (case when (`ra`.`risk_type` = 'DORMANT_ACCOUNT') then 'DORMANT_REACTIVATION' else `ra`.`risk_type` end) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`) order by `ra`.`score` desc,`ra`.`id` limit 1),(case when exists(select 1 from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)) then 'AML_ALERT' else NULL end)) AS `target_scenario_code`, (select count(0) from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`)) AS `target_typology_count`, (case when (exists(select 1 from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`)) and exists(select 1 from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`))) then 'AML_RULE_ENGINE_AND_ALERT' when exists(select 1 from `risk_assessments` `ra` where (`ra`.`transaction_id` = `t`.`id`)) then 'AML_RULE_ENGINE' when exists(select 1 from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)) then 'AML_ALERT' else NULL end) AS `label_source` FROM `transactions` AS `t` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ml_tx_feature_proxy`
--
DROP TABLE IF EXISTS `v_ml_tx_feature_proxy`;

DROP VIEW IF EXISTS `v_ml_tx_feature_proxy`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ml_tx_feature_proxy`  AS SELECT `t`.`id` AS `transaction_id`, `t`.`transaction_reference` AS `transaction_reference`, `ac`.`client_id` AS `client_id`, `t`.`amount` AS `montant_fcfa`, log(greatest(`t`.`amount`,1)) AS `montant_log`, (`t`.`amount` / 10000000.0) AS `ratio_seuil_10m`, (case when ((`t`.`amount` >= 9000000) and (`t`.`amount` < 10000000)) then 1 else 0 end) AS `proche_seuil`, `t`.`transaction_type` AS `transaction_type`, `t`.`channel` AS `channel`, `t`.`transaction_date` AS `transaction_date`, dayofweek(`t`.`transaction_date`) AS `jour_semaine`, (select count(0) from `transactions` `t2` where ((`t2`.`account_id` = `t`.`account_id`) and (`t2`.`transaction_date` >= (`t`.`transaction_date` - interval 7 day)) and (`t2`.`transaction_date` <= `t`.`transaction_date`))) AS `nb_transactions_7j`, (select coalesce(sum(`t2`.`amount`),0) from `transactions` `t2` where ((`t2`.`account_id` = `t`.`account_id`) and (`t2`.`transaction_date` >= (`t`.`transaction_date` - interval 7 day)) and (`t2`.`transaction_date` <= `t`.`transaction_date`))) AS `montant_cumule_7j`, (select count(0) from `transactions` `t2` where (`t2`.`account_id` = `t`.`account_id`)) AS `nb_transactions_compte`, (select coalesce(avg(`t2`.`amount`),0) from `transactions` `t2` where (`t2`.`account_id` = `t`.`account_id`)) AS `montant_moyen_compte`, (case when exists(select 1 from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)) then 1 else 0 end) AS `has_alert`, (select count(0) from `rule_executions` `re` where ((`re`.`transaction_id` = `t`.`id`) and (upper(coalesce(`re`.`execution_result`,'')) in ('MATCH','HIT','TRIGGERED','TRUE','1')))) AS `aml_match_count` FROM (`transactions` `t` join `accounts` `ac` on((`ac`.`id` = `t`.`account_id`))) WHERE (upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED') ;

-- --------------------------------------------------------

--
-- Structure for view `v_sanctions_entities`
--
DROP TABLE IF EXISTS `v_sanctions_entities`;

DROP VIEW IF EXISTS `v_sanctions_entities`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_sanctions_entities`  AS SELECT `se`.`id` AS `id`, `se`.`source` AS `source`, `se`.`source_list` AS `source_list`, `se`.`source_entity_id` AS `source_entity_id`, `se`.`source_reference` AS `source_reference`, `se`.`primary_name` AS `primary_name`, `se`.`normalized_name` AS `normalized_name`, `se`.`entity_type` AS `entity_type`, `se`.`designation_date` AS `designation_date`, `se`.`programme` AS `programme`, `se`.`nationality` AS `nationality`, `se`.`gender` AS `gender`, `se`.`title_or_function` AS `title_or_function`, `se`.`is_active` AS `is_active`, `sl`.`name` AS `screening_list_name`, `sl`.`source_organization` AS `source_organization`, (case when (coalesce(`se`.`is_active`,0) = 1) then 'ACTIVE' else 'INACTIVE' end) AS `entity_status` FROM (`sanctions_entities` `se` join `screening_lists` `sl` on((`sl`.`id` = `se`.`screening_list_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_sanctions_screening_names`
--
DROP TABLE IF EXISTS `v_sanctions_screening_names`;

DROP VIEW IF EXISTS `v_sanctions_screening_names`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_sanctions_screening_names`  AS SELECT `se`.`id` AS `sanctions_entity_id`, `se`.`source` AS `source`, `se`.`source_entity_id` AS `source_entity_id`, `se`.`primary_name` AS `screening_name`, 'PRIMARY' AS `name_type`, `se`.`normalized_name` AS `normalized_name` FROM `sanctions_entities` AS `se` WHERE (`se`.`is_active` = true)union all select `sa`.`sanctions_entity_id` AS `sanctions_entity_id`,`sa`.`source` AS `source`,`se`.`source_entity_id` AS `source_entity_id`,`sa`.`alias_name` AS `screening_name`,'ALIAS' AS `name_type`,NULL AS `normalized_name` from (`sanctions_aliases` `sa` join `sanctions_entities` `se` on((`se`.`id` = `sa`.`sanctions_entity_id`))) where (`se`.`is_active` = true)  ;

-- --------------------------------------------------------

--
-- Structure for view `v_sanctions_source_stats`
--
DROP TABLE IF EXISTS `v_sanctions_source_stats`;

DROP VIEW IF EXISTS `v_sanctions_source_stats`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_sanctions_source_stats`  AS SELECT `se`.`source` AS `source`, count(0) AS `entity_count`, count(distinct `se`.`source_entity_id`) AS `distinct_source_entities`, sum((case when (`se`.`is_active` = true) then 1 else 0 end)) AS `active_entity_count` FROM `sanctions_entities` AS `se` GROUP BY `se`.`source` ;

-- --------------------------------------------------------

--
-- Structure for view `v_suspicious_transactions`
--
DROP TABLE IF EXISTS `v_suspicious_transactions`;

DROP VIEW IF EXISTS `v_suspicious_transactions`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_suspicious_transactions`  AS SELECT `s`.`transaction_id` AS `transaction_id`, `s`.`transaction_reference` AS `transaction_reference`, `s`.`account_id` AS `account_id`, `s`.`client_id` AS `client_id`, `s`.`client_number` AS `client_number`, `s`.`client_type` AS `client_type`, `s`.`transaction_type` AS `transaction_type`, `s`.`amount` AS `amount`, `s`.`currency` AS `currency`, `s`.`channel` AS `channel`, `s`.`country` AS `country`, `s`.`country_from` AS `country_from`, `s`.`country_to` AS `country_to`, `s`.`transaction_date` AS `transaction_date`, `s`.`transaction_status` AS `transaction_status`, `s`.`is_pep` AS `is_pep`, `s`.`pep_indicator` AS `pep_indicator`, `s`.`gender` AS `gender`, `s`.`birth_date` AS `birth_date`, `s`.`nationality` AS `nationality`, `s`.`profession` AS `profession`, `s`.`activity_sector` AS `activity_sector`, `s`.`screening_score` AS `screening_score`, `s`.`sanctions_match_indicator` AS `sanctions_match_indicator`, `s`.`sanctions_match_score` AS `sanctions_match_score`, `s`.`alert_count` AS `alert_count`, `s`.`aml_rule_match_count` AS `aml_rule_match_count`, `s`.`aml_risk_score` AS `aml_risk_score`, `s`.`aml_risk_level` AS `aml_risk_level`, `s`.`agency_code` AS `agency_code`, `s`.`agency_id` AS `agency_id`, `s`.`agency_name` AS `agency_name`, `s`.`caisse_code` AS `caisse_code`, `s`.`caisse_id` AS `caisse_id`, `s`.`caisse_name` AS `caisse_name`, coalesce(`ac`.`current_balance`,0.00) AS `account_current_balance`, coalesce((select sum(`ac2`.`current_balance`) from `accounts` `ac2` where (`ac2`.`client_id` = `s`.`client_id`)),0.00) AS `client_total_current_balance` FROM ((select `t`.`id` AS `transaction_id`,`t`.`transaction_reference` AS `transaction_reference`,`t`.`account_id` AS `account_id`,`c`.`id` AS `client_id`,`c`.`client_number` AS `client_number`,(case when (`ci`.`client_id` is not null) then 'INDIVIDUAL' when (`ce`.`client_id` is not null) then 'ENTITY' else 'UNKNOWN' end) AS `client_type`,`t`.`transaction_type` AS `transaction_type`,`t`.`amount` AS `amount`,`t`.`currency` AS `currency`,`t`.`channel` AS `channel`,`t`.`country` AS `country`,`t`.`country_from` AS `country_from`,`t`.`country_to` AS `country_to`,`t`.`transaction_date` AS `transaction_date`,`t`.`transaction_status` AS `transaction_status`,`c`.`is_pep` AS `is_pep`,(case when (`c`.`is_pep` = 1) then 1 else 0 end) AS `pep_indicator`,`ci`.`gender` AS `gender`,`ci`.`birth_date` AS `birth_date`,(case when (`ci`.`client_id` is not null) then `ci`.`nationality` when (`ce`.`client_id` is not null) then `ce`.`nationality` else NULL end) AS `nationality`,`ci`.`profession` AS `profession`,(case when (`ci`.`client_id` is not null) then `ci`.`activity_sector` when (`ce`.`client_id` is not null) then `ce`.`activity_sector` else NULL end) AS `activity_sector`,(select max(coalesce(`s`.`confidence_score`,0)) from `screenings` `s` where (`s`.`client_id` = `c`.`id`)) AS `screening_score`,(case when exists(select 1 from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) then 1 else 0 end) AS `sanctions_match_indicator`,(select max(coalesce(`sm`.`match_score`,0)) from `sanction_matches` `sm` where (`sm`.`client_id` = `c`.`id`)) AS `sanctions_match_score`,(select count(0) from `alerts` `al` where (`al`.`transaction_id` = `t`.`id`)) AS `alert_count`,(select count(0) from `rule_executions` `re` where ((`re`.`transaction_id` = `t`.`id`) and (`re`.`execution_result` = 'MATCH'))) AS `aml_rule_match_count`,least(100,coalesce((select sum(least(100,coalesce(`ra`.`score`,0))) from `risk_assessments` `ra` where ((`ra`.`transaction_id` = `t`.`id`) and (`ra`.`source` = 'AML_RULE_ENGINE'))),0)) AS `aml_risk_score`,(case when (least(100,coalesce((select sum(least(100,coalesce(`ra`.`score`,0))) from `risk_assessments` `ra` where ((`ra`.`transaction_id` = `t`.`id`) and (`ra`.`source` = 'AML_RULE_ENGINE'))),0)) >= 80) then 'CRITICAL' when (least(100,coalesce((select sum(least(100,coalesce(`ra`.`score`,0))) from `risk_assessments` `ra` where ((`ra`.`transaction_id` = `t`.`id`) and (`ra`.`source` = 'AML_RULE_ENGINE'))),0)) >= 60) then 'HIGH' when (least(100,coalesce((select sum(least(100,coalesce(`ra`.`score`,0))) from `risk_assessments` `ra` where ((`ra`.`transaction_id` = `t`.`id`) and (`ra`.`source` = 'AML_RULE_ENGINE'))),0)) >= 30) then 'MEDIUM' else 'LOW' end) AS `aml_risk_level`,`ag`.`code` AS `agency_code`,`ag`.`id` AS `agency_id`,`ag`.`name` AS `agency_name`,`ca`.`code` AS `caisse_code`,`ca`.`id` AS `caisse_id`,`ca`.`name` AS `caisse_name` from ((((((`transactions` `t` join `accounts` `ac0` on((`ac0`.`id` = `t`.`account_id`))) join `clients` `c` on((`c`.`id` = `ac0`.`client_id`))) left join `client_individuals` `ci` on((`ci`.`client_id` = `c`.`id`))) left join `client_entities` `ce` on((`ce`.`client_id` = `c`.`id`))) left join `agencies` `ag` on((`ag`.`id` = `t`.`agency_id`))) left join `caisses` `ca` on((`ca`.`id` = `ag`.`caisse_id`))) where ((upper(coalesce(`t`.`transaction_status`,'COMPLETED')) = 'COMPLETED') and (exists(select 1 from `alerts` `al2` where (`al2`.`transaction_id` = `t`.`id`)) or exists(select 1 from `risk_assessments` `ra2` where ((`ra2`.`transaction_id` = `t`.`id`) and (`ra2`.`source` = 'AML_RULE_ENGINE') and (`ra2`.`score` > 0)))))) `s` join `accounts` `ac` on((`ac`.`id` = `s`.`account_id`))) ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `fk_accounts_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `agencies`
--
ALTER TABLE `agencies`
  ADD CONSTRAINT `fk_agencies_caisse` FOREIGN KEY (`caisse_id`) REFERENCES `caisses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `fk_alerts_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_alerts_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `alert_actions`
--
ALTER TABLE `alert_actions`
  ADD CONSTRAINT `fk_alert_actions_alert` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_alert_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `aml_rule_parameters`
--
ALTER TABLE `aml_rule_parameters`
  ADD CONSTRAINT `fk_aml_rule_parameters_rule` FOREIGN KEY (`rule_id`) REFERENCES `aml_rules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `beneficial_owners`
--
ALTER TABLE `beneficial_owners`
  ADD CONSTRAINT `fk_beneficial_owners_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `centif_declarations`
--
ALTER TABLE `centif_declarations`
  ADD CONSTRAINT `fk_centif_declarations_alert` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`),
  ADD CONSTRAINT `fk_centif_declarations_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  ADD CONSTRAINT `fk_centif_declarations_user` FOREIGN KEY (`declared_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_clients_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_clients_risk_level` FOREIGN KEY (`risk_level_id`) REFERENCES `risk_levels` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `client_aliases`
--
ALTER TABLE `client_aliases`
  ADD CONSTRAINT `fk_client_aliases_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `client_entities`
--
ALTER TABLE `client_entities`
  ADD CONSTRAINT `fk_client_entities_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `client_individuals`
--
ALTER TABLE `client_individuals`
  ADD CONSTRAINT `fk_client_individuals_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `identity_documents`
--
ALTER TABLE `identity_documents`
  ADD CONSTRAINT `fk_identity_documents_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `investigations`
--
ALTER TABLE `investigations`
  ADD CONSTRAINT `fk_investigations_alert` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_investigations_user` FOREIGN KEY (`assigned_user`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `kyc_document_checks`
--
ALTER TABLE `kyc_document_checks`
  ADD CONSTRAINT `fk_kyc_document_checks_document` FOREIGN KEY (`document_id`) REFERENCES `identity_documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `kyc_reviews`
--
ALTER TABLE `kyc_reviews`
  ADD CONSTRAINT `fk_kyc_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ml_customer_features`
--
ALTER TABLE `ml_customer_features`
  ADD CONSTRAINT `fk_ml_customer_features_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `model_feedback`
--
ALTER TABLE `model_feedback`
  ADD CONSTRAINT `fk_model_feedback_prediction` FOREIGN KEY (`prediction_id`) REFERENCES `predictions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `pep_matches`
--
ALTER TABLE `pep_matches`
  ADD CONSTRAINT `fk_pep_matches_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pep_matches_screening` FOREIGN KEY (`screening_id`) REFERENCES `screenings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `predictions`
--
ALTER TABLE `predictions`
  ADD CONSTRAINT `fk_predictions_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_predictions_model` FOREIGN KEY (`model_id`) REFERENCES `ai_models` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_predictions_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `prediction_explanations`
--
ALTER TABLE `prediction_explanations`
  ADD CONSTRAINT `fk_prediction_explanations_prediction` FOREIGN KEY (`prediction_id`) REFERENCES `predictions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `risk_assessments`
--
ALTER TABLE `risk_assessments`
  ADD CONSTRAINT `fk_risk_assessments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_risk_assessments_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `risk_scores`
--
ALTER TABLE `risk_scores`
  ADD CONSTRAINT `fk_risk_scores_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `rule_conditions`
--
ALTER TABLE `rule_conditions`
  ADD CONSTRAINT `fk_rule_conditions_rule` FOREIGN KEY (`rule_id`) REFERENCES `aml_rules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `rule_executions`
--
ALTER TABLE `rule_executions`
  ADD CONSTRAINT `fk_rule_executions_rule` FOREIGN KEY (`rule_id`) REFERENCES `aml_rules` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rule_executions_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sanctions_addresses`
--
ALTER TABLE `sanctions_addresses`
  ADD CONSTRAINT `fk_saddr_entity` FOREIGN KEY (`sanctions_entity_id`) REFERENCES `sanctions_entities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sanctions_aliases`
--
ALTER TABLE `sanctions_aliases`
  ADD CONSTRAINT `fk_sa_entity` FOREIGN KEY (`sanctions_entity_id`) REFERENCES `sanctions_entities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sanctions_birth_details`
--
ALTER TABLE `sanctions_birth_details`
  ADD CONSTRAINT `fk_sbd_entity` FOREIGN KEY (`sanctions_entity_id`) REFERENCES `sanctions_entities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sanctions_entities`
--
ALTER TABLE `sanctions_entities`
  ADD CONSTRAINT `fk_sanctions_entity_list` FOREIGN KEY (`screening_list_id`) REFERENCES `screening_lists` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `sanctions_identifiers`
--
ALTER TABLE `sanctions_identifiers`
  ADD CONSTRAINT `fk_si_entity` FOREIGN KEY (`sanctions_entity_id`) REFERENCES `sanctions_entities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sanctions_programs_designations`
--
ALTER TABLE `sanctions_programs_designations`
  ADD CONSTRAINT `fk_spd_entity` FOREIGN KEY (`sanctions_entity_id`) REFERENCES `sanctions_entities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sanction_matches`
--
ALTER TABLE `sanction_matches`
  ADD CONSTRAINT `fk_sanction_matches_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sanction_matches_screening` FOREIGN KEY (`screening_id`) REFERENCES `screenings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `screenings`
--
ALTER TABLE `screenings`
  ADD CONSTRAINT `fk_screenings_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_screenings_list` FOREIGN KEY (`screening_list_id`) REFERENCES `screening_lists` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `screening_list_entries`
--
ALTER TABLE `screening_list_entries`
  ADD CONSTRAINT `fk_screening_entries_list` FOREIGN KEY (`screening_list_id`) REFERENCES `screening_lists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transactions_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transactions_reversal` FOREIGN KEY (`reversal_of_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
