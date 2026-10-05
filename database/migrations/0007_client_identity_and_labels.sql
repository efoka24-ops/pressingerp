-- Un client = un numéro de téléphone : déjà garanti par la clé unique uq_clients_phone du schéma de base

-- Journal des impressions d'étiquettes : première impression, réimpression motivée, liste manuelle
CREATE TABLE label_prints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL,
  reason VARCHAR(255) NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_label_prints_order (order_id),
  CONSTRAINT fk_label_prints_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
