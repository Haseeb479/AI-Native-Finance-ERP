<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ControlCenter\Models\SupportCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControlCenterSupportCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cases = SupportCase::query()
            ->latest()
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => ['cases' => $cases->items()],
            'meta' => [
                'current_page' => $cases->currentPage(),
                'last_page' => $cases->lastPage(),
                'total' => $cases->total(),
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'customer_email' => ['required', 'email', 'max:254'],
            'category' => ['required', Rule::in(['access', 'billing', 'onboarding', 'technical', 'other'])],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);
        $validated['created_by_user_id'] = (string) $request->user()->getAuthIdentifier();
        $validated['status'] = 'open';

        $supportCase = SupportCase::query()->create($validated);

        return response()->json(['data' => ['case' => $supportCase], 'errors' => []], 201);
    }

    public function update(Request $request, SupportCase $supportCase): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'waiting_on_customer', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        $supportCase->fill($validated)->save();

        return response()->json(['data' => ['case' => $supportCase->fresh()], 'errors' => []]);
    }
}
