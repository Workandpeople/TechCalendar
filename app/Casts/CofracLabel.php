<?php

namespace App\Casts;

use App\Support\CofracText;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class CofracLabel implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return CofracText::normalize($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return CofracText::normalize($value);
    }
}
