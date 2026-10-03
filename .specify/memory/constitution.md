# Constitution — ERP Pressing

## Principes fondamentaux

### I. Le vêtement est l'unité centrale
Tout part du vêtement individuel (`PR-AAAA-NNNNNN-NN`), pas de la commande ni du ticket de caisse. Chaque vêtement a un statut, un service responsable et un historique complet. Un identifiant n'est jamais réutilisé ni recyclé.

### II. Historique append-only, audit inaltérable
Les passages de service, paiements, annulations, remises, changements de tarif et corrections de facture sont enregistrés dans des journaux en ajout seul (jamais de UPDATE/DELETE applicatif). Une correction est un nouvel événement qui référence l'ancien. Le journal d'audit stocke utilisateur, horodatage, objet, ancienne valeur, nouvelle valeur, motif. Aucune opération sensible ne peut effacer sa propre trace.

### III. Les garde-fous métier sont imposés par le serveur
Les règles bloquantes (pas de « Prêt à livrer » sans qualité validée, pas de « Livré » sans preuve, pas de crédit au-delà du plafond, pas de clôture de caisse avec écart non justifié, pas de prise en charge avant « Terminé » du service précédent) sont appliquées dans l'API et la base, jamais seulement dans l'interface. Toute dérogation est nominative, motivée et auditée.

### IV. Une seule source de vérité, les indicateurs sont dérivés
Aucun KPI, solde, balance âgée, segment client ou stock n'est saisi à la main ni stocké comme vérité : tout se recalcule depuis les transactions sources (vues ou agrégats reconstruisibles). Un test de recette compare chaque KPI à son calcul brut.

### V. Sécurité par défaut : RBAC et cloisonnement par agence
Chaque requête est contrôlée par rôle (lecture, création, modification, validation, suppression) **et** par périmètre d'agence. Données isolées par agence, consolidation uniquement pour les rôles Direction/Admin. Mots de passe hachés (argon2/bcrypt), secrets hors dépôt, données personnelles minimisées.

### VI. Terrain d'abord : rapide, tactile, tolérant aux coupures
Réception et production fonctionnent sur tablette/smartphone Android à bas débit. Les écrans de poste (scan → action) tiennent en 2 interactions. Les postes de réception tolèrent une coupure réseau courte (file locale rejouée, identifiants réservés par poste, sans collision). Toute action asynchrone externe (SMS, WhatsApp, e-mail, paiement) passe par une file avec reprise, jamais en ligne dans la transaction métier.

### VII. Paramétrable sans développeur
Tarifs, délais d'alerte, calendrier de relance, seuils (VIP, plafond, écart de caisse, stock), modèles de message, critères qualité, étapes par type de traitement : tout est en base et administrable, avec historique des versions.

## Contraintes
- Langue de l'interface : français. Devise : FCFA (XAF), entiers, sans décimales.
- Montants et horodatages : stockés en UTC, affichés Africa/Douala.
- Les fournisseurs externes (SMS, WhatsApp, e-mail, mobile money, impression) sont derrière des interfaces remplaçables.
- Aucune suppression physique de données transactionnelles ; archivage logique.
- Sauvegarde quotidienne externalisée, restauration testée avant mise en production.

## Gouvernance
La constitution prime sur le plan et les tâches. Toute modification passe par un avenant daté (version, motif). Les revues vérifient la conformité aux principes II, III et IV.

**Version** : 1.0.0 | **Ratifiée** : 2026-10-03 | **Dernière modification** : 2026-10-03
