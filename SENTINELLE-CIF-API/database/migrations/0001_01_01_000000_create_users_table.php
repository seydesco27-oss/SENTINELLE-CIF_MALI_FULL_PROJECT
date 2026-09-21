<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * La table users appartient au schéma métier DigiAML.
     *
     * Elle existe déjà dans la base digi_aml.
     *
     * Cette migration est volontairement vide afin que Laravel
     * ne tente pas de recréer ou modifier la table métier.
     */
    public function up(): void
    {
        //
    }

    /**
     * Aucun rollback Laravel sur la table métier users.
     */
    public function down(): void
    {
        //
    }
};
