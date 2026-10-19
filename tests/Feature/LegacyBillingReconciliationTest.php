<?php

use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\DossierOperation;
use App\Models\User;
use App\Services\Crm\LegacyBillingReconciliation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function billingArchive(string $table, int $id, array|string $payload, ?int $parent = null): void
{
    DB::table('legacy_records')->insert(['source_table' => $table, 'source_id' => $id, 'dossier_id' => $parent,
        'payload' => is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload]);
}

function billingSourceDossier(string $reference): Dossier
{
    return Dossier::create(['reference' => 'BILLING-SOURCE-'.$reference, 'source' => 'coffrac', 'source_reference' => $reference,
        'address' => '', 'city' => '']);
}

function billingSourceOperation(Dossier $dossier, string $reference, string $status = 'CONTROLE RT'): DossierOperation
{
    return $dossier->operations()->forceCreate(['source' => 'coffrac', 'source_reference' => $reference, 'legacy_status' => $status]);
}

beforeEach(function () {
    expect(DB::getDriverName())->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['app.timezone' => 'Europe/Paris']);
    $this->dossier = billingSourceDossier('901');
    $this->operation = billingSourceOperation($this->dossier, '801');
    $this->reconciliation = app(LegacyBillingReconciliation::class);
});

it('maps exact source identities and preserves source authorship without equating user ids', function () {
    $unrelatedAuthor = User::factory()->create(['id' => 77]);
    $sibling = billingSourceOperation($this->dossier, '802');
    billingArchive('dossier_fiches', 801, ['dossier_id' => 901, 'status_id' => 3]);
    billingArchive('dossier_historique', 501, ['dossier_id' => 901, 'dossier_fiche_id' => 801,
        'status_id' => 11, 'author_id' => $unrelatedAuthor->id, 'created_at' => '2024-01-05 12:20:00']);

    expect($this->reconciliation->run())->toBe(['created' => 1, 'existing' => 0, 'review' => 0, 'invalid' => 0]);
    $this->assertDatabaseCount('dossier_billings', 0);
    expect($this->reconciliation->run(true))->toBe(['created' => 1, 'existing' => 0, 'review' => 0, 'invalid' => 0]);
    $billing = DossierBilling::sole();
    expect($billing->dossier_operation_id)->toBe($this->operation->id)
        ->and($billing->dossier_id)->toBe($this->dossier->id)
        ->and($billing->marked_at->format('Y-m-d H:i:s'))->toBe('2024-01-05 13:20:00')
        ->and($billing->marked_by)->toBeNull()->and($billing->marker)->toBeNull()
        ->and($billing->evidence['records']['dossier_historique']['author_id'])->toBe(77)
        ->and($billing->source_reference)->toBe('dossier_historique:501')
        ->and($billing->invoice_reference)->toBeNull()
        ->and($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')
        ->and($sibling->billing()->exists())->toBeFalse();
});

it('counts distinct operations across repeated evidence and chunk boundaries and is idempotent', function () {
    billingArchive('dossier_fiches', 801, ['dossier_id' => 901, 'status_id' => 11, 'date_status_change' => '2024-01-05 10:00:00']);
    for ($id = 1; $id <= 205; $id++) {
        billingArchive('dossier_historique', $id, ['dossier_id' => 901, 'dossier_fiche_id' => 801, 'status_id' => 11,
            'created_at' => $id === 205 ? '2023-01-01 10:00:00' : '2024-01-05 10:00:00']);
    }
    billingArchive('dossier_fiche_facturation', 1, ['dossier_fiche_id' => 801, 'passer_facture' => 1, 'num_facture' => 'SOURCE-INV']);
    $expected = ['created' => 1, 'existing' => 0, 'review' => 0, 'invalid' => 0];
    expect($this->reconciliation->run())->toBe($expected)->and($this->reconciliation->run(true))->toBe($expected);
    $before = DossierBilling::sole()->getAttributes();
    expect(DossierBilling::sole()->evidence['source_record_count'])->toBe(207)
        ->and(DossierBilling::sole()->marked_at->year)->toBe(2023)
        ->and(DossierBilling::sole()->invoice_reference)->toBe('SOURCE-INV')
        ->and($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 1, 'review' => 0, 'invalid' => 0])
        ->and(DossierBilling::sole()->getAttributes())->toBe($before);
});

it('never replaces a native mark or infers a mark from payment or beneficiary invoice numbers', function () {
    $native = DossierBilling::create(['dossier_id' => $this->dossier->id, 'dossier_operation_id' => $this->operation->id,
        'source' => 'native', 'marked_at' => '2026-01-01 12:00:00', 'invoice_reference' => 'NATIVE', 'evidence' => ['manual' => true]]);
    $before = $native->fresh()->getAttributes();
    billingArchive('dossier_fiches', 801, ['dossier_id' => 901, 'status_id' => 11, 'numero_facture' => 'BENEFICIARY']);
    foreach ([0, null, '1garbage', 2] as $index => $flag) {
        $reference = 810 + $index;
        billingSourceOperation($this->dossier, (string) $reference);
        billingArchive('dossier_fiches', $reference, ['dossier_id' => 901, 'status_id' => 3]);
        billingArchive('dossier_fiche_facturation', $index + 1, ['dossier_fiche_id' => $reference,
            'passer_facture' => $flag, 'status' => 1, 'num_facture' => 'NOT-A-MARK', 'date_facture' => '2024-01-01']);
    }
    expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 1, 'review' => 0, 'invalid' => 0])
        ->and($native->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('dossier_billings', 1);
});

it('uses explicit invoice passage only and never mistakes invoice or update dates for the marking date', function () {
    billingArchive('dossier_fiches', 801, ['dossier_id' => 901, 'status_id' => 3]);
    billingArchive('dossier_fiche_facturation', 1, ['dossier_fiche_id' => 801, 'passer_facture' => '1', 'status' => 1,
        'num_facture' => ' INV-REAL ', 'numero_facture' => 'BENEFICIARY', 'date_facture' => '2020-01-01',
        'updated_at' => '2020-02-01 10:00:00']);
    expect($this->reconciliation->run(true)['created'])->toBe(1);
    $billing = DossierBilling::sole();
    expect($billing->invoice_reference)->toBe('INV-REAL')->and($billing->marked_at)->toBeNull()
        ->and($billing->marked_by)->toBeNull()
        ->and($billing->evidence['records']['dossier_fiche_facturation']['date_facture'])->toBe('2020-01-01')
        ->and($billing->evidence)->not->toHaveKeys(['paid', 'paid_at']);
});

it('ignores malformed dates without losing an unambiguous historical mark', function (mixed $date) {
    billingArchive('dossier_historique', 1, ['dossier_id' => 901, 'dossier_fiche_id' => 801,
        'status_id' => 11, 'created_at' => $date, 'author_id' => 99001]);
    expect($this->reconciliation->run(true))->toBe(['created' => 1, 'existing' => 0, 'review' => 0, 'invalid' => 1]);
    $billing = DossierBilling::sole();
    expect($billing->marked_at)->toBeNull()->and($billing->marked_by)->toBeNull()
        ->and($billing->evidence['records']['dossier_historique']['issues'])->toContain('invalid_created_at')
        ->and($billing->evidence['records']['dossier_historique']['author_id'])->toBe(99001);
})->with(['unparseable' => ['not a date'], 'zero' => ['0000-00-00 00:00:00'], 'rollover' => ['2024-02-30 12:00:00'],
    'null byte' => ["2024-01-01\0"], 'non scalar' => [['date' => '2024-01-01']], 'relative' => ['yesterday'],
    'timezone overflow' => ['9999-12-31 23:59:59']]);

it('does not invent a timestamp or beneficiary invoice for source status eleven', function () {
    billingArchive('dossier_fiches', 801, ['dossier_id' => 901, 'status_id' => 11, 'created_at' => '2024-01-01 10:00:00',
        'updated_at' => '2024-02-01 10:00:00', 'numero_facture' => 'BENEFICIARY']);
    expect($this->reconciliation->run(true)['created'])->toBe(1)
        ->and(DossierBilling::sole()->marked_at)->toBeNull()->and(DossierBilling::sole()->invoice_reference)->toBeNull();
});

it('flags known parents for ambiguous historical evidence without attributing a sibling operation', function (array $mapping) {
    billingSourceOperation($this->dossier, '802');
    billingArchive('dossier_historique', 1, [...$mapping, 'dossier_id' => 901, 'status_id' => 11, 'author_id' => 99]);
    $expected = ['created' => 0, 'existing' => 0, 'review' => 1, 'invalid' => 1];
    expect($this->reconciliation->run())->toBe($expected)->and($this->dossier->fresh()->billing_review_required)->toBeFalse()
        ->and($this->reconciliation->run(true))->toBe($expected)
        ->and($this->dossier->fresh()->billing_review_required)->toBeTrue()
        ->and($this->dossier->fresh()->billing_review_evidence['legacy_billing']['dossier_historique:1']['author_id'])->toBe(99);
    $before = $this->dossier->fresh()->getAttributes();
    expect($this->reconciliation->run(true))->toBe($expected)->and($this->dossier->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('dossier_billings', 0);
})->with(['missing' => [[]], 'unknown' => [['dossier_fiche_id' => 99999]], 'malformed' => [['dossier_fiche_id' => '801garbage']],
    'wrong field' => [['fiche_id' => 801]], 'service type is not operation id' => [['dossier_fiche_id' => null, 'fiche_id' => 801]]]);

it('enforces source parent ownership instead of trusting a matching numeric operation or archive pointer', function () {
    $other = billingSourceDossier('902');
    $unrelated = billingSourceOperation($other, '803');
    billingArchive('dossier_fiches', 803, ['dossier_id' => 901, 'status_id' => 3]);
    billingArchive('dossier_historique', 1, ['dossier_id' => 901, 'dossier_fiche_id' => 803, 'status_id' => 11], $other->id);
    expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 0, 'review' => 2, 'invalid' => 1])
        ->and($this->dossier->fresh()->billing_review_required)->toBeTrue()
        ->and($other->fresh()->billing_review_required)->toBeTrue()
        ->and($unrelated->billing()->exists())->toBeFalse();
});

it('blocks the identified operations local parent when both source parent and archive pointer are absent', function () {
    billingArchive('dossier_historique', 1, ['dossier_fiche_id' => 801, 'status_id' => 11, 'author_id' => 77]);
    $expected = ['created' => 0, 'existing' => 0, 'review' => 1, 'invalid' => 1];
    expect($this->reconciliation->run())->toBe($expected)
        ->and($this->dossier->fresh()->billing_review_required)->toBeFalse()
        ->and($this->reconciliation->run(true))->toBe($expected)
        ->and($this->dossier->fresh()->billing_review_required)->toBeTrue()
        ->and($this->dossier->fresh()->billing_review_evidence['legacy_billing']['dossier_historique:1']['reason'])->toBe('parent_unmapped')
        ->and($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')
        ->and($this->operation->billing()->exists())->toBeFalse()
        ->and($this->reconciliation->run(true))->toBe($expected);
});

it('blocks both the declared source parent and the identified local operations parent without an archived fiche', function () {
    $other = billingSourceDossier('902');
    billingArchive('dossier_historique', 1, ['dossier_id' => 902, 'dossier_fiche_id' => 801, 'status_id' => 11]);
    expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 0, 'review' => 2, 'invalid' => 1])
        ->and($this->dossier->fresh()->billing_review_required)->toBeTrue()
        ->and($other->fresh()->billing_review_required)->toBeTrue();
    $this->assertDatabaseCount('dossier_billings', 0);
});

it('flags both source owners when history disagrees with the archived fiche parent', function () {
    $other = billingSourceDossier('902');
    billingArchive('dossier_fiches', 801, ['dossier_id' => 902, 'status_id' => 3]);
    billingArchive('dossier_historique', 1, ['dossier_id' => 901, 'dossier_fiche_id' => 801, 'status_id' => 11]);
    expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 0, 'review' => 2, 'invalid' => 1])
        ->and($other->fresh()->billing_review_required)->toBeTrue();
    $this->assertDatabaseCount('dossier_billings', 0);
});

it('blocks malformed payload parent pointers but does not treat source ids as local dossier ids', function () {
    billingArchive('dossier_historique', 1, '{broken', $this->dossier->id);
    billingArchive('dossier_historique', 2, '[]', 901);
    expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 0, 'review' => 1, 'invalid' => 2])
        ->and($this->dossier->fresh()->billing_review_required)->toBeTrue()
        ->and($this->dossier->fresh()->billing_review_evidence['legacy_billing']['dossier_historique:1']['reason'])->toBe('malformed_payload');
    $this->assertDatabaseCount('dossier_billings', 0);
});

it('conservatively blocks a known native parent for malformed archived evidence without attributing a mark', function () {
    $this->dossier->forceFill(['source' => 'native', 'source_reference' => null])->save();
    billingArchive('dossier_historique', 1, 'null', $this->dossier->id);
    expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 0, 'review' => 1, 'invalid' => 1])
        ->and($this->dossier->fresh()->billing_review_required)->toBeTrue();
    $this->assertDatabaseCount('dossier_billings', 0);
});

it('does not rewrite review evidence merely because JSON object keys were reordered by the database', function () {
    billingArchive('dossier_historique', 1, ['dossier_id' => 901, 'status_id' => 11]);
    $this->reconciliation->run(true);
    $evidence = $this->dossier->fresh()->billing_review_evidence;
    $evidence['legacy_billing']['dossier_historique:1'] = array_reverse($evidence['legacy_billing']['dossier_historique:1'], true);
    $this->dossier->forceFill(['billing_review_evidence' => $evidence])->save();
    $before = $this->dossier->fresh()->getAttributes();
    $this->reconciliation->run(true);
    expect($this->dossier->fresh()->getAttributes())->toBe($before);
});

it('flags malformed source authors and invoice dates without assigning a native author or date', function () {
    billingArchive('dossier_fiches', 801, ['dossier_id' => 901, 'status_id' => 3]);
    billingArchive('dossier_fiche_facturation', 1, ['dossier_fiche_id' => 801, 'passer_facture' => 1,
        'author_id' => '77invalid', 'date_facture' => '2024-02-30', 'num_facture' => '0']);
    expect($this->reconciliation->run(true))->toBe(['created' => 1, 'existing' => 0, 'review' => 0, 'invalid' => 1]);
    $billing = DossierBilling::sole();
    expect($billing->marked_by)->toBeNull()->and($billing->marked_at)->toBeNull()
        ->and($billing->invoice_reference)->toBe('0')
        ->and($billing->evidence['records']['dossier_fiche_facturation']['issues'])->toBe(['invalid_author_id', 'invalid_date_facture']);
});

it('backfills a native invoiced status but blocks imported statuses with unverified source parents', function () {
    $native = $this->dossier->operations()->forceCreate(['source' => 'native', 'legacy_status' => 'DOSSIERS FACTURER']);
    $this->operation->forceFill(['legacy_status' => 'DOSSIERS FACTURER'])->save();
    expect($this->reconciliation->run(true))->toBe(['created' => 1, 'existing' => 0, 'review' => 1, 'invalid' => 1])
        ->and($native->billing()->sole()->source)->toBe('legacy_status')
        ->and($native->billing()->sole()->marked_at)->toBeNull()
        ->and($this->operation->billing()->exists())->toBeFalse()
        ->and($this->dossier->fresh()->billing_review_required)->toBeTrue();
    $native->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    expect($native->billing()->exists())->toBeTrue();
});

it('backfills an imported current status only with verified source ownership', function () {
    $this->operation->forceFill(['legacy_status' => 'DOSSIERS FACTURER'])->save();
    billingArchive('dossier_fiches', 801, ['dossier_id' => 901, 'status_id' => 3]);
    expect($this->reconciliation->run(true))->toBe(['created' => 1, 'existing' => 0, 'review' => 0, 'invalid' => 0])
        ->and($this->operation->billing()->sole()->marked_at)->toBeNull();
});

it('recovers a competing apply between its existence check and unique insert without overwriting it', function () {
    billingArchive('dossier_historique', 1, ['dossier_id' => 901, 'dossier_fiche_id' => 801, 'status_id' => 11]);
    $competing = null;
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed, &$competing): void {
        if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "dossier_billings"')) {
            $armed = false;
            // Interleave after the SELECT executed and before the first worker's insert/savepoint.
            $competing = app(LegacyBillingReconciliation::class)->run(true);
        }
    });
    try {
        expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 1, 'review' => 0, 'invalid' => 0])
            ->and($competing)->toBe(['created' => 1, 'existing' => 0, 'review' => 0, 'invalid' => 0]);
    } finally {
        $armed = false;
    }
    $this->assertDatabaseCount('dossier_billings', 1);
    expect(fn () => DossierBilling::create(['dossier_id' => $this->dossier->id,
        'dossier_operation_id' => $this->operation->id, 'source' => 'native']))->toThrow(UniqueConstraintViolationException::class);
});

it('retains native marks inserted by a competing writer after the last existence read', function () {
    billingArchive('dossier_historique', 1, ['dossier_id' => 901, 'dossier_fiche_id' => 801, 'status_id' => 11]);
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed): void {
        if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "dossier_billings"')) {
            $armed = false;
            DossierBilling::create(['dossier_id' => $this->dossier->id, 'dossier_operation_id' => $this->operation->id,
                'source' => 'native', 'invoice_reference' => 'MANUAL-KEPT']);
        }
    });
    try {
        expect($this->reconciliation->run(true))->toBe(['created' => 0, 'existing' => 1, 'review' => 0, 'invalid' => 0])
            ->and(DossierBilling::sole()->source)->toBe('native')
            ->and(DossierBilling::sole()->invoice_reference)->toBe('MANUAL-KEPT');
    } finally {
        $armed = false;
    }
});

it('keeps reads and inserts batched over large sets of distinct operations', function () {
    for ($id = 1; $id <= 410; $id++) {
        $reference = (string) (1000 + $id);
        billingSourceOperation($this->dossier, $reference);
        billingArchive('dossier_historique', $id, ['dossier_id' => 901, 'dossier_fiche_id' => $reference, 'status_id' => 11]);
    }
    DB::enableQueryLog();
    try {
        $counts = $this->reconciliation->run(true);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($counts)->toBe(['created' => 410, 'existing' => 0, 'review' => 0, 'invalid' => 0])
        ->and(count($queries))->toBeLessThan(50);
});

it('defaults the command to dry run and prints only concise counts', function () {
    billingArchive('dossier_historique', 1, ['dossier_id' => 901, 'dossier_fiche_id' => 801, 'status_id' => 11,
        'author_id' => 123, 'commentaire' => 'PRIVATE-PERSON']);
    Artisan::call('crm:reconcile-billing');
    expect(Artisan::output())->toContain('Simulation sans ecriture', 'created=1 existing=0 review=0 invalid=0')->not->toContain('PRIVATE-PERSON');
    $this->assertDatabaseCount('dossier_billings', 0);
    Artisan::call('crm:reconcile-billing', ['--apply' => true]);
    expect(Artisan::output())->toContain('created=1 existing=0 review=0 invalid=0')->not->toContain('PRIVATE-PERSON');
    Artisan::call('crm:reconcile-billing', ['--apply' => true]);
    expect(Artisan::output())->toContain('created=0 existing=1 review=0 invalid=0');
});

it('retains deleted native marker relations and refuses destructive ledger rollback', function () {
    $user = User::factory()->create();
    $billing = DossierBilling::create(['dossier_id' => $this->dossier->id, 'dossier_operation_id' => $this->operation->id,
        'source' => 'native', 'marked_by' => $user->id]);
    $user->delete();
    expect($billing->fresh()->marker->trashed())->toBeTrue();
    $user->forceDelete();
    expect($billing->fresh()->marked_by)->toBeNull();
    $migration = require database_path('migrations/2026_10_19_100000_add_persistent_dossier_billing.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retour arriere refuse')
        ->and(Schema::hasTable('dossier_billings'))->toBeTrue();
    $this->assertDatabaseCount('dossier_billings', 1);
});

it('refuses rollback for review evidence even without a mark', function () {
    $this->dossier->forceFill(['billing_review_evidence' => ['unresolved' => true]])->save();
    $migration = require database_path('migrations/2026_10_19_100000_add_persistent_dossier_billing.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retour arriere refuse')
        ->and(Schema::hasColumn('dossiers', 'billing_review_evidence'))->toBeTrue();
});

it('permits an empty ledger migration round trip', function () {
    $migration = require database_path('migrations/2026_10_19_100000_add_persistent_dossier_billing.php');
    $migration->down();
    expect(Schema::hasTable('dossier_billings'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('dossier_billings'))->toBeTrue()
        ->and($this->dossier->fresh()->billing_review_required)->toBeFalse();
});
