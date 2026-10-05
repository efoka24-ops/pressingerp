-- Dérogations au contrôle qualité : une pièce passe sans contrôle conforme uniquement sur décision motivée d'un responsable
CREATE TABLE quality_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  garment_id INT NOT NULL,
  step VARCHAR(12) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  authorised_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_overrides_garment (garment_id, created_at),
  CONSTRAINT fk_overrides_garment FOREIGN KEY (garment_id) REFERENCES garments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
