<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Services\Mymar\MymarBookingActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MymarBookingActionController extends Controller
{
    public function place(Request $request, MymarBookingActions $actions): JsonResponse
    {
        return $this->submit($request, $actions, 'place');
    }

    public function problem(Request $request, MymarBookingActions $actions): JsonResponse
    {
        return $this->submit($request, $actions, 'problem');
    }

    public function status(Request $request, string $id, MymarBookingActions $actions): JsonResponse
    {
        $this->authorizeActor($request);
        abort_unless(preg_match('/^[a-f0-9-]{36}$/D', $id), 404);

        return $this->respond(fn () => $actions->status($request->user(), $id));
    }

    private function submit(Request $request, MymarBookingActions $actions, string $action): JsonResponse
    {
        $this->authorizeActor($request);
        $rules = ['request_id' => ['required', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/'],
            'dossier_id' => ['required', 'integer', 'min:1'], 'expected_revision' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'force_placement' => ['prohibited'], 'force_confirmed' => ['prohibited']];
        $rules += $action === 'place' ? ['technician_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'string', 'max:40'], 'duration_minutes' => ['required', 'integer', 'min:60', 'max:480', 'multiple_of:60']]
            : ['comment' => ['required', 'string', 'min:3', 'max:255', 'not_regex:/[<>]/'],
                'reason' => ['nullable', 'string', 'max:100', 'not_regex:/[<>]/'], 'recall_at' => ['nullable', 'string', 'max:40']];
        $data = $request->validate($rules);

        return $this->respond(fn () => $actions->submit($request->user(), $data, $action));
    }

    private function authorizeActor(Request $request): void
    {
        $actor = $request->user();
        abort_unless(config('crm.v2_enabled') && $actor && ! $actor->trashed() && ! $actor->isExternal()
            && ($actor->admin || in_array($actor->role, [0, 1], true)), 403);
    }

    private function respond(\Closure $callback): JsonResponse
    {
        try {
            $result = $callback();

            return response()->json($result, $result['terminal'] ? 200 : 202)->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Vérifiez la date, la durée et les informations de la demande MAR.'], 422)->header('Cache-Control', 'private, no-store');
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            if (in_array($exception->getMessage(), ['mymar.booking_local_dossier_busy', 'mymar.booking_local_idempotency_conflict'], true)) {
                return response()->json(['message' => 'Une demande existe déjà pour ce dossier MAR. Vérifiez sa confirmation avant de continuer.'], 409)
                    ->header('Cache-Control', 'private, no-store');
            }

            return response()->json(['message' => 'La confirmation MAR n’est pas disponible. Ne renvoyez pas la demande ; vérifiez son résultat avec sa référence.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
