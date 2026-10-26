@extends('errors::minimal')

@section('title', 'Document trop volumineux')
@section('code', '413')
@section('message', 'Le transfert dépasse la limite autorisée. Envoyez un seul document de 1 Go maximum par envoi.')
