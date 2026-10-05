-- Phase 9 : relances des commandes prêtes non retirées (J+2 / J+7 / J+15 paramétrables)

-- Une relance par commande et par palier, jamais deux ; message_id vide = palier sauté (rattrapage) ou modèle désactivé
CREATE TABLE order_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  level TINYINT NOT NULL,
  message_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_reminders (order_id, level),
  CONSTRAINT fk_order_reminders_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO message_templates (event, label, body) VALUES
  ('reminder1', 'Relance non retrait : 1er rappel', 'Pressing : votre commande {numero} est prête depuis {jours} jours. {solde}Passez la retirer quand vous voulez. Suivi : {lien}'),
  ('reminder2', 'Relance non retrait : 2e rappel', 'Pressing : rappel, votre commande {numero} vous attend depuis {jours} jours. {solde}Merci de passer la retirer. Suivi : {lien}'),
  ('reminder3', 'Relance non retrait : dernier rappel', 'Pressing : dernier rappel, votre commande {numero} est prête depuis {jours} jours. {solde}Merci de la retirer rapidement ou de nous contacter : nous ne pouvons pas la conserver indéfiniment. Suivi : {lien}');

INSERT INTO alert_rules (event, label, priority, target_role, delay_minutes, escalate_role, escalate_after_minutes) VALUES
  ('uncollected', 'Commande non retirée au seuil : contacter le client', 'high', 'manager', 0, 'direction', 1440);
