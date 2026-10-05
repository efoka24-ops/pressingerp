-- Réception hors-ligne : postes de travail autorisés, plages de numéros réservées, journal de synchronisation

CREATE TABLE workstations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agency_id INT NOT NULL,
  label VARCHAR(80) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  registered_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  CONSTRAINT fk_workstations_agency FOREIGN KEY (agency_id) REFERENCES agencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plage de numéros de commande confiée à un poste : les numéros non utilisés restent tracés (jamais réattribués)
CREATE TABLE number_blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workstation_id INT NOT NULL,
  year SMALLINT NOT NULL,
  from_seq INT NOT NULL,
  to_seq INT NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  UNIQUE KEY uq_blocks_range (year, from_seq),
  KEY idx_blocks_station (workstation_id, closed_at),
  CONSTRAINT fk_blocks_station FOREIGN KEY (workstation_id) REFERENCES workstations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des synchronisations : un numéro ne peut être synchronisé qu'une fois (rejeu = même réponse)
CREATE TABLE offline_syncs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workstation_id INT NOT NULL,
  number VARCHAR(20) NOT NULL UNIQUE,
  status VARCHAR(10) NOT NULL,
  order_id INT NULL,
  message VARCHAR(255) NULL,
  received_by INT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_syncs_status (status),
  CONSTRAINT fk_syncs_station FOREIGN KEY (workstation_id) REFERENCES workstations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
  ADD COLUMN workstation_id INT NULL,
  ADD COLUMN offline_created_at DATETIME NULL,
  ADD COLUMN synced_at DATETIME NULL;
