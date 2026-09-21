<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientProfileController;
use App\Http\Controllers\Api\ClientAccountController;
use App\Http\Controllers\Api\ClientActivityController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\ClientRiskController;
use App\Http\Controllers\Api\ClientScreeningController;
use App\Http\Controllers\Api\ClientFeaturesController;
use App\Http\Controllers\Api\AccountTransactionController;
use App\Http\Controllers\Api\AmlController;
use App\Http\Controllers\Api\TransactionAmlController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AmlDashboardController;
use App\Http\Controllers\Api\TransactionMlController;
use App\Http\Controllers\Api\AmlBatchController;
use App\Http\Controllers\Api\MlController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InvestigationController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\NetworkController;
use App\Http\Controllers\Api\CentifDeclarationController;
use App\Http\Controllers\Api\ScreeningController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\MlAssistController;
use App\Http\Controllers\Api\MlScoreController;



Route::get('/health', [
    HealthController::class,
    'check'
]);

Route::get('/dashboard', [
    DashboardController::class,
    'index'
]);

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTHENTIFICATION
    |--------------------------------------------------------------------------
    */

    Route::post('/auth/login', [
        AuthController::class,
        'login'
    ])->withoutMiddleware('auth:sanctum');

    Route::post('/auth/logout', [
        AuthController::class,
        'logout'
    ]);

    Route::get('/auth/me', [
        AuthController::class,
        'me'
    ]);

    Route::put('/auth/password', [
        AuthController::class,
        'changePassword'
    ]);

    /*
    |--------------------------------------------------------------------------
    | CLIENTS
    |--------------------------------------------------------------------------
    */

    Route::get('/clients', [
        ClientController::class,
        'index'
    ]);

        Route::post('/clients', [
        ClientController::class,
        'store'
    ]);

    Route::put('/clients/{id}', [
        ClientController::class,
        'update'
    ]);

     Route::patch('/clients/{id}/status', [
        ClientController::class,
        'updateStatus'
    ]);


    Route::get('/clients/{id}', [
        ClientController::class,
        'show'
    ]);

    Route::get('/clients/{id}/profile', [
        ClientProfileController::class,
        'show'
    ]);

    Route::get('/clients/{id}/accounts', [
        ClientAccountController::class,
        'index'
    ]);

    Route::get('/clients/{id}/transactions', [
        ClientActivityController::class,
        'transactions'
    ]);

    Route::get('/clients/{id}/alerts', [
        ClientActivityController::class,
        'alerts'
    ]);

    Route::get('/clients/{id}/risk', [
        ClientRiskController::class,
        'show'
    ]);

    Route::get('/clients/{id}/screening', [
        ClientScreeningController::class,
        'show'
    ]);

    Route::post('/clients/{id}/screening', [
        ClientScreeningController::class,
        'run'
    ]);

    Route::get('/clients/{id}/sanctions', [
        ClientScreeningController::class,
        'sanctions'
    ]);

    Route::get('/clients/{id}/pep', [
        ClientScreeningController::class,
        'pep'
    ]);

    Route::get('/clients/{id}/features', [
        ClientFeaturesController::class,
        'show'
    ]);

       

    /*
    |--------------------------------------------------------------------------
    | COMPTES
    |--------------------------------------------------------------------------
    */

    Route::get('/accounts', [
        AccountController::class,
        'index'
    ]);

    Route::get('/accounts/{id}', [
        AccountController::class,
        'show'
    ]);

    Route::get('/accounts/{id}/transactions', [
        AccountTransactionController::class,
        'index'
    ]);

    /*
    |--------------------------------------------------------------------------
    | TRANSACTIONS
    |--------------------------------------------------------------------------
    */

    Route::get('/transactions', [
        TransactionController::class,
        'index'
    ]);

    Route::get('/transactions/suspicious', [
        TransactionController::class,
        'suspicious'
    ]);

    Route::get('/transactions/{id}/ml-features', [
        TransactionMlController::class,
        'show'
    ]);

    Route::get('/transactions/{id}/risk', [
        TransactionController::class,
        'risk'
    ]);

    Route::get('/transactions/{id}/alerts', [
        TransactionController::class,
        'alerts'
    ]);

    Route::get('/transactions/{id}/analysis', [
        TransactionAmlController::class,
        'analysis'
    ]);

    Route::post('/transactions/{id}/evaluate', [
        TransactionAmlController::class,
        'evaluate'
    ]);

        Route::post('/transactions', [
        TransactionController::class,
        'store'
    ]);

    Route::get('/transactions/{id}', [
        TransactionController::class,
        'show'
    ]);

    /*
    |--------------------------------------------------------------------------
    | ALERTES
    |--------------------------------------------------------------------------
    |
    | IMPORTANT :
    | Les routes statiques doivent précéder /alerts/{id}.
    |--------------------------------------------------------------------------
    */

    Route::get('/alerts', [
        AlertController::class,
        'index'
    ]);

    Route::get('/alerts/open', [
        AlertController::class,
        'open'
    ]);

    Route::get('/alerts/high-risk', [
        AlertController::class,
        'highRisk'
    ]);

    Route::get('/alerts/{id}/client', [
        AlertController::class,
        'client'
    ]);

    Route::get('/alerts/{id}/transaction', [
        AlertController::class,
        'transaction'
    ]);

    Route::get('/alerts/{id}/actions', [
        AlertController::class,
        'actions'
    ]);

    Route::get('/alerts/{id}/investigations', [
        AlertController::class,
        'investigations'
    ]);

    Route::get('/alerts/{id}/risk', [
        AlertController::class,
        'risk'
    ]);

        Route::patch('/alerts/{id}/decision', [
        AlertController::class,
        'decision'
    ]);


    Route::get('/alerts/{id}', [
        AlertController::class,
        'show'
    ]);

    /*
    |--------------------------------------------------------------------------
    | AML ENGINE
    |--------------------------------------------------------------------------
    */

    Route::post('/aml/process', [
        AmlController::class,
        'process'
    ]);

    Route::post('/aml/batch', [
        AmlBatchController::class,
        'process'
    ]);

    /*
    |--------------------------------------------------------------------------
    | MACHINE LEARNING
    |--------------------------------------------------------------------------
    */

    Route::get('/ml/customer-features', [
        MlController::class,
        'customerFeatures'
    ]);

    Route::get('/ml/transaction-features', [
        MlController::class,
        'transactionFeatures'
    ]);

    Route::get('/ml/suspicious-transactions', [
        MlController::class,
        'suspiciousTransactions'
    ]);

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD AML
    |--------------------------------------------------------------------------
    */

    Route::prefix('dashboard')->group(function () {

        Route::get('/summary', [
            AmlDashboardController::class,
            'summary'
        ])->middleware('role:1,2,3,4,5');

        Route::get('/risk-distribution', [
            AmlDashboardController::class,
            'riskDistribution'
        ]);

        Route::get('/alerts', [
            AmlDashboardController::class,
            'alerts'
        ]);

        Route::get('/transactions', [
            AmlDashboardController::class,
            'transactions'
        ]);

        Route::get('/alerts-trend', [
            AmlDashboardController::class,
            'alertsTrend'
        ]);

    });

/*
|--------------------------------------------------------------------------
| INVESTIGATIONS
|--------------------------------------------------------------------------
*/

Route::get('/investigations', [
    InvestigationController::class,
    'index'
]);

Route::get('/investigations/{id}', [
    InvestigationController::class,
    'show'
]);

Route::post('/investigations', [
    InvestigationController::class,
    'store'
]);

Route::patch('/investigations/{id}/close', [
    InvestigationController::class,
    'close'
]);

/* 
   CENTIF DECLARATIONS
*/
    Route::get('/centif/declarations', [
        CentifDeclarationController::class,
        'index'
    ]);
    Route::post('/centif/declarations', [
        CentifDeclarationController::class,
        'store'
    ]);

    Route::get('/centif/declarations/{id}', [
        CentifDeclarationController::class,
        'show'
    ]);

    Route::patch('/centif/declarations/{id}', [
        CentifDeclarationController::class,
        'update'
    ]);



/*
|--------------------------------------------------------------------------
| AUDIT
|--------------------------------------------------------------------------
*/

Route::get('/audit', [
    AuditController::class,
    'index'
]);

Route::get('/audit/{id}', [
    AuditController::class,
    'show'
]);


/*
|--------------------------------------------------------------------------
| NETWORK
|--------------------------------------------------------------------------
*/

Route::get('/network', [
    NetworkController::class,
    'index'
]);


    Route::get('/screening', [
        ScreeningController::class,
        'index'
    ]);

    Route::get('/reports/summary', [
        ReportsController::class,
        'summary'
    ]);

    Route::post('/clients/{id}/report', [
        ClientController::class,
        'report'
    ]);


// --- Assist (templates / contexte dossier) ---
Route::get('/ml/assist/alert/{id}', [MlAssistController::class, 'alertContext']);
Route::get('/ml/assist/client/{id}', [MlAssistController::class, 'clientContext']);
Route::post('/ml/assist', [MlAssistController::class, 'assist']);

// --- Score ML (microservice Python) ---
Route::get('/ml/health', [MlScoreController::class, 'health']);
Route::post('/ml/score', [MlScoreController::class, 'score']);



});
