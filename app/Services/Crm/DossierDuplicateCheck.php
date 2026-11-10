<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DossierDuplicateCheck
{
    public function ensureAvailable(array $data): void
    {
        // Serialize submissions of the same exact fiche, including across organisations.
        // The caller holds this lock until its dossier and operation have been committed.
        Service::query()->whereKey($data['service_ids'][0])->lockForUpdate()->firstOrFail();
        if (Dossier::withTrashed()->where('reference', $data['reference'])->exists()) {
            throw ValidationException::withMessages(['reference' => 'Cette référence est déjà utilisée. Le dossier n’a pas été créé en double.']);
        }
        $phones = self::phones($data['phone'] ?? null);
        $address = self::address($data);
        if ($phones === [] && $address === null) {
            return;
        }

        // Use the service FK index and stream only contact fields. No listing-time scan,
        // stale derived index or rewrite of historical/imported dossiers is needed.
        $candidates = DB::table('dossier_operations as o')->join('dossiers as d', 'd.id', '=', 'o.dossier_id')
            ->where('o.service_id', $data['service_ids'][0])->whereNull('d.deleted_at')
            ->select(['d.phone', 'd.address', 'd.postal_code', 'd.city'])->cursor();
        foreach ($candidates as $candidate) {
            $samePhone = $phones !== [] && array_intersect($phones, self::phones($candidate->phone)) !== [];
            $sameAddress = $address !== null && $address === self::address((array) $candidate);
            if ($samePhone || $sameAddress) {
                // Never disclose another organisation's dossier or contact details.
                throw ValidationException::withMessages(['service_ids' => 'Un dossier existe déjà pour cette fiche CEE avec le même téléphone ou la même adresse. Le nouveau dossier n’a pas été créé. Contactez l’équipe si nécessaire.']);
            }
        }
    }

    public static function phones(?string $value): array
    {
        $numbers = preg_split('~[\r\n;,/|]+~u', $value ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];
        foreach ($numbers as $number) {
            $number = preg_replace('/[\s.()\-]+/u', '', trim($number));
            if (preg_match('/^[1-9][0-9]{8}$/D', $number)) {
                $number = '0'.$number;
            } elseif (preg_match('/^(?:\+33|0033|33)0?([1-9][0-9]{8})$/D', $number, $matches)) {
                $number = '0'.$matches[1];
            }
            if (preg_match('/^0[1-9][0-9]{8}$/D', $number)) {
                $normalized[] = '+33'.substr($number, 1);
            } else {
                $number = str_starts_with($number, '00') ? '+'.substr($number, 2) : $number;
                if (preg_match('/^\+[1-9][0-9]{7,14}$/D', $number)) {
                    $normalized[] = $number;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function address(array $data): ?string
    {
        $street = self::text($data['address'] ?? '');
        $city = self::text($data['city'] ?? '');
        $postal = trim($data['postal_code'] ?? '');
        if (! preg_match('/^[0-9]{5}$/D', $postal)) {
            preg_match_all('/\b[0-9]{5}\b/', $street, $matches);
            $postal = $matches[0] === [] ? '' : end($matches[0]);
        }
        if ($street === '' || $city === '') {
            return null;
        }
        $locality = trim($postal.' '.$city);
        foreach ([$locality.' france', $locality, $city.' france', $city] as $suffix) {
            if ($street === $suffix) {
                return null;
            }
            if (str_ends_with($street, ' '.$suffix)) {
                $street = substr($street, 0, -strlen(' '.$suffix));
                break;
            }
        }

        // A shared town centroid is never evidence of a shared street/site.
        return $street !== '' && $street !== $postal ? $street.'|'.$locality : null;
    }

    private static function text(string $value): string
    {
        $value = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))));

        return preg_replace(['/\bst\b/', '/\bste\b/'], ['saint', 'sainte'], $value);
    }
}
