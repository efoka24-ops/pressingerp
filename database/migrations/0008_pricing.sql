-- Grilles tarifaires administrables et versionnées (ordre de priorité : contrat > agence > VIP > promotion > standard)
CREATE TABLE price_lists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(10) NOT NULL,
  name VARCHAR(120) NOT NULL,
  agency_id INT NULL,
  client_id INT NULL,
  valid_from DATE NULL,
  valid_to DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_price_lists_kind (kind, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un prix = une ligne ; le prix courant d'un article dans une grille est la ligne la plus récente (price NULL = retiré)
CREATE TABLE price_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  list_id INT NOT NULL,
  article_id INT NOT NULL,
  price INT NULL,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_price_items_current (list_id, article_id, id),
  CONSTRAINT fk_price_items_list FOREIGN KEY (list_id) REFERENCES price_lists(id),
  CONSTRAINT fk_price_items_article FOREIGN KEY (article_id) REFERENCES articles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO price_lists (kind, name, created_at) VALUES ('standard', 'Tarif standard', NOW());

INSERT INTO price_items (list_id, article_id, price, reason, created_at)
SELECT (SELECT id FROM price_lists WHERE kind = 'standard' LIMIT 1), id, price, 'Reprise du catalogue initial', NOW() FROM articles WHERE price > 0;
