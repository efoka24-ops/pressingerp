<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AgencyRequestController;
use App\Controllers\AlertController;
use App\Controllers\AuthController;
use App\Controllers\BIController;
use App\Controllers\CashController;
use App\Controllers\DeliveryController;
use App\Controllers\ClientController;
use App\Controllers\CommercialController;
use App\Controllers\GarmentController;
use App\Controllers\HomeController;
use App\Controllers\LossController;
use App\Controllers\MarketingController;
use App\Controllers\OfflineController;
use App\Controllers\OrderController;
use App\Controllers\OverrideController;
use App\Controllers\PaymentController;
use App\Controllers\ProductionController;
use App\Controllers\QualityController;
use App\Controllers\StockController;
use App\Controllers\TariffController;
use App\Controllers\TraceController;
use App\Controllers\TrackingController;
use App\Core\Router;

$r = new Router();

// Authentification
$r->get('/login', [AuthController::class, 'form']);
$r->post('/login', [AuthController::class, 'login']);
$r->post('/logout', [AuthController::class, 'logout'], 'auth');
$r->get('/mot-de-passe', [AccountController::class, 'form'], 'auth');
$r->post('/mot-de-passe', [AccountController::class, 'change'], 'auth');
$r->post('/agence/changer', [AuthController::class, 'switchAgency'], 'auth');
$r->get('/ouvrir-un-pressing', [AgencyRequestController::class, 'form']);
$r->post('/ouvrir-un-pressing', [AgencyRequestController::class, 'submit']);
$r->get('/ouvrir-un-pressing/merci', [AgencyRequestController::class, 'thanks']);

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
$r->get('/commandes/non-retirees', [OrderController::class, 'uncollected'], 'orders');
$r->post('/commandes', [OrderController::class, 'store'], 'orders');
$r->post('/api/devis', [OrderController::class, 'quote'], 'orders');
$r->get('/commandes/{id}', [OrderController::class, 'show'], 'orders');
$r->get('/commandes/{id}/etiquettes', [OrderController::class, 'labels'], 'orders');
$r->get('/commandes/{id}/ticket', [OrderController::class, 'ticket'], 'orders');
$r->post('/commandes/{id}/paiement', [OrderController::class, 'pay'], 'orders');
$r->post('/commandes/{id}/paiement-mobile', [PaymentController::class, 'initiate'], 'orders');
$r->post('/commandes/{id}/remise', [OrderController::class, 'discount'], 'orders');
$r->post('/paiements/{id}/annuler', [PaymentController::class, 'reverse'], 'orders');
$r->get('/paiements/{id}/recu', [PaymentController::class, 'receipt'], 'orders');
$r->post('/paiement-mobile/{id}/confirmer', [PaymentController::class, 'manualConfirm'], 'orders');
$r->get('/caisse/{id}/etat', [CashController::class, 'statement'], 'cash');
$r->get('/livraisons', [DeliveryController::class, 'index'], 'delivery');
$r->get('/livraisons/collecte', [DeliveryController::class, 'newCollect'], 'delivery:create');
$r->post('/livraisons/collecte', [DeliveryController::class, 'createCollect'], 'delivery:create');
$r->get('/livraisons/{id}', [DeliveryController::class, 'show'], 'delivery');
$r->post('/livraisons/{id}/affecter', [DeliveryController::class, 'assign'], 'delivery:update');
$r->post('/livraisons/{id}/adresse', [DeliveryController::class, 'address'], 'delivery:update');
$r->post('/livraisons/{id}/collectee', [DeliveryController::class, 'collected'], 'delivery:update');
$r->post('/livraisons/{id}/depart', [DeliveryController::class, 'start'], 'delivery:update');
$r->post('/livraisons/{id}/livrer', [DeliveryController::class, 'complete'], 'delivery:update');
$r->post('/livraisons/{id}/echec', [DeliveryController::class, 'failed'], 'delivery:update');
$r->get('/caisse/{id}/cloture', [CashController::class, 'closeOtherForm'], 'cash:validate');
$r->post('/caisse/{id}/cloture', [CashController::class, 'closeOther'], 'cash:validate');
$r->get('/alertes', [AlertController::class, 'index'], 'auth');
$r->post('/alertes/{id}/prise-en-compte', [AlertController::class, 'acknowledge'], 'auth');
$r->get('/api/alertes/compte', [AlertController::class, 'count'], 'auth');
$r->webhook('/payments/webhook/sungku', [PaymentController::class, 'sungkuWebhook']);
$r->post('/commandes/{id}/retrait', [OrderController::class, 'pickup'], 'orders');
$r->post('/commandes/{id}/annuler', [OrderController::class, 'cancel'], 'orders:validate');

// 03 Traçabilité · 04 Production (scan atelier)
$r->get('/tracabilite', [TraceController::class, 'index'], 'trace');
$r->get('/production', [ProductionController::class, 'index'], 'production');
$r->get('/scan', [GarmentController::class, 'scan'], 'production');
$r->post('/pieces/{id}/action', [GarmentController::class, 'action'], 'production');
$r->post('/pieces/{id}/sinistre', [LossController::class, 'declare'], 'production');
$r->get('/qualite/derogations', [OverrideController::class, 'index'], 'quality');
$r->get('/qualite/sinistres', [LossController::class, 'index'], 'quality');
$r->post('/pieces/{id}/derogation', [OverrideController::class, 'grant'], 'quality:validate');
$r->post('/qualite/sinistres/{id}', [LossController::class, 'decide'], 'quality:validate');

// 05 Qualité
$r->get('/qualite', [QualityController::class, 'index'], 'quality');
$r->get('/qualite/controle/{id}', [QualityController::class, 'check'], 'quality');
$r->post('/qualite/controle/{id}', [QualityController::class, 'submit'], 'quality:validate');
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
$r->post('/commercial/contrats/{id}/resilier', [CommercialController::class, 'endContract'], 'commercial:validate');
$r->get('/commercial/factures', [CommercialController::class, 'invoices'], 'commercial');
$r->post('/commercial/factures/generer', [CommercialController::class, 'generate'], 'commercial');
$r->get('/commercial/factures/{id}', [CommercialController::class, 'invoice'], 'commercial');
$r->post('/commercial/factures/{id}/paiement', [CommercialController::class, 'payInvoice'], 'commercial');
$r->get('/recouvrement', [CommercialController::class, 'receivables'], 'commercial');
$r->post('/recouvrement/relance', [CommercialController::class, 'remind'], 'commercial');

// 08 Marketing & Fidélité
$r->get('/marketing', [MarketingController::class, 'index'], 'marketing');
$r->post('/marketing/campagnes', [MarketingController::class, 'store'], 'marketing');
$r->post('/marketing/campagnes/{id}/envoyer', [MarketingController::class, 'send'], 'marketing:validate');

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

// Administration (utilisateurs, agences, paramètres, audit, sauvegardes)
$r->get('/admin', [AdminController::class, 'index'], 'admin');
$r->get('/admin/utilisateurs', [AdminController::class, 'users'], 'admin');
$r->post('/admin/utilisateurs', [AdminController::class, 'createUser'], 'admin:create');
$r->post('/admin/utilisateurs/{id}', [AdminController::class, 'updateUser'], 'admin:update');
$r->post('/admin/utilisateurs/{id}/mot-de-passe', [AdminController::class, 'resetPassword'], 'admin:update');
$r->get('/admin/demandes', [AgencyRequestController::class, 'index'], 'admin');
$r->post('/admin/demandes/{id}/valider', [AgencyRequestController::class, 'approve'], 'admin:update');
$r->post('/admin/demandes/{id}/refuser', [AgencyRequestController::class, 'reject'], 'admin:update');
$r->get('/admin/agences', [AdminController::class, 'agencies'], 'admin');
$r->post('/admin/agences', [AdminController::class, 'saveAgency'], 'admin:create');
$r->post('/admin/agences/{id}', [AdminController::class, 'saveAgency'], 'admin:update');
$r->get('/admin/parametres', [AdminController::class, 'settings'], 'admin');
$r->post('/admin/parametres', [AdminController::class, 'saveSetting'], 'admin:update');
$r->get('/admin/audit', [AdminController::class, 'audit'], 'admin');
$r->get('/admin/sauvegardes', [AdminController::class, 'backups'], 'admin');
$r->get('/admin/parcours', [AdminController::class, 'routes'], 'admin');
$r->get('/admin/messages', [AdminController::class, 'messages'], 'admin');
$r->post('/admin/messages/modele', [AdminController::class, 'saveTemplate'], 'admin:update');
$r->post('/admin/messages/test-smtp', [AdminController::class, 'testSmtp'], 'admin:update');
$r->post('/admin/messages/test-envoi', [AdminController::class, 'testMail'], 'admin:update');
$r->get('/admin/alertes', [AdminController::class, 'alertRules'], 'admin');
$r->post('/admin/alertes', [AdminController::class, 'saveAlertRule'], 'admin:update');
$r->get('/admin/postes', [OfflineController::class, 'stations'], 'admin');
$r->post('/admin/postes/{id}', [OfflineController::class, 'deactivate'], 'admin:update');
$r->post('/admin/parcours', [AdminController::class, 'saveRoute'], 'admin:update');

// Tarifs
$r->get('/tarifs', [TariffController::class, 'index'], 'pricing');
$r->post('/tarifs/listes', [TariffController::class, 'createList'], 'pricing:create');
$r->post('/tarifs/articles', [TariffController::class, 'createArticle'], 'pricing:create');
$r->post('/tarifs/articles/{id}', [TariffController::class, 'updateArticle'], 'pricing:update');
$r->post('/tarifs/listes/{id}', [TariffController::class, 'toggleList'], 'pricing:update');
$r->post('/tarifs/prix', [TariffController::class, 'setPrice'], 'pricing:update');

// Réception hors-ligne
$r->get('/hors-ligne', [OfflineController::class, 'page'], 'orders');
$r->get('/api/hors-ligne/csrf', [OfflineController::class, 'csrf'], 'orders');
$r->post('/api/hors-ligne/poste', [OfflineController::class, 'register'], 'orders:validate');
$r->post('/api/hors-ligne/donnees', [OfflineController::class, 'data'], 'orders:create');
$r->post('/api/hors-ligne/commandes', [OfflineController::class, 'sync'], 'orders:create');

return $r;
