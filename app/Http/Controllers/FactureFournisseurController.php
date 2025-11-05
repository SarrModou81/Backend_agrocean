<?php

namespace App\Http\Controllers;

use App\Models\FactureFournisseur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FactureFournisseurController extends Controller
{
    public function index(Request $request)
    {
        $query = FactureFournisseur::with(['commandeAchat', 'fournisseur', 'paiements']);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('fournisseur_id')) {
            $query->where('fournisseur_id', $request->fournisseur_id);
        }

        $factures = $query->orderBy('date_emission', 'desc')->paginate(20);

        $factures->getCollection()->transform(function($facture) {
            $facture->montant_paye = $facture->montant_paye;
            $facture->montant_restant = $facture->montant_restant;
            return $facture;
        });

        return response()->json($factures);
    }

    public function show($id)
    {
        $facture = FactureFournisseur::with([
            'commandeAchat.detailCommandeAchats.produit',
            'fournisseur',
            'paiements'
        ])->findOrFail($id);

        $facture->montant_paye = $facture->montant_paye;
        $facture->montant_restant = $facture->montant_restant;

        return response()->json($facture);
    }

    public function impayees()
    {
        $factures = FactureFournisseur::with(['fournisseur', 'paiements', 'commandeAchat'])
            ->whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->orderBy('date_echeance', 'asc')
            ->get();

        $factures->transform(function($facture) {
            $facture->montant_paye = $facture->montant_paye;
            $facture->montant_restant = $facture->montant_restant;
            $facture->jours_retard = now()->diffInDays($facture->date_echeance, false);
            return $facture;
        });

        return response()->json($factures);
    }
}
