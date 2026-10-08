# Checklist de mise en production (tâches T015 à T017)

Ces trois actions dépendent de vous (accès hébergeur, documents, décision). À terminer avant la bascule décrite dans `bascule-et-hypercare.md`.

## T017 — HTTPS (à faire en premier)
- [ ] Panneau Camoo : activer AutoSSL / Let's Encrypt pour `pressing-erp.trugroup.cm`, ou placer Cloudflare (gratuit) devant le domaine.
- [ ] Vérifier que `https://pressing-erp.trugroup.cm` répond sans alerte de certificat.
- [ ] Rediriger http → https.
- [ ] Contrôler : le service worker s'enregistre (mode hors-ligne), le webhook Sungku est joignable en https, le cookie de session est « secure ».

## T015 — Secrets exposés
- [ ] Changer le mot de passe FTP dans le panneau Camoo.
- [ ] Changer le mot de passe de l'utilisateur de la base MySQL.
- [ ] Mettre à jour `ops/.deploy.env` et `config/config.local.php` (fichiers ignorés par git).
- [ ] Lancer `ops/deploy.sh config`.
- [ ] Vérifier la connexion à l'application et une sauvegarde de test.

## T016 — Conformité et sources
- [ ] Faire relire `docs/conformite.md` par la personne compétente et consigner l'avis.
- [ ] Copier les documents sources (aujourd'hui seulement dans la conversation) dans `docs/sources/`.

## Contrôle final
- [ ] Rejouer le cahier de recette (`recette.md`) sur l'environnement de production.
- [ ] Cocher T015, T016 et T017 dans `specs/001-erp-pressing/tasks.md`.
