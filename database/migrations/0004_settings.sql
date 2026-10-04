-- @delimiter ;;
-- Paramètres administrables, versionnés : une ligne par changement (la valeur courante est la plus récente)
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(60) NOT NULL,
  agency_id INT NULL,
  value TEXT NOT NULL,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_settings_key (key_name, agency_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;;
