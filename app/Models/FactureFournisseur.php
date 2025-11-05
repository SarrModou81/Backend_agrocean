<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FactureFournisseur extends Model
{
    use HasFactory;

    protected $fillable = [
        'numero',
        'commande_achat_id',
        'fournisseur_id',
        'date_emission',
        'date_echeance',
        'montant_total',
        'statut'
    ];

    protected $casts = [
        'date_emission' => 'date',
        'date_echeance' => 'date',
        'montant_total' => 'decimal:2',
    ];

    public function commandeAchat()
    {
        return $this->belongsTo(CommandeAchat::class);
    }

    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class, 'facture_fournisseur_id');
    }

    public function getMontantPayeAttribute()
    {
        return $this->paiements->sum('montant');
    }

    public function getMontantRestantAttribute()
    {
        return $this->montant_total - $this->montant_paye;
    }
}
