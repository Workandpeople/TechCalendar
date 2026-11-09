<?php

use App\Services\Mymar\MymarDocumentSettings;

function documentReaderConfiguration(): array
{
    return ['format' => 'cofrac.mymar-document-reader', 'version' => 1, 'enabled' => true, 'writes_enabled' => false,
        'username' => 'cofrac_mymar_dr_1234abcd', 'password' => 'Md4!'.str_repeat('a', 64),
        'uploads_root' => MymarDocumentSettings::UPLOADS];
}

it('accepts only the dedicated read-only document configuration and supports disabling it', function () {
    $configuration = documentReaderConfiguration();
    expect(MymarDocumentSettings::validate($configuration))->toBe($configuration);
    $configuration['enabled'] = false;
    expect(MymarDocumentSettings::validate($configuration))->toBeNull();
});

it('refuses expanded privileges generic credentials and unknown configuration fields', function (array $change) {
    MymarDocumentSettings::validate(array_replace(documentReaderConfiguration(), $change));
})->with([
    [['writes_enabled' => true]], [['enabled' => 'true']], [['version' => 2]], [['username' => 'root']],
    [['password' => 'generic-password']], [['uploads_root' => '/srv/unrelated']], [['extra' => 'unexpected']],
])->throws(RuntimeException::class);
