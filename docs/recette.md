# Cahier de recette (T160)

Recette du site déployé : http://pressing-erp.trugroup.cm — base de production `trugro9159_pressingerp`.
Dernier passage automatique : **6 octobre 2026 — 183 tests réussis, 0 échec, 4 ignorés** (les 4 ignorés sont les tests de déclencheurs de base de données, que l'hébergeur n'autorise pas ; l'ajout seul est garanti par le code, la chaîne de hachage et l'ancrage de sauvegarde).

Les tests automatiques s'exécutent **sur la base en ligne, dans une transaction annulée à la fin** : rien ne reste en base. Commande : `ops/deploy.sh run selftest`.

## 1. Comment lire ce document

| Colonne | Sens |
|---|---|
| Auto | Couvert par un test automatique (nom du fichier dans `tests/cases/`) |
| À la main | À faire avec une vraie personne, une vraie tablette ou une vraie imprimante : le robot ne peut pas le prouver |
| État | ✅ vérifié automatiquement · ⬜ à faire sur place · ⚠️ limite connue |

Aucune case « à la main » n'a été cochée : **personne n'a encore utilisé le système en conditions réelles.** La recette automatique prouve la logique, pas l'ergonomie ni le matériel.

## 2. Les dix tests critiques

### R1 — Réception de trois vêtements dont un de valeur (SC-002, SE1, SE2, SE3, SE4)
1. Connecté en comptoir, ouvrir sa caisse, créer un client avec nom complet et numéro valide.
2. Saisir 3 vêtements dont un fragile ou de valeur ; **sans photo, la commande est refusée.**
3. Valider : numéro `PR-AAAA-NNNNNN`, pièces `-01` à `-03`, 3 étiquettes QR, ticket avec TVA et NIU.
4. Un client sans nom complet ou sans numéro valide est refusé. Un article sans tarif est refusé et tracé.

Auto : `phase2.php`, `offline.php`. État : ✅ logique · ⬜ chronométrer (objectif : moins de 3 minutes) · ⬜ imprimer les étiquettes sur l'imprimante réelle.

### R2 — Traçabilité et transfert entre services (SE5, SE6, SE7, RG5)
1. Scanner une étiquette : fiche pièce, commande, client, historique.
2. Un opérateur prend en charge, termine ; le poste suivant voit le nombre de pièces disponibles.
3. QR illisible : recherche manuelle par code. Pièce non prise en charge à temps : alerte puis escalade. Incident : pièce « Bloquée » et alerte.
4. Une pièce prise en charge ne peut être terminée que par son opérateur ou un superviseur.

Auto : `workflow.php`, `alerts.php`. État : ✅ logique · ⬜ scan avec la caméra de la tablette (objectif : fiche en moins de 2 secondes).

### R3 — Contrôle qualité avant emballage (RG8, RG9, SE8, SE9)
1. Tenter de passer une pièce à « Prêt » sans contrôle conforme : **refusé côté serveur.**
2. Contrôle conforme : la pièce passe à l'emballage. Reprise : motif et étape obligatoires, validation annulée.
3. Dérogation : réservée aux responsables, motivée, tracée, valable pour cette seule pièce ; une reprise ultérieure l'annule.
4. Une commande dont une pièce n'a pas de contrôle valide ne peut pas être remise.

Auto : `quality.php`. État : ✅

### R4 — Caisse sans tolérance (RG, SE14, SE15)
1. Paiement mixte (espèces + Orange Money) sous un seul reçu.
2. Clôturer avec **un seul franc d'écart** : justification obligatoire de 8 caractères minimum, alerte au responsable.
3. Un écart compensé entre deux modes est quand même un écart.
4. Remise et annulation d'encaissement : autorisation d'un responsable, motif, trace.

Auto : `cash.php`. État : ✅ logique · ⬜ faire un comptage réel avec une caissière.

### R5 — Alertes et retards
1. Commande à risque (orange) puis en retard (rouge) selon la date promise ; fermée quand elle est prête.
2. Alerte non prise en compte : escalade au rôle supérieur après le délai de la règle.
3. Règles modifiables (destinataire, délai) dans l'administration.

Auto : `alerts.php`. État : ✅

### R6 — Notifications au client (SE10, SE11, RG11, RG18)
1. Messages au dépôt, commande prête, retard, départ en livraison, clôture.
2. Échec d'un canal : nouvel essai, puis canal suivant ; tous en échec : alerte à la réception.
3. Client sans coordonnée valide : échec immédiat et alerte. Message promotionnel sans consentement : jamais envoyé.

Auto : `messages.php`, `marketing.php`. État : ✅ logique · ✅ e-mail testé en connexion réelle (connexion et authentification SMTP depuis l'hébergeur) · ⚠️ **SMS et WhatsApp : aucun fournisseur configuré**, les messages aux clients sans e-mail restent en attente (SC-007 non mesurable).

### R7 — Livraison et collecte (RG20, RG21, SE20, SE21)
1. Affecter un livreur, partir : le client reçoit un code à 4 chiffres.
2. Clôturer sans preuve (code, signature ou photo) : **refusé.** Mauvais code : refusé.
3. Solde encaissé par le livreur dans sa caisse, ou report motivé autorisé par un responsable.
4. Échec : motif et nouveau créneau, client prévenu ; adresse introuvable : alerte ; 3 échecs : alerte.

Auto : `delivery.php`. État : ✅ logique · ⬜ signature au doigt sur la tablette du livreur.

### R8 — Commandes non retirées (SE12)
1. Relances à J+2, J+7, J+15 (paramétrables), une seule fois chacune, rattrapage sans rafale.
2. Annulées au retrait. Alerte du responsable à J+15 avec la mention « client injoignable » si besoin.

Auto : `reminders.php`. État : ✅

### R9 — Facturation et recouvrement (D5, RG16, SE16, SE17)
1. Facture mensuelle : HT, TVA, TTC, NIU du vendeur et du client, numérotation continue par agence et par année.
2. Avoir : motif, responsable, jamais au-delà du reste dû ; facture inchangée en cachette.
3. Versement réparti sur les factures de la plus ancienne à la plus récente, sous un seul reçu.
4. Plafond d'encours : commande bloquée, dérogation motivée et tracée.
5. Balance âgée : bornes 30/31, 60/61, 90/91 jours.

Auto : `invoicing.php`. État : ✅ logique · ⚠️ **à faire valider par le comptable** : taux 19,25 % sur prix TTC, obligation du NIU client, format de numérotation.

### R10 — Sécurité, audit, périmètre et continuité (SC-004, SC-005, SC-006, SE23)
1. Chaque profil ne voit que ses menus, liens et boutons (test automatique de toutes les pages de chaque profil).
2. Un refus d'accès et une connexion échouée sont journalisés. Une agence hors périmètre est refusée et journalisée.
3. Modification sensible : ancienne et nouvelle valeur dans le journal ; chaîne de hachage vérifiable ; ancrage écrit à chaque sauvegarde.
4. Sauvegarde puis restauration d'essai dans des tables fantômes : comparaison table par table.

Auto : `rbac.php`, `ui_access.php`, `audit.php`, `hygiene.php`, `pilotage.php`. État : ✅ — sauvegarde du 6 octobre 2026 : 53 tables sur 53, restauration d'essai : **53 tables comparées, 0 écart**.
⚠️ Sauvegarde **sur le même hébergeur** : pas de copie externe tant qu'une destination n'est pas fournie (T029).

## 3. Critères de réussite mesurables

| Critère | État |
|---|---|
| SC-001 Scan → fiche en moins de 2 s sur tablette | ⬜ non mesuré (tablette réelle requise) |
| SC-002 Commande de 3 vêtements en moins de 3 min | ⬜ non mesuré |
| SC-003 KPI du cockpit réconciliés avec le SQL brut | ✅ `pilotage.php`, `documents.php` |
| SC-004 Aucune transition interdite acceptée (RG5, RG8, RG16, RG20) | ✅ `workflow.php`, `quality.php`, `invoicing.php`, `delivery.php` |
| SC-005 Modification sensible retrouvable avec ancienne et nouvelle valeur | ✅ `audit.php` |
| SC-006 Restauration d'essai réussie, RPO ≤ 24 h, RTO ≤ 4 h | ✅ restauration ; ⬜ RTO à mesurer sur un vrai incident |
| SC-007 Notification en moins de 60 s | ⚠️ e-mail seulement, SMS/WhatsApp non configurés |

## 4. Recette par module (liste à cocher sur place)

Pour chaque module, une personne du métier déroule le scénario ci-dessus avec ses vraies habitudes, puis signe.

| Module | Scénarios | Recetteur | Date | Signature |
|---|---|---|---|---|
| Réception / comptoir | R1 | | | |
| Production / traçabilité | R2 | | | |
| Qualité | R3 | | | |
| Caisse | R4 | | | |
| Alertes | R5 | | | |
| Messagerie | R6 | | | |
| Livraison | R7 | | | |
| Relances | R8 | | | |
| Commercial / facturation | R9 | | | |
| Administration / sécurité | R10 | | | |
| Marketing | seuils validés, scénarios relus | | | |
| BI / cockpit | chiffres comparés à un état papier d'une journée | | | |

## 5. Anomalies ouvertes à la date du document

Aucune anomalie bloquante connue dans la logique. Limites assumées :
- Site en **HTTP** (certificat TLS non activé) : pas de mode hors-ligne persistant (service worker), webhook de la passerelle de paiement non reçu (confirmation manuelle), cookies et mots de passe non chiffrés en transit.
- SMS et WhatsApp non configurés.
- Pas de copie de sauvegarde hors hébergeur.
- Valeur déclarée d'un vêtement non saisissable à la réception.
- Imprimante d'étiquettes et tablette non testées.
