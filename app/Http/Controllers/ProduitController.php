<?php

// app/Http/Controllers/ProduitController.php

namespace App\Http\Controllers;

use App\Models\Produit;
use App\Models\Alerte;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProduitController extends Controller
{
    public function index(Request $request)
    {
        $query = Produit::with(['categorie', 'stocks']);

        if ($request->has('categorie_id')) {
            $query->where('categorie_id', $request->categorie_id);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('nom', 'ILIKE', '%' . $request->search . '%')
                    ->orWhere('code', 'ILIKE', '%' . $request->search . '%');
            });
        }

        $produits = $query->paginate(20);

        // ✅ IMPORTANT: S'assurer que stock_total est calculé pour chaque produit
        $produits->getCollection()->transform(function($produit) {
            $produit->stock_total = $produit->stockTotal();
            return $produit;
        });

        return response()->json($produits);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:produits',
            'nom' => 'required|string|max:255',
            'description' => 'nullable|string',
            'categorie_id' => 'required|exists:categories,id',
            'prix_achat' => 'required|numeric|min:0',
            'prix_vente' => 'required|numeric|min:0',
            'seuil_minimum' => 'nullable|integer|min:0',
            'peremption' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $produit = Produit::create($request->all());

        return response()->json([
            'message' => 'Produit créé avec succès',
            'produit' => $produit->load('categorie')
        ], 201);
    }

    public function show($id)
    {
        $produit = Produit::with(['categorie', 'stocks.entrepot'])->findOrFail($id);
        $produit->stock_total = $produit->stockTotal();
        $produit->marge = $produit->calculerMarge();

        return response()->json($produit);
    }

    public function update(Request $request, $id)
    {
        $produit = Produit::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'string|unique:produits,code,' . $id,
            'nom' => 'string|max:255',
            'prix_achat' => 'numeric|min:0',
            'prix_vente' => 'numeric|min:0',
            'seuil_minimum' => 'integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $produit->update($request->all());

        return response()->json([
            'message' => 'Produit mis à jour avec succès',
            'produit' => $produit->load('categorie')
        ]);
    }

    public function destroy($id)
    {
        $produit = Produit::findOrFail($id);

        if ($produit->stocks()->count() > 0) {
            return response()->json([
                'error' => 'Impossible de supprimer un produit avec du stock'
            ], 400);
        }

        $produit->delete();

        return response()->json([
            'message' => 'Produit supprimé avec succès'
        ]);
    }

    public function verifierStock()
    {
        $produitsEnRupture = Produit::whereHas('stocks', function($query) {
            $query->where('statut', 'Disponible');
        }, '=', 0)->get();

        $produitsFaibleStock = Produit::all()->filter(function($produit) {
            return $produit->stockTotal() < $produit->seuil_minimum && $produit->stockTotal() > 0;
        });

        foreach ($produitsEnRupture as $produit) {
            Alerte::firstOrCreate([
                'type' => 'Rupture',
                'produit_id' => $produit->id,
                'message' => "Le produit {$produit->nom} est en rupture de stock",
                'lue' => false
            ]);
        }

        foreach ($produitsFaibleStock as $produit) {
            Alerte::firstOrCreate([
                'type' => 'StockFaible',
                'produit_id' => $produit->id,
                'message' => "Le produit {$produit->nom} a un stock faible ({$produit->stockTotal()} unités)",
                'lue' => false
            ]);
        }

        return response()->json([
            'rupture' => $produitsEnRupture,
            'faible_stock' => $produitsFaibleStock->values()
        ]);
    }
}
