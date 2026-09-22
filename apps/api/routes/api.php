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
});
