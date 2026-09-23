-- =============================================================================
-- SENTINELLE-CIF — SEED 04 Role permissions (matrice machine-readable)
-- Date: 2026-09-22
-- Prérequis : table roles (SEED_01)
-- Doc humaine : RBAC_MATRICE_ROLES_SENTINELLE.md
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

-- Colonne scope optionnelle sur users (ADD only si absente)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'scope_level'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN scope_level VARCHAR(20) NULL DEFAULT NULL COMMENT ''PLATFORM|CAISSE|AGENCY|PORTFOLIO'' AFTER role_id',
  'SELECT ''users.scope_level already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'caisse_id'
);
SET @sql2 := IF(@col2 = 0,
  'ALTER TABLE users ADD COLUMN caisse_id BIGINT NULL DEFAULT NULL COMMENT ''Rattachement caisse pour scope CAISSE'' AFTER agency_id',
  'SELECT ''users.caisse_id already exists'' AS info');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

DELETE FROM role_permissions;

-- Helper: role ids 1=ADMIN 2=CO 3=SUP 4=AGENT
-- ADMIN : presque tout
INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(1,'nav.dashboard',1),(1,'nav.network',1),(1,'nav.alerts',1),(1,'nav.investigations',1),
(1,'nav.centif',1),(1,'nav.clients',1),(1,'nav.accounts',1),(1,'nav.transactions',1),
(1,'nav.screening',1),(1,'nav.analyse',1),(1,'nav.ml',1),(1,'nav.reports',1),
(1,'nav.audit',1),(1,'nav.users',1),(1,'nav.engines',1),(1,'nav.onboarding_org',1),(1,'nav.settings',1),
(1,'data.scope_platform',1),
(1,'alert.view',1),(1,'alert.decide',1),(1,'alert.escalate',1),(1,'alert.signal',1),
(1,'investigation.view',1),(1,'investigation.manage',1),
(1,'centif.view',1),(1,'centif.manage',1),
(1,'client.view',1),(1,'client.create',1),(1,'client.status',1),
(1,'account.view',1),(1,'account.create',1),
(1,'tx.view',1),(1,'tx.create',1),
(1,'screening.view',1),(1,'ml.use',1),(1,'report.view',1),(1,'audit.view',1),
(1,'user.manage',1),(1,'org.register',1),(1,'engine.configure',1);

-- COMPLIANCE_OFFICER
INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(2,'nav.dashboard',1),(2,'nav.network',1),(2,'nav.alerts',1),(2,'nav.investigations',1),
(2,'nav.centif',1),(2,'nav.clients',1),(2,'nav.accounts',1),(2,'nav.transactions',1),
(2,'nav.screening',1),(2,'nav.analyse',1),(2,'nav.ml',1),(2,'nav.reports',1),
(2,'nav.audit',1),(2,'nav.users',0),(2,'nav.engines',0),(2,'nav.onboarding_org',0),(2,'nav.settings',1),
(2,'data.scope_caisse',1),(2,'data.scope_agency',1),
(2,'alert.view',1),(2,'alert.decide',1),(2,'alert.escalate',1),(2,'alert.signal',1),
(2,'investigation.view',1),(2,'investigation.manage',1),
(2,'centif.view',1),(2,'centif.manage',1),
(2,'client.view',1),(2,'client.create',1),(2,'client.status',1),
(2,'account.view',1),(2,'account.create',1),
(2,'tx.view',1),(2,'tx.create',1),
(2,'screening.view',1),(2,'ml.use',1),(2,'report.view',1),(2,'audit.view',1),
(2,'user.manage',0),(2,'org.register',0),(2,'engine.configure',0);

-- SUPERVISOR
INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(3,'nav.dashboard',1),(3,'nav.network',1),(3,'nav.alerts',1),(3,'nav.investigations',1),
(3,'nav.centif',0),(3,'nav.clients',1),(3,'nav.accounts',1),(3,'nav.transactions',1),
(3,'nav.screening',1),(3,'nav.analyse',0),(3,'nav.ml',0),(3,'nav.reports',1),
(3,'nav.audit',0),(3,'nav.users',0),(3,'nav.engines',0),(3,'nav.onboarding_org',0),(3,'nav.settings',1),
(3,'data.scope_agency',1),
(3,'alert.view',1),(3,'alert.decide',0),(3,'alert.escalate',1),(3,'alert.signal',1),
(3,'investigation.view',1),(3,'investigation.manage',1),
(3,'centif.view',0),(3,'centif.manage',0),
(3,'client.view',1),(3,'client.create',1),(3,'client.status',0),
(3,'account.view',1),(3,'account.create',1),
(3,'tx.view',1),(3,'tx.create',1),
(3,'screening.view',1),(3,'ml.use',0),(3,'report.view',1),(3,'audit.view',0),
(3,'user.manage',0),(3,'org.register',0),(3,'engine.configure',0);

-- AGENT
INSERT INTO role_permissions (role_id, permission_code, allowed) VALUES
(4,'nav.dashboard',1),(4,'nav.network',0),(4,'nav.alerts',1),(4,'nav.investigations',0),
(4,'nav.centif',0),(4,'nav.clients',1),(4,'nav.accounts',1),(4,'nav.transactions',1),
(4,'nav.screening',0),(4,'nav.analyse',0),(4,'nav.ml',0),(4,'nav.reports',0),
(4,'nav.audit',0),(4,'nav.users',0),(4,'nav.engines',0),(4,'nav.onboarding_org',0),(4,'nav.settings',1),
(4,'data.scope_agency',1),
(4,'alert.view',1),(4,'alert.decide',0),(4,'alert.escalate',0),(4,'alert.signal',1),
(4,'investigation.view',0),(4,'investigation.manage',0),
(4,'centif.view',0),(4,'centif.manage',0),
(4,'client.view',1),(4,'client.create',0),(4,'client.status',0),
(4,'account.view',1),(4,'account.create',0),
(4,'tx.view',1),(4,'tx.create',1),
(4,'screening.view',0),(4,'ml.use',0),(4,'report.view',0),(4,'audit.view',0),
(4,'user.manage',0),(4,'org.register',0),(4,'engine.configure',0);

-- Scopes users démo
UPDATE users SET scope_level = 'PLATFORM' WHERE role_id = 1;
UPDATE users SET scope_level = 'CAISSE' WHERE role_id = 2;
UPDATE users SET scope_level = 'AGENCY' WHERE role_id IN (3, 4);

SELECT r.name, COUNT(*) AS perms_on
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
WHERE rp.allowed = 1
GROUP BY r.name;
