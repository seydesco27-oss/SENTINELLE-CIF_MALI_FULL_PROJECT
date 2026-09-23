<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SaasOnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SAAS_INTEGRATION') !== '1') $this->markTestSkipped('Lancer explicitement sur MySQL local avec RUN_SAAS_INTEGRATION=1.');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (getenv('RUN_SAAS_INTEGRATION') === '1' && DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'admin.test')->firstOrFail());
    }

    private function payload(string $mode): array
    {
        $suffix = substr(bin2hex(random_bytes(5)), 0, 10);
        return [
            'code' => 'SAS'.$suffix, 'name' => 'Caisse de démonstration', 'city' => 'Bamako', 'country' => 'Mali',
            'agency' => ['code' => 'AG'.$suffix, 'name' => 'Agence initiale', 'city' => 'Bamako'],
            'integration_mode' => $mode, 'data_perimeter' => 'BOUNDED',
            'engines' => ['aml' => true, 'screening' => true, 'centif' => true, 'network' => true, 'ml' => false],
            'users' => [
                ['username' => 'sup'.$suffix, 'first_name' => 'Awa', 'last_name' => 'Kone', 'password' => 'Password#2026!', 'role_id' => 3, 'scope_level' => 'CAISSE'],
                ['username' => 'co'.$suffix, 'first_name' => 'Issa', 'last_name' => 'Kone', 'password' => 'Password#2026!', 'role_id' => 2, 'scope_level' => 'AGENCY'],
                ['username' => 'agent'.$suffix, 'first_name' => 'Fanta', 'last_name' => 'Kone', 'password' => 'Password#2026!', 'role_id' => 4, 'scope_level' => 'AGENCY'],
            ],
        ];
    }

    public function test_admin_routes_reject_non_admin_even_with_a_stored_permission(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'analyste.demo')->firstOrFail());
        foreach (['/api/v1/admin/caisses', '/api/admin/caisses', '/api/v1/admin/users', '/api/admin/screening-lists'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson('/api/admin/screening/run-batch', ['batch_size' => 1, 'force' => false])->assertForbidden();
    }

    public function test_all_three_modes_create_scoped_accounts_and_only_the_relevant_access_contract(): void
    {
        $this->admin();
        foreach (['API', 'CSV', 'SQL'] as $mode) {
            $payload = $this->payload($mode);
            $response = $this->postJson('/api/admin/caisses', $payload)->assertCreated();
            $id = $response->json('data.id');
            $this->assertDatabaseHas('caisses', ['id' => $id, 'integration_mode' => $mode, 'data_perimeter' => 'BOUNDED']);
            $this->assertSame(3, DB::table('users')->where('caisse_id', $id)->count());
            $this->assertTrue(DB::table('users')->where('caisse_id', $id)->where('role_id', 3)->where('scope_level', 'CAISSE')->exists());
            $this->assertTrue(DB::table('users')->where('caisse_id', $id)->where('role_id', 4)->where('scope_level', 'AGENCY')->exists());
            $this->assertNotSame('Password#2026!', DB::table('users')->where('username', $payload['users'][0]['username'])->value('password_hash'));
            $access = $this->getJson("/api/admin/caisses/$id/access")->assertOk()->json('data');
            $this->assertSame($mode, $access['mode']);
            if ($mode === 'API') {
                $this->assertMatchesRegularExpression('/^sk_sent_[a-f0-9]{48}$/', $access['api_key']);
                $this->assertArrayNotHasKey('files', $access);
            } elseif ($mode === 'CSV') {
                $this->assertCount(3, $access['files']);
                $this->getJson("/api/admin/caisses/$id/csv-examples")->assertOk()->assertJsonCount(3, 'data');
                $this->assertArrayNotHasKey('api_key', $access);
            } else {
                $this->assertStringContainsString('CREATE VIEW sentinelle_clients', $access['script']);
                $this->assertArrayNotHasKey('api_key', $access);
            }
        }
        $list = $this->getJson('/api/admin/caisses')->assertOk();
        $this->assertStringNotContainsString('sk_sent_', $list->getContent());
    }

    public function test_invalid_initial_roles_are_rejected_atomically(): void
    {
        $this->admin(); $payload = $this->payload('API');
        $payload['users'][2]['scope_level'] = 'CAISSE';
        $this->postJson('/api/admin/caisses', $payload)->assertUnprocessable();
        $this->assertFalse(DB::table('caisses')->where('code', $payload['code'])->exists());
    }

    public function test_existing_caisse_can_receive_one_stable_api_key(): void
    {
        $this->admin();
        $suffix = substr(bin2hex(random_bytes(5)), 0, 10);
        $id = DB::table('caisses')->insertGetId(['code' => 'OLD'.$suffix, 'name' => 'Caisse existante', 'city' => 'Bamako', 'country' => 'Mali', 'status' => 'ACTIVE']);
        $this->assertNull($this->getJson("/api/admin/caisses/$id/access")->assertOk()->json('data.api_key'));
        $this->postJson("/api/admin/caisses/$id/access-key")->assertOk();
        $key = $this->getJson("/api/admin/caisses/$id/access")->assertOk()->json('data.api_key');
        $this->assertMatchesRegularExpression('/^sk_sent_[a-f0-9]{48}$/', $key);
        $this->postJson("/api/admin/caisses/$id/access-key")->assertOk();
        $this->assertSame($key, $this->getJson("/api/admin/caisses/$id/access")->assertOk()->json('data.api_key'));
    }

    public function test_import_upserts_both_screening_tables_and_keeps_failed_batch_history(): void
    {
        $this->admin();
        $csv = "Entity_EU_ReferenceNumber;Entity_SubjectType;NameAlias_WholeName\nEU.TEST.2026;P;Jean Essai\nEU.TEST.2026;P;J Essai\n";
        foreach ([1, 2] as $iteration) {
            $result = $this->postJson('/api/admin/screening-lists/2/import', ['file' => UploadedFile::fake()->createWithContent('eu.csv', $csv)])->assertOk();
            $this->assertSame('SUCCESS', $result->json('data.status'));
            $this->assertStringContainsString('lancer un screening batch', $result->json('message'));
        }
        $this->assertSame(1, DB::table('screening_list_entries')->where('screening_list_id', 2)->where('external_reference', 'EU.TEST.2026')->count());
        $entity = DB::table('sanctions_entities')->where('source', 'EU')->where('source_entity_id', 'EU.TEST.2026')->first();
        $this->assertNotNull($entity);
        $this->assertSame(1, DB::table('sanctions_aliases')->where('sanctions_entity_id', $entity->id)->where('alias_name', 'J Essai')->count());
        $this->postJson('/api/admin/screening-lists/2/import', ['file' => UploadedFile::fake()->createWithContent('bad.xml', '<html>login</html>')])->assertUnprocessable();
        $this->assertSame('FAILED', DB::table('sanctions_import_batches')->where('source_file', 'bad.xml')->orderByDesc('id')->value('import_status'));
        $this->assertSame(1, DB::table('screening_list_entries')->where('screening_list_id', 2)->where('external_reference', 'EU.TEST.2026')->count());
        $this->assertNotNull(DB::table('screening_lists')->where('id', 2)->value('last_update'));
    }

    public function test_batch_requires_scope_and_calls_existing_procedure_only_for_selected_client(): void
    {
        $this->admin();
        $this->postJson('/api/admin/screening/run-batch', ['batch_size' => 1, 'force' => true])->assertUnprocessable();
        $client = DB::table('clients')->orderBy('id')->first(['id', 'agency_id']);
        $this->assertNotNull($client);
        $before = DB::table('screenings')->where('client_id', $client->id)->count();
        $outside = DB::table('screenings')->where('client_id', '!=', $client->id)->count();
        $result = $this->postJson('/api/admin/screening/run-batch', ['agency_id' => $client->agency_id, 'batch_size' => 1, 'force' => true, 'after_id' => max(0, $client->id - 1)])->assertOk();
        $this->assertSame(1, $result->json('data.processed'));
        $this->assertGreaterThan($before, DB::table('screenings')->where('client_id', $client->id)->count());
        $this->assertSame($outside, DB::table('screenings')->where('client_id', '!=', $client->id)->count());
    }

    public function test_admin_assistant_guides_onboarding_and_redirects_business_analysis(): void
    {
        $this->admin();
        config(['services.groq.key' => '']);
        $guide = $this->postJson('/api/v1/ml/chat', ['object_type' => 'general', 'message' => 'Comment importer la liste OFAC ?', 'history' => []])->assertOk();
        $this->assertStringContainsString('lancez un screening batch', $guide->json('data.message'));
        $this->assertSame('local', $guide->json('data.provider'));
        $business = $this->postJson('/api/v1/ml/chat', ['object_type' => 'general', 'message' => 'Analyse ce dossier LBC', 'history' => []])->assertOk();
        $this->assertStringContainsString('Conformité', $business->json('data.message'));
        $this->assertStringNotContainsString('Synthèse du dossier', $business->json('data.message'));
        $this->postJson('/api/v1/ml/score', ['client_id' => 1])->assertForbidden();
    }

    public function test_supervisor_caisse_network_stays_isolated_even_with_forged_filters(): void
    {
        $supervisor = User::query()->where('username', 'superviseur.demo')->firstOrFail();
        $supervisor->scope_level = 'CAISSE';
        $supervisor->caisse_id = DB::table('agencies')->where('id', $supervisor->agency_id)->value('caisse_id');
        Sanctum::actingAs($supervisor);
        $network = $this->getJson('/api/v1/network?caisse_id=0&agency_id=0')->assertOk()->json('data');
        $this->assertNotEmpty($network['caisses']);
        foreach ($network['caisses'] as $caisse) $this->assertSame((int) $supervisor->caisse_id, (int) $caisse['id']);
        foreach ($network['agencies'] as $agency) $this->assertSame((int) $supervisor->caisse_id, (int) $agency['caisse_id']);
        foreach ($network['caisses'] as $caisse) {
            $visible = collect($network['agencies'])->where('caisse_id', $caisse['id']);
            $this->assertSame($visible->count(), (int) $caisse['agency_count']);
            $this->assertSame((int) $visible->sum('client_count'), (int) $caisse['client_count']);
        }
    }
}
