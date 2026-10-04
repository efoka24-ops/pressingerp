# Tâches — ERP Pressing (mise à niveau du code PHP existant)

Format : `- [ ] Txxx [P] Description (chemin)`. `[P]` = parallélisable. Chaque phase se termine par un point de contrôle. Contexte : [plan.md](plan.md), [audit-code-existant.md](audit-code-existant.md).

## Phase 0 — Reprise, sécurité d'exploitation, localisation, premier déploiement
- [x] T001 Trancher les questions ouvertes (spec §7, D1–D9)
- [x] T002 Choisir la stack : conserver PHP 8.1 + MySQL 8 (research.md)
- [x] T003 Importer le code de base dans le dépôt (`app/`, `bin/`, `config/`, `database/`, `public/`)
- [x] T004 Auditer le code contre la spec (audit-code-existant.md)
- [x] T005 CI : `php -l` sur tous les fichiers (.github/workflows/ci.yml)
- [x] T006 Application vérifiée sur l'instance en ligne (pas de MySQL local sur le poste) : login, cockpit, migrations
- [x] T007 Retirer `DROP TABLE` de `database/migrations/` ; créer `bin/migrate.php`, `database/migrations/` et la table `schema_migrations`
- [x] T008 Protéger `bin/install.php` : refuser si la base contient des données, `--demo` interdit si `APP_ENV=production`
- [x] T009 Charger `config/config.local.php` (non versionné) dans `config/config.php` pour les secrets de l'hébergeur
- [x] T010 Localisation Cameroun : fuseau `Africa/Douala`, préfixe +237, retirer Wave/Moov, données de démo (Douala, Yaoundé, quartiers)
- [x] T011 Pas de comptes de démo en production ; premier administrateur créé par `bin/create-admin.php`
- [x] T012 Hôte Camoo vérifié : PHP 8.1.34, extensions OK, réécriture OK, mysqldump présent, sortie vers Sungku OK ; limites : 30 s, 128 Mo, upload 2 Mo ; cron à créer dans le panneau (ops/exploitation.md)
- [x] T013 `ops/deploy.ps1` : envoi FTP incrémental (hors `uploads/` et `config.local.php`)
- [x] T014 Premier déploiement sur `pressing-erp.trugroup.cm` + migration + HTTPS + `APP_DEBUG=0`
- [ ] T015 Changer les mots de passe FTP et base exposés lors du cadrage, puis `ops/deploy.sh config` (à faire par vous)
- [ ] T016 Avis conformité (docs/conformite.md) ; copier les sources dans `docs/sources/` (à faire par vous : les documents ne sont que dans la conversation)

**Contrôle** : site en ligne, connexion administrateur, aucune donnée de démo, déploiement reproductible.

## Phase 1 — Socle : audit, RBAC, paramètres, sauvegarde
- [ ] T020 Migration audit v2 : `old_value`, `new_value`, `reason`, `agency_id`, `prev_hash`, `hash`
- [ ] T021 Triggers MySQL interdisant UPDATE/DELETE sur `audit_log`, `garment_events`, `payments`, `stock_movements`
- [ ] T022 `Audit::log()` v2 avec ancienne/nouvelle valeur et motif ; l'appeler pour remises, annulations, prix, factures (app/Services/Audit.php)
- [ ] T023 Journaliser les accès refusés (403) et les connexions échouées
- [ ] T024 [P] RBAC fin : lecture/création/modification/validation/suppression par module (app/Domain/Role.php, app/Core/Auth.php)
- [ ] T025 [P] Rôles manquants : administrateur, superviseur, livreur, marketing ; définir « Limité » (spec A6)
- [ ] T026 Cloisonnement par agence : helper central appliquant `agency_id` à toutes les requêtes, avec tests
- [ ] T027 Table `settings`, `SettingsService` et écran admin (seuils D3, relances, VIP, plafonds), versionné
- [ ] T028 Écran admin utilisateurs / agences
- [ ] T029 `bin/backup.php` : mysqldump compressé, rétention, copie externalisée ; cron quotidien
- [ ] T030 Procédure de restauration documentée et testée (ops/restauration.md)
- [ ] T031 Tests CLI : `tests/audit.php`, `tests/rbac.php`, `tests/agency.php`

**Contrôle** : une modification de prix apparaît avec ancienne/nouvelle valeur ; UPDATE sur l'audit refusé ; restauration réussie.

## Phase 2 — Réception conforme (US1)
- [ ] T040 Client anonyme : autorisé au comptoir, interdit pour crédit, livraison, fidélité, relances (spec A10)
- [ ] T041 Règle photo obligatoire paramétrable : fragile, endommagé, valeur > seuil
- [ ] T042 QR en local : embarquer la bibliothèque dans `public/assets/vendor/`, supprimer le CDN (app/Views/orders/labels.php)
- [ ] T043 ID provisoire `TMP-` si impression impossible (SE3) + écran de régularisation
- [ ] T044 Grilles tarifaires en base, versionnées, priorité D4 ; remplacer les majorations codées (app/Services/PricingService.php)
- [ ] T045 Écran admin des tarifs + audit des changements
- [ ] T046 Prix TTC, TVA 19,25 % paramétrable, NIU sur le ticket (D5)
- [ ] T047 Tarif absent : message et escalade au responsable (SE4)
- [ ] T048 Service worker + file locale pour la réception hors-ligne ; plages de numéros réservées par poste
- [ ] T049 Tests : unicité des codes en concurrence, SE1–SE4, RG1–RG4

## Phase 3 — Workflow et traçabilité (US2)
- [ ] T050 Table `treatment_routes` : parcours par traitement (remplace « étape non nécessaire » manuelle)
- [ ] T051 Vérifier et tester RG5 (pas de prise en charge avant « Terminé ») côté serveur
- [ ] T052 Scan : recherche manuelle si QR illisible (SE5), réponse < 2 s
- [ ] T053 Incidents typés (9 types) au lieu de la note libre (app/Services/WorkflowService.php)
- [ ] T054 File d'attente par poste avec nombre de pièces disponibles
- [ ] T055 Statut « Perdu / Endommagé » et indemnisation D8
- [ ] T056 Tests : RG5, SE7, historique complet

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
