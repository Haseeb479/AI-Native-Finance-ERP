<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ControlCenterAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = DB::table('staff_audit_events')
            ->leftJoin('users', 'users.id', '=', 'staff_audit_events.actor_user_id')
            ->orderByDesc('staff_audit_events.created_at')
            ->limit(min(max($request->integer('per_page', 100), 1), 250))
            ->get([
                'staff_audit_events.id',
                'staff_audit_events.actor_user_id',
                'users.name as actor_name',
                'users.email as actor_email',
                'staff_audit_events.action',
                'staff_audit_events.organization_id',
                'staff_audit_events.outcome',
                'staff_audit_events.metadata',
                'staff_audit_events.created_at',
            ]);

        return response()->json([
            'data' => ['events' => $events],
            'meta' => ['count' => $events->count()],
            'errors' => [],
        ]);
    }
}
