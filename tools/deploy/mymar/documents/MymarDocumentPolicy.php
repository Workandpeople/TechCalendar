<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

/** Match the approved invoice/quote names without exposing unrelated source documents. */
final class MymarDocumentPolicy
{
    private const EXCLUDED = ['amo', 'mar', 'contrat', 'contrats', 'impot', 'impots', 'cni', 'identite',
        'attestation', 'attestations', 'honoraire', 'honoraires', 'rib', 'iban', 'mandat', 'mandats', 'passeport'];

    public static function kind(string $label, ?string $filename = null): ?string
    {
        $title = self::words($label);
        $file = $filename === null ? [] : self::words(preg_replace('/(?:\.pdf)+$/iD', '', preg_replace('/^\d{9,13}[_ -]+/', '', $filename)));
        if (($label !== '' && $title === []) || ($filename !== null && $filename !== '' && $file === [])
            || count($title) > 80 || count($file) > 80 || self::containsExcludedWords([...$title, ...$file])) {
            return null;
        }
        // A source title takes precedence over the original filename; neither requires the beneficiary name.
        foreach ([$title, $file] as $words) {
            foreach (['factures?' => 'invoice', 'devis' => 'quote'] as $marker => $kind) {
                foreach ($words as $word) {
                    if (preg_match('/^'.$marker.'(?:[0-9][a-z0-9]*)?$/D', $word)) {
                        return $kind;
                    }
                }
            }
        }

        return null;
    }

    private static function containsExcludedWords(array $words): bool
    {
        foreach ($words as $word) {
            // Legacy names also use AMO2026, FactureAMO and similar joined abbreviations.
            if (preg_match('/^(?:'.implode('|', self::EXCLUDED).')(?:[0-9]+)?$/D', $word)
                || preg_match('/^(?:(?:factures?|devis)(?:amo|mar)|(?:amo|mar)(?:factures?|devis))(?:[0-9]+)?$/D', $word)) {
                return true;
            }
        }

        return false;
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
