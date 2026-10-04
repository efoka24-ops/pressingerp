-- Paiements Mobile Money en attente de confirmation (passerelle Sungku)
CREATE TABLE payment_intents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL UNIQUE,
  order_id INT NOT NULL,
  client_id INT NOT NULL,
  method VARCHAR(12) NOT NULL,
  amount INT NOT NULL,
  phone VARCHAR(20) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'PENDING',
  provider_status VARCHAR(30) NULL,
  provider_ref VARCHAR(80) NULL,
  payment_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_intents_order (order_id, status),
  CONSTRAINT fk_intents_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_intents_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
