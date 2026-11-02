<?php

namespace App\Support;

use Illuminate\Support\Str;

final class FrenchLocalityName
{
    public static function normalize(string $name): string
    {
        $name = Str::squish(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($name))) ?? '');

        return preg_replace_callback('/\b(st|ste)\b/', static fn ($match) => $match[1] === 'st' ? 'saint' : 'sainte', $name) ?? $name;
    }
}
