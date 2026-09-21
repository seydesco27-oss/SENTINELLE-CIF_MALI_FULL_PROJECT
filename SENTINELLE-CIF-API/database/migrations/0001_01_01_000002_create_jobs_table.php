<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * La table jobs existe déjà dans la base digi_aml.
     * Laravel ne doit donc pas tenter de la recréer.
     */
    public function up(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue', 250);
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');

                $table->index('queue');
            });
        }

        /*
         * job_batches existe généralement avec la structure Laravel.
         * On évite également sa recréation si elle existe déjà.
         *
         * Important :
         * longueur 191 pour éviter les problèmes de clé utf8mb4
         * avec la limite MySQL/MariaDB rencontrée dans digi_aml.
         */
        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id', 191)->primary();
                $table->string('name', 255);
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 191)->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Nous ne supprimons PAS les tables existantes de digi_aml.
     */
    public function down(): void
    {
        /*
         * Intentionnellement vide :
         *
         * jobs / job_batches / failed_jobs peuvent appartenir
         * à l'infrastructure existante de digi_aml.
         *
         * Cette migration ne doit donc pas les supprimer.
         */
    }
};

