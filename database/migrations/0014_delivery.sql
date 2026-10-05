-- Phase 8 : livraison et collecte à domicile, avec preuve de remise et solde à percevoir

CREATE TABLE deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(8) NOT NULL DEFAULT 'deliver',
  order_id INT NULL,
  client_id INT NOT NULL,
  agency_id INT NOT NULL,
  status VARCHAR(14) NOT NULL,
  address VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  driver_id INT NULL,
  slot_at DATETIME NULL,
  amount_due INT NOT NULL DEFAULT 0,
  attempts INT NOT NULL DEFAULT 0,
  otp_hash VARCHAR(255) NULL,
  deferred TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  KEY idx_deliveries_status (status, agency_id),
  KEY idx_deliveries_driver (driver_id, status),
  KEY idx_deliveries_order (order_id),
  CONSTRAINT fk_deliveries_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique des statuts, en ajout seul
CREATE TABLE delivery_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT NOT NULL,
  status VARCHAR(14) NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_delivery_events (delivery_id, id),
  CONSTRAINT fk_delivery_events FOREIGN KEY (delivery_id) REFERENCES deliveries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preuves de livraison, en ajout seul : code de remise validé, signature ou photo
CREATE TABLE delivery_proofs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL,
  data VARCHAR(255) NOT NULL,
  recorded_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_delivery_proofs (delivery_id),
  CONSTRAINT fk_delivery_proofs FOREIGN KEY (delivery_id) REFERENCES deliveries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commandes déjà en cours qui demandent une livraison
INSERT INTO deliveries (kind, order_id, client_id, agency_id, status, address, phone, amount_due, created_at)
SELECT 'deliver', o.id, o.client_id, o.agency_id, IF(o.status = 'pret', 'a_livrer', 'en_traitement'), o.delivery_address, c.phone,
       IF(o.on_account = 1, 0, GREATEST(0, o.total - o.paid)), NOW()
FROM orders o JOIN clients c ON c.id = o.client_id
WHERE o.delivery_address IS NOT NULL AND o.status IN ('en_atelier', 'pret');

INSERT INTO alert_rules (event, label, priority, target_role, delay_minutes, escalate_role, escalate_after_minutes) VALUES
  ('delivery_unassigned', 'Livraison ou collecte sans livreur', 'normal', 'comptoir', 60, 'manager', 120),
  ('delivery_failed', 'Livraison non aboutie : à replanifier', 'high', 'comptoir', 0, 'manager', 120),
  ('delivery_address', 'Adresse de livraison introuvable : à corriger', 'high', 'comptoir', 0, NULL, NULL);
