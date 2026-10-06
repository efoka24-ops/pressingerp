# Bascule, suivi renforcé et bilan du pilote (T162)

Ce document est un **plan à exécuter avec la direction** : la bascule et le pilote ne peuvent pas être faits par le développeur seul. Les cases ci-dessous sont à cocher sur place, avec la date et le nom de la personne.

## 1. Conditions pour décider la bascule

### Bloquantes (pas de bascule sans elles)
- [ ] Recette par module signée (docs/recette.md, section 4), toutes les cases « à la main » faites sur le matériel réel : tablette de réception, imprimante d'étiquettes, imprimante de tickets, téléphone du livreur.
- [ ] Chronométrage : commande de 3 vêtements en moins de 3 minutes (SC-002) ; scan → fiche en moins de 2 secondes (SC-001).
- [ ] **Mots de passe changés** : administrateur, FTP, base de données. Les anciens ont circulé dans des conversations.
- [ ] **Tâches planifiées créées** dans le panneau Camoo (guide d'exploitation, section 2) et **une journée complète observée** : alertes, envoi, relances, segments, sauvegarde de 02:30 présente le matin.
- [ ] **Sauvegarde externe** : destination fournie, première copie reçue, restauration d'essai refaite à partir de cette copie.
- [ ] Comptable : TVA, NIU de l'entreprise renseigné dans Administration > Paramètres, numérotation des factures validée.
- [ ] Direction : seuils de segmentation validés (bandeau « à vérifier » levé), plafonds de remise, seuils d'alerte relus.
- [ ] Personnel formé (docs/formation-par-role.md) : chacun répond juste aux 5 questions de fin de séance.

### Fortement recommandées
- [ ] **Certificat HTTPS activé** : protège les mots de passe, débloque le mode hors-ligne complet (le rechargement de page sans réseau échoue en HTTP) et le retour automatique de la passerelle de paiement.
- [ ] Fournisseur **SMS ou WhatsApp** branché : sans lui, les clients qui n'ont qu'un téléphone ne reçoivent aucun message.
- [ ] Vérifier avec la passerelle de paiement (apisungku) l'agrément et le fonctionnement réel d'un paiement Orange Money et MTN MoMo de petit montant.

## 2. Données à reprendre

| Donnée | Source | Qui | Contrôle |
|---|---|---|---|
| Clients | fichier ou cahier existant | réception | nom complet et numéro valide pour chacun ; deux fiches ne peuvent pas partager un numéro ; les doublons sont à fusionner avant l'import |
| Tarifs | grille actuelle | direction | saisis dans le menu Tarifs (prix TTC), comparés ligne à ligne à la grille papier |
| Clients professionnels | contrats en cours | commercial | contrat, plafond d'encours, délai de paiement, **NIU** |
| Encours existants | état des créances papier | comptable | à saisir en factures d'ouverture ou à garder hors système jusqu'à leur règlement : **décision à prendre** |
| Stocks | inventaire physique | atelier | un inventaire au jour de la bascule, saisi comme mouvement « inventaire » |
| Utilisateurs | liste du personnel | administrateur | un compte par personne, jamais partagé |

Il n'y a pas de reprise automatique des anciennes commandes : les commandes en cours le jour J sont **saisies à la main** (voir plan de bascule).

## 3. Plan de bascule

**J−14** : formation par rôle ; création des comptes ; saisie des tarifs et des clients professionnels.
**J−7** : répétition générale sur une journée fictive avec le vrai matériel (une commande de bout en bout : réception, atelier, contrôle, caisse, livraison, facture). Corriger.
**J−2** : sauvegarde manuelle et restauration d'essai ; vérifier les tâches planifiées ; geler les évolutions (plus aucune mise en ligne sauf correctif bloquant).
**J−1 au soir** : inventaire des stocks ; relever toutes les commandes en cours (numéro papier, client, pièces, date promise, acompte versé).
**Jour J, avant l'ouverture** : sauvegarde ; ouvrir les caisses avec le fond compté ; saisir les commandes en cours dans le système (les acomptes versés sont saisis comme paiements) ; chaque pièce reçoit son étiquette QR.
**Jour J, journée** : double saisie autorisée sur papier uniquement pour les opérations bloquées ; le développeur est présent ou joignable.
**Jour J, soir** : clôture de toutes les caisses ; comparer le **rapport journalier** du système à l'état de caisse papier ; écart expliqué ou non.
**J+1** : réunion de 30 minutes : incidents, blocages, ce qui ralentit.

### Retour en arrière
Tant que l'ancien fonctionnement papier est conservé pendant les 7 premiers jours, un retour est possible : les commandes saisies dans le système sont imprimées (fiche commande) et reportées sur papier ; les clients ont déjà leurs étiquettes. **Décider du seuil de retour avant J :** par exemple « plus de deux heures d'arrêt de la réception » ou « un écart de caisse inexpliqué supérieur à X FCFA deux jours de suite ».

## 4. Suivi renforcé (hypercare) : 30 jours

| Fréquence | Contrôle | Responsable |
|---|---|---|
| Matin | Alertes ouvertes, cockpit, sauvegarde de la nuit présente | administrateur |
| Chaque soir | Rapport journalier comparé à la caisse ; écarts de caisse ; commandes en retard | responsable d'agence |
| Chaque semaine | Rapport mensuel partiel ; commandes non retirées ; messages en échec ; refus d'accès anormaux dans l'audit ; restauration d'essai | administrateur + direction |
| Chaque semaine | Copie externe de la sauvegarde | administrateur |
| À J+7, J+15, J+30 | Point avec les équipes : anomalies, demandes d'évolution, formation complémentaire | direction |

**Tenir un registre des anomalies** (date, qui, ce qui s'est passé, gravité, correctif, date de correction) :
- **Bloquante** (on ne peut plus encaisser, recevoir ou livrer) : correctif le jour même, ou retour au papier pour l'opération.
- **Gênante** (contournement possible) : sous 3 jours.
- **Confort** : planifiée.

## 5. Bilan du pilote (modèle)

À remplir à J+30 pour décider : généraliser, corriger puis généraliser, ou arrêter.

| Indicateur | Objectif | Constaté |
|---|---|---|
| Temps moyen de saisie d'une commande de 3 vêtements | < 3 min | |
| Écarts de caisse (nombre, montant cumulé) | 0 inexpliqué | |
| Commandes en retard / total | | |
| Taux de reprise qualité | ≤ 3 % | |
| Commandes non retirées au-delà de 15 jours | | |
| Messages envoyés / en échec | échec < 2 % | |
| Livraisons avec preuve / total | 100 % | |
| Alertes traitées dans le délai | | |
| Incidents bloquants (nombre, durée) | 0 | |
| Sauvegardes de la nuit présentes | 30 / 30 | |
| Personnel qui travaille sans aide | tous | |

Questions à la direction : les chiffres du cockpit correspondent-ils à la réalité de terrain ? Les seuils d'alerte sont-ils les bons ? Quels écrans font perdre du temps ? Quelles évolutions avant d'ouvrir une deuxième agence ?

**Décision :** ☐ généraliser · ☐ corriger puis généraliser · ☐ arrêter. Signature de la direction : ______ Date : ______
