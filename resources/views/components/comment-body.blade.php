@props(['body' => '', 'limit' => null])
<div {{ $attributes->class(['gc-comment-body']) }} data-comment-body @if ($limit) data-comment-limit="{{ (int) $limit }}" @endif>{{ $body }}</div>
