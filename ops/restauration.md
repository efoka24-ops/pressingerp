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
`ops/deploy.sh run restore-test` : sauvegarde fraîche, puis rejeu dans des tables témoins `rt_*` (l'hébergeur n'offre pas de seconde base), comparaison du nombre de lignes de chacune des tables, suppression des tables témoins. Le script refuse de s'exécuter si une instruction vise une table réelle. Dernier résultat (2026-10-05) : 27 tables, 0 écart, restauration OK.

## Ancrage de l'audit
Chaque sauvegarde note la dernière ligne du journal d'audit dans `storage/audit.anchor`. `/admin/audit?verifier=1` contrôle la chaîne et cette ancre. La copie externe (`backup.ftp`) envoie l'ancre à côté de l'archive (`AAAAMMJJ-HHMMSS.audit.anchor`) : conservez-la, elle prouve l'état du journal à la date de la sauvegarde.
