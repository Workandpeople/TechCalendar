<?php

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\NativeLotSatisfactionReview;
use App\Services\Coffrac\ProductionDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Fixtures/CoffracProductionFixture.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
        'admin.mail' => 'seed-admin@example.test', 'admin.password' => 'Seed-password-123',
        'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
    $this->datasetDirectory = productionCoffracFixture();
});

afterEach(fn () => File::deleteDirectory($this->datasetDirectory));

it('retains opinions and free lot labels without inventing native lot membership or control results', function () {
    $rows = array_map(fn ($line) => json_decode($line, true), file($this->datasetDirectory.'/dossiers.jsonl'));
    $rows[0] += ['lot_id' => null, 'avis_satisfaction' => 'SATISFAISANT'];
    $rows[0]['numero_lot'] = 'Same label';
    $rows[1] += ['lot_id' => null, 'avis_satisfaction' => 'NON SATISFAISANT'];
    $rows[1]['numero_lot'] = 'Same label';
    $rows[2] += ['lot_id' => null, 'avis_satisfaction' => 'UNRECOGNISED'];
    $operations = array_map(fn ($line) => json_decode($line, true), file($this->datasetDirectory.'/dossier_fiches.jsonl'));
    $operations[0] += ['is_favorable' => null, 'rapport' => '[{"answer":"yes"}]'];
    $operations[1] += ['is_favorable' => false, 'rapport' => 'not a filename'];
    $operations[] = ['id' => 203, 'dossier_id' => 101, 'fiche_id' => 7, 'status_id' => 8,
        'is_favorable' => true, 'created_at' => null, 'updated_at' => null];
    foreach (['dossiers' => $rows, 'dossier_fiches' => $operations, 'lot_dossier' => [['id' => 17, 'name' => 'Same label', 'percent' => 'hybride sur lieu/contact']]] as $table => $items) {
        file_put_contents($this->datasetDirectory.'/'.$table.'.jsonl', implode('', array_map(fn ($row) => json_encode($row)."\n", $items)));
    }
    sealProductionCoffracFixture($this->datasetDirectory);
    $dataset = new ProductionDataset($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse($dataset)['matching']['lots_and_satisfaction'];
    expect($report['source_lots'])->toBe(1)
        ->and($report['source_lots_without_linked_dossiers'])->toBe([17])
        ->and($report['dossiers_with_explicit_lot_id'])->toBe(0)
        ->and($report['dossiers_with_free_lot_label_only'])->toBe(2)
        ->and(array_column($report['dossier_opinions'], 'opinion'))->toBe(['satisfactory', 'unsatisfactory', null])
        ->and($report['dossier_opinions'][0]['source_operation_ids'])->toBe([201, 203])
        ->and(array_column($report['operation_opinions'], 'opinion'))->toBe(['unsatisfactory', 'satisfactory'])
        ->and($report['operation_report_payloads_retained'])->toBe(2)
        ->and($report['native_mapping_review_required'])->toBeTrue()
        ->and($report['historical_opinions_applied'])->toBeFalse()
        ->and(DB::table('lots')->count())->toBe(0)
        ->and(DB::table('lot_appointments')->count())->toBe(0)
        ->and(DB::table('dossier_operations')->whereNotNull('control_outcome')->count())->toBe(0);
    expect(app(NativeLotSatisfactionReview::class)->rehearse($dataset))->toBe($report)
        ->and(json_encode($report))->not->toContain('Same label')
        ->not->toContain('UNRECOGNISED')->not->toContain('"answer":');
});

it('requires review of valid, dangling and untyped lot identifiers without matching by label', function () {
    $rows = array_map(fn ($line) => json_decode($line, true), file($this->datasetDirectory.'/dossiers.jsonl'));
    foreach ([17, 999, '17'] as $index => $id) {
        $rows[$index]['lot_id'] = $id;
    }
    foreach (['dossiers' => $rows, 'lot_dossier' => [['id' => 17, 'name' => 'Lot source']]] as $table => $items) {
        file_put_contents($this->datasetDirectory.'/'.$table.'.jsonl', implode('', array_map(fn ($row) => json_encode($row)."\n", $items)));
    }
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory))['matching']['lots_and_satisfaction'];
    expect($report['dossiers_with_explicit_lot_id'])->toBe(3)
        ->and(array_column($report['source_lot_links_to_review'], 'source_lot_exists'))->toBe([true, false, false])
        ->and($report['source_lots_without_linked_dossiers'])->toBe([])
        ->and(DB::table('lots')->count())->toBe(0);
});

it('recognises an empty source without creating placeholders and guards native results', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse($dataset)['matching']['lots_and_satisfaction'];
    expect($report['native_mapping_review_required'])->toBeFalse();
    DB::table('dossier_operations')->where('id', 201)->update(['control_outcome' => 'satisfactory']);
    expect(fn () => app(NativeLotSatisfactionReview::class)->rehearse($dataset))->toThrow(RuntimeException::class, 'avis natifs');
    config(['coffrac.offline_rehearsal' => false]);
    expect(fn () => app(NativeLotSatisfactionReview::class)->rehearse($dataset))->toThrow(RuntimeException::class, 'Cible refusee');
});
