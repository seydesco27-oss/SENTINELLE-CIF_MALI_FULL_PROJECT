<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'first_name')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('first_name', 100)->nullable()->after('username');
            });
        }

        if (! Schema::hasColumn('users', 'last_name')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('last_name', 100)->nullable()->after('first_name');
            });
        }

        if (! Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('email', 190)->nullable()->unique()->after('last_name');
            });
        }

        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('phone', 30)->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('users', 'job_title')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('job_title', 150)->nullable()->after('phone');
            });
        }

        if (! Schema::hasColumn('users', 'profile_updated_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('profile_updated_at')->nullable()->after('job_title');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_email_unique');
            });
        }

        $columns = array_values(array_filter([
            'first_name',
            'last_name',
            'email',
            'phone',
            'job_title',
            'profile_updated_at',
        ], fn (string $column): bool => Schema::hasColumn('users', $column)));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
