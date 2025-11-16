<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandeApprovisionnement extends Model
{
    protected $table = 'demandes_approvisionnement';

    protected $fillable = [
        'numero',
        'demandeur_id',
        'destinataire_id',
        'date_demande',
        'motif',
        'priorite',
        'statut',
        'date_traitement',
        'commentaire_traitement'
    ];

    protected $casts = [
        'date_demande' => 'date',
        'date_traitement' => 'datetime'
    ];

    // Relations
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_id');
    }

    public function detailDemandes(): HasMany
    {
        return $this->hasMany(DetailDemandeApprovisionnement::class);
    }

    // Méthodes de workflow
    public function envoyer(?int $destinataireId = null): void
    {
        $this->statut = 'Envoyée';
        if ($destinataireId) {
            $this->destinataire_id = $destinataireId;
        }
        $this->save();
    }

    public function traiter(?string $commentaire = null): void
    {
        $this->statut = 'Traitée';
        $this->date_traitement = now();
        $this->commentaire_traitement = $commentaire;
        $this->save();
    }

    public function rejeter(string $commentaire): void
    {
        $this->statut = 'Rejetée';
        $this->date_traitement = now();
        $this->commentaire_traitement = $commentaire;
        $this->save();
    }
}
