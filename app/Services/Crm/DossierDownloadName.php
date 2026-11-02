<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use Illuminate\Support\Str;

final class DossierDownloadName
{
    public static function make(string $name, string $extension, ?Dossier $dossier = null): string
    {
        $extension = strtolower($extension);
        $extension = preg_match('/^[a-z0-9]{1,10}$/D', $extension) ? $extension : '';
        $name = self::clean($name) ?: 'Document';
        if ($extension !== '' && str_ends_with(strtolower($name), '.'.$extension)) {
            $name = substr($name, 0, -strlen($extension) - 1);
        }
        $suffix = '';
        if ($extension === 'pdf' && $dossier !== null) {
            $person = trim($dossier->last_name.' '.$dossier->first_name);
            $company = trim($dossier->company_name ?? '');
            $beneficiary = $company ?: ($person ?: ($dossier->legacy_label ?? ''));
            if ($company !== '' && $person !== '' && ! self::contains($company, $person)) {
                $beneficiary .= ' - '.$person;
            }
            $beneficiary = mb_strcut(self::clean($beneficiary), 0, 110, 'UTF-8');
            if ($beneficiary !== '' && ! self::contains($name, $beneficiary)) {
                $suffix = ' - '.$beneficiary;
            }
        }
        $base = rtrim(mb_strcut($name, 0, 190 - strlen($suffix), 'UTF-8'), '. ') ?: 'Document';

        return $base.$suffix.($extension !== '' ? '.'.$extension : '');
    }

    private static function clean(string $value): string
    {
        return trim(Str::squish(preg_replace('/[^\pL\pN ._-]/u', '_', $value) ?? ''), '. ');
    }

    private static function contains(string $text, string $name): bool
    {
        $normalize = static fn ($value) => trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))) ?? '');

        return str_contains(' '.$normalize($text).' ', ' '.$normalize($name).' ');
    }
}
