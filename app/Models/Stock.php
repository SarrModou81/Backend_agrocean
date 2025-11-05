<?php

// app/Models/Stock.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'produit_id',
        'entrepot_id',
        'quantite',
        'emplacement',
        'date_entree',
        'numero_lot',
        'date_peremption',
        'statut'
    ];

    protected $casts = [
        'date_entree' => 'date',
        'date_peremption' => 'date',
    ];

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    public function entrepot()
    {
        return $this->belongsTo(Entrepot::class);
    }

    /**
     * Ajuster la quantité en stock avec validation
     */
    public function ajusterQuantite($quantite)
    {
        $nouvelleQuantite = $this->quantite + $quantite;

        if ($nouvelleQuantite < 0) {
            throw new \Exception("La quantité ne peut pas être négative pour le stock ID: {$this->id}. Quantité actuelle: {$this->quantite}, ajustement demandé: {$quantite}");
        }

        // Mettre à jour la quantité
        $this->quantite = $nouvelleQuantite;

        // Ne pas changer le statut automatiquement
        // Le statut doit être géré manuellement par l'utilisateur
        // Seule exception : si le stock est périmé après vérification de date
        if ($this->date_peremption && Carbon::now()->greaterThan($this->date_peremption)) {
            $this->statut = 'Périmé';
        }

        $this->save();

        return $this;
    }

    /**
     * Vérifier l'état de péremption du produit
     */
    public function verifierPeremption()
    {
        if ($this->date_peremption) {
            $joursRestants = Carbon::now()->diffInDays($this->date_peremption, false);

            if ($joursRestants < 0) {
                $this->statut = 'Périmé';
                $this->save();
                return 'expired';
            } elseif ($joursRestants <= 7) {
                return 'warning';
            }
        }
        return 'ok';
    }

    /**
     * Calculer la valeur totale du stock
     */
    public function calculerValeur()
    {
        if (!$this->produit) {
            return 0;
        }
        return $this->quantite * $this->produit->prix_achat;
    }

    /**
     * Scope pour stocks disponibles
     */
    public function scopeDisponible($query)
    {
        return $query->where('statut', 'Disponible')
            ->where('quantite', '>', 0);
    }

    /**
     * Scope pour stocks expirés ou proche expiration
     */
    public function scopeExpirationProche($query, $jours = 7)
    {
        return $query->whereNotNull('date_peremption')
            ->whereDate('date_peremption', '<=', Carbon::now()->addDays($jours))
            ->whereDate('date_peremption', '>=', Carbon::now());
    }
}
