# Contrat d'API REST — ERP Pressing (v0, à raffiner en phase 1)

Base : `/api/v1` · JSON · auth Bearer (JWT court + refresh) · en-tête `X-Agency` pour les rôles multi-agences. Erreurs : `{ "code": "RG8_QUALITY_REQUIRED", "message": "…" }`. Les codes d'erreur reprennent les règles de gestion (RGn) pour les tests de recette.

## Auth & admin
- `POST /auth/login` · `POST /auth/refresh` · `POST /auth/logout`
- `GET/POST/PATCH /users` · `GET /roles` · `GET/PUT /settings/:key`

## Clients
- `GET /customers?q=` · `POST /customers` · `GET/PATCH /customers/:id`
- `PUT /customers/:id/consents` · `GET /customers/:id/history`

## Commandes & vêtements
- `POST /orders` (client + vêtements → numéro, codes, prix, 422 si RG2/RG1)
- `GET /orders/:id` · `POST /orders/:id/validate` · `POST /orders/:id/cancel` (motif)
- `GET /garments/:code` (scan) · `POST /garments/:id/photos`
- `GET /garments/:id/labels` (ZPL/PDF) · `POST /garments/:id/labels/reprint`
- `POST /pricing/quote` (aperçu de prix)

## Production
- `POST /garments/:id/steps/:step/start` · `/complete` · `/block` · `/redo`
- `POST /garments/:id/incidents`
- `GET /stations/:step/queue` (compteurs de pièces disponibles)
- `POST /quality/:garmentId/check` (résultat, motif, service)
- `POST /quality/:garmentId/override` (responsable)

## Alertes & notifications
- `GET /alerts?state=` · `POST /alerts/:id/ack`
- `GET /orders/at-risk` · `GET/PUT /alert-rules`
- `GET /orders/:id/notifications` · `GET/PUT /notification-templates`
- Flux : `GET /events` (SSE)

## Caisse
- `POST /cash/sessions/open` · `POST /cash/sessions/:id/close`
- `POST /payments` (méthodes, mixte = liste) · `POST /payments/:id/reverse`
- `POST /cash/disbursements` · `POST /discounts` (autorisation requise)
- Callbacks passerelle : `POST /webhooks/payments/:provider` (signature vérifiée)

## Commercial & recouvrement
- `GET/POST /quotes` · `/contracts` · `/invoices` · `/credit-notes`
- `GET /receivables/aged` · `GET /receivables/to-chase-today`
- `POST /receivables/:id/chase` · `POST /payments/:id/allocate`

## Marketing & fidélité
- `GET /segments` · `GET /segments/:code/customers`
- `GET/PUT /campaign-scenarios` · `GET /campaign-runs`

## Livraison
- `POST /deliveries` · `PATCH /deliveries/:id/assign`
- `POST /deliveries/:id/status` · `POST /deliveries/:id/proof` · `POST /deliveries/:id/collect-payment`

## Stocks
- `GET/POST /stock/items` · `POST /stock/movements` · `GET /stock/alerts`

## BI
- `GET /bi/cockpit?agency=&from=&to=` (inclut `computed_at`)
- `GET /bi/sales` · `/bi/customers` · `/bi/production` · `/bi/bottlenecks`
- `GET/PUT /objectives`

## Documents, recherche, audit
- `GET /documents/:type/:id` (PDF) · `GET /search?q=`
- `GET /audit?entity=&id=` (lecture seule)
