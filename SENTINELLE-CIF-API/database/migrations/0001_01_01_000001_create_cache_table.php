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
        Schema::create('cache', function (Blueprint $table) {
            /*
             * 250 caractères × 4 octets utf8mb4 = 1000 octets.
             *
             * Cette longueur respecte la limite d'index de notre
             * configuration MySQL tout en restant suffisante pour
             * les clés du cache Laravel.
             */
            $table->string('key', 250)->primary();

            $table->mediumText('value');

            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key', 250)->primary();

            $table->string('owner', 250);

            $table->integer('expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
