-- Parcours par type de traitement : les étapes de travail prévues entre le tri et le contrôle qualité
CREATE TABLE treatments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  label VARCHAR(80) NOT NULL,
  steps VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO treatments (code, label, steps, sort) VALUES
  ('complet', 'Nettoyage complet', 'detachage,lavage,sechage,repassage,finition', 1),
  ('lavage_repassage', 'Lavage + repassage', 'lavage,sechage,repassage,finition', 2),
  ('nettoyage_sec', 'Nettoyage à sec', 'detachage,lavage,repassage,finition', 3),
  ('detachage_lavage', 'Détachage + lavage', 'detachage,lavage,sechage,finition', 4),
  ('repassage', 'Repassage seul', 'repassage,finition', 5);

ALTER TABLE garments ADD COLUMN treatment_id INT NULL AFTER article_id;

-- Incidents typés (un incident ouvert bloque la pièce jusqu'à sa levée)
CREATE TABLE incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  garment_id INT NOT NULL,
  step VARCHAR(12) NOT NULL,
  type VARCHAR(20) NOT NULL,
  severity VARCHAR(10) NOT NULL DEFAULT 'normal',
  note VARCHAR(255) NOT NULL,
  reported_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by INT NULL,
  resolution VARCHAR(255) NULL,
  KEY idx_incidents_open (resolved_at, severity),
  KEY idx_incidents_garment (garment_id),
  CONSTRAINT fk_incidents_garment FOREIGN KEY (garment_id) REFERENCES garments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pièces perdues ou endommagées : déclaration puis décision d'indemnisation plafonnée
CREATE TABLE garment_losses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  garment_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL,
  description VARCHAR(255) NOT NULL,
  declared_by INT NULL,
  declared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(10) NOT NULL DEFAULT 'declaree',
  amount INT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_reason VARCHAR(255) NULL,
  KEY idx_losses_status (status),
  CONSTRAINT fk_losses_garment FOREIGN KEY (garment_id) REFERENCES garments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
