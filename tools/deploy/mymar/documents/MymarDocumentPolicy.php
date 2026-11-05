<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

/** Legacy documents have no reliable type column. Unknown labels stay closed. */
final class MymarDocumentPolicy
{
    public static function kind(string $label, string $customer): ?string
    {
        $label = self::words($label);
        $customer = self::words($customer);
        if ($label === [] || $customer === [] || count($label) > 40
            || array_intersect($label, ['amo', 'mar', 'anah', 'contrat', 'impot', 'impots', 'cni', 'identite', 'attestation', 'acompte', 'honoraires'])) {
            return null;
        }
        $markers = array_values(array_intersect($label, ['facture', 'factures', 'devis']));
        if (count($markers) !== 1) {
            return null;
        }
        $kind = $markers[0] === 'devis' ? 'quote' : 'invoice';
        $label = array_values(array_diff($label, $markers));
        $identity = array_values(array_diff($customer, ['de', 'du', 'des', 'le', 'la', 'les', 'et']));
        if (! in_array('travaux', $label, true) && array_intersect($label, $identity) === []) {
            return null;
        }
        $allowed = [...$customer, 'travaux', 'finale', 'final', 'tamponnee', 'tamponne', 'acquittee', 'acquitte'];
        foreach ($label as $word) {
            if (! in_array($word, $allowed, true) && ! ctype_digit($word)) {
                return null;
            }
        }

        return $kind;
    }

    public static function words(string $text): array
    {
        if (strlen($text) > 2048 || ! mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $text)) {
            return [];
        }
        // iconv transliteration differs between macOS and Linux for French accents.
        $text = strtr(mb_strtolower($text, 'UTF-8'), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'æ' => 'ae', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'œ' => 'oe', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $ascii === false ? [] : (preg_split('/[^a-z0-9]+/', strtolower($ascii), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
