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
 use App\Http\Controllers\Api\ClientComplianceController;
 use App\Http\Controllers\Api\AccountMandateController;



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

    Route::put('/auth/profile', [
        AuthController::class,
        'updateProfile'
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
    ])->middleware('role:1,2,3');

    Route::put('/clients/{id}', [
        ClientController::class,
        'update'
    ])->middleware('role:1,2,3');

     Route::patch('/clients/{id}/status', [
        ClientController::class,
        'updateStatus'
    ])->middleware('role:1,2,3');


    Route::get('/clients/{id}', [
        ClientController::class,
        'show'
    ]);

    Route::get('/clients/{id}/profile', [
        ClientProfileController::class,
        'show'
    ])->middleware('role:1,2,3');

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
    ])->middleware('role:1,2,3');

    Route::get('/clients/{id}/risk', [
        ClientRiskController::class,
        'show'
    ])->middleware('role:1,2,3');

    Route::get('/clients/{id}/screening', [
        ClientScreeningController::class,
        'show'
    ])->middleware('role:1,2,3');

    Route::post('/clients/{id}/screening', [
        ClientScreeningController::class,
        'run'
    ])->middleware('role:1,2,3');

    Route::get('/clients/{id}/sanctions', [
        ClientScreeningController::class,
        'sanctions'
    ])->middleware('role:1,2,3');

    Route::get('/clients/{id}/pep', [
        ClientScreeningController::class,
        'pep'
    ])->middleware('role:1,2,3');

    Route::get('/clients/{id}/features', [
        ClientFeaturesController::class,
        'show'
    ])->middleware('role:1,2');



// --- COMPLIANCE (mandats, PEP/RCA, KYC, moyennes, CENTIF assessments) ---

Route::get('/clients/{id}/compliance/summary', [
    ClientComplianceController::class,
    'summary',
])->middleware('role:1,2,3');

Route::get('/clients/{id}/compliance/pep-rca', [
    ClientComplianceController::class,
    'pepRca',
])->middleware('role:1,2,3');

Route::get('/clients/{id}/compliance/kyc', [
    ClientComplianceController::class,
    'kyc',
])->middleware('role:1,2,3');

Route::get('/clients/{id}/compliance/averages', [
    ClientComplianceController::class,
    'averages',
])->middleware('role:1,2,3');

Route::get('/clients/{id}/compliance/mandates', [
    ClientComplianceController::class,
    'mandates',
])->middleware('role:1,2,3');

Route::get('/clients/{id}/compliance/assessments', [
    ClientComplianceController::class,
    'assessments',
])->middleware('role:1,2,3');

Route::get('/clients/{id}/compliance/network-links', [
    ClientComplianceController::class,
    'networkLinks',
])->middleware('role:1,2,3');

Route::get('/accounts/{id}/mandates', [
    AccountMandateController::class,
    'index',
])->middleware('role:1,2,3');


    /*
    |--------------------------------------------------------------------------
    | COMPTES
    |--------------------------------------------------------------------------
    */

    Route::get('/accounts', [
        AccountController::class,
        'index'
    ]);

    Route::post('/accounts', [
        AccountController::class,
        'store'
    ])->middleware('role:1,2,3');

    Route::get('/accounts/managers', [
        AccountController::class,
        'managers'
    ])->middleware('role:1,2,3');

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
    ])->middleware('role:1,2,3');

    Route::get('/transactions/{id}/ml-features', [
        TransactionMlController::class,
        'show'
    ])->middleware('role:1,2');

    Route::get('/transactions/{id}/risk', [
        TransactionController::class,
        'risk'
    ])->middleware('role:1,2,3');

    Route::get('/transactions/{id}/alerts', [
        TransactionController::class,
        'alerts'
    ])->middleware('role:1,2,3');

    Route::get('/transactions/{id}/analysis', [
        TransactionAmlController::class,
        'analysis'
    ])->middleware('role:1,2,3');

    Route::post('/transactions/{id}/evaluate', [
        TransactionAmlController::class,
        'evaluate'
    ])->middleware('role:1,2');

        Route::post('/transactions', [
        TransactionController::class,
        'store'
    ])->middleware('role:1,2,3');

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
    ])->middleware('role:1,2,3');

    Route::get('/alerts/open', [
        AlertController::class,
        'open'
    ])->middleware('role:1,2,3');

    Route::get('/alerts/high-risk', [
        AlertController::class,
        'highRisk'
    ])->middleware('role:1,2,3');

    Route::get('/alerts/{id}/client', [
        AlertController::class,
        'client'
    ])->middleware('role:1,2,3');

    Route::get('/alerts/{id}/transaction', [
        AlertController::class,
        'transaction'
    ])->middleware('role:1,2,3');

    Route::get('/alerts/{id}/actions', [
        AlertController::class,
        'actions'
    ])->middleware('role:1,2,3');

    Route::get('/alerts/{id}/investigations', [
        AlertController::class,
        'investigations'
    ])->middleware('role:1,2,3');

    Route::get('/alerts/{id}/risk', [
        AlertController::class,
        'risk'
    ])->middleware('role:1,2,3');

    Route::post('/alerts/{id}/ml-score', [
        AlertController::class,
        'scoreWithMl'
    ])->middleware('role:1,2,3');

        Route::patch('/alerts/{id}/decision', [
        AlertController::class,
        'decision'
    ])->middleware('role:1,2,3');


    Route::get('/alerts/{id}', [
        AlertController::class,
        'show'
    ])->middleware('role:1,2,3');

    /*
    |--------------------------------------------------------------------------
    | AML ENGINE
    |--------------------------------------------------------------------------
    */

    Route::post('/aml/process', [
        AmlController::class,
        'process'
    ])->middleware('role:1,2');

    Route::post('/aml/batch', [
        AmlBatchController::class,
        'process'
    ])->middleware('role:1,2');

    /*
    |--------------------------------------------------------------------------
    | MACHINE LEARNING
    |--------------------------------------------------------------------------
    */

    Route::get('/ml/customer-features', [
        MlController::class,
        'customerFeatures'
    ])->middleware('role:1,2');

    Route::get('/ml/transaction-features', [
        MlController::class,
        'transactionFeatures'
    ])->middleware('role:1,2');

    Route::get('/ml/suspicious-transactions', [
        MlController::class,
        'suspiciousTransactions'
    ])->middleware('role:1,2');

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD AML
    |--------------------------------------------------------------------------
    */

    Route::prefix('dashboard')->group(function () {

        Route::get('/summary', [
            AmlDashboardController::class,
            'summary'
        ])->middleware('role:1,2,3');

        Route::get('/risk-distribution', [
            AmlDashboardController::class,
            'riskDistribution'
        ])->middleware('role:1,2,3');

        Route::get('/alerts', [
            AmlDashboardController::class,
            'alerts'
        ])->middleware('role:1,2,3');

        Route::get('/transactions', [
            AmlDashboardController::class,
            'transactions'
        ])->middleware('role:1,2,3');

        Route::get('/alerts-trend', [
            AmlDashboardController::class,
            'alertsTrend'
        ])->middleware('role:1,2,3');

    });

/*
|--------------------------------------------------------------------------
| INVESTIGATIONS
|--------------------------------------------------------------------------
*/

Route::get('/investigations', [
    InvestigationController::class,
    'index'
])->middleware('role:1,2,3');

Route::get('/investigations/{id}', [
    InvestigationController::class,
    'show'
])->middleware('role:1,2,3');

Route::post('/investigations', [
    InvestigationController::class,
    'store'
])->middleware('role:1,2,3');

Route::patch('/investigations/{id}/close', [
    InvestigationController::class,
    'close'
])->middleware('role:1,2,3');

/* 
   CENTIF DECLARATIONS
*/
    Route::get('/centif/declarations', [
        CentifDeclarationController::class,
        'index'
    ])->middleware('role:1,2,3');
    Route::post('/centif/declarations', [
        CentifDeclarationController::class,
        'store'
    ])->middleware('role:1,2,3');

    Route::get('/centif/declarations/{id}', [
        CentifDeclarationController::class,
        'show'
    ])->middleware('role:1,2,3');

    Route::patch('/centif/declarations/{id}', [
        CentifDeclarationController::class,
        'update'
    ])->middleware('role:1,2,3');



/*
|--------------------------------------------------------------------------
| AUDIT
|--------------------------------------------------------------------------
*/

Route::get('/audit', [
    AuditController::class,
    'index'
])->middleware('role:1,2');

Route::get('/audit/{id}', [
    AuditController::class,
    'show'
])->middleware('role:1,2');


/*
|--------------------------------------------------------------------------
| NETWORK
|--------------------------------------------------------------------------
*/

Route::get('/network', [
    NetworkController::class,
    'index'
])->middleware('role:1,2,3');


    Route::get('/screening', [
        ScreeningController::class,
        'index'
    ])->middleware('role:1,2,3');

    Route::get('/reports/summary', [
        ReportsController::class,
        'summary'
    ])->middleware('role:1,2,3');

    Route::post('/clients/{id}/report', [
        ClientController::class,
        'report'
    ])->middleware('role:1,2,3');


// --- Assist (templates / contexte dossier) ---
Route::get('/ml/assist/alert/{id}', [MlAssistController::class, 'alertContext'])->middleware('role:1,2,3');
Route::get('/ml/assist/client/{id}', [MlAssistController::class, 'clientContext'])->middleware('role:1,2,3');
Route::post('/ml/assist', [MlAssistController::class, 'assist'])->middleware('role:1,2,3');
Route::post('/ml/chat', [MlAssistController::class, 'chat'])->middleware('role:1,2,3');

// --- Score ML (microservice Python) ---
Route::get('/ml/health', [MlScoreController::class, 'health'])->middleware('role:1,2,3');
Route::post('/ml/score', [MlScoreController::class, 'score'])->middleware('role:1,2');


Route::get('/clients/{id}/compliance/dual-risk', [
    ClientComplianceController::class,
    'dualRisk',
])->middleware('role:1,2,3');


});
