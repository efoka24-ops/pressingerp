# Tâches — ERP Pressing (mise à niveau du code PHP existant)

Format : `- [ ] Txxx [P] Description (chemin)` — `[x]` fait, `[~]` fait en partie (voir la note). `[P]` = parallélisable. `[x]` fait, `[~]` fait en partie (voir la note). Chaque phase se termine par un point de contrôle. Contexte : [plan.md](plan.md), [audit-code-existant.md](audit-code-existant.md).

## Phase 0 — Reprise, sécurité d'exploitation, localisation, premier déploiement
- [x] T001 Trancher les questions ouvertes (spec §7, D1–D9)
- [x] T002 Choisir la stack : conserver PHP 8.1 + MySQL 8 (research.md)
- [x] T003 Importer le code de base dans le dépôt (`app/`, `bin/`, `config/`, `database/`, `public/`)
- [x] T004 Auditer le code contre la spec (audit-code-existant.md)
- [x] T005 CI : `php -l` sur tous les fichiers (.github/workflows/ci.yml)
- [x] T006 Application vérifiée sur l'instance en ligne (pas de MySQL local sur le poste) : login, cockpit, migrations
- [x] T007 `DROP TABLE` supprimé (`schema.sql` remplacé par `database/migrations/`) ; `bin/migrate.php` et `public/migrate.php` (jeton) via `Migrator`
- [x] T008 Protéger `bin/install.php` : refuser si la base contient des données, `--demo` interdit si `APP_ENV=production`
- [x] T009 Charger `config/config.local.php` (non versionné) dans `config/config.php` pour les secrets de l'hébergeur
- [x] T010 Localisation Cameroun : fuseau `Africa/Douala`, préfixe +237, retirer Wave/Moov, données de démo (Douala, Yaoundé, quartiers)
- [x] T011 Pas de comptes de démo en production ; premier administrateur créé par `bin/create-admin.php`
- [x] T012 Hôte Camoo vérifié : PHP 8.1.34, extensions OK, réécriture OK, mysqldump présent, sortie vers Sungku OK ; limites : 30 s, 128 Mo, upload 2 Mo ; cron à créer dans le panneau (ops/exploitation.md)
- [x] T013 `ops/deploy.sh` : envoi FTP incrémental (code, config, migrate, check)
- [x] T014 Premier déploiement sur `pressing-erp.trugroup.cm` (HTTP ; HTTPS en attente du certificat, `APP_DEBUG=0`)
- [ ] T015 Changer les mots de passe FTP et base exposés lors du cadrage, puis `ops/deploy.sh config` (à faire par vous)
- [ ] T016 Avis conformité (docs/conformite.md) ; copier les sources dans `docs/sources/` (à faire par vous : les documents ne sont que dans la conversation)

**Contrôle** : site en ligne, connexion administrateur, aucune donnée de démo, déploiement reproductible.

## Phase 1 — Socle : audit, RBAC, paramètres, sauvegarde
- [x] T020 Migration audit v2 (0003) : `old_value`, `new_value`, `reason`, `agency_id`, `prev_hash`, `hash` — appliquée en ligne
- [x] T021 Triggers refusés par Camoo (erreur 1419, SUPER requis) : remplacés par (1) aucun UPDATE/DELETE dans le code, vérifié par un test de balayage, (2) chaîne de hachage signée par clé secrète hors base, (3) ancrage de la dernière ligne par chaque sauvegarde (`audit.anchor`) : supprimer des lignes déjà ancrées est détecté. Triggers conservés dans `database/optional/`, tentés à chaque migration. Reste ouvert : les lignes écrites depuis la dernière sauvegarde peuvent encore être retirées sans trace
- [x] T022 `Audit::log()` v2 (ancienne/nouvelle valeur, motif) appelé pour annulation, fiche client, plafond, clôture de caisse, paramètres, utilisateurs, agences. Prix et remises : phases 2 et 5
- [x] T023 Accès refusés (`access.denied`) et connexions échouées (`auth.failed`) journalisés
- [x] T024 RBAC fin : lecture/création/modification/validation/suppression par module (Role.php, Auth.php, Router) ; validations explicites : annulation, contrôle qualité, résiliation, envoi de campagne
- [x] T025 Rôles ajoutés : administrateur, superviseur, livreur, marketing ; « Limité » = sous-ensemble de droits défini dans `Role::rights()`
- [x] T026 Choix d'agence réservé à Admin/Direction ; périmètre appliqué (comptoir et responsable) aux commandes, annulation, retrait, encaissement, Mobile Money, traçabilité, recherche, caisse et cockpit. Les clients restent communs au groupe (un client fréquente toutes les agences) ; le responsable d'agence n'a plus BI, commercial ni marketing, non filtrables par agence. Testé
- [x] T027 Paramètres versionnés en ajout seul (`settings`) + écran admin avec motif obligatoire et historique
- [x] T028 Écrans admin : utilisateurs (création, rôle, agence, activation, réinitialisation), agences
- [~] T029 `bin/backup.php` testé en ligne (27 tables, archive vérifiée, ancrage de l'audit). Reste, à fournir par vous : le cron dans le panneau Camoo et une destination externe (`backup.ftp` dans config.local.php) — sans elle les archives restent sur le même hébergement
- [x] T030 Test de restauration réussi en ligne (`ops/deploy.sh run restore-test`) : archive rejouée dans des tables témoins `rt_*` (hébergement sans seconde base), 27 tables, effectifs identiques, tables témoins supprimées. Procédure : ops/restauration.md
- [x] T031 Recette `tests/` (audit, rbac, agence, Mobile Money) exécutée en ligne via `ops/deploy.sh run selftest` : 24 réussis, 0 échec, 4 ignorés (triggers indisponibles)

**Contrôle** : fait, sauf le cron et la copie externe des sauvegardes (T029) qui dépendent de l'hébergeur et de vous.

## Phase 2 — Réception conforme (US1)
- [x] T040 Pas de client anonyme (décision D10) : nom et prénom + numéro valide (9 chiffres commençant par 6 ou 2, ou numéro étranger avec indicatif) exigés à la création de la fiche ET refusés à la commande si la fiche est incomplète ; un numéro = un client (clé unique). Testé
- [x] T041 Photo obligatoire : article fragile, pièce endommagée ou de valeur (seuil `photo.value_threshold`, 50 000 FCFA par défaut, paramétrable) ; contrôlée côté serveur et côté écran d'après le devis réel ; photos réduites avant envoi (limite 2 Mo de l'hébergeur). Testé
- [x] T042 QR servi localement (`public/assets/vendor/qrcode.min.js`), plus aucun CDN dans les étiquettes ni le ticket. Testé
- [x] T043 Étiquettes : journal `label_prints`, première impression libre, réimpression avec motif obligatoire et audit, mode « étiquetage manuel » (liste des codes à écrire) si l'imprimante est en panne. Les codes `TMP-` ne sont pas nécessaires : les codes sont générés par le serveur avant l'impression. Testé
- [x] T044 Grilles `price_lists`/`price_items` versionnées, priorité D4 (contrat > agence > VIP > promotion > standard), prix retirable, promotions à période, majorations Express/VIP devenues des paramètres. Repli sur l'ancien prix du catalogue tant qu'un article n'a pas de prix standard. Testé
- [x] T045 Écran `/tarifs` (module Tarifs : Admin et Direction modifient, responsable d'agence et comptoir lisent) : prix par grille, motif obligatoire, historique, création/désactivation de grilles, audit ancienne/nouvelle valeur
- [x] T046 Ticket de dépôt 80 mm (`/commandes/{id}/ticket`) : prix TTC, TVA incluse au taux paramétré (19,25 %), NIU, reste à payer, QR de suivi. À faire par vous : renseigner le NIU dans Administration › Paramètres (`company.niu`, vide pour l'instant)
- [x] T047 Article sans tarif : commande refusée avec message clair et trace d'audit `pricing.missing` (le centre d'alertes qui prévient le responsable arrive en phase 6). Testé
- [~] T048 Brouillon de commande conservé sur l'appareil (client, lignes, options) et restaurable après coupure ou erreur. PAS fait : réception réellement hors-ligne (service worker, numéros réservés par poste) — décision à prendre, car les codes pièces sont aujourd'hui attribués par le serveur
- [x] T049 Tests phase 2 (identification, photo, priorité tarifaire, tarif manquant, versions de prix, TVA, étiquettes, numéros) : 37 réussis au total en ligne, 0 échec. La numérotation est testée en séquence, pas en accès simultané réel

## Phase 3 — Workflow et traçabilité (US2)
- [x] T050 Parcours par traitement : tables `treatments` (5 parcours livrés) et `garments.treatment_id`, choix du traitement par pièce à la réception, étape suivante = première étape du parcours après l'étape courante (gère les reprises hors parcours), écran `/admin/parcours` avec motif et audit. Testé
- [x] T051 RG5 : pas de prise en charge avant la fin de l'étape précédente (état par pièce) ; terminer sans prendre en charge déclenche et trace la prise en charge ; une pièce prise en charge ne se termine que par son opérateur ou un superviseur ; contrôle qualité et étapes finales fermés à l'atelier. Testé
- [x] T052 Scan : si le QR est illisible (SE5), recherche par numéro de commande ou fragment de code avec liste de pièces à choisir (jokers neutralisés). Testé
- [x] T053 Incidents typés (les 9 du cahier des charges) dans `incidents`, sévérité critique pour « endommagé » et « erreur d'identification » avec audit prioritaire, résolution motivée à la levée. L'alerte au manager arrive avec le centre d'alertes (phase 6)
- [x] T054 Tableau atelier : par poste, nombre de pièces disponibles / en cours / bloquées
- [x] T055 Sinistres (D8) : déclaration depuis la fiche pièce (bloque la pièce), page `/qualite/sinistres`, indemnité plafonnée à `compensation.max_factor` × prix de la pièce, décision motivée et auditée. Limite : la valeur déclarée à la réception n'est pas encore saisissable
- [x] T056 Tests workflow (parcours, RG5, incidents, recherche manuelle, sinistres) : 49 réussis au total en ligne ; tâche `ops/deploy.sh run smoke` qui parcourt 26 pages en administrateur et a révélé deux erreurs 500 préexistantes (/production et /bi, `max(...)` avec clés texte sous PHP 8.1), corrigées

## Phase 4 — Qualité (US3)
- [ ] T060 Verrou serveur : étape « Prêt » refusée sans contrôle conforme (service + trigger)
- [ ] T061 Dérogation par un responsable : table `quality_overrides` append-only + audit
- [ ] T062 Désignation obligatoire du service fautif à la reprise (spec A4)
- [ ] T063 Tests : contournement par POST direct, dérogation sans droit

## Phase 5 — Caisse (US4) — jalon pilote
- [ ] T070 Tolérance d'écart paramétrable (D3) et alerte manager
- [ ] T071 Paiement mixte (plusieurs lignes par encaissement)
- [ ] T072 Remises et annulations avec autorisation et audit (RG14)
- [ ] T073 Moyens de paiement Cameroun : Orange Money, MTN MoMo, carte, virement, crédit
- [ ] T074 Adaptateur passerelle `apisungku` + webhook signé + idempotence
- [ ] T075 Reçu et état de caisse imprimables
- [ ] T076 Tests : écart, remise après validation, double webhook

**Contrôle** : quickstart complet sur le site déployé ; pilote d'une agence.

## Phase 6 — Alertes et retards (US5)
- [ ] T080 Tables `alert_rules`, `alerts` ; `AlertService`
- [ ] T081 `bin/alerts.php` (cron 1 min) : non-prise en charge, blocage, retard (vert/orange/rouge)
- [ ] T082 Alerte de transfert avec nombre de pièces ; escalade superviseur puis manager
- [ ] T083 Centre d'alertes dans l'interface (interrogation 30 s)
- [ ] T084 Admin des règles et délais
- [ ] T085 Tests avec horloge simulée

## Phase 7 — Notifications (US6)
- [ ] T090 Adaptateurs SMS, WhatsApp, e-mail derrière l'interface de passerelle (bin/send-messages.php)
- [ ] T091 Consentements par canal et finalité ; opérationnel toujours envoyé (RG11)
- [ ] T092 Repli de canal et nouvelle tentative (SE10, SE11)
- [ ] T093 Modèles de messages administrables
- [ ] T094 Tests : consentement retiré, client anonyme

## Phase 8 — Livraison (US7)
- [ ] T100 Migration `deliveries`, `delivery_proofs` (append-only), rôle livreur
- [ ] T101 Statuts À collecter → Livré + « Non livré », affectation, tournée
- [ ] T102 Preuve obligatoire (RG20) et solde à percevoir (RG21)
- [ ] T103 Écran livreur mobile
- [ ] T104 Tests : « Livré » sans preuve refusé

## Phase 9 — Non retirés (US9)
- [ ] T110 `bin/reminders.php` : J+2, J+7, J+15 paramétrables, annulation au retrait
- [ ] T111 Alerte manager au seuil ; injoignable (SE12)
- [ ] T112 Tests de calendrier

## Phase 10 — Recouvrement et facturation (US10)
- [ ] T120 TVA et NIU sur factures, numérotation continue (D5), après avis du comptable
- [ ] T121 Blocage des commandes en compte au-delà du plafond, dérogation tracée (RG16)
- [ ] T122 Avoirs, devis
- [ ] T123 Rapprochement des paiements partiels (SE17)
- [ ] T124 Tests des bornes de balance âgée (30/31, 60/61, 90/91)

## Phase 11 — Marketing (US11)
- [ ] T130 Seuils de segments en paramètres (spec A8) ; statut « à vérifier »
- [ ] T131 Scénarios réactivation, fidélité, VIP automatique ; `bin/segments.php`
- [ ] T132 Respect du consentement (RG18)

## Phase 12 — Stocks et BI (US8, US12)
- [ ] T140 Alerte stock critique dans le cockpit
- [ ] T141 Fraîcheur des données affichée (SE22) ; périmètre agence refusé et journalisé (SE23)
- [ ] T142 Alertes managériales : CA −25 %, commandes à risque, réclamations, productivité
- [ ] T143 Tests de réconciliation KPI vs SQL brut (SC-003)

## Phase 13 — Documents et recherche
- [ ] T150 Documents manquants : devis, bon de commande, bon de livraison, relevé client, état de créances, rapports journalier et mensuel
- [ ] T151 Recherche : ajouter la facture ; vérifier téléphone, nom, commande, QR, vêtement

## Phase 14 — Recette et mise en production
- [ ] T160 Recette par module (spec §32, 10 tests critiques) sur le site déployé
- [ ] T161 Formation par rôle, guide d'exploitation
- [ ] T162 Bascule, hypercare, bilan pilote

## Dépendances
0 → 1 → 2 → 3 → 4 → 5. Phase 6 après 3 ; 7 après 1 ; 8 après 4–5 ; 9 après 5 et 7 ; 10 après 5 ; 11 après 7 ; 12 après 5–6 ; 13 après 10 ; 14 en dernier. La sauvegarde (T029) démarre dès la phase 1.
