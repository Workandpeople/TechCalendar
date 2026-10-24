<?php

use App\Services\Coffrac\LegacyTemplateText;

it('decodes MySQL utf32 exactly while keeping old UTF8 archives readable', function () {
    $text = "Rendez-vous confirmé — {client_name}\nContrôle à 14h";
    $encoded = ['text_base64' => base64_encode(mb_convert_encoding($text, 'UTF-32BE', 'UTF-8')), 'text_charset' => 'utf32'];
    expect(LegacyTemplateText::read($encoded, 'text'))->toBe($text)
        ->and(LegacyTemplateText::read(['text' => $text], 'text'))->toBe($text)
        ->and(LegacyTemplateText::read(['text_base64' => chunk_split($encoded['text_base64'], 76, "\n"), 'text_charset' => 'utf32'], 'text'))->toBe($text)
        ->and(LegacyTemplateText::read(['text_base64' => chunk_split($encoded['text_base64'], 76, "\r\n"), 'text_charset' => 'utf32'], 'text'))->toBe($text)
        ->and(LegacyTemplateText::read(['text_base64' => '', 'text_charset' => 'utf32'], 'text'))->toBe('')
        ->and($encoded)->not->toHaveKey('text');
});

it('rejects ambiguous malformed or unsafe encoded text instead of silently replacing bytes', function (array $record) {
    expect(LegacyTemplateText::read($record, 'text'))->toBeNull();
})->with([
    'missing' => [[]],
    'null plain' => [['text' => null]],
    'not a string' => [['text' => []]],
    'invalid UTF8' => [['text' => "\xff"]],
    'invalid base64' => [['text_base64' => '****', 'text_charset' => 'utf32']],
    'noncanonical base64' => [['text_base64' => 'AAAAQQ== ', 'text_charset' => 'utf32']],
    'bare carriage return' => [['text_base64' => "AAAAQQ==\r", 'text_charset' => 'utf32']],
    'unknown charset' => [['text_base64' => 'AAAAQQ==', 'text_charset' => 'utf8']],
    'missing charset' => [['text_base64' => 'AAAAQQ==']],
    'dual representations' => [['text' => 'A', 'text_base64' => 'AAAAQQ==', 'text_charset' => 'utf32']],
    'truncated code point' => [['text_base64' => 'AAA=', 'text_charset' => 'utf32']],
    'surrogate code point' => [['text_base64' => base64_encode(pack('N', 0xD800)), 'text_charset' => 'utf32']],
    'out of Unicode range' => [['text_base64' => base64_encode(pack('N', 0x110000)), 'text_charset' => 'utf32']],
    'NUL' => [['text_base64' => 'AAAAAA==', 'text_charset' => 'utf32']],
    'controls' => [['text' => "A\x1bB"]],
    'oversized' => [['text_base64' => str_repeat('A', 400004), 'text_charset' => 'utf32']],
]);
