<?php

use App\Services\Sms\SmsText;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes only a single unambiguous mobile number', function (string $input, string $expected) {
    expect(SmsText::phone($input))->toBe($expected);
})->with([['06 12 34 56 78', '+33612345678'], ['+33 (6) 12-34-56-78', '+33612345678'], ['0033661234567', '+33661234567'], ['+262692123456', '+262692123456']]);

it('rejects ambiguous multiple and invalid numbers', function (string $input) {
    expect(fn () => SmsText::phone($input))->toThrow(ValidationException::class);
})->with(['0612345678/0712345678', 'tel:0612345678', '33612345678', '01 23 45 67 89', '+33123456789', '06123', '0692123456 0693123456', '']);

it('counts GSM extended characters and Unicode segments without changing the text', function () {
    expect(SmsText::metrics(str_repeat('a', 160)))->toBe(['unicode' => 0, 'characters' => 160, 'segments' => 1]);
    expect(SmsText::metrics(str_repeat('a', 161))['segments'])->toBe(2);
    expect(SmsText::metrics(str_repeat('€', 81))['segments'])->toBe(2);
    expect(SmsText::metrics(str_repeat('’', 71)))->toBe(['unicode' => 1, 'characters' => 71, 'segments' => 2]);
    expect(SmsText::metrics(str_repeat('🙂', 36))['segments'])->toBe(2);
    expect(SmsText::plain('&lt;p&gt;Bonjour&lt;/p&gt;<p>Suite<br>Fin</p>'))->toBe("Bonjour\nSuite\nFin");
});
