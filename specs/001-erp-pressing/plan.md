# Plan d'implémentation — ERP Pressing

**Spec** : [spec.md](spec.md) | **Date** : 2026-10-03 | **Constitution** : v1.0.0

Choix technologiques = **proposition** (non fournie par les sources), à arbitrer avant la phase 0.

## Contexte technique

| Élément | Choix proposé | Raison |
|---|---|---|
| Langage | TypeScript (Node 22) | Un seul langage web + mobile PWA |
| Architecture | **Monolithe modulaire** + worker de tâches | Équipe réduite, un module par domaine, découpable plus tard |
| Backend | NestJS (modules = domaines) | RBAC par guards, injection, OpenAPI natif |
| Front | Next.js (back-office) + **PWA** pour postes (tablette/mobile) | Android sans app store, caméra pour scan QR |
| Base | PostgreSQL 16 | Transactionnel, vues, RLS par agence, contraintes |
| ORM | Prisma + SQL brut pour vues/triggers d'audit | Cohérent avec Sprint Tracker |
| File/jobs | BullMQ + Redis | Notifications, alertes temporisées, relances |
| Temps réel | Server-Sent Events | Alertes et compteurs, plus simple que WebSocket |
| PDF | Génération serveur (Playwright/pdfmake) | 11 documents |
| QR / étiquettes | `qrcode` + gabarits ZPL/ESC-POS | Imprimantes thermiques |
| Paiement mobile | Passerelle `apisungku` (pawaPay) si validé | Réutilisation |
| Tests | Vitest, Playwright, tests de contrat | Garde-fous testés |
| Hébergement | VPS + sauvegarde objet externalisée (S3-compatible) | Coût, contrôle |
| Observabilité | Pino + Sentry | Journalisation d'erreurs (§27) |

Cibles : p95 API < 300 ms ; scan < 2 s ; 50 utilisateurs simultanés / agence (à confirmer) ; Android Chrome ≥ 100.

## Vérification constitution

| Principe | Application |
|---|---|
| I Vêtement central | Tables `garment`, `garment_event` ; ID formaté par séquence |
| II Append-only | `audit_log`, `garment_event`, `payment`, `stock_movement` sans UPDATE/DELETE (triggers + droits DB) |
| III Garde-fous serveur | Services de domaine + contraintes CHECK/triggers ; tests dédiés |
| IV Dérivé | Vues `v_aged_balance`, `v_kpi_*`, `v_customer_segment` ; pas de colonne de solde saisie |
| V RBAC + agence | Guard `Permission(resource, action)` + RLS Postgres sur `agency_id` |
| VI Terrain | PWA, file hors-ligne, plages d'ID réservées ; jobs asynchrones |
| VII Paramétrable | Table `setting` versionnée + écrans admin |

## Structure du projet

```text
pressing-erp/
├── apps/
│   ├── api/                  # NestJS (monolithe modulaire)
│   │   └── src/modules/
│   │       ├── iam/          # utilisateurs, rôles, permissions, agences
│   │       ├── customers/    # CRM, consentements, segments
│   │       ├── catalog/      # types vêtement, tarifs, services
│   │       ├── orders/       # commandes, vêtements, QR
│   │       ├── workflow/     # étapes, transferts, incidents
│   │       ├── quality/      # contrôles, dérogations
│   │       ├── alerts/       # règles, escalade, retards
│   │       ├── notifications/# modèles, canaux, file
│   │       ├── cash/         # caisse, paiements, écarts
│   │       ├── receivables/  # factures, créances, balance âgée
│   │       ├── marketing/    # scénarios, campagnes
│   │       ├── delivery/     # collectes, tournées, preuves
│   │       ├── inventory/    # stocks
│   │       ├── bi/           # KPI, objectifs
│   │       ├── documents/    # PDF
│   │       └── audit/        # journal
│   ├── worker/               # jobs : alertes, relances, segments, KPI
│   ├── backoffice/           # Next.js : direction, finance, admin
│   └── station/              # PWA : réception, production, qualité, livraison
├── packages/
│   ├── domain/               # types, états, règles partagées
│   └── ui/                   # composants
├── db/                       # migrations, vues, triggers, seeds
├── specs/001-erp-pressing/
└── ops/                      # déploiement, sauvegarde, restauration
```

## Décisions structurantes

1. **Deux machines à états** : `step_status` (5 valeurs) par étape ; `garment_state` pour le cycle de vie. Transitions autorisées en table de référence, validées côté serveur.
2. **Parcours par traitement** : `treatment_route` (liste ordonnée d'étapes) associé au traitement demandé.
3. **Numérotation** : séquence par année et par agence ; en hors-ligne, blocs d'ID réservés par poste pour éviter les collisions ; saisie manuelle SE3 = ID provisoire `TMP-` à régulariser.
4. **Audit** : triggers + service applicatif ; chaîne de hachage optionnelle pour détecter une altération.
5. **Multi-agences** : colonne `agency_id` partout, RLS, rôle Direction multi-agences.
6. **Notifications** : outbox transactionnelle → worker → fournisseur ; repli de canal ; journal.
7. **KPI** : vues SQL + table d'instantanés pour l'historique ; horodatage de fraîcheur exposé.

## Phases (aperçu — détail dans [tasks.md](tasks.md))

| Phase | Contenu | Livrable testable |
|---|---|---|
| 0 | Cadrage, arbitrages, dépôt, CI | Questions du spec §7 closes |
| 1 | Socle : IAM, RBAC, agences, audit, paramètres, outbox | Connexion + droits + audit |
| 2 | Clients CRM | Fiche client, consentements |
| 3 | **MVP** Réception, tarifs, QR, ticket (US1) | Commande + étiquettes |
| 4 | Traçabilité & workflow (US2) | Scan → transfert |
| 5 | Qualité (US3) | Verrou « Prêt à livrer » |
| 6 | Caisse & paiements (US4) | Clôture avec écart |
| 7 | Alertes & retards (US5) | Escalade, commandes à risque |
| 8 | Notifications (US6) | Envoi + repli |
| 9 | Livraison & retrait (US7) | Preuve de livraison |
| 10 | Cockpit BI (US8) | KPI réconciliés |
| 11 | Non retirés (US9) | Relances |
| 12 | Commercial & recouvrement (US10) | Balance âgée |
| 13 | CRM marketing (US11) | Scénarios |
| 14 | Stocks (US12) | Alertes de seuil |
| 15 | Documents, recherche, multi-agences | PDF, consolidation |
| 16 | Sauvegarde, sécurité, recette, mise en production | Recette signée |

**Jalon pilote** : fin phase 6 (une agence, parcours complet du dépôt à l'encaissement).
