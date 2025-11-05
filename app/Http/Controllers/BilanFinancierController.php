<?php
// app/Http/Controllers/BilanFinancierController.php

namespace App\Http\Controllers;

use App\Models\BilanFinancier;
use App\Models\Vente;
use App\Models\CommandeAchat;
use App\Models\Paiement;
use App\Models\Stock;
use App\Models\Facture;
use App\Models\FactureFournisseur;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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

        // Calculer le chiffre d'affaires (ventes validées/livrées)
        $chiffreAffaires = Vente::whereBetween('date_vente', [$dateDebut, $dateFin])
            ->whereIn('statut', ['Validée', 'Livrée'])
            ->sum('montant_ttc');

        // Calculer les charges d'exploitation (commandes d'achat reçues)
        $chargesExploitation = CommandeAchat::whereBetween('date_commande', [$dateDebut, $dateFin])
            ->where('statut', 'Reçue')
            ->sum('montant_total');

        // Calculer la marge globale réelle
        $ventes = Vente::with('detailVentes.produit')
            ->whereBetween('date_vente', [$dateDebut, $dateFin])
            ->whereIn('statut', ['Validée', 'Livrée'])
            ->get();

        $margeGlobale = 0;
        foreach ($ventes as $vente) {
            foreach ($vente->detailVentes as $detail) {
                if ($detail->produit) {
                    $coutAchat = $detail->quantite * $detail->produit->prix_achat;
                    $prixVente = $detail->quantite * $detail->prix_unitaire;
                    $margeGlobale += ($prixVente - $coutAchat);
                }
            }
        }

        // Calculer le bénéfice net
        $beneficeNet = $chiffreAffaires - $chargesExploitation;

        // Taux de marge
        $tauxMarge = $chiffreAffaires > 0 ? ($margeGlobale / $chiffreAffaires) * 100 : 0;

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
                'marge_globale' => $tauxMarge
            ]
        );

        return response()->json([
            'message' => 'Bilan financier généré avec succès',
            'bilan' => $bilan,
            'details' => [
                'nombre_ventes' => $ventes->count(),
                'marge_brute' => $margeGlobale,
                'taux_marge' => round($tauxMarge, 2) . '%'
            ]
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
    public function etatTresorerie(Request $request)
    {
        $dateDebut = $request->input('date_debut', now()->startOfMonth());
        $dateFin = $request->input('date_fin', now()->endOfMonth());

        // ENCAISSEMENTS = Paiements reçus des CLIENTS uniquement
        $encaissements = Paiement::whereNotNull('facture_id')
            ->whereBetween('date_paiement', [$dateDebut, $dateFin])
            ->sum('montant');

        // DÉCAISSEMENTS = Paiements faits aux FOURNISSEURS uniquement
        $decaissements = Paiement::whereNotNull('facture_fournisseur_id')
            ->whereBetween('date_paiement', [$dateDebut, $dateFin])
            ->sum('montant');

        // Solde de trésorerie
        $solde = $encaissements - $decaissements;

        // CRÉANCES CLIENTS = Factures clients impayées ou partiellement payées
        $creancesClients = Facture::whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->with('paiements')
            ->get()
            ->sum(function($facture) {
                return $facture->montant_ttc - $facture->paiements->sum('montant');
            });

        // DETTES FOURNISSEURS = Factures fournisseurs impayées ou partiellement payées
        $dettesFournisseurs = FactureFournisseur::whereIn('statut', ['Impayée', 'Partiellement Payée'])
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
                'encaissements' => (float) $encJour,
                'decaissements' => (float) $decJour,
                'solde' => (float) ($encJour - $decJour)
            ];

            $debut->addDay();
        }

        return response()->json([
            'periode' => [
                'debut' => $dateDebut,
                'fin' => $dateFin
            ],
            'encaissements' => (float) $encaissements,
            'decaissements' => (float) $decaissements,
            'solde' => (float) $solde,
            'creances_clients' => (float) $creancesClients,
            'dettes_fournisseurs' => (float) $dettesFournisseurs,
            'tresorerie_nette' => (float) ($solde - $dettesFournisseurs + $creancesClients),
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
        $ventesHT = Vente::whereBetween('date_vente', [$dateDebut, $dateFin])
            ->whereIn('statut', ['Validée', 'Livrée'])
            ->sum('montant_ht');

        $ventesTTC = Vente::whereBetween('date_vente', [$dateDebut, $dateFin])
            ->whereIn('statut', ['Validée', 'Livrée'])
            ->sum('montant_ttc');

        // CHARGES
        $achats = CommandeAchat::whereBetween('date_commande', [$dateDebut, $dateFin])
            ->where('statut', 'Reçue')
            ->sum('montant_total');

        // Calculer les marges
        $ventesList = Vente::with('detailVentes.produit')
            ->whereBetween('date_vente', [$dateDebut, $dateFin])
            ->whereIn('statut', ['Validée', 'Livrée'])
            ->get();

        $margeCommerciale = 0;
        $coutAchatTotal = 0;

        foreach ($ventesList as $vente) {
            foreach ($vente->detailVentes as $detail) {
                if ($detail->produit) {
                    $coutAchat = $detail->quantite * $detail->produit->prix_achat;
                    $prixVente = $detail->quantite * $detail->prix_unitaire;
                    $coutAchatTotal += $coutAchat;
                    $margeCommerciale += ($prixVente - $coutAchat);
                }
            }
        }

        // Résultat d'exploitation
        $resultatExploitation = $ventesHT - $achats;

        // Résultat net (simplifié - en réalité il faudrait ajouter charges financières, impôts, etc.)
        $resultatNet = $resultatExploitation;

        return response()->json([
            'periode' => [
                'debut' => $dateDebut,
                'fin' => $dateFin
            ],
            'produits' => [
                'ventes_marchandises_ht' => (float) $ventesHT,
                'ventes_marchandises_ttc' => (float) $ventesTTC,
                'total_produits' => (float) $ventesHT
            ],
            'charges' => [
                'achats_marchandises' => (float) $achats,
                'cout_achat_produits_vendus' => (float) $coutAchatTotal,
                'total_charges' => (float) $achats
            ],
            'resultats' => [
                'marge_commerciale' => (float) $margeCommerciale,
                'resultat_exploitation' => (float) $resultatExploitation,
                'resultat_net' => (float) $resultatNet
            ],
            'ratios' => [
                'taux_marge' => $ventesHT > 0 ? round(($margeCommerciale / $ventesHT) * 100, 2) : 0,
                'taux_marque' => $coutAchatTotal > 0 ? round(($margeCommerciale / $coutAchatTotal) * 100, 2) : 0,
                'taux_rentabilite' => $ventesHT > 0 ? round(($resultatNet / $ventesHT) * 100, 2) : 0
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

        $creances = Facture::whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->with('paiements')
            ->get()
            ->sum(function($facture) {
                return $facture->montant_ttc - $facture->paiements->sum('montant');
            });

        // Trésorerie = Encaissements - Décaissements depuis le début
        $tresorerie = Paiement::whereNotNull('facture_id')->sum('montant')
            - Paiement::whereNotNull('facture_fournisseur_id')->sum('montant');

        $totalActifCirculant = $stocksValeur + $creances + max(0, $tresorerie);

        // PASSIF
        // Dettes fournisseurs
        $dettesFournisseurs = FactureFournisseur::whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->with('paiements')
            ->get()
            ->sum(function($facture) {
                return $facture->montant_total - $facture->paiements->sum('montant');
            });

        // Capitaux propres (simplifié)
        $capitauxPropres = $totalActifCirculant - $dettesFournisseurs;

        return response()->json([
            'date' => $date,
            'actif' => [
                'actif_circulant' => [
                    'stocks' => (float) $stocksValeur,
                    'creances_clients' => (float) $creances,
                    'tresorerie' => (float) max(0, $tresorerie),
                    'total' => (float) $totalActifCirculant
                ],
                'total_actif' => (float) $totalActifCirculant
            ],
            'passif' => [
                'capitaux_propres' => (float) $capitauxPropres,
                'dettes' => [
                    'fournisseurs' => (float) $dettesFournisseurs,
                    'total_dettes' => (float) $dettesFournisseurs
                ],
                'total_passif' => (float) ($capitauxPropres + $dettesFournisseurs)
            ],
            'verification' => [
                'equilibre' => abs($totalActifCirculant - ($capitauxPropres + $dettesFournisseurs)) < 0.01,
                'difference' => (float) ($totalActifCirculant - ($capitauxPropres + $dettesFournisseurs))
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

        $caMois = Vente::whereBetween('date_vente', [$moisActuel, $finMois])
            ->whereIn('statut', ['Validée', 'Livrée'])
            ->sum('montant_ttc');

        $depensesMois = CommandeAchat::whereBetween('date_commande', [$moisActuel, $finMois])
            ->where('statut', 'Reçue')
            ->sum('montant_total');

        $creancesTotales = Facture::whereIn('statut', ['Impayée', 'Partiellement Payée'])
            ->with('paiements')
            ->get()
            ->sum(function($facture) {
                return $facture->montant_ttc - $facture->paiements->sum('montant');
            });

        $valeurStock = Stock::where('statut', 'Disponible')
            ->get()
            ->sum(function($stock) {
                return $stock->calculerValeur();
            });

        return response()->json([
            'ca_mois' => (float) $caMois,
            'depenses_mois' => (float) $depensesMois,
            'creances_totales' => (float) $creancesTotales,
            'valeur_stock' => (float) $valeurStock
        ]);
    }
}
