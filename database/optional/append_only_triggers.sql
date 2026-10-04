-- @delimiter ;;
-- Journaux en ajout seul : toute modification ou suppression est refusée par la base.
-- Une correction est une nouvelle ligne qui référence l'ancienne (principe II de la constitution).

DROP TRIGGER IF EXISTS audit_log_no_update;;
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log est en ajout seul';;
DROP TRIGGER IF EXISTS audit_log_no_delete;;
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log est en ajout seul';;

DROP TRIGGER IF EXISTS garment_events_no_update;;
CREATE TRIGGER garment_events_no_update BEFORE UPDATE ON garment_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'garment_events est en ajout seul';;
DROP TRIGGER IF EXISTS garment_events_no_delete;;
CREATE TRIGGER garment_events_no_delete BEFORE DELETE ON garment_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'garment_events est en ajout seul';;

DROP TRIGGER IF EXISTS stock_movements_no_update;;
CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements est en ajout seul';;
DROP TRIGGER IF EXISTS stock_movements_no_delete;;
CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements est en ajout seul';;

DROP TRIGGER IF EXISTS settings_no_update;;
CREATE TRIGGER settings_no_update BEFORE UPDATE ON settings FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'settings est versionné en ajout seul';;
DROP TRIGGER IF EXISTS settings_no_delete;;
CREATE TRIGGER settings_no_delete BEFORE DELETE ON settings FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'settings est versionné en ajout seul';;

-- Paiements : jamais supprimés ; seul le rattachement à une session de caisse peut être posé une fois
DROP TRIGGER IF EXISTS payments_no_delete;;
CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments: suppression interdite (passer une écriture inverse)';;
DROP TRIGGER IF EXISTS payments_guard_update;;
CREATE TRIGGER payments_guard_update BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
  IF NOT (NEW.amount <=> OLD.amount AND NEW.method <=> OLD.method AND NEW.order_id <=> OLD.order_id
          AND NEW.invoice_id <=> OLD.invoice_id AND NEW.client_id <=> OLD.client_id
          AND NEW.reference <=> OLD.reference AND NEW.created_at <=> OLD.created_at
          AND (OLD.cash_session_id IS NULL OR NEW.cash_session_id <=> OLD.cash_session_id)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments: modification interdite (passer une écriture inverse)';
  END IF;
END;;
