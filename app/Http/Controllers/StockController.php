<?php

// app/Http/Controllers/StockController.php

namespace App\Http\Controllers;

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
        $query = Stock::with(['produit.categorie', 'entrepot']);

        // Filtres
        if ($request->has('entrepot_id')) {
            $query->where('entrepot_id', $request->entrepot_id);
        }

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('produit_id')) {
            $query->where('produit_id', $request->produit_id);
        }

        // Recherche par nom de produit
        if ($request->has('search')) {
            $query->whereHas('produit', function($q) use ($request) {
                $q->where('nom', 'ILIKE', '%' . $request->search . '%')
                    ->orWhere('code', 'ILIKE', '%' . $request->search . '%');
            });
        }

        $stocks = $query->orderBy('date_entree', 'desc')->paginate(20);

        // Ajouter des informations calculées
        $stocks->getCollection()->transform(function($stock) {
            $stock->valeur = $stock->calculerValeur();
            $stock->etat_peremption = $stock->verifierPeremption();
            return $stock;
        });

        return response()->json($stocks);
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

            // Créer l'entrée de stock
            $stock = Stock::create([
                'produit_id' => $request->produit_id,
                'entrepot_id' => $request->entrepot_id,
                'quantite' => $request->quantite,
                'emplacement' => $request->emplacement,
                'date_entree' => now(),
                'numero_lot' => $request->numero_lot ?? 'LOT' . date('YmdHis') . rand(1000, 9999),
                'date_peremption' => $request->date_peremption,
                'statut' => 'Disponible'
            ]);

            // Recharger avec les relations
            $stock->load(['produit', 'entrepot']);

            return response()->json([
                'message' => 'Entrée de stock enregistrée avec succès',
                'stock' => $stock
            ], 201);

        } catch (\Exception $e) {
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
        $stock = Stock::with(['produit.categorie', 'entrepot'])->findOrFail($id);

        $stock->valeur = $stock->calculerValeur();
        $stock->etat_peremption = $stock->verifierPeremption();

        // Historique des mouvements pour ce lot
        $stock->historique = Stock::where('numero_lot', $stock->numero_lot)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($stock);
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
    }

    /**
     * Ajuster la quantité d'un stock
     */
    public function ajusterStock(Request $request, $id)
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

            $stock->ajusterQuantite($request->ajustement);

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
                'stock' => $stock,
                'ancienne_quantite' => $ancienneQuantite,
                'nouvelle_quantite' => $nouvelleQuantite
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur ajustement stock', [
                'message' => $e->getMessage(),
                'stock_id' => $id
            ]);

            return response()->json([
                'error' => 'Erreur lors de l\'ajustement',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ... (le reste des méthodes reste identique)

    /**
     * Supprimer un stock (soft delete ou réel selon besoin)
     */
    public function destroy($id)
    {
        $stock = Stock::findOrFail($id);

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
    }
}
