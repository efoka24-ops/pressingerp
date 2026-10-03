# Recherche et décisions — ERP Pressing

| Sujet | Décision | Alternatives écartées | Statut |
|---|---|---|---|
| Architecture | Monolithe modulaire + worker | Microservices (trop lourd), ERP du marché (Odoo : perd la finesse vêtement/QR) | Proposé |
| Terminal poste | PWA Android/Chrome | App native (publication, maintenance double) | Proposé |
| Scan | Caméra (BarcodeDetector / jsQR) + douchette Bluetooth HID | Scanner dédié obligatoire | Proposé |
| Étiquette | QR contenant le code court `PR-…` (pas d'URL) ; impression thermique ZPL/ESC-POS | QR avec URL signée (dépend du réseau) | Proposé |
| Hors-ligne | File locale (IndexedDB) pour la réception, blocs d'ID réservés | Offline total pour toute l'app (coût) | Proposé |
| Audit | Table AO + triggers + chaîne de hachage | Event sourcing complet (surdimensionné) | Proposé |
| Multi-agences | Base unique + `agency_id` + RLS | Une base par agence (consolidation lourde) | Proposé |
| Notifications | Outbox + BullMQ, fournisseur SMS local + WhatsApp Business API + SMTP | Appels directs dans la transaction | Proposé |
| Mobile Money | Via passerelle mutualisée `apisungku` | Intégration directe Orange/MTN par projet | À valider |
| KPI | Vues SQL + snapshots | Entrepôt séparé (non justifié en v1) | Proposé |
| Temps réel | SSE | WebSocket | Proposé |
| Numérotation facture | Séquence continue par agence/année, verrouillée à l'émission | Numéro libre | À valider (conformité) |

## Risques

1. **Adoption terrain** : le scan à chaque étape ne tient que si l'écran est ≤ 2 actions. Prévoir un pilote d'une agence avant extension.
2. **Réseau instable** : mitigé par hors-ligne réception ; la production reste en ligne en v1.
3. **Qualité des étiquettes** : humidité, chaleur, lavage ⇒ tester support et adhésif réels dès la phase 3.
4. **Fournisseurs de messages** : coûts, agrément WhatsApp, délais ; prévoir SMS en repli.
5. **Conformité** : facturation et données personnelles à valider avec un référent juridique (hypothèses non vérifiées).
6. **Périmètre** : 10 modules ; discipline MVP = phases 0–6.
