<?php

use App\Support\AccessProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            if (! Schema::hasColumn('users', 'scope_level')) {
                Schema::table('users', function (Blueprint $table): void {
                    $table->string('scope_level', 20)->nullable()->after('role_id');
                });
            }
            if (! Schema::hasColumn('users', 'caisse_id')) {
                Schema::table('users', function (Blueprint $table): void {
                    $table->bigInteger('caisse_id')->nullable()->after('agency_id')->index();
                });
            }
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('role_id');
                $table->string('permission_code', 80);
                $table->boolean('allowed')->default(true);
                $table->unique(['role_id', 'permission_code']);
                $table->index('permission_code');
                $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->delete();
            $roleIds = DB::table('roles')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $rows = [];
            foreach ($roleIds as $roleId) {
                $allowed = AccessProfile::permissions($roleId);
                foreach (AccessProfile::ALL_PERMISSION_CODES as $permission) {
                    $rows[] = [
                        'role_id' => $roleId,
                        'permission_code' => $permission,
                        'allowed' => in_array($permission, $allowed, true),
                    ];
                }
            }
            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table('role_permissions')->insert($chunk);
            }
        }

        if (Schema::hasTable('users')) {
            DB::table('users')->where('role_id', AccessProfile::ADMIN)->update(['scope_level' => 'PLATFORM']);
            DB::table('users')->where('role_id', AccessProfile::COMPLIANCE_OFFICER)->update(['scope_level' => 'CAISSE']);
            DB::table('users')->where('role_id', AccessProfile::SUPERVISOR)->update(['scope_level' => 'AGENCY']);
            DB::table('users')->where('role_id', AccessProfile::AGENT)->update(['scope_level' => 'AGENCY']);
            DB::table('users')->where('role_id', AccessProfile::ACCOUNT_MANAGER)->update(['scope_level' => 'PORTFOLIO']);

            DB::table('users')->whereNull('caisse_id')->whereNotNull('agency_id')->orderBy('id')->eachById(
                function ($user): void {
                    $caisseId = DB::table('agencies')->where('id', $user->agency_id)->value('caisse_id');
                    DB::table('users')->where('id', $user->id)->update(['caisse_id' => $caisseId]);
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        if (Schema::hasTable('users')) {
            $columns = array_values(array_filter(
                ['scope_level', 'caisse_id'],
                fn (string $column): bool => Schema::hasColumn('users', $column)
            ));
            if ($columns !== []) {
                Schema::table('users', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
