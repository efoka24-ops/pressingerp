# Recherche et décisions — ERP Pressing

| Sujet | Décision | Raison |
|---|---|---|
| Stack | **Conserver PHP 8.1 + MySQL 8** | Code existant fonctionnel, hébergement Camoo, cohérent avec GFC 2026 |
| Tâches asynchrones | cron + tables de file (`messages`, alertes) | Pas de worker sur mutualisé |
| Audit | Table append-only (triggers MySQL `BEFORE UPDATE/DELETE` → SIGNAL) + chaîne de hachage | Pas besoin d'event sourcing |
| QR | Bibliothèque JS locale (`public/assets/vendor/`) | Impression sans dépendre d'un CDN |
| Hors-ligne | Service worker + file locale pour la réception uniquement | Réseau instable, coût maîtrisé |
| Mobile Money | Passerelle `apisungku` (pawaPay) ; Orange et MTN seulement | Wave/Moov hors marché camerounais |
| Notifications | Interface `Gateway` (existe : `LogGateway`) → adaptateurs SMS/WhatsApp/SMTP | Déjà prévu dans `bin/send-messages.php` |
| Temps réel | Interrogation périodique (30 s) | Pas de SSE fiable sur mutualisé |
| Fuseau | `Africa/Douala` (UTC+1) | Le code utilise `Africa/Abidjan` (UTC+0) : décalage d'une heure |
| Migrations | Fichiers numérotés + table `schema_migrations` | `schema.sql` actuel fait `DROP TABLE` : dangereux en production |

## Risques
1. **Perte de données en production** : `database/schema.sql` supprime toutes les tables et `bin/install.php --demo` insère des données de démonstration. Ne jamais l'exécuter sur la base de l'hébergeur une fois utilisée.
2. **Données de démonstration ivoiriennes** (Abidjan, +225, Wave, Moov) : à remplacer.
3. **Comptes de démo** avec mot de passe public `pressing2026` : à supprimer avant toute mise en ligne.
4. **Hébergement mutualisé** : version de PHP, limites, cron et `mod_rewrite` à vérifier.
5. **Identifiants communiqués dans une conversation** : à considérer comme exposés ; changer les mots de passe FTP et base après mise en place.
6. Conformité (TVA, données personnelles) : hypothèses non vérifiées.
