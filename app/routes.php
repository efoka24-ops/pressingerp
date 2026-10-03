<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\BIController;
use App\Controllers\CashController;
use App\Controllers\ClientController;
use App\Controllers\CommercialController;
use App\Controllers\GarmentController;
use App\Controllers\HomeController;
use App\Controllers\MarketingController;
use App\Controllers\OrderController;
use App\Controllers\ProductionController;
use App\Controllers\QualityController;
use App\Controllers\StockController;
use App\Controllers\TraceController;
use App\Controllers\TrackingController;
use App\Core\Router;

$r = new Router();

// Authentification
$r->get('/login', [AuthController::class, 'form']);
$r->post('/login', [AuthController::class, 'login']);
$r->post('/logout', [AuthController::class, 'logout'], 'auth');

// Accueils
$r->get('/', [HomeController::class, 'index'], 'auth');
$r->get('/comptoir', [HomeController::class, 'counter'], 'counter');
$r->get('/cockpit', [HomeController::class, 'cockpit'], 'cockpit');
$r->get('/recherche', [HomeController::class, 'search'], 'auth');

// 01 Clients / CRM
$r->get('/clients', [ClientController::class, 'index'], 'clients');
$r->get('/clients/nouveau', [ClientController::class, 'create'], 'clients');
$r->post('/clients', [ClientController::class, 'store'], 'clients');
$r->get('/clients/{id}', [ClientController::class, 'show'], 'clients');
$r->get('/clients/{id}/modifier', [ClientController::class, 'edit'], 'clients');
$r->post('/clients/{id}', [ClientController::class, 'update'], 'clients');
$r->get('/api/clients', [ClientController::class, 'lookup'], 'orders');

// 02 Commandes
$r->get('/commandes', [OrderController::class, 'index'], 'orders');
$r->get('/commandes/nouvelle', [OrderController::class, 'create'], 'orders');
$r->post('/commandes', [OrderController::class, 'store'], 'orders');
$r->post('/api/devis', [OrderController::class, 'quote'], 'orders');
$r->get('/commandes/{id}', [OrderController::class, 'show'], 'orders');
$r->get('/commandes/{id}/etiquettes', [OrderController::class, 'labels'], 'orders');
$r->post('/commandes/{id}/paiement', [OrderController::class, 'pay'], 'orders');
$r->post('/commandes/{id}/retrait', [OrderController::class, 'pickup'], 'orders');
$r->post('/commandes/{id}/annuler', [OrderController::class, 'cancel'], 'orders');

// 03 Traçabilité · 04 Production (scan atelier)
$r->get('/tracabilite', [TraceController::class, 'index'], 'trace');
$r->get('/production', [ProductionController::class, 'index'], 'production');
$r->get('/scan', [GarmentController::class, 'scan'], 'production');
$r->post('/pieces/{id}/action', [GarmentController::class, 'action'], 'production');

// 05 Qualité
$r->get('/qualite', [QualityController::class, 'index'], 'quality');
$r->get('/qualite/controle/{id}', [QualityController::class, 'check'], 'quality');
$r->post('/qualite/controle/{id}', [QualityController::class, 'submit'], 'quality');
$r->post('/qualite/reclamations', [QualityController::class, 'storeComplaint'], 'quality');
$r->post('/qualite/reclamations/{id}', [QualityController::class, 'updateComplaint'], 'quality');

// 06 Caisse & Finance
$r->get('/caisse', [CashController::class, 'index'], 'cash');
$r->post('/caisse/ouvrir', [CashController::class, 'open'], 'cash');
$r->get('/caisse/cloture', [CashController::class, 'closeForm'], 'cash');
$r->post('/caisse/cloture', [CashController::class, 'close'], 'cash');
$r->post('/caisse/depenses', [CashController::class, 'expense'], 'cash');

// 07 Commercial & Recouvrement
$r->get('/commercial', [CommercialController::class, 'contracts'], 'commercial');
$r->post('/commercial/contrats', [CommercialController::class, 'storeContract'], 'commercial');
$r->post('/commercial/contrats/{id}/resilier', [CommercialController::class, 'endContract'], 'commercial');
$r->get('/commercial/factures', [CommercialController::class, 'invoices'], 'commercial');
$r->post('/commercial/factures/generer', [CommercialController::class, 'generate'], 'commercial');
$r->get('/commercial/factures/{id}', [CommercialController::class, 'invoice'], 'commercial');
$r->post('/commercial/factures/{id}/paiement', [CommercialController::class, 'payInvoice'], 'commercial');
$r->get('/recouvrement', [CommercialController::class, 'receivables'], 'commercial');
$r->post('/recouvrement/relance', [CommercialController::class, 'remind'], 'commercial');

// 08 Marketing & Fidélité
$r->get('/marketing', [MarketingController::class, 'index'], 'marketing');
$r->post('/marketing/campagnes', [MarketingController::class, 'store'], 'marketing');
$r->post('/marketing/campagnes/{id}/envoyer', [MarketingController::class, 'send'], 'marketing');

// 09 Stocks
$r->get('/stocks', [StockController::class, 'index'], 'stock');
$r->post('/stocks/articles', [StockController::class, 'store'], 'stock');
$r->post('/stocks/mouvement', [StockController::class, 'move'], 'stock');
$r->post('/stocks/commande', [StockController::class, 'order'], 'stock');
$r->post('/stocks/commande/{id}/reception', [StockController::class, 'receive'], 'stock');

// 10 Business Intelligence
$r->get('/bi', [BIController::class, 'index'], 'bi');
$r->get('/bi/export', [BIController::class, 'export'], 'bi');

// Site public de suivi client
$r->get('/suivi', [TrackingController::class, 'lookup']);
$r->post('/suivi', [TrackingController::class, 'find']);
$r->get('/suivi/{token}', [TrackingController::class, 'show']);
$r->post('/suivi/{token}/livraison', [TrackingController::class, 'delivery']);

return $r;
