<?php

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

beforeEach(function () {
    app()->setLocale('fr');
    $this->paginationDocument = static function (string $html): DOMXPath {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    };
});

it('uses the published styled and translated pagination for standard links', function (int $page) {
    $paginator = new LengthAwarePaginator(range(1, 25), 250, 25, $page, ['path' => '/v2/dossiers']);
    $html = (string) $paginator->links();
    $xpath = ($this->paginationDocument)($html);

    expect($html)->toContain('gc-pagination', 'Précédent', 'Suivant', "Page {$page} sur 10")
        ->toContain('Résultats '.(($page - 1) * 25 + 1).' à '.($page * 25).' sur 250')
        ->not->toContain('Showing', 'results', 'Next', 'Previous', 'dark:', 'pagination.');
    expect($xpath->query('//nav[@aria-label="Pagination"]')->length)->toBe(1)
        ->and($xpath->query('//*[@aria-current="page"]')->length)->toBe(1)
        ->and($xpath->query('//*[@aria-current="page"]')->item(0)->textContent)->toBe((string) $page)
        ->and($xpath->query('//a[@rel="prev"]')->length)->toBe($page === 1 ? 0 : 1)
        ->and($xpath->query('//a[@rel="next"]')->length)->toBe($page === 10 ? 0 : 1);
    foreach ($xpath->query('//svg') as $icon) {
        expect($icon->getAttribute('width'))->toBe('16')
            ->and($icon->getAttribute('height'))->toBe('16')
            ->and($icon->getAttribute('aria-hidden'))->toBe('true');
    }
})->with([1, 5, 10]);

it('preserves independent page parameters filters and fragments on dossier details', function (string $pageName, string $fragment) {
    $paginator = (new LengthAwarePaginator(range(1, 25), 76, 25, 2, [
        'path' => '/v2/dossiers/42', 'pageName' => $pageName,
    ]))->appends(['q' => 'Société & Fils', 'per_page' => 25, 'another_page' => 4])->fragment($fragment);
    $xpath = ($this->paginationDocument)((string) $paginator->links());

    foreach ($xpath->query('//a[@href]') as $link) {
        $url = parse_url($link->getAttribute('href'));
        parse_str($url['query'], $query);
        expect($url['path'])->toBe('/v2/dossiers/42')
            ->and($url['fragment'])->toBe($fragment)
            ->and($query)->toHaveKeys([$pageName, 'q', 'per_page', 'another_page'])
            ->and($query['q'])->toBe('Société & Fils')
            ->and($query['another_page'])->toBe('4');
    }
})->with([
    ['appointments_page', 'rendez-vous'],
    ['legacy_appointments_page', 'rendez-vous'],
    ['documents_page', 'documents'],
    ['legacy_documents_page', 'documents'],
    ['lot_documents_page', 'documents-lots'],
    ['history_page', 'historique'],
]);

it('omits pagination when the list fits on one page', function (int $count) {
    $paginator = new LengthAwarePaginator($count ? range(1, $count) : [], $count, 25);
    expect(trim((string) $paginator->links()))->toBe('');
})->with([0, 1, 25]);

it('keeps the dossier page size control available even with an empty or single page', function (int $count) {
    $paginator = new LengthAwarePaginator($count ? range(1, $count) : [], $count, 50);
    $html = (string) $paginator->links('pagination::tailwind', ['pageSizeControl' => 'crm.dossiers.page-size']);
    $xpath = ($this->paginationDocument)($html);
    expect($xpath->query('//nav[@class="gc-pagination"]//*[@data-dossier-page-size]')->length)->toBe(1)
        ->and($xpath->query('//select[@data-dossier-page-size]/option[@selected and @value="50"]')->length)->toBe(1)
        ->and($xpath->query('//a[@rel="prev" or @rel="next"]')->length)->toBe(0);
})->with([0, 1, 25]);

it('handles a page that became empty after deleting its last result', function () {
    $paginator = new LengthAwarePaginator([], 30, 25, 3, ['path' => '/manager/lots']);
    $html = (string) $paginator->links();
    expect($html)->toContain('Aucun résultat sur cette page', 'rel="prev"')->not->toContain('Résultats  à');
});

it('uses the same controls for simple and cursor pagination', function (bool $cursor) {
    $items = collect(range(1, 26))->map(fn (int $id): array => ['id' => $id]);
    $paginator = $cursor
        ? new CursorPaginator($items, 25, new Cursor(['id' => 50]), ['path' => '/dossiers', 'parameters' => ['id']])
        : new Paginator($items, 25, 2, ['path' => '/dossiers']);
    $html = (string) $paginator->links();
    $xpath = ($this->paginationDocument)($html);
    expect($html)->toContain('gc-pagination', 'Précédent', 'Suivant')->not->toContain('pagination.', 'Next', 'dark:')
        ->and($xpath->query('//a[@rel="prev"]')->length)->toBe(1)
        ->and($xpath->query('//a[@rel="next"]')->length)->toBe(1);
})->with([false, true]);

it('translates paginator json labels as well as html labels', function () {
    $paginator = new LengthAwarePaginator(range(1, 25), 50, 25);
    $links = $paginator->linkCollection();
    expect($links->first()['label'])->toBe('Précédent')->and($links->last()['label'])->toBe('Suivant');
});

it('escapes filter values in pagination links', function () {
    $paginator = (new LengthAwarePaginator(range(1, 25), 50, 25))
        ->appends(['q' => '"><script>alert(1)</script>']);
    expect((string) $paginator->links())->not->toContain('<script>');
});
