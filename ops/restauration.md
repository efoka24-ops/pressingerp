# Sauvegarde et restauration

## Sauvegarde
- `bin/backup.php` : `mysqldump` (transaction unique, routines et triggers inclus) compressé en `pressing/storage/backups/AAAAMMJJ-HHMMSS.sql.gz`.
- Chaque archive est vérifiée : fichier terminé normalement (`-- Dump completed`) et autant de `CREATE TABLE` que de tables en base.
- Rétention : 14 archives par défaut (`backup.keep` dans `config.local.php`).
- Copie externalisée : renseigner `backup.ftp` (`host`, `user`, `pass`, `dir`) dans `config/config.local.php`. Sans cela, les sauvegardes restent sur le même hébergement : **ce n'est pas une sauvegarde externalisée** (T029 reste incomplet tant qu'une destination n'est pas fournie).
- Planification : cron Camoo `30 2 * * * php /home/trugro9159/pressing-erp/pressing/bin/backup.php`.
- Le dossier `storage/` est interdit au web (`.htaccess`) ; récupération par FTP.

## Restauration (procédure)
1. Récupérer l'archive voulue par FTP (`pressing/storage/backups/`).
2. Mettre le site en maintenance (renommer `.htaccess` racine ou couper l'accès).
3. Faire une sauvegarde de l'état actuel avant d'écraser quoi que ce soit.
4. Via phpMyAdmin (`https://pma-12.camoo.net`) : onglet *Importer*, choisir le `.sql.gz` (phpMyAdmin décompresse). L'archive contient les `DROP TABLE IF EXISTS` / `CREATE TABLE` / `INSERT` de toutes les tables, triggers compris.
5. Vérifier : `/login`, `/admin/audit?verifier=1` (la chaîne d'audit doit être intègre), quelques commandes récentes.
6. Rouvrir le site.

## Test de restauration
Un test complet exige une seconde base. Si l'hébergeur autorise `CREATE DATABASE`, restaurer l'archive dans `…_restore`, comparer le nombre de lignes par table avec la production, puis supprimer cette base. Résultat du dernier test : voir `specs/001-erp-pressing/tasks.md` (T030).
