<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Operations\Models\DemoRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'company_name' => ['required', 'string', 'max:200'],
            'company_size' => ['required', 'in:1-10,11-50,51-200,201-1000,1000+'],
            'role' => ['nullable', 'string', 'max:120'],
            'referral_source' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        DemoRequest::query()->create($validated);

        return response()->json([
            'data' => [
                'message' => 'Your demo request has been received. Our team will follow up using the work email you provided.',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
            'errors' => [],
        ], 201);
    }
}
