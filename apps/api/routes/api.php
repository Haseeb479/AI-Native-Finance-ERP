<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| AI-Native Finance ERP REST API endpoints.
| All responses strictly adhere to the standard envelope format:
| { "data": ..., "meta": ..., "errors": [] }
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', function (): JsonResponse {
        $dbStatus = 'ok';
        $dbError = null;

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'error';
            $dbError = $e->getMessage();
        }

        $healthy = ($dbStatus === 'ok');

        return response()->json([
            'data' => [
                'status' => $healthy ? 'healthy' : 'degraded',
                'services' => [
                    'database' => [
                        'status' => $dbStatus,
                        'driver' => config('database.default'),
                        'error' => $dbError,
                    ],
                ],
                'version' => 'v1.0.0',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'environment' => config('app.env'),
            ],
            'errors' => $dbError ? [$dbError] : [],
        ], $healthy ? 200 : 503);
    });

    // Authentication Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register']);
        Route::post('/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
            Route::get('/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
        });
    });

    // Multi-Tenant Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/organizations', [\App\Http\Controllers\Api\V1\OrganizationController::class, 'index']);
        Route::post('/organizations', [\App\Http\Controllers\Api\V1\OrganizationController::class, 'store']);
        Route::get('/organizations/{id}', [\App\Http\Controllers\Api\V1\OrganizationController::class, 'show']);

        // Member & RBAC Management
        Route::get('/organizations/{id}/members', [\App\Http\Controllers\Api\V1\OrganizationMemberController::class, 'index']);
        Route::post('/organizations/{id}/members', [\App\Http\Controllers\Api\V1\OrganizationMemberController::class, 'store']);
        Route::delete('/organizations/{id}/members/{userId}', [\App\Http\Controllers\Api\V1\OrganizationMemberController::class, 'destroy']);

        // Chart of Accounts (COA) Management
        Route::get('/account-types', [\App\Http\Controllers\Api\V1\AccountController::class, 'types']);
        Route::get('/organizations/{orgId}/accounts', [\App\Http\Controllers\Api\V1\AccountController::class, 'index']);
        Route::post('/organizations/{orgId}/accounts', [\App\Http\Controllers\Api\V1\AccountController::class, 'store']);
        Route::post('/organizations/{orgId}/accounts/seed-template', [\App\Http\Controllers\Api\V1\AccountController::class, 'seedTemplate']);
        Route::get('/organizations/{orgId}/accounts/{accountId}', [\App\Http\Controllers\Api\V1\AccountController::class, 'show']);
        Route::put('/organizations/{orgId}/accounts/{accountId}', [\App\Http\Controllers\Api\V1\AccountController::class, 'update']);
        Route::delete('/organizations/{orgId}/accounts/{accountId}', [\App\Http\Controllers\Api\V1\AccountController::class, 'destroy']);

        // Fiscal Years & Accounting Periods
        Route::get('/organizations/{orgId}/fiscal-years', [\App\Http\Controllers\Api\V1\PeriodController::class, 'indexFiscalYears']);
        Route::post('/organizations/{orgId}/fiscal-years', [\App\Http\Controllers\Api\V1\PeriodController::class, 'storeFiscalYear']);
        Route::get('/organizations/{orgId}/periods', [\App\Http\Controllers\Api\V1\PeriodController::class, 'indexPeriods']);
        Route::post('/organizations/{orgId}/periods/{periodId}/close', [\App\Http\Controllers\Api\V1\PeriodController::class, 'close']);
        Route::post('/organizations/{orgId}/periods/{periodId}/reopen', [\App\Http\Controllers\Api\V1\PeriodController::class, 'reopen']);
        Route::post('/organizations/{orgId}/periods/{periodId}/lock', [\App\Http\Controllers\Api\V1\PeriodController::class, 'lock']);

        // General Ledger Double-Entry Journals
        Route::get('/organizations/{orgId}/journals', [\App\Http\Controllers\Api\V1\JournalController::class, 'index']);
        Route::post('/organizations/{orgId}/journals', [\App\Http\Controllers\Api\V1\JournalController::class, 'store']);
        Route::get('/organizations/{orgId}/journals/{journalId}', [\App\Http\Controllers\Api\V1\JournalController::class, 'show']);
        Route::put('/organizations/{orgId}/journals/{journalId}', [\App\Http\Controllers\Api\V1\JournalController::class, 'update']);
        Route::post('/organizations/{orgId}/journals/{journalId}/post', [\App\Http\Controllers\Api\V1\JournalController::class, 'post']);
        Route::post('/organizations/{orgId}/journals/{journalId}/reverse', [\App\Http\Controllers\Api\V1\JournalController::class, 'reverse']);
    });
});
