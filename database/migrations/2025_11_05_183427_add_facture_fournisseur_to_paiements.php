// database/migrations/xxxx_add_facture_fournisseur_to_paiements.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignId('facture_fournisseur_id')
                ->nullable()
                ->after('facture_id')
                ->constrained('facture_fournisseurs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropForeign(['facture_fournisseur_id']);
            $table->dropColumn('facture_fournisseur_id');
        });
    }
};
