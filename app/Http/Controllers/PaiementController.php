<?php

// app/Http/Controllers/PaiementController.php

namespace App\Http\Controllers;

use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PaiementController extends Controller
{
    public function index(Request $request)
    {
        $query = Paiement::with([
            'facture.vente.client',
            'factureFournisseur.fournisseur',
            'client',
            'fournisseur'
        ]);

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('fournisseur_id')) {
            $query->where('fournisseur_id', $request->fournisseur_id);
        }

        if ($request->has('mode_paiement')) {
            $query->where('mode_paiement', $request->mode_paiement);
        }

        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->whereBetween('date_paiement', [$request->date_debut, $request->date_fin]);
        }

        $paiements = $query->orderBy('date_paiement', 'desc')->paginate(20);

        return response()->json($paiements);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'facture_id' => 'nullable|exists:factures,id|required_without:facture_fournisseur_id',
            'facture_fournisseur_id' => 'nullable|exists:facture_fournisseurs,id|required_without:facture_id',
            'montant' => 'required|numeric|min:0.01',
            'date_paiement' => 'required|date',
            'mode_paiement' => 'required|in:Espèces,Chèque,Virement,MobileMoney,Carte',
            'reference' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        // Vérifier qu'une seule facture est fournie
        if ($request->facture_id && $request->facture_fournisseur_id) {
            return response()->json([
                'error' => 'Vous ne pouvez spécifier qu\'une seule facture (client ou fournisseur)'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Récupérer les informations selon le type de facture
            $factureData = [];
            $montantRestant = 0;

            if ($request->facture_id) {
                $facture = \App\Models\Facture::with('vente.client')->lockForUpdate()->findOrFail($request->facture_id);

                // Calculer le montant restant avec précision
                $montantPaye = round($facture->paiements()->sum('montant'), 2);
                $montantTotal = round($facture->montant_ttc, 2);
                $montantRestant = $montantTotal - $montantPaye;

                // Arrondir le montant restant pour éviter les problèmes d'arrondi
                $montantRestant = round($montantRestant, 2);

                $factureData = [
                    'facture_id' => $facture->id,
                    'client_id' => $facture->vente->client_id ?? null,
                    'fournisseur_id' => null,
                    'facture_fournisseur_id' => null
                ];

                $typeFacture = 'client';
                $numeroFacture = $facture->numero;
            }

            if ($request->facture_fournisseur_id) {
                $factureFournisseur = \App\Models\FactureFournisseur::with('fournisseur')->lockForUpdate()->findOrFail($request->facture_fournisseur_id);

                // Calculer le montant restant avec précision
                $montantPaye = round($factureFournisseur->paiements()->sum('montant'), 2);
                $montantTotal = round($factureFournisseur->montant_total, 2);
                $montantRestant = $montantTotal - $montantPaye;

                // Arrondir le montant restant pour éviter les problèmes d'arrondi
                $montantRestant = round($montantRestant, 2);

                $factureData = [
                    'facture_fournisseur_id' => $factureFournisseur->id,
                    'fournisseur_id' => $factureFournisseur->fournisseur_id,
                    'client_id' => null,
                    'facture_id' => null
                ];

                $typeFacture = 'fournisseur';
                $numeroFacture = $factureFournisseur->numero;
            }

            // Arrondir le montant du paiement
            $montantPaiement = round($request->montant, 2);

            // VALIDATION STRICTE : Le montant ne peut pas dépasser le montant restant
            if ($montantPaiement > $montantRestant) {
                DB::rollBack();
                return response()->json([
                    'error' => 'Le montant du paiement dépasse le montant restant à payer',
                    'montant_saisi' => $montantPaiement,
                    'montant_restant' => $montantRestant,
                    'facture' => $numeroFacture,
                    'type' => $typeFacture
                ], 422);
            }

            // Si le montant est très proche du montant restant (différence < 1 FCFA), ajuster automatiquement
            if (abs($montantPaiement - $montantRestant) < 1 && $montantPaiement < $montantRestant) {
                $montantPaiement = $montantRestant;
            }

            // Créer le paiement
            $paiement = Paiement::create([
                ...$factureData,
                'montant' => $montantPaiement,
                'date_paiement' => $request->date_paiement,
                'mode_paiement' => $request->mode_paiement,
                'reference' => $request->reference
            ]);

            DB::commit();

            $paiement->load([
                'facture.vente.client',
                'factureFournisseur.fournisseur',
                'client',
                'fournisseur'
            ]);

            return response()->json([
                'message' => 'Paiement enregistré avec succès',
                'paiement' => $paiement,
                'nouveau_montant_restant' => max(0, round($montantRestant - $montantPaiement, 2))
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Erreur création paiement', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Erreur lors de l\'enregistrement du paiement',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $paiement = Paiement::with([
            'facture.vente.client',
            'factureFournisseur.fournisseur',
            'client',
            'fournisseur'
        ])->findOrFail($id);

        return response()->json($paiement);
    }

    /**
     * Statistiques des paiements
     */
    public function statistiques(Request $request)
    {
        $dateDebut = $request->input('date_debut', now()->startOfMonth());
        $dateFin = $request->input('date_fin', now()->endOfMonth());

        $paiementsClients = Paiement::whereNotNull('facture_id')
            ->whereBetween('date_paiement', [$dateDebut, $dateFin])
            ->sum('montant');

        $paiementsFournisseurs = Paiement::whereNotNull('facture_fournisseur_id')
            ->whereBetween('date_paiement', [$dateDebut, $dateFin])
            ->sum('montant');

        $parMode = Paiement::whereBetween('date_paiement', [$dateDebut, $dateFin])
            ->selectRaw('mode_paiement, SUM(montant) as total, COUNT(*) as nombre')
            ->groupBy('mode_paiement')
            ->get();

        return response()->json([
            'periode' => [
                'debut' => $dateDebut,
                'fin' => $dateFin
            ],
            'total_paiements_clients' => round($paiementsClients, 2),
            'total_paiements_fournisseurs' => round($paiementsFournisseurs, 2),
            'solde_net' => round($paiementsClients - $paiementsFournisseurs, 2),
            'par_mode' => $parMode
        ]);
    }

    /**
     * Obtenir le montant restant d'une facture
     */
    public function getMontantRestant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'facture_id' => 'nullable|exists:factures,id|required_without:facture_fournisseur_id',
            'facture_fournisseur_id' => 'nullable|exists:facture_fournisseurs,id|required_without:facture_id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        if ($request->facture_id) {
            $facture = \App\Models\Facture::with('paiements')->findOrFail($request->facture_id);
            $montantPaye = round($facture->paiements->sum('montant'), 2);
            $montantTotal = round($facture->montant_ttc, 2);
            $montantRestant = round($montantTotal - $montantPaye, 2);

            return response()->json([
                'facture_id' => $facture->id,
                'numero' => $facture->numero,
                'montant_total' => $montantTotal,
                'montant_paye' => $montantPaye,
                'montant_restant' => max(0, $montantRestant),
                'type' => 'client'
            ]);
        }

        if ($request->facture_fournisseur_id) {
            $facture = \App\Models\FactureFournisseur::with('paiements')->findOrFail($request->facture_fournisseur_id);
            $montantPaye = round($facture->paiements->sum('montant'), 2);
            $montantTotal = round($facture->montant_total, 2);
            $montantRestant = round($montantTotal - $montantPaye, 2);

            return response()->json([
                'facture_id' => $facture->id,
                'numero' => $facture->numero,
                'montant_total' => $montantTotal,
                'montant_paye' => $montantPaye,
                'montant_restant' => max(0, $montantRestant),
                'type' => 'fournisseur'
            ]);
        }
    }
}
