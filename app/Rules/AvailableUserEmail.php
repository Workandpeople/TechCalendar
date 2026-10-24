<?php

namespace App\Rules;

use App\Models\User;
use App\Services\UserAccountConflict;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AvailableUserEmail implements ValidationRule
{
    public function __construct(private readonly User $actor, private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $conflict = app(UserAccountConflict::class)->find($this->actor, $value, $this->ignoreId);

        if ($conflict !== null) {
            $fail($conflict['message']);
        }
    }
}
