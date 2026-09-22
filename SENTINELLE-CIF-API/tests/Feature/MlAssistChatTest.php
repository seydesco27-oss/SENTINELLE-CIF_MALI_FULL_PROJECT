<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MlAssistController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MlAssistChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Le pilote PDO SQLite est requis pour ces tests isolés.');
        }

        Config::set('services.groq.key', 'test-key');
        Config::set('services.groq.model', 'model-primary');
        Config::set('services.groq.fallback_models', ['model-fallback']);
        Config::set('services.groq.ca_bundle', '');

        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('client_number');
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('agency_id')->nullable();
        });
        Schema::create('client_individuals', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        Schema::create('client_entities', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id');
            $table->string('legal_name')->nullable();
        });
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('reference');
            $table->string('priority');
            $table->string('status');
            $table->decimal('final_score', 8, 2)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_provider_falls_back_when_primary_model_is_unavailable(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['code' => 'model_not_found']], 404)
            ->push($this->answer('Bonjour, comment puis-je vous aider ?'), 200);

        $response = $this->chat('salut');

        $response->assertOk();
        $this->assertSame('model-fallback', $response->getData(true)['data']['model']);
        $this->assertSame([], $response->getData(true)['data']['sources']);
        Http::assertSentCount(2);
    }

    public function test_agent_searches_client_then_fetches_real_alerts(): void
    {
        $this->seedClient(7, 'CLI-007', 'Mariam', 'KEITA');
        \DB::table('alerts')->insert([
            'id' => 12,
            'client_id' => 7,
            'reference' => 'AML-12',
            'priority' => 'HIGH',
            'status' => 'OPEN',
            'final_score' => 60,
            'created_at' => now(),
        ]);

        Http::fakeSequence()
            ->push($this->toolCall('call-search', 'rechercher_client', ['nom' => 'Mariam Keita']), 200)
            ->push($this->toolCall('call-alerts', 'obtenir_alertes_client', ['client_id' => 7]), 200)
            ->push($this->answer('Mariam KEITA possède une alerte ouverte AML-12, de priorité HIGH.'), 200);

        $response = $this->chat('Quelles alertes possède Mariam Keita ?');
        $data = $response->getData(true)['data'];

        $response->assertOk();
        $this->assertSame(['rechercher_client', 'obtenir_alertes_client'], $data['sources']);
        $this->assertStringContainsString('AML-12', $data['message']);
        Http::assertSentCount(3);
    }

    public function test_client_without_alert_is_reported_from_empty_database_result(): void
    {
        $this->seedClient(8, 'CLI-008', 'Amadou', 'DIALLO');

        Http::fakeSequence()
            ->push($this->toolCall('call-search', 'rechercher_client', ['nom' => 'Amadou Diallo']), 200)
            ->push($this->toolCall('call-alerts', 'obtenir_alertes_client', ['client_id' => 8]), 200)
            ->push($this->answer('Aucune alerte n’est enregistrée pour Amadou DIALLO.'), 200);

        $response = $this->chat('Amadou Diallo a-t-il une alerte ?');

        $response->assertOk();
        $this->assertStringContainsString('Aucune alerte', $response->getData(true)['data']['message']);
    }

    public function test_approximate_and_reversed_name_is_found(): void
    {
        $this->seedClient(9, 'CLI-009', 'Mariam', 'KEITA');

        Http::fakeSequence()
            ->push($this->toolCall('call-search', 'rechercher_client', ['nom' => 'Keita Maryam']), 200)
            ->push($this->answer('Le client correspondant est Mariam KEITA (CLI-009).'), 200);

        $response = $this->chat('Retrouve Keita Maryam');

        $response->assertOk();
        $requests = Http::recorded();
        $toolResult = $requests[1][0]->data()['messages'][2]['content'];
        $this->assertStringContainsString('CLI-009', $toolResult);
        $this->assertStringContainsString('Mariam KEITA', $toolResult);
    }

    public function test_session_identity_never_uses_the_open_client(): void
    {
        $user = (object) [
            'id' => 1,
            'username' => 'admin.test',
            'first_name' => 'Adama',
            'last_name' => 'Coulibaly',
            'full_name' => 'Adama Coulibaly',
            'job_title' => 'Administrateur de la plateforme',
            'role' => (object) [
                'id' => 1,
                'name' => 'ADMIN',
                'description' => 'Administrateur plateforme',
            ],
            'agency' => (object) [
                'id' => 1,
                'code' => 'AG-SEED-BAM-01',
                'name' => 'Agence Bamako Hippodrome',
                'city' => 'Bamako',
                'caisse' => (object) [
                    'id' => 1,
                    'code' => 'CIF-SEED-BAM',
                    'name' => 'Caisse Bamako Centre',
                ],
            ],
        ];

        Http::fake();

        $response = $this->chat('Qui sui-je ?', $user);
        $data = $response->getData(true)['data'];

        $response->assertOk();
        $this->assertStringContainsString('Adama Coulibaly', $data['message']);
        $this->assertStringContainsString('admin.test', $data['message']);
        $this->assertStringContainsString('ADMIN', $data['message']);
        $this->assertStringNotContainsString('Ibrahim', $data['message']);
        $this->assertSame(['session_authentifiee'], $data['sources']);
        Http::assertNothingSent();
    }

    private function chat(string $message, ?object $user = null): TestResponse
    {
        $request = Request::create('/api/v1/ml/chat', 'POST', [
            'object_type' => 'general',
            'object_id' => null,
            'message' => $message,
            'history' => [['role' => 'user', 'content' => $message]],
        ]);
        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }
        $response = app(MlAssistController::class)->chat($request);

        return TestResponse::fromBaseResponse($response);
    }

    private function seedClient(int $id, string $reference, string $firstName, string $lastName): void
    {
        \DB::table('clients')->insert(['id' => $id, 'client_number' => $reference]);
        \DB::table('client_individuals')->insert([
            'client_id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }

    private function answer(string $content): array
    {
        return ['choices' => [[
            'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => $content],
        ]]];
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return ['choices' => [[
            'finish_reason' => 'tool_calls',
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $id,
                    'type' => 'function',
                    'function' => [
                        'name' => $name,
                        'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                    ],
                ]],
            ],
        ]]];
    }
}
