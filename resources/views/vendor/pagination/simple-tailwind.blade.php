@if ($paginator->hasPages())
    <nav class="gc-pagination" aria-label="{{ __('pagination.navigation') }}">
        <div class="gc-pagination-controls">
            @include('pagination::step', ['direction' => 'previous'])
            @include('pagination::step', ['direction' => 'next'])
        </div>
    </nav>
@endif
