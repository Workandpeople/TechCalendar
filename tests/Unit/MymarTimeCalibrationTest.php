<?php

use Cofrac2\Deploy\MymarTimeCalibration;

require_once __DIR__.'/../../tools/deploy/mymar/MymarReadOnlyAudit.php';
require_once __DIR__.'/../../tools/deploy/mymar/calibration/MymarTimeCalibration.php';

function mymarTimeMetadata(): array
{
    $rows = [['section' => 'server', 'port' => 3306, 'socket' => '/var/run/mysqld/mysqld.sock', 'version' => '8.0.46']];
    foreach (['dossiers' => ['id' => 'bigint', 'status_id' => 'bigint', 'technicien_id' => 'bigint', 'mar_type' => 'int'],
        'dossier_rdv' => ['id' => 'bigint', 'dossier_id' => 'bigint', 'date_rdv' => 'datetime', 'duree' => 'int', 'is_old' => 'int']] as $table => $columns) {
        $rows[] = ['section' => 'table', 'table' => $table, 'engine' => 'InnoDB', 'estimated_rows' => 12000];
        foreach ($columns as $column => $type) {
            $rows[] = compact('table', 'column', 'type') + ['section' => 'column'];
        }
        $rows[] = ['section' => 'index', 'table' => $table, 'column' => $table === 'dossiers' ? 'id' : 'dossier_id'];
    }

    return $rows;
}

function mymarTimeRows(): array
{
    return [['section' => 'clock', 'transaction_read_only' => 1, 'session_timezone' => 'SYSTEM'],
        ['section' => 'reference', 'rdv_id' => 777, 'dossier_id' => 91, 'stored_start' => '2026-11-02 09:30:00',
            'raw_duration' => 2, 'is_old' => 0, 'technician_id' => 26, 'status_id' => 16, 'mar_type' => 0],
        ['section' => 'complete', 'read_only_collection' => true]];
}

it('reads only numeric duration options and safe unit labels without publishing other controls or literals', function () {
    $source = <<<'VUE'
<template>
<select id="appointment-length" v-model.number="form.rdv_duree">
<option :value="1">1 heure</option><option value="2">2 heures</option>
<option value="3">PRIVATE_EMAIL@example.test</option><option :value="duration">dynamic private data</option>
</select>
<input id="password" value="PRIVATE_PASSWORD"><select name="email"><option value="1">PRIVATE_ADDRESS</option></select>
</template>
<script>const secret = 'PRIVATE_TOKEN'; const start = date.toISOString(); const end = date.getHours();</script>
VUE;
    $controls = MymarTimeCalibration::durationControls($source);
    expect($controls)->toHaveCount(1)->and($controls[0]['binding'])->toBe('form.rdv_duree')
        ->and($controls[0]['numeric_options'])->toBe([
            ['value' => '1', 'label' => '1 heure'], ['value' => '2', 'label' => '2 heures'],
            ['value' => '3', 'label' => '[unrecognized label omitted]'],
        ])->and(json_encode($controls))->not->toContain('PRIVATE', 'password', 'email', '@');
    $calls = MymarTimeCalibration::timeCalls($source);
    expect(array_column($calls, 'call'))->toBe(['toISOString', 'getHours'])
        ->and(json_encode($calls))->not->toContain('PRIVATE');
});

it('keeps the raw duration and wall clock comparison without assuming units or timezone conversion', function () {
    $result = MymarTimeCalibration::validateData(mymarTimeRows());
    expect($result['references'][0]['rdv_id'])->toBe(777)->and($result['references'][0]['raw_duration'])->toBe(2)
        ->and($result['stored_start_matches_observed_display'])->toBeTrue()
        ->and($result['reference_technician_matches_observation'])->toBeTrue()
        ->and($result['duration_unit_confirmed'])->toBeFalse()->and($result['timezone_interpretation_confirmed'])->toBeFalse();
    $rows = mymarTimeRows();
    $rows[1]['stored_start'] = '2026-11-02 08:30:00';
    $rows[1]['technician_id'] = 99;
    $rows[1]['raw_duration'] = null;
    $changed = MymarTimeCalibration::validateData($rows);
    expect($changed['stored_start_matches_observed_display'])->toBeFalse()
        ->and($changed['reference_technician_matches_observation'])->toBeFalse()
        ->and($changed['references'][0]['raw_duration'])->toBeNull();
});

it('reports only numeric input bounds unit hints and recognized time arithmetic', function () {
    $controls = MymarTimeCalibration::durationControls('<label>Durée (en heures)</label><input type="number" v-model="rdv_duree" min="1" max="2" step="0.5" value="PRIVATE_SECRET">');
    expect($controls[0]['numeric_attributes'])->toBe(['min' => '1', 'max' => '2', 'step' => '0.5'])
        ->and($controls[0]['preceding_label_unit_hint'])->toBe('hours')
        ->and(json_encode($controls))->not->toContain('PRIVATE_SECRET', 'Durée');
    $facts = MymarTimeCalibration::timeCalls("const end = moment(start).add(this.rdv_duree, 'hours'); const secret='PRIVATE_SECRET';");
    expect($facts)->toHaveCount(1)->and($facts[0])->toMatchArray(['call' => 'add', 'unit' => 'hours', 'duration_input' => true]);
});

it('never chooses one appointment silently when the dossier has zero or multiple current appointments', function (int $count) {
    $rows = mymarTimeRows();
    $clock = $rows[0];
    $reference = $rows[1];
    $complete = $rows[2];
    $rows = [$clock];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [...$reference, 'rdv_id' => 777 + $i];
    }
    $rows[] = $complete;
    $result = MymarTimeCalibration::validateData($rows);
    expect($result['active_reference_unique'])->toBeFalse()->and($result['stored_start_matches_observed_display'])->toBeNull();
})->with([0, 2]);

it('refuses incomplete excessive or unrelated SQL rows before publication', function (string $case) {
    $rows = mymarTimeRows();
    if ($case === 'partial') {
        array_pop($rows);
    }
    if ($case === 'writable') {
        $rows[0]['transaction_read_only'] = 0;
    }
    if ($case === 'wrong_dossier') {
        $rows[1]['dossier_id'] = 92;
    }
    if ($case === 'old') {
        $rows[1]['is_old'] = 1;
    }
    if ($case === 'extra') {
        $rows[1]['email'] = 'PRIVATE_EMAIL';
    }
    if ($case === 'many') {
        array_splice($rows, 1, 0, array_fill(0, 11, $rows[1]));
    }
    expect(fn () => MymarTimeCalibration::validateData($rows))->toThrow(RuntimeException::class);
})->with(['partial', 'writable', 'wrong_dossier', 'old', 'extra', 'many']);

it('requires indexed local InnoDB tables and the expected storage types', function () {
    MymarTimeCalibration::validateMetadata(mymarTimeMetadata());
    foreach (['engine', 'index', 'type', 'volume'] as $case) {
        $rows = mymarTimeMetadata();
        foreach ($rows as &$row) {
            if (($row['table'] ?? '') !== 'dossier_rdv') {
                continue;
            }
            if ($case === 'engine' && $row['section'] === 'table') {
                $row['engine'] = 'MyISAM';
            }
            if ($case === 'volume' && $row['section'] === 'table') {
                $row['estimated_rows'] = 100001;
            }
            if ($case === 'type' && ($row['column'] ?? '') === 'date_rdv') {
                $row['type'] = 'timestamp';
            }
        }
        unset($row);
        if ($case === 'index') {
            $rows = array_filter($rows, fn ($row) => $row['section'] !== 'index' || ($row['table'] ?? '') !== 'dossier_rdv');
        }
        expect(fn () => MymarTimeCalibration::validateMetadata($rows))->toThrow(RuntimeException::class);
    }
});

it('uses bounded read-only SQL with no contacts or cross-application access', function () {
    $sql = MymarTimeCalibration::metadataSql().MymarTimeCalibration::dataSql();
    expect($sql)->toContain('START TRANSACTION READ ONLY', 'max_execution_time=1500', 'd.id=91', 'r.is_old=0', 'LIMIT 11', 'ROLLBACK')
        ->not->toMatch('/\b(?:INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|GRANT|CALL|OUTFILE)\b/i')
        ->not->toContain('users', 'cofrac2production', 'email', 'password', 'address', 'beneficiaire', 'CONVERT_TZ');
});

it('follows only bounded local view and Vue imports without evaluating source code', function () {
    $root = sys_get_temp_dir().'/mymar-time-'.bin2hex(random_bytes(6));
    $write = function (string $path, string $text) use ($root): void {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0700, true);
        }
        file_put_contents($root.'/'.$path, $text);
    };
    $write('app/Http/Controllers/HomeController.php', '<?php throw new RuntimeException("PRIVATE_MUST_NOT_EXECUTE"); return view("calendar");');
    $write('resources/views/calendar.blade.php', "@include('partials.duration')");
    $write('resources/views/partials/duration.blade.php', '<select name="duree"><option value="1">1 h</option></select>');
    $write('resources/js/views/Home.vue', "<script>import Duration from '../components/Duration.vue'; import Forbidden from '../../../../private.js';</script>");
    $write('resources/js/components/Duration.vue', '<select v-model="rdv_duree"><option value="2">2 h</option></select>');
    $result = MymarTimeCalibration::sources($root);
    expect(array_keys($result['files']))->toContain('resources/views/partials/duration.blade.php', 'resources/js/components/Duration.vue')
        ->not->toContain('private.js');
    expect($result['calendar_source_present'])->toBeTrue()->and($result['application_executed'])->toBeFalse()
        ->and(MymarTimeCalibration::unchanged($root, $result['proof']))->toBeTrue();
    $proof = $result['proof'];
    unset($result['proof']);
    expect(json_encode($result))->not->toContain('PRIVATE_MUST_NOT_EXECUTE', 'throw new', 'return view');
    $write('resources/js/components/Duration.vue', '<select name="duree"></select>');
    expect(MymarTimeCalibration::unchanged($root, $proof))->toBeFalse();
    // Only the synthetic test directory created above is removed.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});
