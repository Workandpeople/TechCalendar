<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AppointmentPlacementConflict extends ValidationException
{
    public array $conflicts = [];

    public bool $canForce = true;

    public static function fromConflicts(array $conflicts, bool $canForce = true): self
    {
        $errors = [];
        foreach ($conflicts as $conflict) {
            $errors[$conflict['field']][] = $conflict['message'];
        }
        $exception = self::withMessages($errors);
        $exception->conflicts = array_values($conflicts);
        $exception->canForce = $canForce;

        return $exception;
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Le placement est bloqué par les contraintes du planning.',
                'errors' => $this->errors(), 'conflicts' => $this->conflicts, 'can_force' => $this->canForce], 422);
        }
    }
}
