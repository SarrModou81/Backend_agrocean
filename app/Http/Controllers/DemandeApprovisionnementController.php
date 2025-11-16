<?php
// app/Http/Controllers/DemandeApprovisionnementController.php

namespace App\Http\Controllers;

use App\Models\DemandeApprovisionnement;
use App\Models\DetailDemandeApprovisionnement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DemandeApprovisionnementController extends Controller
{
    /**
     * Liste des demandes (avec filtre selon le rôle)
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = DemandeApprovisionnement::with([
            'demandeur',
            'destinataire',
            'detailDemandes.produit'
        ]);

        // Filtrer selon le rôle
        if ($user->role === 'GestionnaireStock') {
            // Le gestionnaire voit ses demandes
            $query->where('demandeur_id', $user->id);
        } elseif ($user->role === 'AgentApprovisionnement') {
            // L'agent voit les demandes qui lui sont assignées
            $query->where('destinataire_id', $user->id)
                ->orWhere('statut', 'Envoyée'); // Ou toutes les envoyées non assignées
        }

        // Filtres supplémentaires
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('priorite')) {
            $query->where('priorite', $request->priorite);
        }

        $demandes = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($demandes);
    }

    /**
     * Créer une nouvelle demande
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_demande' => 'required|date',
            'motif' => 'nullable|string',
            'priorite' => 'required|in:Normale,Urgente,Critique',
            'produits' => 'required|array|min:1',
            'produits.*.produit_id' => 'required|exists:produits,id',
            'produits.*.quantite_demandee' => 'required|integer|min:1',
            'produits.*.justification' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Générer le numéro
            $annee = date('Y');
            $dernierNumero = DemandeApprovisionnement::where('numero', 'like', 'DA' . $annee . '%')
                ->orderBy('numero', 'desc')
                ->value('numero');

            if ($dernierNumero) {
                $dernierSequence = (int) substr($dernierNumero, -6);
                $nouveauSequence = $dernierSequence + 1;
            } else {
                $nouveauSequence = 1;
            }

            $numero = 'DA' . $annee . str_pad($nouveauSequence, 6, '0', STR_PAD_LEFT);

            // Créer la demande
            $demande = DemandeApprovisionnement::create([
                'numero' => $numero,
                'demandeur_id' => auth()->id(),
                'date_demande' => $request->date_demande,
                'motif' => $request->motif,
                'priorite' => $request->priorite,
                'statut' => 'Brouillon'
            ]);

            // Ajouter les produits
            foreach ($request->produits as $item) {
                $produit = \App\Models\Produit::find($item['produit_id']);

                DetailDemandeApprovisionnement::create([
                    'demande_approvisionnement_id' => $demande->id,
                    'produit_id' => $item['produit_id'],
                    'quantite_demandee' => $item['quantite_demandee'],
                    'quantite_actuelle' => $produit->stockTotal(),
                    'seuil_minimum' => $produit->seuil_minimum,
                    'justification' => $item['justification'] ?? null
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Demande créée avec succès',
                'demande' => $demande->load(['demandeur', 'detailDemandes.produit'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Erreur lors de la création',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Détails d'une demande
     */
    public function show($id)
    {
        $demande = DemandeApprovisionnement::with([
            'demandeur',
            'destinataire',
            'detailDemandes.produit.categorie'
        ])->findOrFail($id);

        return response()->json($demande);
    }

    /**
     * Envoyer une demande
     */
    public function envoyer(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'destinataire_id' => 'nullable|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        $demande = DemandeApprovisionnement::findOrFail($id);

        if ($demande->statut !== 'Brouillon') {
            return response()->json([
                'error' => 'Seules les demandes en brouillon peuvent être envoyées'
            ], 400);
        }

        $demande->envoyer($request->destinataire_id);

        return response()->json([
            'message' => 'Demande envoyée avec succès',
            'demande' => $demande->load(['demandeur', 'destinataire'])
        ]);
    }

    /**
     * Prendre en charge une demande (Agent)
     */
    public function prendrEnCharge($id)
    {
        $demande = DemandeApprovisionnement::findOrFail($id);

        if ($demande->statut !== 'Envoyée') {
            return response()->json([
                'error' => 'Cette demande ne peut pas être prise en charge'
            ], 400);
        }

        $demande->statut = 'EnCours';
        $demande->destinataire_id = auth()->id();
        $demande->save();

        return response()->json([
            'message' => 'Demande prise en charge',
            'demande' => $demande->load(['demandeur', 'destinataire'])
        ]);
    }

    /**
     * Traiter une demande (Agent)
     */
    public function traiter(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'commentaire' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        $demande = DemandeApprovisionnement::findOrFail($id);

        if (!in_array($demande->statut, ['Envoyée', 'EnCours'])) {
            return response()->json([
                'error' => 'Cette demande ne peut pas être traitée'
            ], 400);
        }

        $demande->traiter($request->commentaire);

        return response()->json([
            'message' => 'Demande traitée avec succès',
            'demande' => $demande->load(['demandeur', 'destinataire'])
        ]);
    }

    /**
     * Rejeter une demande (Agent)
     */
    public function rejeter(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'commentaire' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors()
            ], 422);
        }

        $demande = DemandeApprovisionnement::findOrFail($id);

        if (!in_array($demande->statut, ['Envoyée', 'EnCours'])) {
            return response()->json([
                'error' => 'Cette demande ne peut pas être rejetée'
            ], 400);
        }

        $demande->rejeter($request->commentaire);

        return response()->json([
            'message' => 'Demande rejetée',
            'demande' => $demande->load(['demandeur', 'destinataire'])
        ]);
    }

    /**
     * Annuler une demande (Gestionnaire)
     */
    public function annuler($id)
    {
        $demande = DemandeApprovisionnement::findOrFail($id);

        if ($demande->demandeur_id !== auth()->id()) {
            return response()->json([
                'error' => 'Non autorisé'
            ], 403);
        }

        if (in_array($demande->statut, ['Traitée', 'Rejetée', 'Annulée'])) {
            return response()->json([
                'error' => 'Cette demande ne peut plus être annulée'
            ], 400);
        }

        $demande->statut = 'Annulée';
        $demande->save();

        return response()->json([
            'message' => 'Demande annulée',
            'demande' => $demande
        ]);
    }

    /**
     * Liste des agents d'approvisionnement
     */
    public function getAgents()
    {
        $agents = User::where('role', 'AgentApprovisionnement')
            ->where('is_active', true)
            ->select('id', 'nom', 'prenom', 'email')
            ->get();

        return response()->json($agents);
    }

    /**
     * Statistiques
     */
    public function statistiques()
    {
        $user = auth()->user();

        if ($user->role === 'GestionnaireStock') {
            $stats = [
                'total' => DemandeApprovisionnement::where('demandeur_id', $user->id)->count(),
                'brouillon' => DemandeApprovisionnement::where('demandeur_id', $user->id)->where('statut', 'Brouillon')->count(),
                'en_cours' => DemandeApprovisionnement::where('demandeur_id', $user->id)->whereIn('statut', ['Envoyée', 'EnCours'])->count(),
                'traitees' => DemandeApprovisionnement::where('demandeur_id', $user->id)->where('statut', 'Traitée')->count()
            ];
        } else {
            $stats = [
                'total' => DemandeApprovisionnement::where('destinataire_id', $user->id)->count(),
                'en_attente' => DemandeApprovisionnement::where('statut', 'Envoyée')->count(),
                'en_cours' => DemandeApprovisionnement::where('destinataire_id', $user->id)->where('statut', 'EnCours')->count(),
                'traitees' => DemandeApprovisionnement::where('destinataire_id', $user->id)->where('statut', 'Traitée')->count()
            ];
        }

        return response()->json($stats);
    }
}
