-- Phase 7 : messagerie client (modèles, consentements, tentatives, repli de canal)
ALTER TABLE messages
  ADD COLUMN purpose VARCHAR(12) NOT NULL DEFAULT 'operational',
  ADD COLUMN event VARCHAR(30) NULL,
  ADD COLUMN order_id INT NULL,
  ADD COLUMN subject VARCHAR(150) NULL,
  ADD COLUMN chain VARCHAR(60) NULL,
  ADD COLUMN next_try_at DATETIME NULL,
  ADD COLUMN provider_ref VARCHAR(80) NULL,
  ADD KEY idx_messages_order (order_id),
  ADD KEY idx_messages_due (status, next_try_at);

-- Chaque tentative d'envoi est conservée (canal, résultat, cause)
CREATE TABLE message_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  channel VARCHAR(12) NOT NULL,
  status VARCHAR(14) NOT NULL,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attempts_message (message_id, channel),
  CONSTRAINT fk_attempts_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consentements marketing par canal : historique en ajout seul, la ligne la plus récente fait foi (aucune offre sans consentement)
CREATE TABLE client_consents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  channel VARCHAR(12) NOT NULL,
  purpose VARCHAR(12) NOT NULL DEFAULT 'marketing',
  granted TINYINT(1) NOT NULL,
  source VARCHAR(40) NOT NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_consents_current (client_id, channel, purpose, id),
  CONSTRAINT fk_consents_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modèles de messages opérationnels, modifiables sans développeur. Variables : {numero} {pieces} {date_promise} {lien} …
CREATE TABLE message_templates (
  event VARCHAR(30) NOT NULL PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO message_templates (event, label, body) VALUES
  ('deposit', 'Dépôt : confirmation de réception', 'Pressing : commande {numero} reçue ({pieces}). Prête le {date_promise}. Suivi : {lien}'),
  ('ready', 'Commande prête', 'Pressing : votre commande {numero} est prête. {retrait}{solde} Suivi : {lien}'),
  ('late', 'Retard : nouveau délai', 'Pressing : votre commande {numero} aura du retard, nous nous en excusons. Nouveau délai estimé : {date_promise}. Suivi : {lien}'),
  ('delivery_started', 'Départ en livraison', 'Pressing : votre commande {numero} part en livraison ({creneau}). Code à donner au livreur : {code}. Livreur : {livreur}.'),
  ('delivery_failed', 'Livraison non aboutie', 'Pressing : nous n''avons pas pu vous livrer la commande {numero} ({motif}). Nouvelle tentative : {creneau}.'),
  ('closed', 'Clôture : remise et fidélisation', 'Pressing : commande {numero} remise. Merci de votre confiance ! {fidelite}');

INSERT INTO alert_rules (event, label, priority, target_role, delay_minutes, escalate_role, escalate_after_minutes) VALUES
  ('message_failed', 'Client injoignable : message non remis', 'high', 'comptoir', 0, 'manager', 120),
  ('messages_stuck', 'Messages en attente : aucun canal d''envoi configuré', 'normal', 'admin', 0, NULL, NULL);
