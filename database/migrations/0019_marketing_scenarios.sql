-- Phase 11 : scénarios marketing automatiques (réactivation, fidélité, VIP), toujours soumis au consentement (RG18)

CREATE TABLE marketing_scenarios (
  code VARCHAR(20) NOT NULL PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  segment VARCHAR(20) NOT NULL,
  body TEXT NOT NULL,
  cooldown_days INT NOT NULL DEFAULT 90,
  active TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Désactivés à l'installation : l'équipe marketing les active après relecture des messages et des seuils
INSERT INTO marketing_scenarios (code, label, segment, body, cooldown_days, active) VALUES
  ('reactivation', 'Réactivation : client à risque', 'a_risque', 'Bonjour {prenom}, cela fait un moment ! Vos vêtements méritent notre soin : passez nous voir cette semaine.', 90, 0),
  ('fidelite', 'Fidélité : points à utiliser', 'points', 'Bonjour {prenom}, vous avez {points} points fidélité à utiliser sur votre prochaine commande. Merci de votre confiance !', 180, 0),
  ('vip', 'Club VIP : bienvenue et avantages', 'vip', 'Bonjour {prenom}, vous faites partie de nos clients VIP : traitement prioritaire et avantages réservés. Merci de votre fidélité !', 365, 0);

CREATE TABLE scenario_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scenario VARCHAR(20) NOT NULL,
  client_id INT NOT NULL,
  message_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_scenario_runs (scenario, client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
