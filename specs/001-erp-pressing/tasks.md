# Tâches — ERP Pressing

**Entrées** : [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [contracts/api.md](contracts/api.md)
Format : `- [ ] Txxx [P] [USn] Description (chemin)` — `[P]` = parallélisable. Chaque phase se termine par un point de contrôle testable.

## Phase 0 — Cadrage et arbitrages
- [x] T001 Trancher les questions ouvertes, décisions D1–D9 dans spec.md §7
- [x] T002 Valider la stack du plan.md (ou la remplacer) et mettre à jour research.md
- [ ] T003 Obtenir l'avis conformité : facturation, données personnelles, moyens de paiement (CR §5) — `docs/conformite.md`
- [x] T004 Initialiser le dépôt, monorepo (`apps/`, `packages/`, `db/`, `ops/`), lint, format, hooks
- [x] T005 [P] CI : build, tests, migrations, scan de dépendances
- [ ] T006 [P] Environnements dev / préprod / prod, gestion des secrets (.env.example fait ; reste hébergement)
- [ ] T007 Archiver les deux documents sources dans `docs/sources/`

**Contrôle** : spec sans point [BLOQUANT] ouvert pour les phases 1–3 ; CI verte sur un dépôt vide.

## Phase 1 — Socle (IAM, RBAC, agences, audit, paramètres)
- [ ] T010 Migrations : `agency`, `user`, `role`, `permission`, `setting`, `audit_log`, `outbox` (db/migrations)
- [ ] T011 Authentification, hachage argon2, refresh, verrouillage (apps/api/src/modules/iam)
- [ ] T012 Matrice RBAC : lecture/création/modification/validation/suppression × 9 rôles, définir « Limité » (spec A6)
- [ ] T013 Guard `Permission` + contexte agence + RLS Postgres
- [ ] T014 Journal d'audit append-only : triggers, droits DB, chaîne de hachage (apps/api/src/modules/audit)
- [ ] T015 [P] Service de paramètres versionnés + API `/settings`
- [ ] T016 [P] Outbox transactionnelle + squelette worker (apps/worker)
- [ ] T017 [P] Journalisation d'erreurs (Pino, Sentry), identifiant de corrélation
- [ ] T018 [P] Gabarit back-office et PWA (layout, navigation par rôle, thème sombre/clair)
- [ ] T019 Tests : accès refusé journalisé, modification auditée avec ancienne/nouvelle valeur, tentative d'UPDATE sur `audit_log` rejetée
- [ ] T020 Écrans admin : utilisateurs, rôles, agences

**Contrôle** : un utilisateur Production reçoit 403 sur Direction ; chaque modification sensible apparaît dans l'audit.

## Phase 2 — Clients CRM
- [ ] T030 Migrations `customer`, `customer_consent`, `business_account`, vues `v_customer_stats`
- [ ] T031 [P] API clients + recherche (téléphone, nom) avec doublons
- [ ] T032 [P] Consentements par canal et finalité (opérationnel/marketing), journal AO
- [ ] T033 Client anonyme avec restrictions (spec A10)
- [ ] T034 Écran fiche client (PWA + back-office) : historique, CA, panier moyen, solde débiteur
- [ ] T035 Tests : doublon de téléphone, consentement marketing retiré

## Phase 3 — Réception, tarifs, QR (US1 — MVP)
- [ ] T040 Migrations catalogue : `garment_type`, `treatment`, `treatment_route`, `service_level`, `price_list`, `price_item`
- [ ] T041 Moteur de prix : priorités, express, VIP, entreprise, promo, agence (spec A7) + tests unitaires par règle
- [ ] T042 [P] Écrans admin de tarification versionnée (FR-072), audit des changements
- [ ] T043 Migrations `order`, `garment`, `garment_photo`, `label_print`, `id_block`
- [ ] T044 Génération de numéros `PR-AAAA-NNNNNN` et suffixes, sans collision, test de concurrence
- [ ] T045 API `POST /orders` avec RG1, RG2, SE4 (tarif absent ⇒ escalade)
- [ ] T046 Règle photo obligatoire paramétrable (valeur, fragile, endommagé) + upload
- [ ] T047 Génération d'étiquettes QR (ZPL/ESC-POS + PDF), réimpression tracée
- [ ] T048 Mode dégradé impression : ID provisoire `TMP-`, régularisation (SE3)
- [ ] T049 File hors-ligne de la réception + blocs d'ID par poste (IndexedDB, rejeu)
- [ ] T050 Écran PWA de réception : parcours en ≤ 3 minutes pour 3 vêtements
- [ ] T051 Ticket de dépôt (PDF/thermique)
- [ ] T052 Tests : SE1, SE2, SE3, SE4, unicité des identifiants (RG3)

**Contrôle** : quickstart étape 1 réussie ; test d'imprimante réelle et d'étiquette lavée.

## Phase 4 — Traçabilité et workflow (US2)
- [ ] T060 Migrations `garment_step`, `garment_event` (AO), `incident`
- [ ] T061 Machines à états étape et vêtement en table de transitions (spec A1)
- [ ] T062 Parcours par traitement (spec A3) : seed et édition admin
- [ ] T063 API de scan `GET /garments/:code` (< 2 s p95) + recherche manuelle (SE5)
- [ ] T064 Actions start / complete / block / redo + RG5, horodatage, responsable
- [ ] T065 File d'attente par poste avec compteurs de pièces disponibles
- [ ] T066 Incidents typés (9 types) ⇒ Bloqué + événement outbox (SE7, RG7)
- [ ] T067 Écran PWA de poste : scan → action en ≤ 2 interactions
- [ ] T068 Historique du vêtement (vue chronologique)
- [ ] T069 Politique perte/dommage : statut « Perdu/Endommagé » et processus (spec A21)
- [ ] T070 Tests : RG5, SE5, SE7, historique complet, concurrence de prise en charge

**Contrôle** : quickstart étapes 3–4.

## Phase 5 — Qualité (US3)
- [ ] T080 Migrations `quality_check`, `quality_check_item`, `quality_override` (AO)
- [ ] T081 Critères paramétrables (9 par défaut)
- [ ] T082 API de contrôle : Conforme ⇒ Emballage ; À reprendre ⇒ motif + service obligatoires (RG9, SE8)
- [ ] T083 Verrou RG8 en service + contrainte base ; dérogation (SE9)
- [ ] T084 Écran qualité PWA
- [ ] T085 Tests : tentative de contournement via API directe, reprise en boucle, dérogation sans droit

**Contrôle** : quickstart étape 5 ; aucun chemin API ne permet « Prêt à livrer » sans validation.

## Phase 6 — Caisse et paiements (US4) — jalon pilote
- [x] T090 CA théorique et tolérance d'écart : voir spec D3
- [ ] T091 Migrations `cash_session`, `payment` (AO), `cash_movement`, `v_order_balance`
- [ ] T092 Encaissement multi-moyens et mixte ; reçu
- [ ] T093 Annulation/remise = écriture inverse + autorisation + audit (SE15, RG14)
- [ ] T094 Ouverture et clôture, écart, motif obligatoire, alerte manager (SE14, RG15)
- [ ] T095 [P] Adaptateur Mobile Money (passerelle) + webhook signé + idempotence
- [ ] T096 [P] Décaissements autorisés
- [ ] T097 Écrans caisse PWA + état de caisse (PDF)
- [ ] T098 Tests : écart, remise après validation, double webhook, paiement partiel

**Contrôle** : quickstart complet ; pilote en une agence avec revue.

## Phase 7 — Alertes et retards (US5)
- [ ] T100 Migrations `alert_rule`, `alert`
- [ ] T101 Moteur de règles (événement, priorité, service, délai) dans le worker
- [ ] T102 Alertes de transfert avec nombre de pièces (T02 étape 6)
- [ ] T103 Non-prise en charge ⇒ alerte, escalade superviseur ⇒ manager (SE6, RG6)
- [ ] T104 Calcul vert/orange/rouge vs date promise ; vue « Commandes à risque »
- [ ] T105 Flux SSE + centre d'alertes (PWA et back-office)
- [ ] T106 Admin des règles et délais
- [ ] T107 Tests : escalade temporisée, horloge simulée, déduplication

## Phase 8 — Notifications client (US6)
- [ ] T110 Migrations `notification_template`, `notification` (AO)
- [ ] T111 Adaptateurs SMS, WhatsApp, e-mail (interface commune, repli)
- [ ] T112 Déclencheurs : dépôt, prête, retard, livraison, clôture (RG10)
- [ ] T113 Séparation opérationnel/marketing (RG11), coordonnées invalides (SE10), canal indisponible (SE11)
- [ ] T114 Modèles administrables, variables, aperçu
- [ ] T115 Tests : repli de canal, consentement, anonymes exclus

## Phase 9 — Livraison et retrait (US7)
- [ ] T120 Migrations `delivery_request`, `delivery_proof` (AO)
- [ ] T121 Statuts incluant « Non livré » (spec A2), tournées, affectation
- [ ] T122 Preuve obligatoire (RG20) et solde (RG21), encaissement livreur
- [ ] T123 Absence et adresse erronée (SE20, SE21)
- [ ] T124 Écran livreur PWA
- [ ] T125 Retrait en agence et remise
- [ ] T126 Tests : « Livré » sans preuve refusé

## Phase 10 — Cockpit BI (US8)
- [ ] T130 Vues `v_kpi_*` pour les 19 KPI du cockpit, snapshots
- [ ] T131 Objectifs (6 types) : cible, réalisé, écart, %
- [ ] T132 Alertes managériales (CA −25 %, commandes à risque, réclamations, encours, stock, productivité)
- [ ] T133 Cockpit responsive, filtres agence/période/service, fraîcheur (SE22), périmètre (SE23)
- [ ] T134 Carte des goulots par étape
- [ ] T135 Analyses commerciale, client, production (20.1–20.3)
- [ ] T136 Flux de réclamation minimal pour le KPI (spec A20)
- [ ] T137 Tests de réconciliation KPI vs SQL brut (SC-003)

## Phase 11 — Vêtements non retirés (US9)
- [ ] T140 Job quotidien : ancienneté depuis disponibilité
- [ ] T141 Calendrier J+2/J+7/J+15 paramétrable, annulation au retrait (SE13, RG13)
- [ ] T142 Seuil et alerte manager, injoignable (SE12)
- [ ] T143 Tableau nombre/valeur/ancienneté
- [ ] T144 Tests de calendrier

## Phase 12 — Commercial et recouvrement (US10)
- [ ] T150 Trancher fiscalité et format de facture (spec A11)
- [ ] T151 Migrations prospects, devis, contrats, factures, avoirs, actions de relance
- [ ] T152 Facturation, numérotation continue, avoirs
- [ ] T153 Plafond et blocage de crédit, dérogation (SE16, RG16)
- [ ] T154 Vue `v_aged_balance` (5 tranches), recalcul continu (RG17)
- [ ] T155 File « à relancer aujourd'hui », relances, échéancier
- [ ] T156 Rapprochement des paiements partiels (SE17)
- [ ] T157 Facturation périodique des contrats
- [ ] T158 Tests : soldes et tranches aux bornes (30/31, 60/61, 90/91)

## Phase 13 — CRM marketing (US11)
- [ ] T160 Fixer les seuils de segments (spec A8)
- [ ] T161 Job de segmentation et « à vérifier » (SE19)
- [ ] T162 Scénarios réactivation, fidélisation (10 commandes), VIP (RG19)
- [ ] T163 Ciblage par critères, respect du consentement (SE18, RG18)
- [ ] T164 Tableau de bord campagnes
- [ ] T165 Tests : consentement retiré, passage VIP automatique

## Phase 14 — Stocks (US12)
- [ ] T170 Migrations stock, mouvements AO, vue niveau
- [ ] T171 Entrées, sorties, ajustements, par agence
- [ ] T172 Alerte stock critique
- [ ] T173 Écrans et tests

## Phase 15 — Documents, recherche, multi-agences
- [ ] T180 Les 11 documents PDF (FR-150)
- [ ] T181 Recherche globale (téléphone, nom, commande, QR, vêtement, facture)
- [ ] T182 Consolidation Direction et vérification de cloisonnement
- [ ] T183 Tarifs et stocks par agence
- [ ] T184 Rapports journalier et mensuel planifiés

## Phase 16 — Sauvegarde, sécurité, recette, mise en production
- [ ] T190 Sauvegarde quotidienne externalisée, rétention
- [ ] T191 Procédure et test de restauration (SC-006), plan de reprise
- [ ] T192 Revue sécurité : OWASP, secrets, limitation de débit, sauvegarde des journaux
- [ ] T193 Tests de charge (scan, cockpit, import massif)
- [ ] T194 Recette par module selon spec §32 (10 tests critiques)
- [ ] T195 Formation par rôle, supports, guide d'exploitation
- [ ] T196 Mise en production, hypercare, bilan pilote

## Dépendances
0 → 1 → 2 → 3 → 4 → 5 → 6 (**pilote**). 7 dépend de 4 ; 8 de 3 ; 9 de 5–6 ; 10 de 6–7 ; 11 de 6, 8 ; 12 de 6 ; 13 de 2, 8 ; 14 indépendant après 1 ; 15 après 6 ; 16 en dernier (la sauvegarde T190 peut démarrer dès la phase 3).

## Parallélisation
Après la phase 6 : (7+8), (12), (14) peuvent avancer en parallèle ; 10 attend les données des précédentes.
