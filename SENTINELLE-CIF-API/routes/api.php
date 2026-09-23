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
use App\Http\Controllers\Api\AdminUserController;
 use App\Http\Controllers\Api\ClientComplianceController;
 use App\Http\Controllers\Api\AccountMandateController;



Route::get('/health', [
    HealthController::class,
    'check'
]);

Route::get('/dashboard', [
    AmlDashboardController::class,
    'summary'
])->middleware(['auth:sanctum', 'permission:nav.dashboard']);

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

    Route::put('/auth/profile', [
        AuthController::class,
        'updateProfile'
    ]);

    Route::get('/admin/users', [AdminUserController::class, 'index'])
        ->middleware('permission:user.manage');
    Route::post('/admin/users', [AdminUserController::class, 'store'])
        ->middleware('permission:user.manage');
    Route::patch('/admin/users/{id}', [AdminUserController::class, 'update'])
        ->middleware('permission:user.manage');

    /*
    |--------------------------------------------------------------------------
    | CLIENTS
    |--------------------------------------------------------------------------
    */

    Route::get('/clients', [
        ClientController::class,
        'index'
    ])->middleware('permission:client.view');

    Route::post('/clients', [
        ClientController::class,
        'store'
    ])->middleware('permission:client.create');

    Route::put('/clients/{id}', [
        ClientController::class,
        'update'
    ])->middleware('permission:client.create');

     Route::patch('/clients/{id}/status', [
        ClientController::class,
        'updateStatus'
    ])->middleware('permission:client.status');


    Route::get('/clients/{id}', [
        ClientController::class,
        'show'
    ])->middleware('permission:client.view');

    Route::get('/clients/{id}/profile', [
        ClientProfileController::class,
        'show'
    ])->middleware('permission:client.view');

    Route::get('/clients/{id}/accounts', [
        ClientAccountController::class,
        'index'
    ])->middleware('permission:account.view');

    Route::get('/clients/{id}/transactions', [
        ClientActivityController::class,
        'transactions'
    ])->middleware('permission:tx.view');

    Route::get('/clients/{id}/alerts', [
        ClientActivityController::class,
        'alerts'
    ])->middleware('permission:alert.view');

    Route::get('/clients/{id}/risk', [
        ClientRiskController::class,
        'show'
    ])->middleware('permission:client.view');

    Route::get('/clients/{id}/screening', [
        ClientScreeningController::class,
        'show'
    ])->middleware('permission:screening.view');

    Route::post('/clients/{id}/screening', [
        ClientScreeningController::class,
        'run'
    ])->middleware('permission:ml.use');

    Route::get('/clients/{id}/sanctions', [
        ClientScreeningController::class,
        'sanctions'
    ])->middleware('permission:screening.view');

    Route::get('/clients/{id}/pep', [
        ClientScreeningController::class,
        'pep'
    ])->middleware('permission:screening.view');

    Route::get('/clients/{id}/features', [
        ClientFeaturesController::class,
        'show'
    ])->middleware('permission:ml.use');



// --- COMPLIANCE (mandats, PEP/RCA, KYC, moyennes, CENTIF assessments) ---

Route::get('/clients/{id}/compliance/summary', [
    ClientComplianceController::class,
    'summary',
])->middleware('permission:client.view');

Route::get('/clients/{id}/compliance/pep-rca', [
    ClientComplianceController::class,
    'pepRca',
])->middleware('permission:client.view');

Route::get('/clients/{id}/compliance/kyc', [
    ClientComplianceController::class,
    'kyc',
])->middleware('permission:client.view');

Route::get('/clients/{id}/compliance/averages', [
    ClientComplianceController::class,
    'averages',
])->middleware('permission:client.view');

Route::get('/clients/{id}/compliance/mandates', [
    ClientComplianceController::class,
    'mandates',
])->middleware('permission:client.view');

Route::get('/clients/{id}/compliance/assessments', [
    ClientComplianceController::class,
    'assessments',
])->middleware('permission:screening.view');

Route::get('/clients/{id}/compliance/network-links', [
    ClientComplianceController::class,
    'networkLinks',
])->middleware('permission:screening.view');

Route::get('/accounts/{id}/mandates', [
    AccountMandateController::class,
    'index',
])->middleware('permission:account.view');


    /*
    |--------------------------------------------------------------------------
    | COMPTES
    |--------------------------------------------------------------------------
    */

    Route::get('/accounts', [
        AccountController::class,
        'index'
    ])->middleware('permission:account.view');

    Route::post('/accounts', [
        AccountController::class,
        'store'
    ])->middleware('permission:account.create');

    Route::get('/accounts/managers', [
        AccountController::class,
        'managers'
    ])->middleware('permission:account.create');

    Route::get('/accounts/{id}', [
        AccountController::class,
        'show'
    ])->middleware('permission:account.view');

    Route::get('/accounts/{id}/transactions', [
        AccountTransactionController::class,
        'index'
    ])->middleware('permission:tx.view');

    /*
    |--------------------------------------------------------------------------
    | TRANSACTIONS
    |--------------------------------------------------------------------------
    */

    Route::get('/transactions', [
        TransactionController::class,
        'index'
    ])->middleware('permission:tx.view');

    Route::get('/transactions/suspicious', [
        TransactionController::class,
        'suspicious'
    ])->middleware('permission:alert.view');

    Route::get('/transactions/{id}/ml-features', [
        TransactionMlController::class,
        'show'
    ])->middleware('permission:ml.use');

    Route::get('/transactions/{id}/risk', [
        TransactionController::class,
        'risk'
    ])->middleware('permission:tx.view');

    Route::get('/transactions/{id}/alerts', [
        TransactionController::class,
        'alerts'
    ])->middleware('permission:alert.view');

    Route::get('/transactions/{id}/analysis', [
        TransactionAmlController::class,
        'analysis'
    ])->middleware('permission:tx.view');

    Route::post('/transactions/{id}/evaluate', [
        TransactionAmlController::class,
        'evaluate'
    ])->middleware('permission:ml.use');

        Route::post('/transactions', [
        TransactionController::class,
        'store'
    ])->middleware('permission:tx.create');

    Route::get('/transactions/{id}', [
        TransactionController::class,
        'show'
    ])->middleware('permission:tx.view');

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
    ])->middleware('permission:alert.view');

    Route::get('/alerts/open', [
        AlertController::class,
        'open'
    ])->middleware('permission:alert.view');

    Route::get('/alerts/high-risk', [
        AlertController::class,
        'highRisk'
    ])->middleware('permission:alert.view');

    Route::get('/alerts/{id}/client', [
        AlertController::class,
        'client'
    ])->middleware('permission:alert.view');

    Route::get('/alerts/{id}/transaction', [
        AlertController::class,
        'transaction'
    ])->middleware('permission:alert.view');

    Route::get('/alerts/{id}/actions', [
        AlertController::class,
        'actions'
    ])->middleware('permission:alert.view');

    Route::get('/alerts/{id}/investigations', [
        AlertController::class,
        'investigations'
    ])->middleware('permission:investigation.view');

    Route::get('/alerts/{id}/risk', [
        AlertController::class,
        'risk'
    ])->middleware('permission:alert.view');

    Route::post('/alerts/{id}/ml-score', [
        AlertController::class,
        'scoreWithMl'
    ])->middleware('permission:ml.use');

    Route::patch('/alerts/{id}/decision', [
        AlertController::class,
        'decision'
    ])->middleware('permission:alert.decide');

    Route::patch('/alerts/{id}/escalate', [
        AlertController::class,
        'escalate'
    ])->middleware('permission:alert.escalate');


    Route::get('/alerts/{id}', [
        AlertController::class,
        'show'
    ])->middleware('permission:alert.view');

    /*
    |--------------------------------------------------------------------------
    | AML ENGINE
    |--------------------------------------------------------------------------
    */

    Route::post('/aml/process', [
        AmlController::class,
        'process'
    ])->middleware('permission:engine.configure');

    Route::post('/aml/batch', [
        AmlBatchController::class,
        'process'
    ])->middleware('permission:engine.configure');

    /*
    |--------------------------------------------------------------------------
    | MACHINE LEARNING
    |--------------------------------------------------------------------------
    */

    Route::get('/ml/customer-features', [
        MlController::class,
        'customerFeatures'
    ])->middleware('permission:ml.use');

    Route::get('/ml/transaction-features', [
        MlController::class,
        'transactionFeatures'
    ])->middleware('permission:ml.use');

    Route::get('/ml/suspicious-transactions', [
        MlController::class,
        'suspiciousTransactions'
    ])->middleware('permission:ml.use');

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD AML
    |--------------------------------------------------------------------------
    */

    Route::prefix('dashboard')->group(function () {

        Route::get('/summary', [
            AmlDashboardController::class,
            'summary'
        ])->middleware('permission:nav.dashboard');

        Route::get('/risk-distribution', [
            AmlDashboardController::class,
            'riskDistribution'
        ])->middleware('permission:nav.dashboard');

        Route::get('/alerts', [
            AmlDashboardController::class,
            'alerts'
        ])->middleware('permission:nav.dashboard');

        Route::get('/transactions', [
            AmlDashboardController::class,
            'transactions'
        ])->middleware('permission:nav.dashboard');

        Route::get('/alerts-trend', [
            AmlDashboardController::class,
            'alertsTrend'
        ])->middleware('permission:nav.dashboard');

    });

/*
|--------------------------------------------------------------------------
| INVESTIGATIONS
|--------------------------------------------------------------------------
*/

Route::get('/investigations', [
    InvestigationController::class,
    'index'
])->middleware('permission:investigation.view');

Route::get('/investigations/{id}', [
    InvestigationController::class,
    'show'
])->middleware('permission:investigation.view');

Route::post('/investigations', [
    InvestigationController::class,
    'store'
])->middleware('permission:investigation.manage');

Route::patch('/investigations/{id}/close', [
    InvestigationController::class,
    'close'
])->middleware('permission:investigation.manage');

/* 
   CENTIF DECLARATIONS
*/
    Route::get('/centif/declarations', [
        CentifDeclarationController::class,
        'index'
    ])->middleware('permission:centif.view');
    Route::post('/centif/declarations', [
        CentifDeclarationController::class,
        'store'
    ])->middleware('permission:centif.manage');

    Route::get('/centif/declarations/{id}', [
        CentifDeclarationController::class,
        'show'
    ])->middleware('permission:centif.view');

    Route::patch('/centif/declarations/{id}', [
        CentifDeclarationController::class,
        'update'
    ])->middleware('permission:centif.manage');



/*
|--------------------------------------------------------------------------
| AUDIT
|--------------------------------------------------------------------------
*/

Route::get('/audit', [
    AuditController::class,
    'index'
])->middleware('permission:audit.view');

Route::get('/audit/{id}', [
    AuditController::class,
    'show'
])->middleware('permission:audit.view');


/*
|--------------------------------------------------------------------------
| NETWORK
|--------------------------------------------------------------------------
*/

Route::get('/network', [
    NetworkController::class,
    'index'
])->middleware('permission:nav.network');


    Route::get('/screening', [
        ScreeningController::class,
        'index'
    ])->middleware('permission:screening.view');

    Route::get('/reports/summary', [
        ReportsController::class,
        'summary'
    ])->middleware('permission:report.view');

    Route::post('/clients/{id}/report', [
        ClientController::class,
        'report'
    ])->middleware('permission:alert.signal');


// --- Assist (templates / contexte dossier) ---
Route::get('/ml/assist/alert/{id}', [MlAssistController::class, 'alertContext'])->middleware('permission:ml.use');
Route::get('/ml/assist/client/{id}', [MlAssistController::class, 'clientContext'])->middleware('permission:ml.use');
Route::post('/ml/assist', [MlAssistController::class, 'assist'])->middleware('permission:ml.use');
Route::post('/ml/chat', [MlAssistController::class, 'chat'])->middleware('permission:ml.use');

// --- Score ML (microservice Python) ---
Route::get('/ml/health', [MlScoreController::class, 'health'])->middleware('permission:ml.use');
Route::post('/ml/score', [MlScoreController::class, 'score'])->middleware('permission:ml.use');


Route::get('/clients/{id}/compliance/dual-risk', [
    ClientComplianceController::class,
    'dualRisk',
])->middleware('permission:client.view');


});
