<?php

// app/Http/Controllers/StockController.php

namespace App\Http\Controllers;

use App\Models\MouvementStock;
use App\Models\Stock;
use App\Models\Produit;
use App\Models\Entrepot;
use App\Models\Alerte;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StockController extends Controller
{
    /**
     * Afficher la liste des stocks
     */
    public function index(Request $request)
    {
        try {
            $query = Stock::with(['produit.categorie', 'entrepot']);

            // Filtres
            if ($request->has('entrepot_id') && $request->entrepot_id) {
                $query->where('entrepot_id', $request->entrepot_id);
            }

            if ($request->has('statut') && $request->statut) {
                $query->where('statut', $request->statut);
            }

            if ($request->has('produit_id') && $request->produit_id) {
                $query->where('produit_id', $request->produit_id);
            }

            // Recherche par nom de produit
            if ($request->has('search') && $request->search) {
                $query->whereHas('produit', function($q) use ($request) {
                    $q->where('nom', 'ILIKE', '%' . $request->search . '%')
                        ->orWhere('code', 'ILIKE', '%' . $request->search . '%');
                });
            }

            $perPage = $request->input('per_page', 20);
            $stocks = $query->orderBy('date_entree', 'desc')->paginate($perPage);

            // Ajouter des informations calculées de manière sécurisée
            $stocks->getCollection()->transform(function($stock) {
                try {
                    $stock->valeur = $stock->calculerValeur();
                    $stock->etat_peremption = $stock->verifierPeremption();
                } catch (\Exception $e) {
                    Log::warning('Erreur calcul stock', [
                        'stock_id' => $stock->id,
                        'error' => $e->getMessage()
                    ]);
                    $stock->valeur = 0;
                    $stock->etat_peremption = 'ok';
                }
                return $stock;
            });

            return response()->json($stocks);

        } catch (\Exception $e) {
            Log::error('Erreur index stocks', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'error' => 'Erreur lors du chargement des stocks',
                'message' => $e->getMessage(),
                'data' => [],
                'total' => 0
            ], 500);
        }
    }

    /**
     * Créer une nouvelle entrée de stock
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'produit_id' => 'required|exists:produits,id',
            'entrepot_id' => 'required|exists:entrepots,id',
            'quantite' => 'required|integer|min:1',
            'emplacement' => 'required|string|max:255',
            'numero_lot' => 'nullable|string|max:255',
            'date_peremption' => 'nullable|date|after:today'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Vérifier la capacité de l'entrepôt
            $entrepot = Entrepot::findOrFail($request->entrepot_id);
            $capaciteDisponible = $entrepot->verifierCapacite();

            if ($capaciteDisponible < $request->quantite) {
                return response()->json([
                    'error' => 'Capacité de l\'entrepôt insuffisante',
                    'capacite_disponible' => $capaciteDisponible,
                    'quantite_demandee' => $request->quantite
                ], 400);
            }

            // Générer le numéro de lot si non fourni
            $numeroLot = $request->numero_lot ?? 'LOT' . date('YmdHis') . rand(1000, 9999);

            // Créer l'entrée de stock
            $stock = Stock::create([
                'produit_id' => $request->produit_id,
                'entrepot_id' => $request->entrepot_id,
                'quantite' => $request->quantite,
                'emplacement' => $request->emplacement,
                'date_entree' => now(),
                'numero_lot' => $numeroLot,
                'date_peremption' => $request->date_peremption,
                'statut' => 'Disponible'
            ]);

            // Créer le mouvement d'entrée
            MouvementStock::create([
                'type' => 'Entrée',
                'stock_id' => $stock->id,
                'produit_id' => $stock->produit_id,
                'entrepot_id' => $stock->entrepot_id,
                'quantite' => $stock->quantite,
                'numero_lot' => $stock->numero_lot,
                'motif' => 'Entrée manuelle de stock',
                'reference_type' => 'Stock',
                'reference_id' => $stock->id,
                'user_id' => auth()->id(),
                'date' => now()
            ]);

            DB::commit();

            // Recharger avec les relations
            $stock->load(['produit', 'entrepot']);

            return response()->json([
                'message' => 'Entrée de stock enregistrée avec succès',
                'stock' => $stock
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erreur création stock', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'error' => 'Erreur lors de l\'enregistrement du stock',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher un stock spécifique
     */
    public function show($id)
    {
        try {
            $stock = Stock::with(['produit.categorie', 'entrepot'])->findOrFail($id);

            $stock->valeur = $stock->calculerValeur();
            $stock->etat_peremption = $stock->verifierPeremption();

            // Historique des mouvements pour ce lot
            $stock->historique = MouvementStock::where('numero_lot', $stock->numero_lot)
                ->with(['produit', 'entrepot', 'user'])
                ->orderBy('date', 'desc')
                ->get();

            return response()->json($stock);

        } catch (\Exception $e) {
            Log::error('Erreur show stock', [
                'stock_id' => $id,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Erreur lors du chargement du stock',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour un stock
     */
    public function update(Request $request, $id)
    {
        $stock = Stock::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'quantite' => 'sometimes|integer|min:0',
            'emplacement' => 'sometimes|string|max:255',
            'statut' => 'sometimes|in:Disponible,Réservé,Périmé,Endommagé',
            'date_peremption' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        try {
            $stock->update($request->only([
                'quantite',
                'emplacement',
                'statut',
                'date_peremption'
            ]));

            return response()->json([
                'message' => 'Stock mis à jour avec succès',
                'stock' => $stock->load(['produit', 'entrepot'])
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur update stock', [
                'stock_id' => $id,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Erreur lors de la mise à jour',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ajuster la quantité d'un stock
     */
    public function ajuster(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'ajustement' => 'required|integer',
            'motif' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $stock = Stock::findOrFail($id);
            $ancienneQuantite = $stock->quantite;
            $nouvelleQuantite = $ancienneQuantite + $request->ajustement;

            if ($nouvelleQuantite < 0) {
                return response()->json([
                    'error' => 'La quantité ne peut pas être négative',
                    'quantite_actuelle' => $ancienneQuantite,
                    'ajustement_demande' => $request->ajustement
                ], 400);
            }

            // Ajuster la quantité
            $stock->ajusterQuantite(
                $request->ajustement,
                $request->motif ?? 'Ajustement manuel',
                'Ajustement',
                null
            );

            DB::commit();

            // Log de l'ajustement
            Log::info('Ajustement de stock', [
                'stock_id' => $stock->id,
                'produit' => $stock->produit->nom,
                'ancienne_quantite' => $ancienneQuantite,
                'nouvelle_quantite' => $nouvelleQuantite,
                'ajustement' => $request->ajustement,
                'motif' => $request->motif,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Ajustement de stock effectué avec succès',
                'stock' => $stock->fresh(['produit', 'entrepot']),
                'ancienne_quantite' => $ancienneQuantite,
                'nouvelle_quantite' => $nouvelleQuantite
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erreur ajustement stock', [
                'stock_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Erreur lors de l\'ajustement',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer un stock
     */
    public function destroy($id)
    {
        try {
            $stock = Stock::findOrFail($id);

            // Autoriser la suppression seulement si quantité = 0 OU statut n'est pas Disponible
            if ($stock->quantite > 0 && $stock->statut == 'Disponible') {
                return response()->json([
                    'error' => 'Impossible de supprimer un stock disponible avec des quantités',
                    'suggestion' => 'Ajustez d\'abord la quantité à 0 ou changez le statut'
                ], 400);
            }

            $stock->delete();

            return response()->json([
                'message' => 'Stock supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression stock', [
                'stock_id' => $id,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Erreur lors de la suppression',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifier les produits en péremption
     */
    public function verifierPeremptions()
    {
        try {
            $stocksProchesPeremption = Stock::expirationProche(7)
                ->with(['produit', 'entrepot'])
                ->get();

            $stocksExpires = Stock::where('date_peremption', '<', Carbon::now())
                ->where('statut', '!=', 'Périmé')
                ->with(['produit', 'entrepot'])
                ->get();

            // Mettre à jour les stocks expirés
            foreach ($stocksExpires as $stock) {
                $stock->statut = 'Périmé';
                $stock->save();

                // Créer une alerte
                Alerte::firstOrCreate([
                    'type' => 'Péremption',
                    'produit_id' => $stock->produit_id,
                    'message' => "Le lot {$stock->numero_lot} du produit {$stock->produit->nom} est périmé",
                    'lue' => false
                ]);
            }

            // Créer des alertes pour les stocks proches de la péremption
            foreach ($stocksProchesPeremption as $stock) {
                $joursRestants = Carbon::now()->diffInDays($stock->date_peremption);
                Alerte::firstOrCreate([
                    'type' => 'Péremption',
                    'produit_id' => $stock->produit_id,
                    'message' => "Le lot {$stock->numero_lot} du produit {$stock->produit->nom} expire dans {$joursRestants} jour(s)",
                    'lue' => false
                ]);
            }

            return response()->json([
                'expires' => $stocksExpires->count(),
                'proche_expiration' => $stocksProchesPeremption->count(),
                'details' => [
                    'expires' => $stocksExpires,
                    'proche_expiration' => $stocksProchesPeremption
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur vérification péremptions', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Erreur lors de la vérification',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Inventaire complet
     */
    public function inventaire(Request $request)
    {
        try {
            $query = Stock::with(['produit.categorie', 'entrepot'])
                ->where('statut', 'Disponible');

            if ($request->has('entrepot_id') && $request->entrepot_id) {
                $query->where('entrepot_id', $request->entrepot_id);
            }

            $stocks = $query->get();

            // Statistiques globales
            $totalProduits = $stocks->groupBy('produit_id')->count();
            $quantiteTotale = $stocks->sum('quantite');
            $valeurTotale = $stocks->sum(function($stock) {
                return $stock->calculerValeur();
            });

            // Par entrepôt
            $parEntrepot = $stocks->groupBy('entrepot_id')->map(function($items) {
                return [
                    'entrepot' => $items->first()->entrepot->nom,
                    'nombre_produits' => $items->groupBy('produit_id')->count(),
                    'quantite_totale' => $items->sum('quantite'),
                    'valeur_totale' => $items->sum(function($stock) {
                        return $stock->calculerValeur();
                    })
                ];
            })->values();

            // Par catégorie
            $parCategorie = $stocks->groupBy(function($stock) {
                return $stock->produit->categorie_id;
            })->map(function($items) {
                return [
                    'categorie' => $items->first()->produit->categorie->nom,
                    'nombre_produits' => $items->groupBy('produit_id')->count(),
                    'quantite_totale' => $items->sum('quantite'),
                    'valeur_totale' => $items->sum(function($stock) {
                        return $stock->calculerValeur();
                    })
                ];
            })->values();

            return response()->json([
                'total_produits' => $totalProduits,
                'quantite_totale' => $quantiteTotale,
                'valeur_totale' => $valeurTotale,
                'par_entrepot' => $parEntrepot,
                'par_categorie' => $parCategorie
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur inventaire', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Erreur lors de la génération de l\'inventaire',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tracer un produit (historique complet)
     */
    public function tracer($produitId)
    {
        try {
            $produit = Produit::findOrFail($produitId);

            $stocks = Stock::where('produit_id', $produitId)
                ->with(['entrepot'])
                ->orderBy('date_entree', 'desc')
                ->get();

            $mouvements = MouvementStock::where('produit_id', $produitId)
                ->with(['entrepot', 'user'])
                ->orderBy('date', 'desc')
                ->get();

            $ventes = \App\Models\DetailVente::where('produit_id', $produitId)
                ->with(['vente.client'])
                ->orderBy('created_at', 'desc')
                ->get();

            $commandesAchat = \App\Models\DetailCommandeAchat::where('produit_id', $produitId)
                ->with(['commandeAchat.fournisseur'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'produit' => $produit,
                'stock_actuel' => $produit->stockTotal(),
                'stocks' => $stocks,
                'mouvements' => $mouvements,
                'ventes' => $ventes,
                'commandes_achat' => $commandesAchat
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur traçabilité', [
                'produit_id' => $produitId,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Erreur lors de la traçabilité',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mouvements de stock pour une période
     */
    public function mouvementsPeriode(Request $request)
    {
        try {
            $dateDebut = $request->input('date_debut', Carbon::now()->startOfMonth());
            $dateFin = $request->input('date_fin', Carbon::now()->endOfMonth());

            // Convertir les dates pour inclure toute la journée
            $dateDebut = Carbon::parse($dateDebut)->startOfDay();
            $dateFin = Carbon::parse($dateFin)->endOfDay();

            // Récupérer les mouvements
            $mouvements = MouvementStock::with(['produit', 'entrepot', 'user'])
                ->whereBetween('date', [$dateDebut, $dateFin])
                ->orderBy('date', 'desc')
                ->get();

            // Formater les données
            $mouvementsFormates = $mouvements->map(function($mouvement) {
                return [
                    'id' => $mouvement->id,
                    'date' => $mouvement->date,
                    'type' => $mouvement->type,
                    'produit' => $mouvement->produit,
                    'entrepot' => $mouvement->entrepot,
                    'numero_lot' => $mouvement->numero_lot,
                    'quantite' => $mouvement->quantite,
                    'motif' => $mouvement->motif,
                    'reference_type' => $mouvement->reference_type,
                    'reference_id' => $mouvement->reference_id,
                    'user' => $mouvement->user ? [
                        'id' => $mouvement->user->id,
                        'nom' => $mouvement->user->nom,
                        'prenom' => $mouvement->user->prenom
                    ] : null
                ];
            });

            return response()->json($mouvementsFormates);

        } catch (\Exception $e) {
            Log::error('Erreur mouvements période', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Erreur lors du chargement des mouvements',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
