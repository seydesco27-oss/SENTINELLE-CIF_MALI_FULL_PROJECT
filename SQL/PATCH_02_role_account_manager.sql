-- Rôle ACCOUNT_MANAGER — pas de table gestionnaires
-- Les gestionnaires = users avec ce rôle (ou AGENT)
-- Lien portefeuille : accounts.account_manager_id → users.id

USE digi_aml;

INSERT INTO roles (id, name, description)
SELECT 5, 'ACCOUNT_MANAGER', 'Gestionnaire de comptes clients — suivi portefeuille'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'ACCOUNT_MANAGER');

-- Agents seed existants (ids 4,5,6 si présents) peuvent rester AGENT ;
-- pour démo, promouvoir user id=4 si existe :
UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'ACCOUNT_MANAGER' LIMIT 1)
WHERE id = 4
  AND EXISTS (SELECT 1 FROM users WHERE id = 4);

SELECT id, name, description FROM roles ORDER BY id;
SELECT u.id, u.username, r.name AS role
FROM users u
LEFT JOIN roles r ON r.id = u.role_id
ORDER BY u.id;
