-- Numéro de facture : FA-<code agence jusqu'à 10 caractères>-AAAA-NNNNN
ALTER TABLE invoices MODIFY number VARCHAR(30) NOT NULL;
