# Pressing ERP — PHP 8.1

ERP multi-agences pour un pressing : un atelier central et plusieurs agences. Il couvre 10 modules, l'accueil comptoir, le cockpit direction et un site de suivi pour les clients.
Le code est en PHP 8.1 natif, avec un MVC léger. Il n'utilise aucune dépendance Composer et tourne sur MySQL 8 ou MariaDB 10.5 et plus.

## Installation

Prérequis : PHP ≥ 8.1 avec les extensions `pdo_mysql`, `mbstring` et `fileinfo`.

```bash
# 1. Base de données
mysql -u root -e "CREATE DATABASE pressing_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. Configuration (variables d'environnement ou config/config.php)
export DB_DSN="mysql:host=127.0.0.1;dbname=pressing_erp;charset=utf8mb4" DB_USER=root DB_PASS=""
export APP_URL="http://localhost:8000" APP_ENV=local

# 3. Tables + données de démonstration (12 mois d'historique)
php bin/install.php --demo        # sans --demo : données de référence uniquement

# 4. Serveur de développement
php -S localhost:8000 -t public public/index.php
```

Comptes de démonstration (mot de passe `pressing2026`) :

| Identifiant | Profil | Arrive sur |
|---|---|---|
| `direction` | Direction | Cockpit |
| `manager.akwa` | Responsable d'agence | Cockpit |
| `fatou.diallo` | Comptoir (Akwa, caisse ouverte) | Accueil comptoir |
| `atelier.bamba` | Atelier (PIN `1234`) | Scan QR |
| `qualite.aka` | Contrôle qualité | Qualité |
| `commercial.toure` | Commercial | Contrats |

Le suivi client se fait sur `/suivi` (numéro de commande + téléphone). Le client peut aussi utiliser le lien `/suivi/{token}` envoyé par SMS ou imprimé sur son ticket.

Envoi des SMS, messages WhatsApp et e-mails : lancer `php bin/send-messages.php` toutes les minutes via cron. Remplacez `LogGateway` par l'adaptateur de votre fournisseur.

## Production

- **Apache** : faire pointer le DocumentRoot sur `public/`. Le fichier `.htaccess` est fourni.
- **Nginx** : `root …/public; location / { try_files $uri /index.php?$query_string; }`. Interdire PHP dans `/uploads`.
- Mettre `APP_DEBUG=0` et passer en HTTPS (le cookie de session devient alors `secure`).
- Rendre `public/uploads/` et `storage/` accessibles en écriture pour PHP.
- Changer tous les mots de passe de démonstration.

## Structure

```
public/            index.php (contrôleur frontal), assets/, uploads/ (photos des pièces)
config/config.php  BDD, fidélité, frais de livraison, seuils d'alerte
app/
  Core/            Router, Database (PDO), Auth, Csrf, View, Controller
  Domain/          Enums PHP 8.1 : Step, GarmentStatus, OrderStatus, ServiceLevel, PaymentMethod, Role ; Module
  Services/        Logique métier (voir ci-dessous)
  Controllers/     Un contrôleur par module
  Views/           Gabarits PHP (layout, public, 1 dossier par module)
database/migrations/ (SQL numéroté, non destructif)
bin/install.php, bin/send-messages.php
```

## Logique métier

- **Réception** (`OrderService::create`) :
  - chaque vêtement devient une pièce qui reçoit un code QR (`PR-2026-000124-03`) ;
  - une photo est obligatoire si l'article est fragile ou déjà endommagé ;
  - le prix est calculé par `PricingService` : majoration Express +50 % ou VIP +20 %, remise contrat pour les pros, −10 % fidélité sur chaque 10e commande ;
  - la date promise respecte les horaires d'ouverture ;
  - un client pro sous contrat est servi « en compte », avec contrôle de son plafond d'encours ;
  - un SMS de suivi part automatiquement.
- **Workflow atelier** (`WorkflowService`) :
  - étapes : Tri → Détachage → Lavage / sec → Séchage → Repassage → Finition → Contrôle → Emballage → Prêt ;
  - actions possibles sur chaque pièce : prise en charge, fin d'étape, étape non nécessaire, incident (la pièce est bloquée) ;
  - toutes les actions sont tracées dans `garment_events` ;
  - quand toutes les pièces sont prêtes, la commande passe « Prête » et le client est prévenu.
- **Contrôle qualité** (`QualityService`) :
  - grille de 9 critères ;
  - si la pièce doit être reprise, il faut un motif et une étape de retour ;
  - le taux de reprise suivi est sur 7 et 30 jours.
- **Caisse** (`CashService`, `PaymentService`) :
  - session par agent, avec fond de caisse ;
  - le comptoir encaisse en espèces, Mobile Money (Orange, MTN) ou carte ;
  - à la clôture, les montants comptés sont comparés aux montants théoriques ;
  - tout écart doit être justifié et apparaît comme alerte dans le cockpit.
- **Commercial** (`InvoiceService`) :
  - facturation mensuelle groupée des commandes en compte ;
  - balance âgée (non échu, 1–30, 31–60, 61–90, > 90 j) ;
  - relances par WhatsApp, SMS, e-mail, appel ou visite.
- **Marketing** (`MarketingService`) :
  - segments calculés automatiquement : VIP, réguliers, nouveaux, à risque, perdus, pros ;
  - campagnes avec variables `{prenom}`, `{nom}` et `{points}` ;
  - mesure des retours à 14 jours.
- **Stocks** (`StockService`) : entrées, sorties, inventaire, autonomie en jours, bons de commande fournisseur.
- **Cockpit** (`DashboardService`) :
  - chiffres du jour comparés à la moyenne des 4 derniers mêmes jours de semaine ;
  - carte de production qui repère le goulot d'étranglement ;
  - commandes à risque, alertes et objectifs mensuels (table `objectives`).

## Sécurité

- Requêtes préparées partout.
- Jeton CSRF sur chaque POST.
- `password_hash` et `session_regenerate_id` à la connexion.
- Contrôle d'accès par rôle et par module (`Role::modules()`).
- Échappement systématique des sorties (`e()`).
- Contrôle du type MIME des photos (`finfo`).
- Exécution de scripts interdite dans `uploads/`.
- Journal d'audit (`audit_log`).

## Pilotage du projet (Spec Kit)

- Constitution : [.specify/memory/constitution.md](.specify/memory/constitution.md)
- Spécification et décisions : [specs/001-erp-pressing/spec.md](specs/001-erp-pressing/spec.md)
- Audit du code vs spec : [audit-code-existant.md](specs/001-erp-pressing/audit-code-existant.md)
- Plan : [plan.md](specs/001-erp-pressing/plan.md) · Tâches : [tasks.md](specs/001-erp-pressing/tasks.md) · Recette : [quickstart.md](specs/001-erp-pressing/quickstart.md)
- Identifiants d'hébergement : `ops/.deploy.env` (non versionné, mots de passe à renseigner).
