@props(['body' => '', 'limit' => null, 'plain' => false])
@php($normalized = \App\Support\CofracText::normalize($body))
<div {{ $attributes->class(['gc-comment-body']) }} data-comment-body data-comment-rendered="true">@if ($plain || $limit){{ $limit ? \Illuminate\Support\Str::limit(\App\Support\CommentContent::text($normalized), (int) $limit) : \App\Support\CommentContent::text($normalized) }}@else{!! \App\Support\CommentContent::html($normalized) !!}@endif</div>
