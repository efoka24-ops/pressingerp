-- Demandes d'ouverture de pressing déposées par un futur responsable, validées par le super administrateur

CREATE TABLE agency_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pressing_name VARCHAR(100) NOT NULL,
  city VARCHAR(80) NOT NULL,
  address VARCHAR(200) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  manager_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  message VARCHAR(500) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'en_attente',
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  reject_reason VARCHAR(255) NULL,
  agency_id INT NULL,
  user_id INT NULL,
  mail_status VARCHAR(10) NULL,
  KEY idx_agency_requests_status (status, created_at),
  KEY idx_agency_requests_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mot de passe provisoire (envoyé par e-mail ou généré par l'administrateur) : à changer à la première connexion
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO alert_rules (event, label, priority, target_role, delay_minutes, escalate_role, escalate_after_minutes) VALUES
  ('agency_request', 'Demande d''ouverture de pressing à valider', 'normal', 'admin', 0, NULL, NULL);
