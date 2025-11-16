<?php

// routes/api.php

use App\Http\Controllers\DemandeApprovisionnementController;
use App\Http\Controllers\FactureFournisseurController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\EntrepotController;
use App\Http\Controllers\VenteController;
use App\Http\Controllers\CommandeAchatController;
use App\Http\Controllers\FournisseurController;
use App\Http\Controllers\LivraisonController;
use App\Http\Controllers\FactureController;
use App\Http\Controllers\PaiementController;
use App\Http\Controllers\AlerteController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\BilanFinancierController;

/*
|--------------------------------------------------------------------------
| Routes Publiques
|--------------------------------------------------------------------------
*/
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Routes Protégées
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->group(function () {

    // ===== AUTHENTIFICATION =====
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    // Routes de profil
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

    // ===== UTILISATEURS (Administrateur) =====
    Route::apiResource('users', UserController::class);
    Route::post('/users/{id}/toggle-active', [UserController::class, 'toggleActive']);
    Route::post('/users/{id}/assign-role', [UserController::class, 'assignRole']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::get('/users/stats/global', [UserController::class, 'statistiques']);

    // ===== CLIENTS =====
    Route::apiResource('clients', ClientController::class);
    Route::get('/clients/{id}/historique', [ClientController::class, 'historique']);

    // ===== CATÉGORIES =====
    Route::apiResource('categories', CategorieController::class);

    // ===== PRODUITS =====
    Route::apiResource('produits', ProduitController::class);
    Route::get('/produits/verifier/stock', [ProduitController::class, 'verifierStock']);

    // ===== ENTREPÔTS =====
    Route::apiResource('entrepots', EntrepotController::class);

    // ===== STOCKS =====
    Route::apiResource('stocks', StockController::class);
    Route::post('/stocks/{id}/ajuster', [StockController::class, 'ajusterStock']);
    Route::get('/stocks/verifier/peremptions', [StockController::class, 'verifierPeremptions']);
    Route::get('/stocks/inventaire/complet', [StockController::class, 'inventaire']);
    Route::get('/stocks/tracer/{produitId}', [StockController::class, 'tracerProduit']);
    Route::get('/stocks/mouvements/periode', [StockController::class, 'mouvementsPeriode']);

    // ===== VENTES =====
    Route::apiResource('ventes', VenteController::class);
    Route::post('/ventes/{id}/valider', [VenteController::class, 'valider']);
    Route::post('/ventes/{id}/annuler', [VenteController::class, 'annuler']);
    Route::get('/ventes/statistiques/analyse', [VenteController::class, 'statistiques']);

    // ===== COMMANDES D'ACHAT =====
    Route::apiResource('commandes-achat', CommandeAchatController::class);
    Route::post('/commandes-achat/{id}/valider', [CommandeAchatController::class, 'valider']);
    Route::post('/commandes-achat/{id}/receptionner', [CommandeAchatController::class, 'receptionner']);
    Route::post('/commandes-achat/{id}/annuler', [CommandeAchatController::class, 'annuler']);
    Route::put('/commandes-achat/{id}', [CommandeAchatController::class, 'update']);
    Route::delete('/commandes-achat/{id}', [CommandeAchatController::class, 'destroy']);
    // ===== FOURNISSEURS =====
    Route::apiResource('fournisseurs', FournisseurController::class);
    Route::get('/fournisseurs/{id}/historique', [FournisseurController::class, 'historique']);
    Route::post('/fournisseurs/{id}/evaluer', [FournisseurController::class, 'evaluer']);
    Route::get('/fournisseurs/top/meilleurs', [FournisseurController::class, 'topFournisseurs']);
    Route::get('/fournisseurs/recherche/avancee', [FournisseurController::class, 'rechercher']);

    // ===== LIVRAISONS =====
    Route::apiResource('livraisons', LivraisonController::class);
    Route::post('/livraisons/{id}/demarrer', [LivraisonController::class, 'demarrer']);
    Route::post('/livraisons/{id}/confirmer', [LivraisonController::class, 'confirmer']);
    Route::post('/livraisons/{id}/annuler', [LivraisonController::class, 'annuler']);
    Route::get('/livraisons/aujourd-hui/liste', [LivraisonController::class, 'aujourdhui']);
    Route::get('/livraisons/statistiques/analyse', [LivraisonController::class, 'statistiques']);

    // ===== FACTURES =====
    Route::apiResource('factures', FactureController::class);
    Route::get('/factures/impayees/liste', [FactureController::class, 'impayees']);
    Route::get('/factures/echues/liste', [FactureController::class, 'echues']);
    Route::get('/factures/{id}/generer-pdf', [FactureController::class, 'genererPDF']);
    Route::post('/factures/{id}/envoyer', [FactureController::class, 'envoyer']);
    Route::get('/factures/statistiques/analyse', [FactureController::class, 'statistiques']);

    // ===== PAIEMENTS =====
    Route::apiResource('paiements', PaiementController::class);
    Route::get('/paiements/statistiques/analyse', [PaiementController::class, 'statistiques']);

    // ===== ALERTES =====
    Route::get('alertes', [AlerteController::class, 'index']);
    Route::get('alertes/non-lues/count', [AlerteController::class, 'getNonLuesCount']); // NOUVELLE ROUTE
    Route::post('alertes/{id}/lire', [AlerteController::class, 'marquerLue']);
    Route::post('alertes/tout-lire', [AlerteController::class, 'marquerToutesLues']); // NOUVELLE ROUTE
    Route::delete('alertes/{id}', [AlerteController::class, 'destroy']);

    // ===== RAPPORTS =====
    Route::get('/rapports/dashboard', [RapportController::class, 'dashboard']);
    Route::get('/rapports/financier', [RapportController::class, 'rapportFinancier']);
    Route::get('/rapports/stocks', [RapportController::class, 'rapportStocks']);
    Route::get('/rapports/ventes', [RapportController::class, 'rapportVentes']);
    Route::get('/rapports/performances', [RapportController::class, 'analysePerformances']);

    // ===== BILANS FINANCIERS =====
    Route::apiResource('bilans', BilanFinancierController::class)->only(['index', 'show']);
    Route::post('/bilans/generer', [BilanFinancierController::class, 'genererBilan']);
    Route::get('/bilans/tresorerie/etat', [BilanFinancierController::class, 'etatTresorerie']);
    Route::get('/bilans/compte-resultat', [BilanFinancierController::class, 'compteResultat']);
    Route::get('/bilans/bilan-comptable', [BilanFinancierController::class, 'bilanComptable']);
    Route::get('/bilans/dashboard-financier', [BilanFinancierController::class, 'dashboardFinancier']);

    // ===== FACTURES FOURNISSEURS =====
    Route::apiResource('factures-fournisseurs', FactureFournisseurController::class);
    Route::get('/factures-fournisseurs/impayees/liste', [FactureFournisseurController::class, 'impayees']);
    Route::get('/factures-fournisseurs/{id}/generer-pdf', [FactureFournisseurController::class, 'genererPDF']);

    Route::apiResource('demandes-approvisionnement', DemandeApprovisionnementController::class);
    Route::post('/demandes-approvisionnement/{id}/envoyer', [DemandeApprovisionnementController::class, 'envoyer']);
    Route::post('/demandes-approvisionnement/{id}/prendre-en-charge', [DemandeApprovisionnementController::class, 'prendrEnCharge']);
    Route::post('/demandes-approvisionnement/{id}/traiter', [DemandeApprovisionnementController::class, 'traiter']);
    Route::post('/demandes-approvisionnement/{id}/rejeter', [DemandeApprovisionnementController::class, 'rejeter']);
    Route::post('/demandes-approvisionnement/{id}/annuler', [DemandeApprovisionnementController::class, 'annuler']);
    Route::get('/demandes-approvisionnement/agents/liste', [DemandeApprovisionnementController::class, 'getAgents']);
    Route::get('/demandes-approvisionnement/stats/global', [DemandeApprovisionnementController::class, 'statistiques']);
});
