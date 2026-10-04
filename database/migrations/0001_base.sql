CREATE TABLE agencies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(30) NULL,
  is_workshop TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agency_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  login VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,
  role VARCHAR(20) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_agency FOREIGN KEY (agency_id) REFERENCES agencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  type VARCHAR(12) NOT NULL DEFAULT 'particulier',
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  is_vip TINYINT(1) NOT NULL DEFAULT 0,
  credit_limit INT NOT NULL DEFAULT 0,
  payment_terms_days INT NOT NULL DEFAULT 0,
  preferred_channel VARCHAR(12) NOT NULL DEFAULT 'sms',
  preferences TEXT NULL,
  notes TEXT NULL,
  loyalty_points INT NOT NULL DEFAULT 0,
  referred_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clients_phone (phone),
  KEY idx_clients_name (name),
  CONSTRAINT fk_clients_referrer FOREIGN KEY (referred_by) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE articles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  price INT NOT NULL,
  unit VARCHAR(5) NOT NULL DEFAULT 'piece',
  fragile TINYINT(1) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE counters (
  name VARCHAR(20) NOT NULL,
  year SMALLINT NOT NULL,
  value INT NOT NULL DEFAULT 0,
  PRIMARY KEY (name, year)
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  total INT NOT NULL,
  paid INT NOT NULL DEFAULT 0,
  due_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'emise',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_invoices_status (status, due_date),
  CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  tracking_token CHAR(32) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  agency_id INT NOT NULL,
  user_id INT NULL,
  service_level VARCHAR(10) NOT NULL DEFAULT 'standard',
  status VARCHAR(12) NOT NULL DEFAULT 'en_atelier',
  promised_at DATETIME NOT NULL,
  subtotal INT NOT NULL DEFAULT 0,
  surcharge INT NOT NULL DEFAULT 0,
  discount INT NOT NULL DEFAULT 0,
  discount_label VARCHAR(100) NULL,
  delivery_fee INT NOT NULL DEFAULT 0,
  total INT NOT NULL DEFAULT 0,
  paid INT NOT NULL DEFAULT 0,
  on_account TINYINT(1) NOT NULL DEFAULT 0,
  invoice_id INT NULL,
  delivery_address VARCHAR(255) NULL,
  rail VARCHAR(10) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ready_at DATETIME NULL,
  picked_up_at DATETIME NULL,
  picked_up_by INT NULL,
  KEY idx_orders_status (status, promised_at),
  KEY idx_orders_created (created_at),
  KEY idx_orders_client (client_id, created_at),
  KEY idx_orders_account (on_account, invoice_id),
  CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_orders_agency FOREIGN KEY (agency_id) REFERENCES agencies(id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE garments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  seq SMALLINT NOT NULL,
  code VARCHAR(30) NOT NULL UNIQUE,
  article_id INT NULL,
  label VARCHAR(80) NOT NULL,
  qty DECIMAL(6,2) NOT NULL DEFAULT 1,
  price INT NOT NULL DEFAULT 0,
  brand VARCHAR(60) NULL,
  color VARCHAR(40) NULL,
  material VARCHAR(60) NULL,
  damages VARCHAR(255) NULL,
  photo_path VARCHAR(255) NULL,
  step VARCHAR(12) NOT NULL,
  status VARCHAR(12) NOT NULL,
  assigned_to INT NULL,
  rework_count INT NOT NULL DEFAULT 0,
  step_since DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_garments_step (step, status),
  KEY idx_garments_order (order_id),
  CONSTRAINT fk_garments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_garments_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE SET NULL,
  CONSTRAINT fk_garments_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE garment_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  garment_id INT NOT NULL,
  step VARCHAR(12) NOT NULL,
  action VARCHAR(20) NOT NULL,
  note VARCHAR(255) NULL,
  machine VARCHAR(40) NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_events_garment (garment_id, created_at),
  KEY idx_events_action (action, created_at),
  CONSTRAINT fk_events_garment FOREIGN KEY (garment_id) REFERENCES garments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quality_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  garment_id INT NOT NULL,
  user_id INT NULL,
  result VARCHAR(10) NOT NULL,
  criteria JSON NULL,
  reason VARCHAR(60) NULL,
  back_to VARCHAR(12) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qc_created (created_at, result),
  CONSTRAINT fk_qc_garment FOREIGN KEY (garment_id) REFERENCES garments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE complaints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  order_id INT NULL,
  subject VARCHAR(200) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'ouverte',
  assigned_to INT NULL,
  compensation INT NOT NULL DEFAULT 0,
  resolution TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  CONSTRAINT fk_complaints_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_complaints_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agency_id INT NOT NULL,
  user_id INT NOT NULL,
  label VARCHAR(30) NOT NULL,
  opened_at DATETIME NOT NULL,
  opening_float INT NOT NULL DEFAULT 0,
  closed_at DATETIME NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'ouverte',
  justification TEXT NULL,
  KEY idx_cash_user (user_id, status),
  CONSTRAINT fk_cash_agency FOREIGN KEY (agency_id) REFERENCES agencies(id),
  CONSTRAINT fk_cash_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_counts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cash_session_id INT NOT NULL,
  method VARCHAR(12) NOT NULL,
  expected INT NOT NULL,
  counted INT NOT NULL,
  CONSTRAINT fk_counts_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  order_id INT NULL,
  invoice_id INT NULL,
  cash_session_id INT NULL,
  method VARCHAR(12) NOT NULL,
  amount INT NOT NULL,
  reference VARCHAR(60) NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payments_created (created_at),
  KEY idx_payments_session (cash_session_id),
  CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_payments_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agency_id INT NOT NULL,
  cash_session_id INT NULL,
  label VARCHAR(150) NOT NULL,
  amount INT NOT NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  sector VARCHAR(60) NULL,
  tariff_label VARCHAR(60) NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  pickup_schedule VARCHAR(100) NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_contracts_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  invoice_id INT NULL,
  channel VARCHAR(12) NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reminders_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  channel VARCHAR(12) NOT NULL,
  segment VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  scheduled_at DATETIME NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'brouillon',
  sent_count INT NOT NULL DEFAULT 0,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  channel VARCHAR(12) NOT NULL,
  body TEXT NOT NULL,
  campaign_id INT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'en_attente',
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY idx_messages_status (status),
  KEY idx_messages_campaign (campaign_id),
  CONSTRAINT fk_messages_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
  min_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
  daily_usage DECIMAL(10,2) NOT NULL DEFAULT 0,
  supplier VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_item_id INT NOT NULL,
  type VARCHAR(12) NOT NULL,
  qty DECIMAL(10,2) NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_movements_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_item_id INT NOT NULL,
  qty DECIMAL(10,2) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'commandee',
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME NULL,
  CONSTRAINT fk_po_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE objectives (
  id INT AUTO_INCREMENT PRIMARY KEY,
  month CHAR(7) NOT NULL,
  metric VARCHAR(20) NOT NULL,
  target INT NOT NULL,
  UNIQUE KEY uq_objectives (month, metric)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_id INT NULL,
  data JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
