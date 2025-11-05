<?php

// app/Models/Paiement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    use HasFactory;

    protected $fillable = [
        'facture_id',
        'client_id',
        'fournisseur_id',
        'montant',
        'date_paiement',
        'mode_paiement',
        'reference'
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'date_paiement' => 'date',
    ];

    public function facture()
    {
        return $this->belongsTo(Facture::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function factureFournisseur()
    {
        return $this->belongsTo(FactureFournisseur::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($paiement) {
            // Mise à jour facture client
            if ($paiement->facture) {
                $totalPaiements = $paiement->facture->paiements->sum('montant');

                if ($totalPaiements >= $paiement->facture->montant_ttc) {
                    $paiement->facture->statut = 'Payée';
                } else {
                    $paiement->facture->statut = 'Partiellement Payée';
                }

                $paiement->facture->save();
            }

            // Mise à jour facture fournisseur
            if ($paiement->factureFournisseur) {
                $totalPaiements = $paiement->factureFournisseur->paiements->sum('montant');

                if ($totalPaiements >= $paiement->factureFournisseur->montant_total) {
                    $paiement->factureFournisseur->statut = 'Payée';
                } else {
                    $paiement->factureFournisseur->statut = 'Partiellement Payée';
                }

                $paiement->factureFournisseur->save();
            }
        });
    }
}
