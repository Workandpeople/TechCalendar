@if ($paginator->hasPages())
    <nav class="gc-pagination" aria-label="{{ __('pagination.navigation') }}">
        <p class="gc-pagination-summary">
            @if ($paginator->firstItem())
                {{ __('pagination.results', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
            @else
                {{ __('pagination.empty') }}
            @endif
        </p>

        <div class="gc-pagination-controls">
            @include('pagination::step', ['direction' => 'previous'])

            <span class="gc-pagination-position gc-pagination-mobile-position">
                {{ __('pagination.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}
            </span>

            <div class="gc-pagination-pages">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="gc-pagination-ellipsis" aria-label="{{ __('pagination.more_pages') }}">{{ $element }}</span>
                    @elseif (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="gc-pagination-button" aria-current="page" aria-label="{{ __('pagination.current_page', ['page' => $page]) }}">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="gc-pagination-button" aria-label="{{ __('pagination.go_to_page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            @include('pagination::step', ['direction' => 'next'])
        </div>
    </nav>
@endif
