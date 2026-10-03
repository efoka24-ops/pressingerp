> Remplacé par `plan.md` : le schéma réel est `database/schema.sql` (MySQL). Ce modèle reste une cible conceptuelle.

# Modèle de données — ERP Pressing

Toutes les tables métier portent `agency_id`, `created_at`, `created_by`. Les tables marquées **AO** sont append-only (pas de UPDATE/DELETE, droits DB retirés). Montants en entiers FCFA.

## Socle
- `agency` (id, code, name, city)
- `user` (id, agency_ids[], name, phone, email, password_hash, active)
- `role` (id, code) · `permission` (resource, action) · `role_permission` · `user_role`
- `setting` (key, agency_id?, value_json, version, valid_from) — **versionné**
- `audit_log` **AO** (id, at, user_id, agency_id, entity, entity_id, action, old_json, new_json, reason, prev_hash, hash)
- `outbox` (id, type, payload, status, attempts, next_try_at) — événements à diffuser

## Clients
- `customer` (id, number, type[individual|business|anonymous], name, phone, whatsapp, email, address, district, city, source, registered_at, birthday?)
- `customer_consent` **AO** (customer_id, channel, purpose[operational|marketing], granted, at)
- `business_account` (customer_id, credit_limit, payment_terms_days, contract_id, periodic_billing)
- `v_customer_stats` (vue : CA cumulé, nb commandes, panier moyen, dernière visite, fréquence)
- `v_customer_segment` (vue/job : segment selon seuils `setting`)

## Catalogue & tarifs
- `garment_type` (id, category, label, fragile_default)
- `treatment` (id, label) · `treatment_route` (treatment_id, step_code, position)
- `service_level` (standard|express|vip, surcharge_rule)
- `price_list` (id, kind[standard|vip|business|promo|agency], agency_id?, customer_id?, valid_from, valid_to, version)
- `price_item` (price_list_id, garment_type_id, treatment_id, amount)

## Commandes & vêtements
- `order` (id, number `PR-AAAA-NNNNNN`, customer_id, service_level, deposited_at, promised_at, total, state)
- `garment` (id, code `...-NN`, order_id, type_id, brand, color, material, qty, damages, requested_treatment_id, remarks, price, photo_required, state, current_step, current_owner_id, temp_code?)
- `garment_photo` (garment_id, url, taken_by, at)
- `label_print` **AO** (garment_id, printed_at, by, reprint_reason)
- `id_block` (station_id, year, from_seq, to_seq) — plages hors-ligne

## Production
- `garment_step` (garment_id, step_code, status[todo|in_progress|done|blocked|redo], started_at, done_at, owner_id)
- `garment_event` **AO** (garment_id, at, user_id, from_step, to_step, status, note) — historique de traçabilité
- `incident` (id, garment_id, type[9 valeurs], severity, description, state, raised_by)
- `quality_check` (id, garment_id, controller_id, result, service_to_redo, reason, at)
- `quality_check_item` (check_id, criterion, ok)
- `quality_override` **AO** (garment_id, authorised_by, reason, at)

## Alertes & notifications
- `alert_rule` (event, priority, target_role/service, delay_minutes, escalate_to, escalate_after)
- `alert` (id, rule_id, subject_type, subject_id, level, state[open|ack|closed], opened_at, escalated_at)
- `notification_template` (event, channel, language, body)
- `notification` **AO** (id, order_id, customer_id, event, channel, status, attempt, provider_ref, at)

## Caisse & paiements
- `cash_session` (id, agency_id, opened_by, opened_at, closed_by, closed_at, opening_float, expected_total, counted_total, variance, variance_reason)
- `payment` **AO** (id, order_id|invoice_id, method, amount, session_id, ref, at, kind[payment|refund|reversal], reverses_id?)
- `cash_movement` **AO** (session_id, kind[disbursement|discount], amount, authorised_by, reason)
- `v_order_balance` (vue : total − Σ paiements)

## Commercial & recouvrement
- `prospect` · `quote` · `contract` (customer_id, terms, negotiated_price_list_id)
- `invoice` (id, number continu, customer_id, issue_date, due_date, total, tax) · `invoice_line`
- `credit_note` (invoice_id, amount, reason)
- `collection_action` **AO** (invoice_id|customer_id, channel, at, by, outcome, promise_date)
- `v_aged_balance` (vue : non échu, 1–30, 31–60, 61–90, >90 par client)
- `v_customer_exposure` (encours vs plafond)

## Marketing
- `campaign_scenario` (code, condition_json, action_json, active)
- `campaign_run` **AO** (scenario_id, customer_id, at, outcome)
- `loyalty_account` (customer_id, points/avantage) · `loyalty_movement` **AO**

## Stocks
- `stock_item` (id, agency_id, category, unit, min_qty, alert_threshold, unit_cost, supplier_id)
- `stock_movement` **AO** (item_id, qty, kind[in|out|adjust], at, by, ref)
- `v_stock_level`

## Livraison
- `delivery_request` (id, order_id, kind[collect|deliver], address, phone, driver_id, slot, state, amount_due)
- `delivery_proof` **AO** (delivery_id, kind[signature|photo|code], data_ref, at)

## BI
- `objective` (kind, period, target, agency_id)
- `kpi_snapshot` (kpi, period, agency_id, value, computed_at)
- `v_kpi_*` — vues source ; `kpi_snapshot` reconstruisible.

## États

**Étape** : todo → in_progress → done ; in_progress → blocked → in_progress ; done → redo (par qualité).
**Vêtement** : received → in_production → quality_passed → packed → ready → [out_for_delivery →] delivered | picked_up ; `cancelled` terminal. `ready` exige `quality_passed` ou `quality_override`.
**Livraison** : to_collect → collected → processing → to_deliver → on_the_way → delivered | not_delivered (→ retry).

## Contraintes clés
- `UNIQUE(order.number)`, `UNIQUE(garment.code)`.
- `CHECK` : `garment.state='ready'` ⇒ présence de `quality_check(result='ok')` ou `quality_override`.
- `CHECK` : `delivery.state='delivered'` ⇒ existe `delivery_proof`.
- RLS : `agency_id = ANY(current_user_agencies())` sauf rôle consolidé.
