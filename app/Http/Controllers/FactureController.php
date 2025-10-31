<?php

// app/Http/Controllers/FactureController.php

namespace App\Http\Controllers;

use App\Models\Facture;
use App\Models\Vente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FactureController extends Controller
{
    /**
     * Liste des factures
     */
    public function index(Request $request)
    {
        $query = Facture::with(['vente.client', 'paiements']);

        // Filtrer par statut
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        // Filtrer par client
        if ($request->has('client_id')) {
            $query->whereHas('vente', function($q) use ($request) {
                $q->where('client_id', $request->client_id);
            });
        }

        // Filtrer par période
        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->whereBetween('date_emission', [$request->date_debut, $request->date_fin]);
        }

        $factures = $query->orderBy('date_emission', 'desc')->paginate(20);

        // Ajouter le montant restant
        $factures->getCollection()->transform(function($facture) {
            $facture->montant_paye = $facture->paiements->sum('montant');
            $facture->montant_restant = $facture->montant_ttc - $facture->montant_paye;
            return $facture;
        });

        return response()->json($factures);
    }

    /**
     * Créer une facture manuellement
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vente_id' => 'required|exists:ventes,id|unique:factures,vente_id',
            'date_echeance' => 'nullable|date|after:today'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        $vente = Vente::findOrFail($request->vente_id);

        $facture = Facture::create([
            'numero' => 'F' . date('Y') . str_pad(Facture::count() + 1, 6, '0', STR_PAD_LEFT),
            'vente_id' => $vente->id,
            'date_emission' => now(),
            'date_echeance' => $request->date_echeance ?? now()->addDays(30),
            'montant_ttc' => $vente->montant_ttc,
            'statut' => 'Impayée'
        ]);

        return response()->json([
            'message' => 'Facture créée avec succès',
            'facture' => $facture->load(['vente.client'])
        ], 201);
    }

    /**
     * Détails d'une facture
     */
    public function show($id)
    {
        $facture = Facture::with([
            'vente.client',
            'vente.detailVentes.produit',
            'paiements'
        ])->findOrFail($id);

        $facture->montant_paye = $facture->paiements->sum('montant');
        $facture->montant_restant = $facture->montant_ttc - $facture->montant_paye;

        return response()->json($facture);
    }

    /**
     * Mettre à jour une facture
     */
    public function update(Request $request, $id)
    {
        $facture = Facture::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'date_echeance' => 'sometimes|date',
            'statut' => 'sometimes|in:Impayée,Partiellement Payée,Payée,Annulée'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        $facture->update($request->only(['date_echeance', 'statut']));

        return response()->json([
            'message' => 'Facture mise à jour avec succès',
            'facture' => $facture
        ]);
    }

    /**
     * Factures impayées
     */
    public function impayees()
    {
        $factures = Facture::with(['vente.client', 'paiements'])
            ->whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->orderBy('date_echeance', 'asc')
            ->get();

        $factures->transform(function($facture) {
            $facture->montant_paye = $facture->paiements->sum('montant');
            $facture->montant_restant = $facture->montant_ttc - $facture->montant_paye;
            $facture->jours_retard = now()->diffInDays($facture->date_echeance, false);
            return $facture;
        });

        // Retourner directement le tableau de factures (pas un objet avec meta)
        return response()->json($factures);
    }
    /**
     * Factures échues
     */
    public function echues()
    {
        $factures = Facture::with(['vente.client', 'paiements'])
            ->whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->where('date_echeance', '<', now())
            ->orderBy('date_echeance', 'asc')
            ->get();

        $factures->transform(function($facture) {
            $facture->montant_paye = $facture->paiements->sum('montant');
            $facture->montant_restant = $facture->montant_ttc - $facture->montant_paye;
            $facture->jours_retard = now()->diffInDays($facture->date_echeance);
            return $facture;
        });

        return response()->json([
            'total_factures_echues' => $factures->count(),
            'montant_total' => $factures->sum('montant_restant'),
            'factures' => $factures
        ]);
    }

    /**
     * Générer PDF (simulation)
     */
    public function genererPDF($id)
    {
        $facture = Facture::with([
            'vente.client',
            'vente.detailVentes.produit'
        ])->findOrFail($id);

        // Ici, vous pouvez utiliser une bibliothèque comme DomPDF ou TCPDF
        // Pour l'instant, on retourne juste les données

        return response()->json([
            'message' => 'PDF généré (simulation)',
            'facture' => $facture,
            'pdf_url' => url('/storage/factures/' . $facture->numero . '.pdf')
        ]);
    }

    /**
     * Envoyer facture par email (simulation)
     */
    public function envoyer($id)
    {
        $facture = Facture::with(['vente.client'])->findOrFail($id);

        if (!$facture->vente->client->email) {
            return response()->json([
                'error' => 'Le client n\'a pas d\'email'
            ], 400);
        }

        // Ici, envoyer l'email avec Laravel Mail
        // Mail::to($facture->vente->client->email)->send(new FactureMail($facture));

        return response()->json([
            'message' => 'Facture envoyée par email (simulation)',
            'email' => $facture->vente->client->email
        ]);
    }

    /**
     * Statistiques des factures
     */
    public function statistiques(Request $request)
    {
        $dateDebut = $request->input('date_debut', now()->startOfMonth());
        $dateFin = $request->input('date_fin', now()->endOfMonth());

        $factures = Facture::whereBetween('date_emission', [$dateDebut, $dateFin])->get();

        $stats = [
            'periode' => [
                'debut' => $dateDebut,
                'fin' => $dateFin
            ],
            'total_factures' => $factures->count(),
            'montant_total' => $factures->sum('montant_ttc'),
            'payees' => $factures->where('statut', 'Payée')->count(),
            'impayees' => $factures->where('statut', 'Impayée')->count(),
            'partiellement_payees' => $factures->where('statut', 'Partiellement Payée')->count(),
            'taux_paiement' => $factures->count() > 0
                ? round(($factures->where('statut', 'Payée')->count() / $factures->count()) * 100, 2)
                : 0
        ];

        return response()->json($stats);
    }
}
