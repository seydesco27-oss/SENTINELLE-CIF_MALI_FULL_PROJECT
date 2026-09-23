<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {

            $table->id();

            /*
             * Laravel Sanctum utilise cette table pour associer
             * un token à son utilisateur.
             *
             * On utilise 190 caractères afin de rester largement
             * sous la limite d'index MySQL de notre environnement.
             */
            $table->string('tokenable_type', 190);

            $table->unsignedBigInteger('tokenable_id');

            $table->index(
                ['tokenable_type', 'tokenable_id'],
                'personal_access_tokens_tokenable_index'
            );

            $table->string('name');

            /*
             * Hash SHA-256 du token.
             */
            $table->string('token', 64)->unique();

            $table->text('abilities')->nullable();

            $table->timestamp('last_used_at')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // La table peut appartenir à la base historique digi_aml.
        // Ne pas la supprimer lors d'un rollback applicatif.
    }
};
