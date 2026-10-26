@props(['body' => ''])
@php($contentId = 'comment-excerpt-'.\Illuminate\Support\Str::uuid())
<div class="gc-comment-excerpt" data-comment-excerpt>
    <x-comment-body :body="$body" :id="$contentId" data-excerpt-body />
    <button type="button" class="gc-comment-excerpt-toggle" data-excerpt-toggle aria-expanded="false" aria-controls="{{ $contentId }}" hidden>Afficher plus</button>
</div>
