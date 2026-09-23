<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_RBAC_INTEGRATION') !== '1') {
            $this->markTestSkipped('Test d’intégration à exécuter explicitement sur la base locale RBAC.');
        }
    }

    public function test_agent_has_operational_routes_and_no_compliance_administration(): void
    {
        $agent = User::query()->where('username', 'agent.bamako.01')->firstOrFail();
        Sanctum::actingAs($agent);

        $this->getJson('/api/v1/dashboard/summary')->assertOk();
        $this->getJson('/api/v1/alerts')->assertOk();
        $this->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/transactions')->assertOk();

        $this->getJson('/api/v1/investigations')->assertForbidden();
        $this->getJson('/api/v1/centif/declarations')->assertForbidden();
        $this->getJson('/api/v1/screening')->assertForbidden();
        $this->getJson('/api/v1/audit')->assertForbidden();
        $this->getJson('/api/v1/ml/health')->assertForbidden();
        $this->patchJson('/api/v1/alerts/0/decision', [])->assertForbidden();
        $this->patchJson('/api/v1/alerts/0/escalate', [])->assertForbidden();
    }

    public function test_only_administrator_can_create_a_scoped_user_account(): void
    {
        $officer = User::query()->where('username', 'analyste.demo')->firstOrFail();
        Sanctum::actingAs($officer);
        $this->getJson('/api/v1/admin/users')->assertForbidden();

        $admin = User::query()->where('username', 'admin.test')->firstOrFail();
        $agencyId = (int) DB::table('agencies')->value('id');
        Sanctum::actingAs($admin);

        DB::beginTransaction();
        try {
            $username = 'rbac.test.'.uniqid();
            $this->postJson('/api/v1/admin/users', [
                'username' => $username,
                'password' => 'TestPassword@2026',
                'first_name' => 'Compte',
                'last_name' => 'Test',
                'role_id' => 4,
                'agency_id' => $agencyId,
                'scope_level' => 'AGENCY',
                'is_active' => true,
            ])->assertCreated()->assertJsonPath('success', true);
            $this->assertDatabaseHas('users', [
                'username' => $username,
                'agency_id' => $agencyId,
                'scope_level' => 'AGENCY',
            ]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_supervisor_has_supervision_routes_without_decision_ml_or_centif(): void
    {
        $supervisor = User::query()->where('username', 'superviseur.demo')->firstOrFail();
        Sanctum::actingAs($supervisor);

        $this->getJson('/api/v1/network')->assertOk();
        $this->getJson('/api/v1/investigations')->assertOk();
        $this->getJson('/api/v1/screening')->assertOk();
        $this->getJson('/api/v1/reports/summary')->assertOk();

        $this->getJson('/api/v1/centif/declarations')->assertForbidden();
        $this->getJson('/api/v1/audit')->assertForbidden();
        $this->getJson('/api/v1/ml/health')->assertForbidden();
        $this->patchJson('/api/v1/alerts/0/decision', [])->assertForbidden();
        $this->patchJson('/api/v1/alerts/0/escalate', [])->assertNotFound();
    }

    public function test_compliance_officer_has_caisse_compliance_workspace(): void
    {
        $officer = User::query()->where('username', 'analyste.demo')->firstOrFail();
        Sanctum::actingAs($officer);

        $this->getJson('/api/v1/dashboard/summary')->assertOk();
        $this->getJson('/api/v1/network')->assertOk();
        $this->getJson('/api/v1/centif/declarations')->assertOk();
        $this->getJson('/api/v1/audit')->assertOk();
        $this->getJson('/api/v1/ml/customer-features?limit=5')->assertOk();
        $this->getJson('/api/v1/ml/transaction-features?limit=5')->assertOk();
        $this->getJson('/api/v1/ml/suspicious-transactions?limit=5')->assertOk();
        $this->patchJson('/api/v1/alerts/0/decision', [])->assertNotFound();

        $identity = $this->postJson('/api/v1/ml/chat', [
            'object_type' => 'general',
            'object_id' => null,
            'message' => 'Qui suis-je ?',
            'history' => [],
        ])->assertOk();
        $this->assertStringContainsString($officer->username, $identity->json('data.message'));
        $this->assertSame('session', $identity->json('data.provider'));
    }

    public function test_chat_uses_the_local_engine_when_the_remote_provider_is_unavailable(): void
    {
        config([
            'services.groq.key' => 'integration-test-key',
            'services.groq.model' => 'integration-test-model',
            'services.groq.fallback_models' => [],
        ]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Provider unreachable'));

        $admin = User::query()->where('username', 'admin.test')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/ml/chat', [
            'object_type' => 'general',
            'object_id' => null,
            'message' => 'salut',
            'history' => [],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider', 'local')
            ->assertJsonPath('data.degraded', false);

        $this->assertStringContainsString('Bonjour', $response->json('data.message'));
        $this->assertStringNotContainsString('indisponible', $response->json('data.message'));

        $alerts = $this->postJson('/api/v1/ml/chat', [
            'object_type' => 'general',
            'object_id' => null,
            'message' => 'Liste les alertes critiques ouvertes.',
            'history' => [],
        ])->assertOk()
            ->assertJsonPath('data.provider', 'local')
            ->assertJsonPath('data.degraded', true);
        $this->assertStringContainsString('Alertes critiques ouvertes', $alerts->json('data.message'));

        $alertId = (int) DB::table('alerts')->value('id');
        if ($alertId > 0) {
            $analysis = $this->postJson('/api/v1/ml/chat', [
                'object_type' => 'alert',
                'object_id' => $alertId,
                'message' => 'Explique les signaux et le score ML du dossier.',
                'history' => [],
            ])->assertOk()->assertJsonPath('data.provider', 'local');
            $this->assertStringContainsString('Signal ML', $analysis->json('data.message'));
        }

    }

    public function test_client_lists_are_filtered_by_the_authenticated_scope(): void
    {
        $agent = User::query()->where('username', 'agent.bamako.01')->firstOrFail();
        Sanctum::actingAs($agent);
        $agentClients = $this->getJson('/api/v1/clients?per_page=100')->assertOk()->json('data');
        $this->assertNotEmpty($agentClients);
        $this->assertSame(
            [$agent->agency_id],
            collect($agentClients)->pluck('client_agency_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );
        $outsideClientId = \DB::table('clients')->where('agency_id', '!=', $agent->agency_id)->value('id');
        if ($outsideClientId !== null) {
            $this->getJson('/api/v1/clients/'.(int) $outsideClientId)->assertNotFound();
        }

        $officer = User::query()->where('username', 'analyste.demo')->firstOrFail();
        Sanctum::actingAs($officer);
        $officerClients = $this->getJson('/api/v1/clients?per_page=100')->assertOk()->json('data');
        $allowedAgencyIds = \DB::table('agencies')->where('caisse_id', $officer->caisse_id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $returnedAgencyIds = collect($officerClients)->pluck('client_agency_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->assertEmpty(array_diff($returnedAgencyIds, $allowedAgencyIds));
    }

    public function test_supervisor_can_escalate_an_alert_in_their_agency_with_audit_trace(): void
    {
        $supervisor = User::query()->where('username', 'superviseur.demo')->firstOrFail();
        $alertId = DB::table('alerts as a')
            ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
            ->leftJoin('transactions as t', 't.id', '=', 'a.transaction_id')
            ->whereRaw('COALESCE(t.agency_id, c.agency_id) = ?', [$supervisor->agency_id])
            ->where('a.status', 'OPEN')
            ->value('a.id');

        if ($alertId === null) {
            $this->markTestSkipped('Aucune alerte ouverte disponible dans le périmètre du superviseur.');
        }

        Sanctum::actingAs($supervisor);
        $comment = 'Escalade RBAC vérifiée automatiquement.';

        DB::beginTransaction();
        try {
            $this->patchJson('/api/v1/alerts/'.(int) $alertId.'/escalate', [
                'comment' => $comment,
            ])->assertOk()->assertJsonPath('data.status', 'IN_REVIEW');

            $this->assertDatabaseHas('alerts', [
                'id' => (int) $alertId,
                'status' => 'IN_REVIEW',
            ]);
            $this->assertDatabaseHas('alert_actions', [
                'alert_id' => (int) $alertId,
                'user_id' => $supervisor->id,
                'action_type' => 'ESCALATED_TO_COMPLIANCE',
                'comment' => $comment,
            ]);
        } finally {
            DB::rollBack();
        }
    }
}
