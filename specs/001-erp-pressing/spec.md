# Spécification — ERP Pressing

**Branche** : `001-erp-pressing` | **Date** : 2026-10-03 | **Statut** : Brouillon
**Sources** : Cahier des charges v1.0 (32 sections) + Spécifications fonctionnelles détaillées v1.0 (T01–T10, RG1–RG23, SE1–SE23).

## 1. Analyse des sources (écarts, ambiguïtés, trous)

Résultat du croisement des deux documents. Les points **[BLOQUANT]** doivent être tranchés avant la phase concernée ; les autres reçoivent une hypothèse par défaut (§6) que la Direction peut renverser.

| # | Constat | Impact | Phase |
|---|---------|--------|-------|
| A1 | **Deux machines à états confondues.** Les statuts « À traiter / En cours / Terminé / Bloqué / À reprendre » sont ceux d'une *étape de production* ; « Prêt à livrer », « À collecter … Livré » sont ceux du *vêtement/commande*. Le CdC les mélange. | Modèle de données | 4 |
| A2 | **« Non livré » (SE20) absent** de la liste des statuts de livraison (§25). | Statuts | 9 |
| A3 | **Les 11 étapes sont-elles toutes obligatoires ?** T03 parle de « services de production *requis* ». Une chemise n'a pas de détachage. | Parcours par type de traitement | 4 |
| A4 | **« À reprendre » : retour vers quel service ?** Le contrôleur doit désigner le service fautif ; non précisé. | Qualité | 5 |
| A5 | **CA théorique non défini** (§16.2, T06). Avec les ventes à crédit et acomptes, théorique ≠ réel est normal. **[BLOQUANT caisse]** | Écart de caisse | 6 |
| A6 | **Matrice RBAC à 4 colonnes** (§22) vs **9 profils** (§3). Le droit « Limité » n'est pas défini. | RBAC | 1 |
| A7 | **Priorité entre tarifs non définie** (VIP vs express vs promo vs agence vs contrat entreprise). **[BLOQUANT réception]** | Tarification | 3 |
| A8 | **Seuils de segmentation absents** (actif, régulier, occasionnel, inactif, perdu, fort panier, VIP). | CRM | 13 |
| A9 | **Crédit B2C ?** RG16 ne parle que du plafond des pros ; « Paiement à crédit » est listé pour tous. Segment « Débiteur » existe pour les particuliers. | Paiement | 6, 12 |
| A10 | **Client anonyme (SE1)** incompatible avec crédit, livraison, relances non-retrait, fidélité. **Résolu : anonyme interdit (D10).** | Règles | 2 |
| A11 | **Facturation légale** : NIU, TVA (19,25 % au Cameroun, à confirmer), numérotation continue, mentions. Non évoqué. **[BLOQUANT facture]** | Conformité | 12 |
| A12 | **Protection des données** : loi camerounaise de 2024 sur les données personnelles (référence à vérifier) ; consentement marketing et notifications opérationnelles distincts (RG11, RG18). Régulateur « à vérifier » (CR §5). | Conformité | 0 |
| A13 | **Mobile Money** : intégration directe ou via une passerelle mutualisée ? La passerelle `apisungku` (pawaPay) existe déjà dans l'écosystème. | Architecture | 6 |
| A14 | **Mode hors-ligne** : implémenté (T048). Numéros réservés par poste, synchronisation idempotente. Fiable seulement avec HTTPS. | Architecture | 3 |
| A15 | **Aucune volumétrie** (agences, pièces/jour, utilisateurs, historique à conserver). Dimensionnement impossible. | Plan | 0 |
| A16 | **Date de mise en production non fixée** (CR §4). | Planning | 0 |
| A17 | **Photo obligatoire** : « valeur / fragile / endommagé » sont déclarés par l'opérateur ; seuil de valeur non défini. | Contrôle | 3 |
| A18 | **Étiquette** : format, imprimante (thermique ?), contenu du QR (URL ou ID) non précisés ; SE3 autorise une saisie manuelle de code → risque de doublon. | Traçabilité | 3 |
| A19 | **Application client** « éventuelle » : hors périmètre v1. | Périmètre | – |
| A20 | **Avoirs, litiges, réclamations** : le taux de réclamation est un KPI mais aucun flux de réclamation n'est décrit. | BI | 10 |
| A21 | **Objet perdu / vêtement endommagé** : indemnisation absente alors que « zéro vêtement perdu » est un critère de succès. | Processus | 4 |
| A22 | **Cohérence des chiffres** : 10 cas d'usage détaillés (T01–T10) ne couvrent pas stocks, marketing manuel, documents, recherche, multi-agences, sauvegarde ; ces domaines viennent du CdC seul. | Couverture | – |

## 2. Utilisateurs

Administrateur système · Direction/Manager · Réception/Caisse · Tri/Détachage · Lavage/Nettoyage · Repassage/Finition · Contrôle qualité · Livraison · Commercial/Recouvrement · Responsable marketing · Superviseur de production (cité dans les alertes, absent de la liste §3).

## 3. Récits utilisateur (priorisés)

### US1 — Réception et enregistrement d'une commande (P1, MVP)
Réception crée/retrouve le client, saisit chaque vêtement, obtient prix, identifiants uniques, étiquettes QR et ticket.
- **Test indépendant** : déposer 3 vêtements dont 1 de valeur ; commande `PR-2026-000001`, vêtements `-01..-03`, 3 étiquettes, ticket, photo exigée.
- **Scénarios** : nominal T01 ; SE1 client anonyme ; SE2 photo manquante ⇒ blocage ; SE3 imprimante HS ⇒ saisie manuelle régularisable ; SE4 tarif absent ⇒ escalade responsable.

### US2 — Traçabilité et transfert entre services (P1)
Un opérateur scanne, prend en charge, termine ; le suivant est alerté avec le nombre de pièces disponibles.
- **Test** : scanner un vêtement, retrouver commande, client, statut, service, historique complet en < 2 s.
- **Scénarios** : SE5 QR illisible ⇒ recherche manuelle ; SE6 non pris en charge ⇒ alerte + escalade ; SE7 incident ⇒ « Bloqué » + alerte superviseur puis manager.

### US3 — Contrôle qualité avant emballage (P1)
Contrôleur évalue 9 critères ; Conforme ⇒ Emballage ; À reprendre ⇒ motif obligatoire, service désigné, alerte.
- **Test** : tenter de passer un vêtement à « Prêt à livrer » sans validation ⇒ refus ; avec dérogation d'un responsable ⇒ accepté et tracé.
- **Scénarios** : SE8 motif manquant ; SE9 dérogation.

### US4 — Encaissement et clôture de caisse (P1)
Ouverture, encaissements multi-moyens (dont mixte), reçus, annulations/remises autorisées, clôture avec comparaison théorique/réel.
- **Test** : écart simulé ⇒ alerte manager, clôture impossible sans motif.
- **Scénarios** : SE14, SE15.

### US5 — Alertes interservices et retards (P2)
Moteur d'alertes paramétrable (événement, priorité, service, délai) ; feu vert/orange/rouge ; vue « Commandes à risque » ; escalade.

### US6 — Notifications client (P2)
SMS/WhatsApp/e-mail aux événements dépôt, prête, retard, livraison, clôture ; repli sur canal alternatif ; journal d'envoi. SE10, SE11, RG10, RG11.

### US7 — Livraison et retrait (P2)
Collecte, affectation livreur, statuts, encaissement du solde, preuve de livraison. SE20, SE21, RG20, RG21.

### US8 — Cockpit Direction (P2)
KPI jour/mois, objectifs, alertes managériales, carte des goulots, filtres agence/période/service, fraîcheur des données. SE22, SE23.

### US9 — Vêtements non retirés (P3)
Relances J+2/J+7/J+15 paramétrables, annulées au retrait, alerte manager au seuil, tableau nombre/valeur/ancienneté. SE12, SE13.

### US10 — Commercial et recouvrement (P3)
Prospects, devis, contrats, factures, avoirs, plafonds, échéanciers, balance âgée (5 tranches), relances, rapprochement de paiements. SE16, SE17.

### US11 — CRM : segmentation et marketing (P3)
Segments automatiques, scénarios (réactivation, fidélité, VIP), ciblage, respect du consentement. SE18, SE19.

### US12 — Stocks (P3)
Références, mouvements, seuils, alerte stock critique, par agence.

### Transverses (non récits mais livrables)
RBAC, audit, multi-agences, documents PDF, recherche globale, administration des paramètres, sauvegarde/restauration.

## 4. Exigences fonctionnelles

**Clients** — FR-001 fiche client unique avec numéro ; FR-002 historique, CA cumulé, panier moyen, fréquence, dernière visite ; FR-003 segmentation automatique (9 segments + « à vérifier ») ; FR-004 préférences de consentement par canal et par finalité (opérationnel / marketing) ; FR-005 client toujours identifiable : nom complet et numéro valide unique, vérifiés à la création de la fiche et à chaque commande (D10).

**Commandes & vêtements** — FR-010 numéro `PR-AAAA-NNNNNN` unique par année, sans trou toléré hors annulation tracée ; FR-011 identifiant vêtement par suffixe ; FR-012 attributs vêtement (type, catégorie, marque, couleur, matière, quantité, dommages, traitement, remarques, service, prix, dates) ; FR-013 photo obligatoire selon règle paramétrable (valeur, fragile, endommagé) ; FR-014 au moins un vêtement par commande (RG1) ; FR-015 QR/code-barres imprimable.

**Traçabilité & production** — FR-020 scan ⇒ fiche vêtement ; FR-021 transitions d'étape horodatées avec responsable ; FR-022 statuts d'étape à 5 valeurs ; FR-023 RG5 (pas de prise en charge avant « Terminé ») ; FR-024 parcours d'étapes configurable par type de traitement (A3) ; FR-025 incidents typés (9 types) ; FR-026 mesures de délais par étape.

**Alertes** — FR-030 règles par événement/priorité/service/délai ; FR-031 escalade en chaîne superviseur → manager ; FR-032 couleurs de retard vs date promise ; FR-033 vue « Commandes à risque ».

**Qualité** — FR-040 9 critères paramétrables ; FR-041 résultat Conforme / À reprendre ; FR-042 motif et service de reprise obligatoires ; FR-043 RG8 verrou « Prêt à livrer » ; FR-044 dérogation tracée.

**Notifications** — FR-050 modèles par événement et canal ; FR-051 file d'envoi avec reprise et canal de repli ; FR-052 journal par commande ; FR-053 règle opérationnel vs marketing.

**Non retirés** — FR-060 relances paramétrables ; FR-061 retrait ⇒ annulation ; FR-062 alerte manager au seuil ; FR-063 tableau nombre/valeur/ancienneté.

**Tarification** — FR-070 grilles standard, express, VIP, entreprise, promo, par agence ; FR-071 ordre de priorité explicite (A7) ; FR-072 historique des versions de tarifs.

**Commercial & recouvrement** — FR-080 prospects, devis, contrats, factures, avoirs, paiements ; FR-081 plafond, délai de paiement, tarifs négociés, facturation périodique ; FR-082 balance âgée 5 tranches recalculée à chaque mouvement ; FR-083 blocage crédit au-delà du plafond sauf dérogation ; FR-084 relances et file « à relancer aujourd'hui » ; FR-085 rapprochement de paiement partiel.

**Caisse** — FR-090 moyens : espèces, Orange Money, MTN MoMo, carte, virement, crédit, mixte ; FR-091 ouverture/clôture ; FR-092 décaissements autorisés, annulations, remises avec autorisation ; FR-093 comparaison théorique/réel et alerte ; FR-094 RG15.

**Marketing** — FR-100 ciblage par critères ; FR-101 scénarios réactivation / fidélité / VIP ; FR-102 respect du consentement (RG18) ; FR-103 journalisation des actions.

**Stocks** — FR-110 références avec unité, stock, minimum, coût, fournisseur, seuil ; FR-111 mouvements append-only ; FR-112 alerte stock critique ; FR-113 stock par agence.

**BI** — FR-120 KPI Direction (19 indicateurs §19) ; FR-121 analyses CA par dimension et comparaisons N/N-1 ; FR-122 analyses client et production ; FR-123 carte des goulots ; FR-124 objectifs (6 types) avec cible, réalisé, écart, % ; FR-125 alertes managériales ; FR-126 fraîcheur des données affichée.

**Livraison** — FR-130 demande de collecte/livraison, livreur, créneau, statuts dont « Non livré » ; FR-131 preuve obligatoire (RG20) ; FR-132 solde soldé ou reporté (RG21).

**Sécurité & audit** — FR-140 RBAC par rôle + agence ; FR-141 audit append-only avec ancienne/nouvelle valeur ; FR-142 journalisation des accès refusés (SE23) ; FR-143 mots de passe chiffrés, verrouillage de session.

**Documents & recherche** — FR-150 onze documents PDF (ticket, devis, bon de commande, facture, reçu, bon de livraison, relevé client, état de caisse, état de créances, rapport journalier, rapport mensuel) ; FR-151 recherche par téléphone, nom, n° commande, QR, n° vêtement, facture.

**Multi-agences** — FR-160 données propres par agence ; FR-161 consolidation Direction ; FR-162 tarifs par agence.

**Continuité** — FR-170 sauvegarde quotidienne externalisée ; FR-171 restauration testée ; FR-172 protection contre suppression accidentelle ; FR-173 plan de reprise.

## 5. Critères de réussite mesurables

- SC-001 : scan → fiche complète affichée en < 2 s (p95) sur tablette Android.
- SC-002 : création d'une commande de 3 vêtements en < 3 min, étiquettes imprimées.
- SC-003 : 100 % des KPI du cockpit réconciliés avec leur calcul SQL brut (test de recette).
- SC-004 : 0 transition interdite acceptée dans la suite de tests des garde-fous (RG5, RG8, RG16, RG20).
- SC-005 : toute modification sensible retrouvable dans l'audit avec ancienne et nouvelle valeur (100 % des cas testés).
- SC-006 : restauration d'une sauvegarde de test réussie, RPO ≤ 24 h, RTO cible 4 h (à valider).
- SC-007 : notification déclenchée dans les 60 s suivant l'événement (hors panne fournisseur).

## 6. Hypothèses par défaut (à confirmer)

1. **Statuts** : étape = 5 statuts ; vêtement = cycle de vie séparé (Reçu, En production, Contrôlé, Prêt à livrer, En livraison, Livré, Retiré, Annulé).
2. **CA théorique** (jour, caisse) = somme des paiements attendus au comptoir pour les commandes soldées ce jour + acomptes ; les ventes à crédit sont exclues et affichées à part.
3. **Priorité tarifaire** : contrat entreprise > tarif agence > VIP > promotion active > standard ; express est un *niveau de service* qui s'ajoute (majoration), pas une grille.
4. **Seuils initiaux** : nouveau ≤ 30 j ; actif = commande < 30 j ; inactif 30–60 j ; perdu > 180 j ; régulier ≥ 4 commandes/6 mois ; VIP seuil CA annuel paramétrable.
5. **Crédit** réservé aux comptes professionnels ; les particuliers paient avant retrait (acompte possible).
6. **Client anonyme** : interdit (décision D10).
7. **Réseau** : réception en PWA avec file hors-ligne et plages d'identifiants réservées par poste.
8. **Stack** (voir `plan.md`) : PostgreSQL, TypeScript, PWA ; arbitrable.
9. **Hors périmètre v1** : application client, comptabilité générale, paie, intégration fiscale en ligne.

## 7. Décisions (2026-10-03)

Questions du §7 initial tranchées par défaut ; chacune reste révisable par la Direction par avenant.

| # | Question | Décision |
|---|----------|----------|
| D1 | Volumétrie | Pilote : 1 agence, ~300 pièces/jour, ~30 utilisateurs. Dimensionné pour 5 agences et 1 500 pièces/jour au total. |
| D2 | Calendrier | Démarrage 2026-10-12. Pilote (phases 0–6) : 14 semaines, mise en service pilote vers fin janvier 2027. Mise en production complète (phases 7–16) : fin mai 2027. |
| D3 | CA théorique (A5) | Somme des montants attendus au comptoir pour les commandes soldées du jour + acomptes ; ventes à crédit exclues et affichées à part. **Aucune tolérance (décision du 2026-10-05)** : tout écart de caisse, même d'un franc, doit être justifié et alerte le responsable. |
| D4 | Priorité tarifaire (A7) | Contrat entreprise > tarif agence > VIP > promotion active > standard. Express = majoration de niveau de service, pas une grille. |
| D5 | Fiscalité (A11) | TVA 19,25 % paramétrable, NIU de l'entreprise et du client pro sur les factures, numérotation continue par agence et par année, prix comptoir TTC. À faire confirmer par le comptable avant la phase 12. |
| D6 | Fournisseurs | Interfaces remplaçables. Défaut : SMS via agrégateur local choisi à l'intégration, WhatsApp Business Cloud API, SMTP. Impression : thermique ESC/POS 80 mm pour les tickets, étiquettes 50x30 mm en ZPL. |
| D7 | Mobile Money (A13) | Via la passerelle mutualisée `apisungku`. |
| D8 | Perte ou dommage (A21) | Indemnisation plafonnée à 10 fois le prix du service, sauf valeur déclarée à la réception ; validée par le manager, tracée. Plafond paramétrable. |
| D9 | Données personnelles (A12) | Conservation 5 ans après la dernière activité, 10 ans pour les pièces comptables. Consentement marketing explicite et révocable. Revue juridique en phase 0. |

| D10 | Client anonyme (A10, SE1) | **Interdit** (décision du 2026-10-05) : chaque client est identifiable par son nom complet et un numéro de téléphone valide et unique. Un client qui refuse de les donner ne peut pas déposer de vêtements. Remplace l'hypothèse 6 et l'exception SE1. |

Hors périmètre v1 : application client, comptabilité générale, paie.

## 8. Questions encore ouvertes
- Agence pilote : nom, nombre de tablettes et d'imprimantes.
- Fournisseur SMS précis et tarifs.
- Confirmation comptable de D5.
