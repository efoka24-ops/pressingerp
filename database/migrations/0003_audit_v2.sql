-- Journal d'audit v2 : ancienne/nouvelle valeur, motif, agence, chaîne de hachage
ALTER TABLE audit_log
  ADD COLUMN agency_id INT NULL AFTER user_id,
  ADD COLUMN old_value TEXT NULL AFTER data,
  ADD COLUMN new_value TEXT NULL AFTER old_value,
  ADD COLUMN reason VARCHAR(255) NULL AFTER new_value,
  ADD COLUMN prev_hash CHAR(64) NULL AFTER ip,
  ADD COLUMN hash CHAR(64) NULL AFTER prev_hash,
  ADD KEY idx_audit_created (created_at),
  ADD KEY idx_audit_action (action)
