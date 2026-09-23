-- =============================================================================
-- SENTINELLE-CIF — SEED 04 Role permissions (ENDURCI)
-- Date: 2026-09-22b
-- Doc: RBAC_MATRICE_ROLES_SENTINELLE.md
-- Prérequis: roles (SEED_01) — ids 1 ADMIN, 2 CO, 3 SUP, 4 AGENT
-- =============================================================================

USE digi_aml;

CREATE TABLE IF NOT EXISTS role_permissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  role_id BIGINT NOT NULL,
  permission_code VARCHAR(80) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_perm (role_id, permission_code),
  KEY idx_perm_code (permission_code),
  CONSTRAINT fk_role_perm_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'scope_level');
SET @s1 := IF(@c1 = 0,
  'ALTER TABLE users ADD COLUMN scope_level VARCHAR(20) NULL DEFAULT NULL COMMENT ''PLATFORM|CAISSE|AGENCY|PORTFOLIO'' AFTER role_id',
  'SELECT 1');
PREPARE ps1 FROM @s1; EXECUTE ps1; DEALLOCATE PREPARE ps1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'caisse_id');
SET @s2 := IF(@c2 = 0,
  'ALTER TABLE users ADD COLUMN caisse_id BIGINT NULL DEFAULT NULL COMMENT ''Rattachement caisse (scope CAISSE)'' AFTER agency_id',
  'SELECT 1');
PREPARE ps2 FROM @s2; EXECUTE ps2; DEALLOCATE PREPARE ps2;

DELETE FROM role_permissions;

INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(1,'nav.dashboard',1),(1,'nav.network',1),(1,'nav.alerts',1),(1,'nav.investigations',1),
(1,'nav.centif',1),(1,'nav.clients',1),(1,'nav.accounts',1),(1,'nav.transactions',1),
(1,'nav.screening',1),(1,'nav.screening_admin',1),(1,'nav.analyse',1),(1,'nav.ml',1),
(1,'nav.reports',1),(1,'nav.audit',1),(1,'nav.users',1),(1,'nav.engines',1),
(1,'nav.onboarding_org',1),(1,'nav.demo_scenarios',1),(1,'nav.settings',1),
(1,'data.scope_platform',1),
(1,'alert.view',1),(1,'alert.decide',1),(1,'alert.escalate',1),(1,'alert.signal',1),
(1,'investigation.view',1),(1,'investigation.manage',1),
(1,'centif.view',1),(1,'centif.manage',1),
(1,'client.view',1),(1,'client.create',1),(1,'client.status',1),
(1,'account.view',1),(1,'account.create',1),
(1,'tx.view',1),(1,'tx.create',1),
(1,'screening.view',1),(1,'list.import',1),(1,'list.publish',1),(1,'screening.run_batch',1),
(1,'ml.use',1),(1,'report.view',1),(1,'audit.view',1),
(1,'user.manage',1),(1,'org.register',1),(1,'engine.configure',1);

INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(2,'nav.dashboard',1),(2,'nav.network',1),(2,'nav.alerts',1),(2,'nav.investigations',1),
(2,'nav.centif',1),(2,'nav.clients',1),(2,'nav.accounts',1),(2,'nav.transactions',1),
(2,'nav.screening',1),(2,'nav.screening_admin',0),(2,'nav.analyse',1),(2,'nav.ml',1),
(2,'nav.reports',1),(2,'nav.audit',1),(2,'nav.users',0),(2,'nav.engines',0),
(2,'nav.onboarding_org',0),(2,'nav.demo_scenarios',0),(2,'nav.settings',1),
(2,'data.scope_caisse',1),(2,'data.scope_agency',1),
(2,'alert.view',1),(2,'alert.decide',1),(2,'alert.escalate',1),(2,'alert.signal',1),
(2,'investigation.view',1),(2,'investigation.manage',1),
(2,'centif.view',1),(2,'centif.manage',1),
(2,'client.view',1),(2,'client.create',1),(2,'client.status',1),
(2,'account.view',1),(2,'account.create',1),
(2,'tx.view',1),(2,'tx.create',1),
(2,'screening.view',1),(2,'list.import',0),(2,'list.publish',0),(2,'screening.run_batch',0),
(2,'ml.use',1),(2,'report.view',1),(2,'audit.view',1),
(2,'user.manage',0),(2,'org.register',0),(2,'engine.configure',0);

INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(3,'nav.dashboard',1),(3,'nav.network',1),(3,'nav.alerts',1),(3,'nav.investigations',1),
(3,'nav.centif',0),(3,'nav.clients',1),(3,'nav.accounts',1),(3,'nav.transactions',1),
(3,'nav.screening',1),(3,'nav.screening_admin',0),(3,'nav.analyse',0),(3,'nav.ml',0),
(3,'nav.reports',1),(3,'nav.audit',0),(3,'nav.users',0),(3,'nav.engines',0),
(3,'nav.onboarding_org',0),(3,'nav.demo_scenarios',0),(3,'nav.settings',1),
(3,'data.scope_agency',1),(3,'data.scope_caisse',1),
(3,'alert.view',1),(3,'alert.decide',0),(3,'alert.escalate',1),(3,'alert.signal',1),
(3,'investigation.view',1),(3,'investigation.manage',1),
(3,'centif.view',0),(3,'centif.manage',0),
(3,'client.view',1),(3,'client.create',1),(3,'client.status',0),
(3,'account.view',1),(3,'account.create',1),
(3,'tx.view',1),(3,'tx.create',1),
(3,'screening.view',1),(3,'list.import',0),(3,'list.publish',0),(3,'screening.run_batch',0),
(3,'ml.use',0),(3,'report.view',1),(3,'audit.view',0),
(3,'user.manage',0),(3,'org.register',0),(3,'engine.configure',0);

INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(4,'nav.dashboard',1),(4,'nav.network',0),(4,'nav.alerts',1),(4,'nav.investigations',0),
(4,'nav.centif',0),(4,'nav.clients',1),(4,'nav.accounts',1),(4,'nav.transactions',1),
(4,'nav.screening',0),(4,'nav.screening_admin',0),(4,'nav.analyse',0),(4,'nav.ml',0),
(4,'nav.reports',0),(4,'nav.audit',0),(4,'nav.users',0),(4,'nav.engines',0),
(4,'nav.onboarding_org',0),(4,'nav.demo_scenarios',0),(4,'nav.settings',1),
(4,'data.scope_agency',1),
(4,'alert.view',1),(4,'alert.decide',0),(4,'alert.escalate',0),(4,'alert.signal',1),
(4,'investigation.view',0),(4,'investigation.manage',0),
(4,'centif.view',0),(4,'centif.manage',0),
(4,'client.view',1),(4,'client.create',0),(4,'client.status',0),
(4,'account.view',1),(4,'account.create',0),
(4,'tx.view',1),(4,'tx.create',1),
(4,'screening.view',0),(4,'list.import',0),(4,'list.publish',0),(4,'screening.run_batch',0),
(4,'ml.use',0),(4,'report.view',0),(4,'audit.view',0),
(4,'user.manage',0),(4,'org.register',0),(4,'engine.configure',0);

UPDATE users SET scope_level = 'PLATFORM' WHERE role_id = 1;
UPDATE users SET scope_level = 'CAISSE'   WHERE role_id = 2;
UPDATE users SET scope_level = 'AGENCY'   WHERE role_id IN (3, 4);

SELECT r.name AS role_name, SUM(rp.allowed) AS permissions_enabled
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
GROUP BY r.name
ORDER BY r.id;
