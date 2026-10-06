-- Phase 12 : alertes managériales sur les indicateurs (CA, retards, réclamations, productivité) pour la direction
INSERT INTO alert_rules (event, label, priority, target_role, delay_minutes, escalate_role, escalate_after_minutes) VALUES
  ('kpi_alert', 'Indicateur de pilotage en alerte (CA, retards, réclamations, productivité)', 'normal', 'direction', 0, NULL, NULL);
