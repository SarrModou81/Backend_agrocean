<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Produit extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'nom',
        'description',
        'categorie_id',
        'prix_achat',
        'prix_vente',
        'seuil_minimum',
        'peremption'
    ];

    protected $casts = [
        'prix_achat' => 'decimal:2',
        'prix_vente' => 'decimal:2',
        'peremption' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        // Générer automatiquement le code avant la création
        static::creating(function ($produit) {
            if (empty($produit->code)) {
                $produit->code = $produit->genererCode();
            }
        });
    }

    /**
     * Génère automatiquement un code produit basé sur la catégorie
     */
    public function genererCode(): string
    {
        $categorie = Categorie::find($this->categorie_id);

        if (!$categorie || !$categorie->code_prefix) {
            // Fallback si pas de catégorie ou pas de préfixe
            $prefix = 'PROD';
        } else {
            $prefix = $categorie->code_prefix;
        }

        // Trouver le dernier numéro pour ce préfixe
        $dernierProduit = static::where('code', 'LIKE', $prefix . '%')
            ->orderBy('code', 'desc')
            ->first();

        if ($dernierProduit) {
            // Extraire le numéro du dernier code
            $dernierNumero = (int) substr($dernierProduit->code, strlen($prefix));
            $nouveauNumero = $dernierNumero + 1;
        } else {
            $nouveauNumero = 1;
        }

        // Formater le code avec un padding de 3 chiffres
        return $prefix . str_pad($nouveauNumero, 3, '0', STR_PAD_LEFT);
    }

    public function categorie()
    {
        return $this->belongsTo(Categorie::class);
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function detailVentes()
    {
        return $this->hasMany(DetailVente::class);
    }

    public function detailCommandeAchats()
    {
        return $this->hasMany(DetailCommandeAchat::class);
    }

    public function alertes()
    {
        return $this->hasMany(Alerte::class);
    }

    public function calculerMarge()
    {
        return $this->prix_vente - $this->prix_achat;
    }

    public function verifierDisponibilite($quantite)
    {
        return $this->stocks()
                ->where('statut', 'Disponible')
                ->sum('quantite') >= $quantite;
    }

    public function stockTotal()
    {
        return $this->stocks()
            ->where('statut', 'Disponible')
            ->sum('quantite');
    }

}
