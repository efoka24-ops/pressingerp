-- Phase 5 : caisse (paiements mixtes, annulations par écriture inverse, remises autorisées, reçus)
ALTER TABLE payments
  ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'payment',
  ADD COLUMN reverses_id INT NULL,
  ADD COLUMN reason VARCHAR(255) NULL,
  ADD COLUMN authorised_by INT NULL,
  ADD COLUMN split_group VARCHAR(20) NULL,
  ADD COLUMN receipt_no VARCHAR(20) NULL,
  ADD KEY idx_payments_reverses (reverses_id),
  ADD KEY idx_payments_group (split_group),
  ADD KEY idx_payments_receipt (receipt_no);

-- Remises accordées après la création de la commande : demandeur, responsable qui autorise, motif, montants avant/après
CREATE TABLE order_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL,
  amount INT NOT NULL,
  old_total INT NOT NULL,
  new_total INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  requested_by INT NULL,
  authorised_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_adjustments_order (order_id),
  CONSTRAINT fk_adjustments_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cash_sessions ADD COLUMN variance INT NULL;

-- Phase 6 : alertes interservices et escalade
CREATE TABLE alert_rules (
  event VARCHAR(30) NOT NULL PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  priority VARCHAR(10) NOT NULL DEFAULT 'normal',
  target_role VARCHAR(20) NOT NULL,
  delay_minutes INT NOT NULL DEFAULT 0,
  escalate_role VARCHAR(20) NULL,
  escalate_after_minutes INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO alert_rules (event, label, priority, target_role, delay_minutes, escalate_role, escalate_after_minutes) VALUES
  ('transfer', 'Fin de traitement : pièces disponibles au poste suivant', 'normal', 'atelier', 0, NULL, NULL),
  ('stale', 'Pièce non prise en charge dans le délai', 'high', 'superviseur', 120, 'manager', 120),
  ('blocked', 'Pièce bloquée par un incident', 'high', 'superviseur', 0, 'manager', 30),
  ('incident_critical', 'Incident critique', 'critical', 'manager', 0, NULL, NULL),
  ('rework', 'Reprise qualité à traiter', 'high', 'atelier', 0, 'superviseur', 60),
  ('order_late', 'Commande en retard', 'critical', 'manager', 0, NULL, NULL),
  ('order_risk', 'Commande à risque de retard', 'high', 'superviseur', 0, 'manager', 60),
  ('cash_variance', 'Écart de caisse', 'critical', 'manager', 0, NULL, NULL),
  ('credit_over', 'Encours client supérieur au plafond', 'high', 'manager', 0, NULL, NULL),
  ('stock_low', 'Stock sous le minimum', 'normal', 'manager', 0, NULL, NULL);

CREATE TABLE alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dedupe_key VARCHAR(80) NOT NULL,
  event VARCHAR(30) NOT NULL,
  level VARCHAR(10) NOT NULL,
  message VARCHAR(255) NOT NULL,
  subject_type VARCHAR(20) NULL,
  subject_id INT NULL,
  agency_id INT NULL,
  target_role VARCHAR(20) NOT NULL,
  opened_at DATETIME NOT NULL,
  escalated_at DATETIME NULL,
  escalated_role VARCHAR(20) NULL,
  ack_by INT NULL,
  ack_at DATETIME NULL,
  closed_at DATETIME NULL,
  close_reason VARCHAR(120) NULL,
  KEY idx_alerts_key (dedupe_key, closed_at),
  KEY idx_alerts_open (closed_at, target_role),
  KEY idx_alerts_event (event, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
