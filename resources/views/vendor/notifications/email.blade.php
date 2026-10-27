<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@else
# {{ $level === 'error' ? 'Une difficulté est survenue' : 'Bonjour,' }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
@php($color = in_array($level, ['success', 'error'], true) ? $level : 'primary')
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@else
À bientôt,<br>
L’équipe Genius Contrôle
@endif

@isset($actionText)
<x-slot:subcopy>
Si le bouton « {{ $actionText }} » ne fonctionne pas, copiez ce lien dans votre navigateur :
[{{ $displayableActionUrl }}]({{ $actionUrl }})
</x-slot:subcopy>
@endisset
</x-mail::message>
