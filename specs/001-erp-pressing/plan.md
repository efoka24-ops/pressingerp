# Plan d'implémentation — ERP Pressing

**Spec** : [spec.md](spec.md) | **Mis à jour** : 2026-10-03 | **Constitution** : v1.0.0
**Changement majeur** : un code de base existe (PHP 8.1 natif, MySQL 8, ~5 500 lignes, 10 modules). Le plan NestJS/PostgreSQL initial est abandonné. Le projet devient une **mise à niveau du code existant** vers la spec. Écarts détaillés : [audit-code-existant.md](audit-code-existant.md).

## Contexte technique (imposé par l'existant et l'hébergement)

| Élément | Choix |
|---|---|
| Langage | PHP 8.1 natif, MVC léger (`app/Core`, `Domain`, `Services`, `Controllers`, `Views`), sans Composer |
| Base | MySQL 8.0 (`trugro9159_pressingerp`), InnoDB, utf8mb4 |
| Hébergement | Camoo (mutualisé) — `pressing-erp.trugroup.cm`, FTP, phpMyAdmin ; pas de Docker, pas de Redis, pas de process longs |
| Tâches planifiées | **cron** (`bin/send-messages.php` existe ; à ajouter : alertes, relances, segments, sauvegarde) |
| Front | Pages PHP rendues serveur + `public/assets/app.js`, responsive ; PWA légère pour les postes |
| QR | à embarquer en local (aujourd'hui chargé depuis un CDN) |
| Tests | scripts PHP CLI (`tests/`) sur base MySQL de test — pas de PHPUnit (pas de Composer) |
| Déploiement | FTP (scripté), migrations SQL numérotées jouées par `bin/migrate.php` |

Contraintes de l'hébergement mutualisé : pas de workers permanents, donc alertes et escalades = cron chaque minute ; pas de WebSocket/SSE fiable, donc rafraîchissement par interrogation (30 s) ; limites d'exécution PHP à vérifier sur l'hôte.

## Vérification constitution

| Principe | État du code | Action |
|---|---|---|
| I Vêtement central | OK (`garments`, `garment_events`, codes `PR-AAAA-NNNNNN-NN`) | Vérifier unicité en concurrence |
| II Append-only / audit | **Non conforme** : `audit_log` sans ancienne/nouvelle valeur, ni motif, modifiable | Audit v2 + triggers MySQL |
| III Garde-fous serveur | Partiel : annulation, justification d'écart OK ; verrou qualité et dérogation, preuve de livraison absents | Phases 4, 8 |
| IV Dérivé | Plutôt OK (cockpit calculé) | Tests de réconciliation |
| V RBAC + agence | 6 rôles, droits par module seulement ; `agency_id` présent | CRUD fin, rôles manquants, tests de cloisonnement |
| VI Terrain / hors-ligne | Non : QR par CDN, pas de file hors-ligne | Phase 2 |
| VII Paramétrable | Partiel : `config.php` (fichier) | Table `settings` + écran admin |

## Arborescence cible (ajouts)

```text
pressing-erp/
├── app/ …                    # existant
│   └── Services/AlertService.php, DeliveryService.php, SettingsService.php (à créer)
├── bin/
│   ├── migrate.php           # migrations non destructives
│   ├── alerts.php            # cron 1 min : retards, escalades
│   ├── reminders.php         # cron quotidien : non retirés, recouvrement
│   ├── segments.php          # cron quotidien : segmentation, scénarios
│   └── backup.php            # mysqldump + rétention
├── database/
│   ├── schema.sql            # état de base (DROP retirés)
│   └── migrations/NNNN_*.sql
├── tests/                    # scripts de recette automatisés
└── ops/deploy.ps1            # déploiement FTP
```

## Phases (détail dans [tasks.md](tasks.md))

| Phase | Contenu | Livrable |
|---|---|---|
| 0 | Reprise du code, sécurité d'exploitation, localisation Cameroun, premier déploiement | Site en ligne sur `pressing-erp.trugroup.cm` (démo) |
| 1 | Socle : audit v2, RBAC fin, paramètres, sauvegarde | Audit inaltérable, droits testés |
| 2 | Réception conforme (US1) | Règles photo/anonyme/TMP, QR local, TVA |
| 3 | Workflow et traçabilité (US2) | Parcours par traitement, RG5 testée |
| 4 | Qualité (US3) | Verrou « Prêt » + dérogation |
| 5 | Caisse (US4) | Tolérance, autorisations, Mobile Money Cameroun |
| 6 | Alertes et retards (US5) | Moteur cron + escalade |
| 7 | Notifications (US6) | Fournisseur réel, repli, consentements |
| 8 | Livraison (US7) | Rôle livreur, tournées, preuve |
| 9 | Non retirés (US9) | Relances J+2/7/15 |
| 10 | Recouvrement et facturation (US10) | TVA/NIU, blocage crédit |
| 11 | Marketing (US11) | Seuils, consentement |
| 12 | Stocks et BI (US8, US12) | KPI réconciliés |
| 13 | Documents et recherche | 11 documents |
| 14 | Recette et mise en production | Recette signée |

**Jalon pilote** : fin phase 5. Comme le code existe déjà, la durée de D2 (14 semaines) est probablement surestimée ; à ré-estimer après la phase 0.
