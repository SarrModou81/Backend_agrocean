<?php
// app/Http/Controllers/BilanFinancierController.php

namespace App\Http\Controllers;

use App\Models\BilanFinancier;
use App\Models\Vente;
use App\Models\CommandeAchat;
use App\Models\Paiement;
use App\Models\Stock;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BilanFinancierController extends Controller
{
    /**
     * Liste des bilans
     */
    public function index()
    {
        $bilans = BilanFinancier::orderBy('date_debut', 'desc')->paginate(20);
        return response()->json($bilans);
    }

    /**
     * Générer un bilan pour une période
     */
    public function genererBilan(Request $request)
    {
        $request->validate([
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after_or_equal:date_debut',
            'periode' => 'required|string'
        ]);

        $dateDebut = Carbon::parse($request->date_debut);
        $dateFin = Carbon::parse($request->date_fin);

        // Calculer le chiffre d'affaires
        $chiffreAffaires = Vente::whereBetween('date_vente', [$dateDebut, $dateFin])
            ->where('statut', '!=', 'Annulée')
            ->sum('montant_ttc');

        // Calculer les charges d'exploitation (commandes d'achat)
        $chargesExploitation = CommandeAchat::whereBetween('date_commande', [$dateDebut, $dateFin])
            ->where('statut', 'Reçue')
            ->sum('montant_total');

        // Calculer la marge globale
        $ventes = Vente::with('detailVentes.produit')
            ->whereBetween('date_vente', [$dateDebut, $dateFin])
            ->where('statut', '!=', 'Annulée')
            ->get();

        $margeGlobale = 0;
        foreach ($ventes as $vente) {
            foreach ($vente->detailVentes as $detail) {
                $margeGlobale += $detail->quantite * ($detail->produit->prix_vente - $detail->produit->prix_achat);
            }
        }

        // Calculer le bénéfice net
        $beneficeNet = $margeGlobale - $chargesExploitation;

        // Créer ou mettre à jour le bilan
        $bilan = BilanFinancier::updateOrCreate(
            [
                'periode' => $request->periode,
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin
            ],
            [
                'chiffre_affaires' => $chiffreAffaires,
                'charges_exploitation' => $chargesExploitation,
                'benefice_net' => $beneficeNet,
                'marge_globale' => $margeGlobale
            ]
        );

        return response()->json([
            'message' => 'Bilan financier généré avec succès',
            'bilan' => $bilan
        ], 201);
    }

    /**
     * Détails d'un bilan
     */
    public function show($id)
    {
        $bilan = BilanFinancier::findOrFail($id);
        return response()->json($bilan);
    }

    /**
     * État de la trésorerie
     */
// app/Http/Controllers/BilanFinancierController.php
    public function etatTresorerie(Request $request)
    {
        $dateDebut = $request->input('date_debut', now()->startOfMonth());
        $dateFin = $request->input('date_fin', now()->endOfMonth());

        // ENCAISSEMENTS = Paiements reçus des CLIENTS uniquement
        $encaissements = Paiement::whereNotNull('facture_id') // Factures clients
        ->whereBetween('date_paiement', [$dateDebut, $dateFin])
            ->sum('montant');

        // DÉCAISSEMENTS = Paiements faits aux FOURNISSEURS uniquement
        $decaissements = Paiement::whereNotNull('facture_fournisseur_id') // Factures fournisseurs
        ->whereBetween('date_paiement', [$dateDebut, $dateFin])
            ->sum('montant');

        // Solde de trésorerie
        $solde = $encaissements - $decaissements;

        // CRÉANCES CLIENTS = Factures clients impayées ou partiellement payées
        $creancesClients = \App\Models\Facture::whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->with('paiements')
            ->get()
            ->sum(function($facture) {
                return $facture->montant_ttc - $facture->paiements->sum('montant');
            });

        // DETTES FOURNISSEURS = Factures fournisseurs impayées ou partiellement payées
        $dettesFournisseurs = \App\Models\FactureFournisseur::whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->with('paiements')
            ->get()
            ->sum(function($facture) {
                return $facture->montant_total - $facture->paiements->sum('montant');
            });

        // Évolution quotidienne
        $evolutionQuotidienne = [];
        $debut = Carbon::parse($dateDebut);
        $fin = Carbon::parse($dateFin);

        while ($debut <= $fin) {
            // Encaissements du jour (paiements clients)
            $encJour = Paiement::whereNotNull('facture_id')
                ->whereDate('date_paiement', $debut)
                ->sum('montant');

            // Décaissements du jour (paiements fournisseurs)
            $decJour = Paiement::whereNotNull('facture_fournisseur_id')
                ->whereDate('date_paiement', $debut)
                ->sum('montant');

            $evolutionQuotidienne[] = [
                'date' => $debut->format('Y-m-d'),
                'encaissements' => $encJour,
                'decaissements' => $decJour,
                'solde' => $encJour - $decJour
            ];

            $debut->addDay();
        }

        return response()->json([
            'periode' => [
                'debut' => $dateDebut,
                'fin' => $dateFin
            ],
            'encaissements' => $encaissements,
            'decaissements' => $decaissements,
            'solde' => $solde,
            'creances_clients' => $creancesClients,
            'dettes_fournisseurs' => $dettesFournisseurs,
            'tresorerie_nette' => $solde - $dettesFournisseurs + $creancesClients,
            'evolution_quotidienne' => $evolutionQuotidienne
        ]);
    }
    /**
     * Compte de résultat
     */
    public function compteResultat(Request $request)
    {
        $dateDebut = $request->input('date_debut', now()->startOfMonth());
        $dateFin = $request->input('date_fin', now()->endOfMonth());

        // PRODUITS
        $ventes = Vente::whereBetween('date_vente', [$dateDebut, $dateFin])
            ->where('statut', '!=', 'Annulée')
            ->sum('montant_ttc');

        // CHARGES
        $achats = CommandeAchat::whereBetween('date_commande', [$dateDebut, $dateFin])
            ->where('statut', 'Reçue')
            ->sum('montant_total');

        // Calculer les marges
        $ventesList = Vente::with('detailVentes.produit')
            ->whereBetween('date_vente', [$dateDebut, $dateFin])
            ->where('statut', '!=', 'Annulée')
            ->get();

        $margeCommerciale = 0;
        foreach ($ventesList as $vente) {
            foreach ($vente->detailVentes as $detail) {
                $margeCommerciale += $detail->quantite * ($detail->produit->prix_vente - $detail->produit->prix_achat);
            }
        }

        // Résultat d'exploitation
        $resultatExploitation = $margeCommerciale - $achats;

        // Résultat net (simplifié)
        $resultatNet = $resultatExploitation;

        return response()->json([
            'periode' => [
                'debut' => $dateDebut,
                'fin' => $dateFin
            ],
            'produits' => [
                'ventes_marchandises' => $ventes,
                'total_produits' => $ventes
            ],
            'charges' => [
                'achats_marchandises' => $achats,
                'total_charges' => $achats
            ],
            'resultats' => [
                'marge_commerciale' => $margeCommerciale,
                'resultat_exploitation' => $resultatExploitation,
                'resultat_net' => $resultatNet
            ],
            'ratios' => [
                'taux_marge' => $ventes > 0 ? round(($margeCommerciale / $ventes) * 100, 2) : 0,
                'taux_rentabilite' => $ventes > 0 ? round(($resultatNet / $ventes) * 100, 2) : 0
            ]
        ]);
    }

    /**
     * Bilan comptable
     */
    public function bilanComptable(Request $request)
    {
        $date = $request->input('date', now());

        // ACTIF
        // Actif circulant
        $stocksValeur = Stock::where('statut', 'Disponible')
            ->get()
            ->sum(function($stock) {
                return $stock->calculerValeur();
            });

        $creances = \App\Models\Facture::whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->with('paiements')
            ->get()
            ->sum(function($facture) {
                return $facture->montant_ttc - $facture->paiements->sum('montant');
            });

        $totalActifCirculant = $stocksValeur + $creances;

        // PASSIF
        // Dettes fournisseurs
        $dettesFournisseurs = CommandeAchat::where('statut', 'Reçue')
            ->whereDoesntHave('paiements')
            ->sum('montant_total');

        // Capitaux propres (simplifié)
        $capitauxPropres = $totalActifCirculant - $dettesFournisseurs;

        return response()->json([
            'date' => $date,
            'actif' => [
                'actif_circulant' => [
                    'stocks' => $stocksValeur,
                    'creances_clients' => $creances,
                    'total' => $totalActifCirculant
                ],
                'total_actif' => $totalActifCirculant
            ],
            'passif' => [
                'capitaux_propres' => $capitauxPropres,
                'dettes' => [
                    'fournisseurs' => $dettesFournisseurs,
                    'total_dettes' => $dettesFournisseurs
                ],
                'total_passif' => $capitauxPropres + $dettesFournisseurs
            ],
            'verification' => [
                'equilibre' => $totalActifCirculant === ($capitauxPropres + $dettesFournisseurs)
            ]
        ]);
    }

    /**
     * Tableau de bord financier
     */
    public function dashboardFinancier()
    {
        $moisActuel = now()->startOfMonth();
        $finMois = now()->endOfMonth();

        return response()->json([
            'ca_mois' => Vente::whereBetween('date_vente', [$moisActuel, $finMois])
                ->where('statut', '!=', 'Annulée')
                ->sum('montant_ttc'),
            'depenses_mois' => CommandeAchat::whereBetween('date_commande', [$moisActuel, $finMois])
                ->where('statut', 'Reçue')
                ->sum('montant_total'),
            'creances_totales' => \App\Models\Facture::whereIn('statut', ['Impayée', 'Partiellement Payée'])
                ->with('paiements')
                ->get()
                ->sum(function($facture) {
                    return $facture->montant_ttc - $facture->paiements->sum('montant');
                }),
            'valeur_stock' => Stock::where('statut', 'Disponible')
                ->get()
                ->sum(function($stock) {
                    return $stock->calculerValeur();
                })
        ]);
    }
}
