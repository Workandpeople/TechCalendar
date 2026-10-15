<?php

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__.'/../../tools/deploy/demo-cutover.php';

it('discards only terminal line endings from hidden password input', function (string $password, string $ending) {
    $input = new ArrayInput([]);
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $password.$ending);
    rewind($stream);
    $input->setStream($stream);
    $output = new BufferedOutput;
    $question = cutoverPasswordQuestion('Mot de passe : ');
    // Disable terminal control only for the in-memory fixture, then restore it.
    $stty = new ReflectionProperty(QuestionHelper::class, 'stty');
    $previous = $stty->getValue();
    QuestionHelper::disableStty();
    try {
        $actual = (new QuestionHelper)->ask($input, $output, $question);
        expect($actual)->toBe($password)
            ->and($question->isHidden())->toBeTrue()
            ->and($question->isHiddenFallback())->toBeFalse()
            ->and($output->fetch())->not->toContain($password)
            ->and(password_verify($password, password_hash($actual, PASSWORD_BCRYPT)))->toBeTrue();
    } finally {
        fclose($stream);
        $stty->setValue(null, $previous);
    }
})->with([
    ['FauxPass9', "\n"],
    ['FauxPass9', "\r\n"],
    ['  FauxPass9  ', "\n"],
    ['FauxPass9', ''],
]);
