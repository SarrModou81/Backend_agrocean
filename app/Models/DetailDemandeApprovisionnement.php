<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetailDemandeApprovisionnement extends Model
{
    protected $table = 'detail_demandes_approvisionnement';

    protected $fillable = [
        'demande_approvisionnement_id',
        'produit_id',
        'quantite_demandee',
        'quantite_actuelle',
        'seuil_minimum',
        'justification'
    ];

    protected $casts = [
        'quantite_demandee' => 'integer',
        'quantite_actuelle' => 'integer',
        'seuil_minimum' => 'integer'
    ];

    // Relations
    public function demandeApprovisionnement(): BelongsTo
    {
        return $this->belongsTo(DemandeApprovisionnement::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
