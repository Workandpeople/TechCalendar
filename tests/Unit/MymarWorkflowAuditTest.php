<?php

use Cofrac2\Deploy\MymarReadOnlyAudit;
use Cofrac2\Deploy\MymarWorkflowAudit;

require_once __DIR__.'/../../tools/deploy/mymar/MymarReadOnlyAudit.php';
require_once __DIR__.'/../../tools/deploy/mymar/workflow/MymarWorkflowAudit.php';

it('extracts mutation structure without executing source or leaking literal secrets', function () {
    $source = <<<'PHP'
<?php
file_put_contents('/tmp/NEVER_RUN_MYMAR_WORKFLOW', 'EXECUTION_CANARY');
class LegacyController {
    public function ajouterRDV($request) {
        $password = 'PRIVATE_SECRET_CANARY';
        $code = 736291;
        $dossier->status_id = 3;
        RDV::where('dossier_id', $request->id)->update(['is_old' => 1]);
        $dossier->date_rdv = $request->date_rdv.' '.$request->heure_rdv;
        // COMMENT_CANARY
        Mail::to('private@example.test')->send(new Message("BODY_CANARY {$request->name}"));
        if (true) { $dossier->save(); }
    }
    public function unrelated() { return 'DO_NOT_EXPORT'; }
}
PHP;
    $facts = MymarReadOnlyAudit::facts($source);
    $methods = MymarWorkflowAudit::methods($source);
    $json = json_encode($methods);
    expect($methods['methods'])->toBe(['ajouterRDV', 'unrelated'])
        ->and($methods['mutation_excerpts'])->toHaveCount(1)
        ->and($json)->toContain('dossier_id', 'is_old', 'date_rdv', 'Mail::to', 'save')
        ->and($json)->not->toContain('PRIVATE_SECRET_CANARY', '736291', 'private@example.test', 'BODY_CANARY', 'COMMENT_CANARY', 'DO_NOT_EXPORT', 'EXECUTION_CANARY')
        ->and(collect($facts)->where('field', 'status_id')->first()['literal'])->toBe(3)
        ->and(collect($facts)->where('field', 'is_old')->first()['literal'])->toBe(1);
});

it('captures model hooks and relations but not arbitrary controller methods', function () {
    $source = '<?php class Model { protected static function booted() { static::saved(fn () => null); } public function rdvs() { return $this->hasMany(RDV::class); } }';
    expect(MymarWorkflowAudit::methods($source)['mutation_excerpts'])->toHaveCount(1)
        ->and(MymarWorkflowAudit::methods($source, true)['mutation_excerpts'])->toHaveCount(2);
});

it('ignores closures and reports incomplete or oversized methods as failures', function () {
    expect(MymarWorkflowAudit::methods('<?php $x = function () { return 1; };')['methods'])->toBe([])
        ->and(fn () => MymarWorkflowAudit::methods('<?php function ajouterRDV() {'))->toThrow(RuntimeException::class)
        ->and(fn () => MymarWorkflowAudit::methods('<?php function ajouterRDV() { $x="'.str_repeat('a', 66000).'"; }'))->toThrow(RuntimeException::class);
});

it('masks interpolation, escaped strings, inline html and numbers in method excerpts', function () {
    $redacted = MymarWorkflowAudit::redact(<<<'PHP'
<?php
$value = "PRIVATE_INTERPOLATED $name";
$other = "\x69\x64";
$number = 789123.456;
?>PRIVATE_HTML
PHP);
    expect($redacted)->not->toContain('PRIVATE', '789123', '\\x69')
        ->and($redacted)->toContain('[MASKED]');
});

it('uses fixed bounded read-only metadata queries without reading business records or trigger bodies', function () {
    $sql = MymarWorkflowAudit::metadataSql();
    expect($sql)->toContain('max_execution_time=1500', 'lock_wait_timeout=2', 'START TRANSACTION READ ONLY', 'ROLLBACK;')
        ->and($sql)->not->toContain('FROM MYMAR.', 'ACTION_STATEMENT', 'GRANT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'CREATE ');
});

it('preserves literal status groups but not unrelated numeric arrays or expressions', function () {
    $source = <<<'PHP'
<?php
// in_array($request->status_changed, [79,80]);
if (in_array($request->status_changed, [2,15])) {}
if (in_array($dossier->status_id, [1,14], true)) {}
if (in_array($request->password, [876,987])) {}
if (in_array($request->status_changed, [2,$untrusted])) {}
if (in_array($request->status_changed, [2+99])) {}
PHP;
    expect(MymarWorkflowAudit::statusConditions($source))->toBe([
        ['line' => 3, 'field' => 'status_changed', 'in_values' => [2, 15]],
        ['line' => 4, 'field' => 'status_id', 'in_values' => [1, 14]],
    ]);
});

it('requires the workflow sources and refuses indirect source roots', function () {
    $root = sys_get_temp_dir().'/mymar-workflow-test-'.bin2hex(random_bytes(5));
    mkdir($root, 0700);
    symlink($root, $root.'-link');
    try {
        expect(fn () => MymarWorkflowAudit::sources($root))->toThrow(RuntimeException::class)
            ->and(fn () => MymarWorkflowAudit::sources($root.'-link'))->toThrow(RuntimeException::class);
    } finally {
        unlink($root.'-link');
        rmdir($root);
    }
});
