<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TechnicianAbsenceConflict extends ValidationException
{
    public array $conflicts = [];

    public static function fromConflicts(array $conflicts): self
    {
        $exception = self::withMessages(['absence_block' => array_column($conflicts, 'message')]);
        $exception->conflicts = $conflicts;

        return $exception;
    }

    public function render(Request $request): ?JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Aucun blocage créé : résolvez les conflits avant de réessayer. Les absences et RDV existants sont conservés.',
                'errors' => $this->errors(),
                'conflicts' => $this->conflicts,
            ], 422);
        }

        return null;
    }
}
