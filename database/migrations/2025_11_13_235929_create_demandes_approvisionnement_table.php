<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table principale
        Schema::create('demandes_approvisionnement', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->foreignId('demandeur_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('destinataire_id')->nullable()->constrained('users')->onDelete('set null');
            $table->date('date_demande');
            $table->text('motif')->nullable();
            $table->enum('priorite', ['Normale', 'Urgente', 'Critique'])->default('Normale');
            $table->enum('statut', ['Brouillon', 'Envoyée', 'EnCours', 'Traitée', 'Rejetée', 'Annulée'])->default('Brouillon');
            $table->dateTime('date_traitement')->nullable();
            $table->text('commentaire_traitement')->nullable();
            $table->timestamps();

            $table->index('statut');
            $table->index('priorite');
            $table->index('demandeur_id');
            $table->index('destinataire_id');
        });

        // Table des détails/lignes
        Schema::create('detail_demandes_approvisionnement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_approvisionnement_id')->constrained('demandes_approvisionnement')->onDelete('cascade');
            $table->foreignId('produit_id')->constrained('produits')->onDelete('cascade');
            $table->integer('quantite_demandee');
            $table->integer('quantite_actuelle')->nullable();
            $table->integer('seuil_minimum')->nullable();
            $table->text('justification')->nullable();
            $table->timestamps();

            $table->index('demande_approvisionnement_id');
            $table->index('produit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detail_demandes_approvisionnement');
        Schema::dropIfExists('demandes_approvisionnement');
    }
};
