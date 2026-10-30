<?php

use App\Support\CommentContent;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('preserves approved formats but never arbitrary attributes or CSS', function () {
    $html = CommentContent::html('<p onclick="attack()">Texte <strong>gras</strong> <span class="ql-font-serif evil" style="font-size:18px;color:rgb(185,28,28);position:fixed;background:url(https://bad.test)">important</span></p>');
    expect($html)->toBe('<p>Texte <strong>gras</strong> <span style="font-family: Georgia, serif; font-size: 18px; color: #b91c1c">important</span></p>');
    expect(CommentContent::html($html))->toBe($html);
});

it('discards active content, remote media, links and disallowed styles', function (string $payload) {
    $html = CommentContent::html($payload);
    expect($html)->not->toMatch('/<(script|style|img|svg|math|iframe|object|embed|form|input|a|video)|onerror|onclick|onload|javascript:|https:|url\(|position:|999px|secret/i');
    expect(CommentContent::html($html))->toBe($html);
})->with([
    '<script>secret</script><style>secret</style><p>OK</p>',
    '<svg><foreignObject><p onload="secret">secret</p></foreignObject></svg><p>OK</p>',
    '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=secret>">',
    '<iframe src="https://bad.test">secret</iframe><object>secret</object><embed src=x><p>OK</p>',
    '<span style="font-family:url(secret);font-size:999px;color:expression(secret);background-image:url(https://bad.test)">OK</span>',
    '<a href="javascript:secret()" onclick="secret()">OK</a><img src="https://bad.test" onerror="secret()">',
    '<form action="https://bad.test"><input value="secret">OK</form>',
    '<span class="ql-formula" data-value="&lt;/span&gt;&lt;img src=x onerror=secret&gt;">OK</span><iframe class="ql-video" src="https://bad.test"></iframe>',
]);

it('keeps plain previews readable without reinterpreting decoded entities', function () {
    expect(CommentContent::text('<p>Bonjour&nbsp;<b>Lucas</b></p><p>Suite<br>Fin</p>'))->toBe("Bonjour Lucas\n\nSuite\nFin")
        ->and(CommentContent::text('&lt;img src=x onerror=example&gt;'))->toBe('<img src=x onerror=example>')
        ->and(CommentContent::forStorage("Texte simple\nDeuxième ligne"))->toBe("Texte simple\nDeuxième ligne");
});

it('retains formats attached directly to bold or paragraph elements by the editor', function () {
    expect(CommentContent::html('<p><strong class="ql-font-serif" style="font-size:18px;color:rgb(185,28,28)">Urgent</strong></p>'))
        ->toBe('<p><strong style="font-family: Georgia, serif; font-size: 18px; color: #b91c1c">Urgent</strong></p>');
});

it('rejects comments which are empty after sanitization', function (string $value) {
    expect(fn () => CommentContent::forStorage($value))->toThrow(ValidationException::class);
})->with(['', ' ', '<p><br></p>', '<script>secret</script>', '<p>&nbsp;</p>', "<p>\u{200B}</p>"]);

it('bounds deep content and rejects unapproved style variants', function () {
    expect(CommentContent::html(str_repeat('<div>', 100).'<b>deep</b>'.str_repeat('</div>', 100)))->not->toContain('deep');
    expect(CommentContent::html('<span style="color:#B91C1C;font-size:18px !important;font-family:var(--secret)">OK</span>'))
        ->toBe('<span style="color: #b91c1c">OK</span>');
});
