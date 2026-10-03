# Quickstart — scénario de validation bout en bout (pilote, phases 0–6)

Prérequis : base seedée (1 agence, rôles, tarifs standard, parcours par traitement, comptes de test par rôle).

1. **Réception** : se connecter (Réception) → créer le client « Test » → ajouter 3 vêtements dont un costume de valeur. Vérifier : blocage sans photo (RG2) ; avec photo → commande `PR-AAAA-000001`, codes `-01..-03`, 3 étiquettes, ticket.
2. **Notification** : SMS/WhatsApp de confirmation journalisé sur la commande.
3. **Production** : (Tri) scanner `-01` → démarrer → terminer ; le service suivant voit +1 pièce et reçoit l'alerte. Tenter de prendre en charge `-02` à l'étape 3 avant « Terminé » à l'étape 2 → refus (RG5).
4. **Incident** : déclarer « tache difficile » sur `-02` → statut Bloqué, alerte superviseur, escalade manager après le délai.
5. **Qualité** : contrôler `-01` « À reprendre » sans motif → refus ; avec motif et service → retour au service, alerte. Re-contrôler → Conforme → Emballage. Tenter « Prêt à livrer » sur `-03` non contrôlé → refus (RG8) ; avec dérogation responsable → accepté + trace.
6. **Caisse** : ouvrir la caisse → encaisser en mixte (espèces + Orange Money) → reçu. Fermer avec un écart simulé → alerte manager, clôture refusée sans motif (RG15).
7. **Audit** : appliquer une remise → consulter l'audit : ancienne/nouvelle valeur, motif, autorisation.
8. **RBAC** : avec le compte Production, accès à `/bi/cockpit` ⇒ 403 journalisé.

Critère de réussite : tous les contrôles passent sans anomalie bloquante.
