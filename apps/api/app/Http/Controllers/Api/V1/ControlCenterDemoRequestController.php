<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Operations\Models\DemoRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControlCenterDemoRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = DemoRequest::query()
            ->latest()
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => [
                'requests' => $requests->getCollection()->map(fn (DemoRequest $demoRequest): array => [
                    'id' => $demoRequest->id,
                    'first_name' => $demoRequest->first_name,
                    'last_name' => $demoRequest->last_name,
                    'email' => $demoRequest->email,
                    'company_name' => $demoRequest->company_name,
                    'company_size' => $demoRequest->company_size,
                    'role' => $demoRequest->role,
                    'referral_source' => $demoRequest->referral_source,
                    'message' => $demoRequest->message,
                    'status' => $demoRequest->status,
                    'created_at' => $demoRequest->created_at?->toIso8601String(),
                ])->values(),
            ],
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
            'errors' => [],
        ]);
    }

    public function update(Request $request, DemoRequest $demoRequest): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['new', 'contacted', 'scheduled', 'closed'])],
        ]);

        $demoRequest->forceFill(['status' => $validated['status']])->save();

        return response()->json([
            'data' => ['id' => $demoRequest->id, 'status' => $demoRequest->status],
            'errors' => [],
        ]);
    }
}
