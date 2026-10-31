<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarPendingBookings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class MymarPendingBookingController extends Controller
{
    public function __invoke(Request $request, MymarPendingBookings $pending): JsonResponse
    {
        $actor = $request->user();
        abort_unless(config('crm.v2_enabled') && $actor && ! $actor->trashed() && ! $actor->isExternal()
            && ($actor->admin || in_array($actor->role, [0, 1], true)), 403);
        $data = $request->validate(['after' => ['sometimes', 'regex:/^(0|[1-9][0-9]{0,17})$/'], 'refresh' => ['sometimes', 'in:0,1']]);
        try {
            $page = $pending->page((int) ($data['after'] ?? 0), 25, ($data['refresh'] ?? '0') === '1');

            try {
                $actions = app(MymarActionSettings::class)->load();
            } catch (Throwable) {
                $actions = null;
            }

            return response()->json($page + ['read_only' => true, 'actions_enabled' => (bool) ($actions['writes_enabled'] ?? false)])
                ->header('Cache-Control', 'private, no-store');
        } catch (Throwable) {
            return response()->json(['error' => 'mymar_pending_unavailable', 'message' => 'La lecture des demandes MAR est momentanement indisponible. Reessayez dans quelques instants.'], 503)
                ->header('Cache-Control', 'private, no-store')->header('Retry-After', '5');
        }
    }
}
