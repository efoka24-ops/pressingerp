# Audit du code existant vs spec (2026-10-03)

Code : PHP 8.1 / MySQL, 89 fichiers, 22 tables, ~5 500 lignes. Audit par lecture du code, **rien n'a été exécuté** : les « OK » sont à confirmer par test. Légende : OK, PARTIEL, ABSENT, ERREUR.

| Domaine (spec) | État | Constat |
|---|---|---|
| Clients / CRM (FR-001..005) | PARTIEL | Fiche, historique, filtres VIP/pros/inactifs/solde. Pas de consentements par canal et finalité ; client anonyme non géré. |
| Réception (FR-010..015) | OK/PARTIEL | Numérotation `PR-AAAA-NNNNNN-NN`, prix, express/VIP, photo si fragile ou endommagé. Pas de règle « valeur », pas d'ID provisoire (SE3), pas de hors-ligne. |
| Étiquettes QR (FR-015) | PARTIEL | Générées côté navigateur via CDN `cdnjs` : inutilisables sans internet. |
| Traçabilité (FR-020..026) | OK/PARTIEL | Étapes, actions, `garment_events`, statuts d'étape à 5 valeurs. Pas de parcours par type de traitement (« étape non nécessaire » manuelle). |
| Alertes interservices (FR-030..033) | **ABSENT** | Seuils dans `config.php` et « commandes à risque » au cockpit. Pas de moteur de règles, d'alerte de transfert ni d'escalade. |
| Qualité (FR-040..044) | PARTIEL | 9 critères, motif et étape de retour. **Pas de dérogation tracée** ; le verrou « Prêt » repose sur le flux linéaire, rien ne l'impose en base. |
| Notifications (FR-050..053) | PARTIEL | Table `messages` + cron. Seulement `LogGateway` (aucun envoi réel), pas de repli de canal, pas de consentement. |
| Non retirés (FR-060..063) | PARTIEL | Indicateurs au cockpit (nombre, valeur, > J+15). **Pas de relances J+2/J+7/J+15.** |
| Tarification (FR-070..072) | PARTIEL | `PricingService` : majorations codées en dur (+50 %, +20 %, −10 %). Pas de grilles versionnées, de priorité D4 ni d'écran d'admin. |
| Commercial / recouvrement (FR-080..085) | OK/PARTIEL | Contrats, factures groupées, balance âgée 5 tranches, relances. Pas de TVA/NIU, d'avoirs, de devis ni de rapprochement des paiements partiels. |
| Caisse (FR-090..094) | OK/PARTIEL | Sessions, fond de caisse, comptage, écart justifié, dépenses. Pas de tolérance paramétrable (D3), de paiement mixte ni d'autorisation de remise. Wave et Moov présents (hors marché camerounais). |
| Marketing (FR-100..103) | PARTIEL | Segments, campagnes, retours à 14 j. Pas de consentement ; seuils en dur. |
| Stocks (FR-110..113) | OK | Articles, mouvements, inventaire, bons de commande. Alerte « stock critique » du cockpit à vérifier. |
| BI (FR-120..126) | OK/PARTIEL | Cockpit, goulot, objectifs, export. Fraîcheur des données non affichée ; réclamations présentes (`complaints`). |
| Livraison (FR-130..132) | **ABSENT** | Seulement une demande de livraison côté client (`/suivi/{token}/livraison`). Pas de livreur, tournée, preuve, « non livré », solde à percevoir. |
| RBAC (FR-140) | PARTIEL | 6 rôles, accès par module seulement (pas lecture/création/modification/validation/suppression). Manquent : administrateur, superviseur, livreur, marketing. |
| Audit (FR-141..142) | **NON CONFORME** | `audit_log(user, action, entity, data JSON, ip)` : pas d'ancienne/nouvelle valeur ni de motif, aucune protection contre UPDATE/DELETE. |
| Documents / recherche (FR-150..151) | PARTIEL | Étiquettes, facture, recherche globale. Devis, bon de livraison, relevé, états et rapports absents. |
| Multi-agences (FR-160..162) | PARTIEL | `agencies`, `agency_id` dans 12 fichiers. Cloisonnement non testé ; tarifs par agence absents. |
| Sauvegarde (FR-170..173) | **ABSENT** | Rien dans le dépôt. |
| Localisation | **ERREUR** | Fuseau `Africa/Abidjan` (Douala = UTC+1) ; données de démo ivoiriennes (+225, Cocody) ; moyens Wave/Moov. |
| Exploitation | **RISQUE** | `schema.sql` commence par `DROP TABLE` ; comptes de démo à mot de passe connu ; pas de migrations. |

## Points forts à conserver
Requêtes préparées, CSRF, `password_hash`, échappement des sorties, contrôle MIME des photos, interdiction de scripts dans `uploads`, enums de domaine, transactions sur les actions d'atelier, annulation avec motif et refus si paiements existent.

## Conséquences sur le plan
Les phases 1 à 11 sont des **mises à niveau ciblées** : une bonne partie du périmètre est déjà là. Trois chantiers sont neufs : moteur d'alertes, livraison avec preuve, sauvegarde et audit inaltérable.
