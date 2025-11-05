<?php

// app/Http/Controllers/VenteController.php

namespace App\Http\Controllers;

use App\Models\Vente;
use App\Models\DetailVente;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class VenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Vente::with(['client', 'user', 'detailVentes.produit']);

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->whereBetween('date_vente', [$request->date_debut, $request->date_fin]);
        }

        $ventes = $query->orderBy('date_vente', 'desc')->paginate(20);

        return response()->json($ventes);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'date_vente' => 'required|date',
            'remise' => 'nullable|numeric|min:0',
            'produits' => 'required|array|min:1',
            'produits.*.produit_id' => 'required|exists:produits,id',
            'produits.*.quantite' => 'required|integer|min:1',
            'produits.*.prix_unitaire' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        // Vérifier la disponibilité des stocks AVANT la transaction
        foreach ($request->produits as $item) {
            $produit = \App\Models\Produit::findOrFail($item['produit_id']);
            $stockDisponible = $produit->stockTotal();

            if ($stockDisponible < $item['quantite']) {
                return response()->json([
                    'error' => "Stock insuffisant pour le produit: {$produit->nom}",
                    'stock_disponible' => $stockDisponible,
                    'quantite_demandee' => $item['quantite']
                ], 400);
            }
        }

        DB::beginTransaction();

        try {
            // Créer la vente
            $vente = Vente::create([
                'numero' => 'V' . date('Y') . str_pad(Vente::count() + 1, 6, '0', STR_PAD_LEFT),
                'client_id' => $request->client_id,
                'user_id' => auth()->id(),
                'date_vente' => $request->date_vente,
                'remise' => $request->remise ?? 0,
                'montant_ht' => 0,
                'montant_ttc' => 0,
                'statut' => 'Brouillon'
            ]);

            // Créer les détails de vente
            $sousTotal = 0;

            foreach ($request->produits as $item) {
                $sousTotalLigne = $item['quantite'] * $item['prix_unitaire'];
                $sousTotal += $sousTotalLigne;

                DetailVente::create([
                    'vente_id' => $vente->id,
                    'produit_id' => $item['produit_id'],
                    'quantite' => $item['quantite'],
                    'prix_unitaire' => $item['prix_unitaire'],
                    'sous_total' => $sousTotalLigne
                ]);
            }

            // Calculer les totaux
            $montantHT = $sousTotal - ($request->remise ?? 0);
            $montantTTC = $montantHT * 1.18; // TVA 18%

            $vente->update([
                'montant_ht' => $montantHT,
                'montant_ttc' => $montantTTC
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Vente créée avec succès',
                'vente' => $vente->load(['client', 'detailVentes.produit'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erreur création vente', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'error' => 'Erreur lors de la création de la vente',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $vente = Vente::with([
            'client',
            'user',
            'detailVentes.produit',
            'facture',
            'livraison'
        ])->findOrFail($id);

        return response()->json($vente);
    }

    public function update(Request $request, $id)
    {
        $vente = Vente::findOrFail($id);

        if ($vente->statut == 'Livrée' || $vente->statut == 'Annulée') {
            return response()->json([
                'error' => 'Impossible de modifier une vente livrée ou annulée'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'remise' => 'nullable|numeric|min:0',
            'statut' => 'in:Brouillon,Validée,Livrée,Annulée'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        $vente->update($request->all());

        if ($request->has('statut') && $request->statut == 'Validée') {
            $vente->genererFacture();
        }

        return response()->json([
            'message' => 'Vente mise à jour avec succès',
            'vente' => $vente->load(['client', 'detailVentes.produit'])
        ]);
    }

    public function valider($id)
    {
        DB::beginTransaction();

        try {
            $vente = Vente::with('detailVentes.produit')->findOrFail($id);

            // Vérifier le statut
            if ($vente->statut != 'Brouillon') {
                return response()->json([
                    'error' => 'Seules les ventes en brouillon peuvent être validées',
                    'statut_actuel' => $vente->statut
                ], 400);
            }

            // Vérifier à nouveau la disponibilité des stocks
            foreach ($vente->detailVentes as $detail) {
                $produit = $detail->produit;
                $stockDisponible = $produit->stockTotal();

                if ($stockDisponible < $detail->quantite) {
                    DB::rollBack();
                    return response()->json([
                        'error' => "Stock insuffisant pour le produit: {$produit->nom}",
                        'stock_disponible' => $stockDisponible,
                        'quantite_requise' => $detail->quantite
                    ], 400);
                }
            }

            // Déduire le stock (FIFO)
            foreach ($vente->detailVentes as $detail) {
                $quantiteRestante = $detail->quantite;
                $stocks = Stock::where('produit_id', $detail->produit_id)
                    ->where('statut', 'Disponible')
                    ->where('quantite', '>', 0)
                    ->orderBy('date_entree', 'asc')
                    ->get();

                foreach ($stocks as $stock) {
                    if ($quantiteRestante <= 0) break;

                    if ($stock->quantite >= $quantiteRestante) {
                        $stock->ajusterQuantite(-$quantiteRestante);
                        $quantiteRestante = 0;
                    } else {
                        $quantiteRestante -= $stock->quantite;
                        $stock->ajusterQuantite(-$stock->quantite);
                    }
                }

                if ($quantiteRestante > 0) {
                    DB::rollBack();
                    return response()->json([
                        'error' => "Impossible de réserver le stock pour le produit: {$detail->produit->nom}",
                        'quantite_manquante' => $quantiteRestante
                    ], 400);
                }
            }

            // Changer le statut
            $vente->statut = 'Validée';
            $vente->save();

            // Générer la facture
            $facture = $vente->genererFacture();

            DB::commit();

            return response()->json([
                'message' => 'Vente validée avec succès',
                'vente' => $vente->load(['client', 'detailVentes.produit', 'facture']),
                'facture' => $facture
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erreur validation vente', [
                'vente_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Erreur lors de la validation de la vente',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function annuler($id)
    {
        DB::beginTransaction();

        try {
            $vente = Vente::with('detailVentes.produit')->findOrFail($id);

            // Vérifier que la vente peut être annulée
            if ($vente->statut == 'Annulée') {
                return response()->json([
                    'error' => 'Cette vente est déjà annulée'
                ], 400);
            }

            if ($vente->statut == 'Livrée') {
                return response()->json([
                    'error' => 'Impossible d\'annuler une vente déjà livrée'
                ], 400);
            }

            // Si la vente était validée, remettre le stock
            if ($vente->statut == 'Validée') {
                foreach ($vente->detailVentes as $detail) {
                    // Remettre le stock dans le premier entrepôt disponible
                    $stock = Stock::where('produit_id', $detail->produit_id)
                        ->where('statut', 'Disponible')
                        ->orderBy('date_entree', 'desc')
                        ->first();

                    if ($stock) {
                        $stock->ajusterQuantite($detail->quantite);
                    } else {
                        // Créer une nouvelle entrée de stock
                        $entrepot = \App\Models\Entrepot::first();
                        if ($entrepot) {
                            Stock::create([
                                'produit_id' => $detail->produit_id,
                                'entrepot_id' => $entrepot->id,
                                'quantite' => $detail->quantite,
                                'emplacement' => 'Retour-' . $vente->numero,
                                'date_entree' => now(),
                                'numero_lot' => 'RET-' . $vente->numero . '-' . $detail->produit_id,
                                'statut' => 'Disponible'
                            ]);
                        }
                    }
                }
            }

            // Annuler la vente
            $vente->statut = 'Annulée';
            $vente->save();

            // Annuler la facture si elle existe
            if ($vente->facture) {
                $vente->facture->statut = 'Annulée';
                $vente->facture->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Vente annulée avec succès',
                'vente' => $vente->load(['client', 'detailVentes.produit', 'facture'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erreur annulation vente', [
                'vente_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Erreur lors de l\'annulation de la vente',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function statistiques(Request $request)
    {
        $dateDebut = $request->input('date_debut', now()->startOfMonth());
        $dateFin = $request->input('date_fin', now()->endOfMonth());

        $ventes = Vente::whereBetween('date_vente', [$dateDebut, $dateFin])
            ->where('statut', '!=', 'Annulée')
            ->get();

        $stats = [
            'total_ventes' => $ventes->count(),
            'chiffre_affaires' => $ventes->sum('montant_ttc'),
            'ventes_par_jour' => $ventes->groupBy(function($vente) {
                return $vente->date_vente->format('Y-m-d');
            })->map(function($jour) {
                return [
                    'nombre' => $jour->count(),
                    'montant' => $jour->sum('montant_ttc')
                ];
            }),
            'top_clients' => $ventes->groupBy('client_id')
                ->map(function($ventesClient) {
                    return [
                        'client' => $ventesClient->first()->client->nom,
                        'nombre_ventes' => $ventesClient->count(),
                        'montant_total' => $ventesClient->sum('montant_ttc')
                    ];
                })
                ->sortByDesc('montant_total')
                ->take(10)
                ->values(),
            'produits_vendus' => DetailVente::whereIn('vente_id', $ventes->pluck('id'))
                ->with('produit')
                ->get()
                ->groupBy('produit_id')
                ->map(function($details) {
                    return [
                        'produit' => $details->first()->produit->nom,
                        'quantite' => $details->sum('quantite'),
                        'montant' => $details->sum('sous_total')
                    ];
                })
                ->sortByDesc('quantite')
                ->take(10)
                ->values()
        ];

        return response()->json($stats);
    }
}
