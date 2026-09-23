<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MlLiveIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_ML_INTEGRATION') !== '1') {
            $this->markTestSkipped('Test de scoring réel à exécuter explicitement avec le microservice ML.');
        }
    }

    public function test_alert_scoring_calls_the_live_model_and_persists_its_explanation(): void
    {
        $admin = User::query()->where('username', 'admin.test')->firstOrFail();
        $alert = DB::table('alerts')
            ->whereNotNull('transaction_id')
            ->orderByDesc('id')
            ->first(['id', 'transaction_id']);

        $this->assertNotNull($alert, 'Une alerte liée à une transaction est requise pour ce test.');
        Sanctum::actingAs($admin);

        DB::beginTransaction();
        try {
            $response = $this->postJson('/api/v1/alerts/'.(int) $alert->id.'/ml-score')
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonStructure([
                    'data' => [
                        'engine', 'model_score', 'rule_score', 'screening_score',
                        'fused_score', 'operational_score', 'factors', 'features',
                    ],
                ]);

            $this->assertSame('cif-risk-mlp-v1', $response->json('data.engine'));
            $this->assertIsNumeric($response->json('data.model_score'));
            $this->assertNotEmpty($response->json('data.factors'));
            $this->assertDatabaseHas('predictions', [
                'transaction_id' => (int) $alert->transaction_id,
            ]);
            $this->assertDatabaseHas('risk_assessments', [
                'transaction_id' => (int) $alert->transaction_id,
                'risk_type' => 'ML_TRANSACTION_RISK',
                'source' => 'ML_MODEL',
            ]);
        } finally {
            DB::rollBack();
        }
    }
}
