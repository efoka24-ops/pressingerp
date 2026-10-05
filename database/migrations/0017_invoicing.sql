-- Phase 10 : facturation avec TVA et NIU, avoirs, devis, rapprochement des règlements

ALTER TABLE clients ADD COLUMN niu VARCHAR(30) NULL;

-- Les montants des commandes sont TTC (D5) : la facture ventile HT, TVA et TTC au taux en vigueur à l'émission
ALTER TABLE invoices
  ADD COLUMN agency_id INT NULL,
  ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'facture',
  ADD COLUMN ref_invoice_id INT NULL,
  ADD COLUMN vat_rate DECIMAL(5,2) NULL,
  ADD COLUMN subtotal INT NULL,
  ADD COLUMN vat_amount INT NULL,
  ADD COLUMN credited INT NOT NULL DEFAULT 0,
  ADD COLUMN seller_name VARCHAR(150) NULL,
  ADD COLUMN seller_niu VARCHAR(30) NULL,
  ADD COLUMN client_niu VARCHAR(30) NULL,
  ADD COLUMN reason VARCHAR(255) NULL,
  ADD COLUMN created_by INT NULL;

-- Factures déjà émises : ventilation au taux de 19,25 % (taux en vigueur), sans changer le TTC
UPDATE invoices SET vat_rate = 19.25, subtotal = ROUND(total / 1.1925), vat_amount = total - ROUND(total / 1.1925) WHERE vat_rate IS NULL;

CREATE TABLE quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(24) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  agency_id INT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'envoye',
  valid_until DATE NOT NULL,
  service_level VARCHAR(12) NOT NULL DEFAULT 'standard',
  subtotal INT NOT NULL,
  surcharge INT NOT NULL DEFAULT 0,
  discount INT NOT NULL DEFAULT 0,
  total INT NOT NULL,
  notes VARCHAR(255) NULL,
  decided_at DATETIME NULL,
  decided_note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_quotes_client (client_id, status),
  CONSTRAINT fk_quotes_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quote_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NOT NULL,
  article_id INT NULL,
  label VARCHAR(150) NOT NULL,
  qty DECIMAL(8,2) NOT NULL DEFAULT 1,
  unit_price INT NOT NULL,
  line_total INT NOT NULL,
  CONSTRAINT fk_quote_lines_quote FOREIGN KEY (quote_id) REFERENCES quotes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dépassement du plafond d'encours accordé par un responsable (RG16) : motif et autorisant conservés
CREATE TABLE credit_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  order_id INT NULL,
  outstanding INT NOT NULL,
  order_total INT NOT NULL,
  credit_limit INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  authorised_by INT NOT NULL,
  requested_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_credit_overrides_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO alert_rules (event, label, priority, target_role, delay_minutes, escalate_role, escalate_after_minutes) VALUES
  ('credit_override', 'Plafond d''encours dépassé avec autorisation', 'normal', 'direction', 0, NULL, NULL);
