<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlCenterServiceReadinessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return app(ProductionReadinessController::class)->check($request);
    }
}
