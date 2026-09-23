<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminStructureController extends Controller
{
    public function index(): JsonResponse
    {
        $caisses = DB::table('caisses')->orderByDesc('id')->get([
            'id', 'code', 'name', 'city', 'country', 'status', 'integration_mode',
            'data_perimeter', 'engines_json', 'onboarded_at',
        ])->map(function ($row) {
            $row->engines = json_decode($row->engines_json ?? '{}', true);
            unset($row->engines_json);
            return $row;
        });
        return response()->json(['success' => true, 'data' => $caisses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Za-z0-9_-]+$/', 'max:20', 'unique:caisses,code'],
            'name' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'agency.code' => ['required', 'regex:/^[A-Za-z0-9_-]+$/', 'max:20', 'unique:agencies,code'],
            'agency.name' => ['required', 'string', 'max:150'],
            'agency.city' => ['required', 'string', 'max:100'],
            'integration_mode' => ['required', Rule::in(['API', 'CSV', 'SQL'])],
            'data_perimeter' => ['required', Rule::in(['REALTIME', 'BOUNDED', 'FULL', 'REFUSED'])],
            'engines' => ['required', 'array:aml,screening,centif,network,ml'],
            'engines.aml' => ['required', 'boolean'], 'engines.screening' => ['required', 'boolean'],
            'engines.centif' => ['required', 'boolean'], 'engines.network' => ['required', 'boolean'],
            'engines.ml' => ['required', 'boolean'],
            'sql_connection_hint' => ['nullable', 'string', 'max:4000'],
            'users' => ['required', 'array', 'min:3', 'max:20'],
            'users.*.username' => ['required', 'string', 'min:3', 'max:100', 'distinct:ignore_case', 'unique:users,username'],
            'users.*.first_name' => ['required', 'string', 'max:100'],
            'users.*.last_name' => ['required', 'string', 'max:100'],
            'users.*.password' => ['required', 'string', 'min:12', 'max:255'],
            'users.*.role_id' => ['required', 'integer', Rule::in([2, 3, 4])],
            'users.*.scope_level' => ['required', Rule::in(['CAISSE', 'AGENCY'])],
        ]);
        $users = collect($data['users']);
        if (! $users->contains(fn ($u) => (int) $u['role_id'] === 3 && $u['scope_level'] === 'CAISSE')
            || ! $users->contains(fn ($u) => (int) $u['role_id'] === 2)
            || ! $users->contains(fn ($u) => (int) $u['role_id'] === 4)) {
            throw ValidationException::withMessages(['users' => 'Prévoyez un administrateur de caisse (Superviseur / CAISSE), un responsable conformité et un agent.']);
        }
        foreach ($data['users'] as $i => $u) {
            if ((int) $u['role_id'] === 4 && $u['scope_level'] !== 'AGENCY') {
                throw ValidationException::withMessages(["users.$i.scope_level" => 'Un agent est strictement rattaché à son agence.']);
            }
        }

        $id = DB::transaction(function () use ($data, $request) {
            $id = DB::table('caisses')->insertGetId([
                'code' => strtoupper($data['code']), 'name' => $data['name'],
                'city' => $data['city'], 'country' => $data['country'], 'status' => 'ACTIVE',
                'integration_mode' => $data['integration_mode'], 'data_perimeter' => $data['data_perimeter'],
                'engines_json' => json_encode($data['engines'], JSON_THROW_ON_ERROR),
                'api_key' => $data['integration_mode'] === 'API' ? 'sk_sent_'.bin2hex(random_bytes(24)) : null,
                'sql_connection_hint' => $data['integration_mode'] === 'SQL' ? ($data['sql_connection_hint'] ?? null) : null,
                'onboarded_at' => now(), 'onboarded_by' => $request->user()->id,
            ]);
            $agencyId = DB::table('agencies')->insertGetId([
                'caisse_id' => $id, 'code' => strtoupper($data['agency']['code']),
                'name' => $data['agency']['name'], 'city' => $data['agency']['city'],
            ]);
            foreach ($data['users'] as $u) {
                User::create([
                    'username' => $u['username'], 'first_name' => $u['first_name'], 'last_name' => $u['last_name'],
                    'password_hash' => Hash::make($u['password']), 'role_id' => $u['role_id'],
                    'agency_id' => $agencyId, 'caisse_id' => $id, 'scope_level' => $u['scope_level'],
                    'is_active' => true, 'profile_updated_at' => now(),
                ]);
            }
            return $id;
        });
        return response()->json(['success' => true, 'message' => 'Structure et utilisateurs inscrits.', 'data' => ['id' => $id]], 201);
    }

    public function access(Request $request, int $id): JsonResponse
    {
        $caisse = DB::table('caisses')->where('id', $id)->first();
        abort_unless($caisse, 404, 'Structure introuvable.');
        $mode = $caisse->integration_mode;
        $endpoint = $request->getSchemeAndHttpHost().'/api/v1/ingest/transactions';
        $data = [
            'id' => $id, 'code' => $caisse->code, 'name' => $caisse->name, 'mode' => $mode,
            'contract_status' => 'CONTRACT_ONLY',
            'notice' => 'Contrat de raccordement à remettre à la caisse. La réception automatique des flux sera activée lors du raccordement de son SI.',
        ];
        if ($mode === 'API') {
            $data += [
                'api_key' => $caisse->api_key, 'endpoint' => $endpoint,
                'headers' => ['X-API-Key' => $caisse->api_key, 'X-Caisse-Code' => $caisse->code, 'Content-Type' => 'application/json'],
                'example' => ['account_number' => 'DEMO-A-001', 'amount' => 25000, 'currency' => 'XOF', 'tx_date' => '2026-09-23 10:00:00', 'channel' => 'GUICHET', 'counterpart_name' => 'Contrepartie exemple'],
            ];
            $data['guide'] = "SENTINELLE-CIF — contrat API\nStructure : {$caisse->name} ({$caisse->code})\nPOST {$endpoint}\nX-API-Key: {$caisse->api_key}\nX-Caisse-Code: {$caisse->code}\nContent-Type: application/json\n\n".json_encode($data['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n".$data['notice'];
        } elseif ($mode === 'CSV') {
            $data['files'] = $this->csvFiles($id);
            $data['guide'] = "SENTINELLE-CIF — import CSV\nStructure : {$caisse->name} ({$caisse->code})\nEncodage UTF-8 ; séparateur point-virgule ; dates YYYY-MM-DD HH:MM:SS.\nOrdre de remise : clients.csv, accounts.csv, transactions.csv.\nLes références client, compte et agence doivent être cohérentes dans les trois fichiers.\nLes lignes DEMO sont des exemples fictifs à remplacer.\n\n".$data['notice'];
        } else {
            $data['instructions'] = "Option A : fournir un dump des tables sources clients, comptes et transactions.\nOption B : fournir host, port, schéma et un utilisateur disposant uniquement de SELECT sur les vues de mapping. Transmettre les secrets par un canal sécurisé.\nAucun droit INSERT, UPDATE, DELETE ou DDL n’est nécessaire.\n".($caisse->sql_connection_hint ?? '');
            $data['script'] = "-- SENTINELLE-CIF : modèle de vues à adapter aux tables du SI source.\n-- À exécuter par l'administrateur de la caisse après validation du mapping.\nCREATE VIEW sentinelle_clients AS\nSELECT client_number, first_name, last_name, nina, phone, agency_code FROM source_clients;\n\nCREATE VIEW sentinelle_accounts AS\nSELECT account_number, client_number, type, currency, opening_balance FROM source_accounts;\n\nCREATE VIEW sentinelle_transactions AS\nSELECT account_number, amount, currency, tx_date, channel, counterpart_name FROM source_transactions;\n";
        }
        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'no-store');
    }

    public function csvExamples(int $id): JsonResponse
    {
        abort_unless(DB::table('caisses')->where('id', $id)->where('integration_mode', 'CSV')->exists(), 404, 'Connecteur CSV introuvable.');
        return response()->json(['success' => true, 'data' => $this->csvFiles($id)]);
    }

    public function provisionApiKey(Request $request, int $id): JsonResponse
    {
        $caisse = DB::transaction(function () use ($request, $id) {
            $row = DB::table('caisses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && $row->integration_mode === 'API', 404, 'Connecteur API introuvable.');
            if (! $row->api_key) {
                DB::table('caisses')->where('id', $id)->update([
                    'api_key' => 'sk_sent_'.bin2hex(random_bytes(24)),
                    'onboarded_at' => $row->onboarded_at ?? now(),
                    'onboarded_by' => $row->onboarded_by ?? $request->user()->id,
                ]);
            }
            return $row;
        });
        return response()->json(['success' => true, 'message' => 'Accès API disponible.', 'data' => ['id' => $caisse->id]])->header('Cache-Control', 'no-store');
    }

    private function csvFiles(int $id): array
    {
        $agency = DB::table('agencies')->where('caisse_id', $id)->orderBy('id')->value('code') ?? 'AG-DEMO';
        return [
            'clients.csv' => "client_number;first_name;last_name;nina;phone;agency_code\nDEMO-C-001;Prenom;Exemple;DEMO-NINA;;{$agency}\n",
            'accounts.csv' => "account_number;client_number;type;currency;opening_balance\nDEMO-A-001;DEMO-C-001;COURANT;XOF;0\n",
            'transactions.csv' => "account_number;amount;currency;tx_date;channel;counterpart_name\nDEMO-A-001;25000;XOF;2026-09-23 10:00:00;GUICHET;Contrepartie exemple\n",
        ];
    }
}
