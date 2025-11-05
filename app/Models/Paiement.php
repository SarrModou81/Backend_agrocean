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
        'facture_fournisseur_id',
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

    public function factureFournisseur()
    {
        return $this->belongsTo(FactureFournisseur::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($paiement) {
            // Mise à jour facture CLIENT
            if ($paiement->facture_id && $paiement->facture) {
                $facture = $paiement->facture;
                $totalPaiements = round($facture->paiements()->sum('montant'), 2);
                $montantFacture = round($facture->montant_ttc, 2);

                // Calculer le montant restant
                $montantRestant = $montantFacture - $totalPaiements;

                // Si le montant restant est très petit (erreur d'arrondi), on considère comme payé
                if (abs($montantRestant) < 0.01) {
                    $facture->statut = 'Payée';
                } elseif ($totalPaiements >= $montantFacture) {
                    $facture->statut = 'Payée';
                } elseif ($totalPaiements > 0) {
                    $facture->statut = 'Partiellement Payée';
                } else {
                    $facture->statut = 'Impayée';
                }

                $facture->save();
            }

            // Mise à jour facture FOURNISSEUR
            if ($paiement->facture_fournisseur_id && $paiement->factureFournisseur) {
                $facture = $paiement->factureFournisseur;
                $totalPaiements = round($facture->paiements()->sum('montant'), 2);
                $montantFacture = round($facture->montant_total, 2);

                // Calculer le montant restant
                $montantRestant = $montantFacture - $totalPaiements;

                // Si le montant restant est très petit (erreur d'arrondi), on considère comme payé
                if (abs($montantRestant) < 0.01) {
                    $facture->statut = 'Payée';
                } elseif ($totalPaiements >= $montantFacture) {
                    $facture->statut = 'Payée';
                } elseif ($totalPaiements > 0) {
                    $facture->statut = 'Partiellement Payée';
                } else {
                    $facture->statut = 'Impayée';
                }

                $facture->save();
            }
        });
    }
}
