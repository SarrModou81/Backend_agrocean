<?php

// app/Models/CommandeAchat.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommandeAchat extends Model
{
    use HasFactory;

    protected $fillable = [
        'numero',
        'fournisseur_id',
        'user_id',
        'date_commande',
        'date_livraison_prevue',
        'statut',
        'montant_total'
    ];

    protected $casts = [
        'date_commande' => 'date',
        'date_livraison_prevue' => 'date',
        'montant_total' => 'decimal:2',
    ];

    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function detailCommandeAchats()
    {
        return $this->hasMany(DetailCommandeAchat::class);
    }

    public function calculerTotal()
    {
        $this->montant_total = $this->detailCommandeAchats->sum('sous_total');
        $this->save();
    }

    public function valider()
    {
        $this->statut = 'Validée';
        $this->save();
    }

    public function factureFournisseur()
    {
        return $this->hasOne(FactureFournisseur::class);
    }

    public function receptionner($entrepot_id)
    {
        foreach ($this->detailCommandeAchats as $detail) {
            Stock::create([
                'produit_id' => $detail->produit_id,
                'entrepot_id' => $entrepot_id,
                'quantite' => $detail->quantite,
                'emplacement' => 'Zone-A',
                'date_entree' => now(),
                'numero_lot' => 'LOT' . date('Ymd') . $this->id,
                'statut' => 'Disponible'
            ]);
        }

        $this->statut = 'Reçue';
        $this->save();

        // Générer automatiquement la facture fournisseur
        $this->genererFactureFournisseur();
    }

    public function genererFactureFournisseur()
    {
        if ($this->factureFournisseur) {
            return $this->factureFournisseur;
        }

        return FactureFournisseur::create([
            'numero' => 'FF' . date('Y') . str_pad($this->id, 6, '0', STR_PAD_LEFT),
            'commande_achat_id' => $this->id,
            'fournisseur_id' => $this->fournisseur_id,
            'date_emission' => now(),
            'date_echeance' => now()->addDays(30),
            'montant_total' => $this->montant_total,
            'statut' => 'Impayée'
        ]);
    }
}


