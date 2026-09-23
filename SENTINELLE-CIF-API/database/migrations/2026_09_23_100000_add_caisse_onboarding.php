<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'integration_mode' => fn (Blueprint $t) => $t->string('integration_mode', 20)->default('API'),
            'api_key' => fn (Blueprint $t) => $t->string('api_key', 64)->nullable(),
            'data_perimeter' => fn (Blueprint $t) => $t->string('data_perimeter', 30)->default('REALTIME'),
            'engines_json' => fn (Blueprint $t) => $t->json('engines_json')->nullable(),
            'sql_connection_hint' => fn (Blueprint $t) => $t->text('sql_connection_hint')->nullable(),
            'onboarded_at' => fn (Blueprint $t) => $t->timestamp('onboarded_at')->nullable(),
            'onboarded_by' => fn (Blueprint $t) => $t->bigInteger('onboarded_by')->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('caisses', $name)) Schema::table('caisses', $add);
        }
        foreach (['org.register', 'nav.onboarding_org', 'nav.users', 'user.manage', 'list.import', 'list.publish', 'screening.run_batch'] as $permission) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => 1, 'permission_code' => $permission], ['allowed' => true]
            );
        }
    }

    public function down(): void
    {
        // Migration additive : conserver les accès et les structures déjà inscrites.
    }
};
