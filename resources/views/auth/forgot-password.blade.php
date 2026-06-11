<x-layouts.auth>
    <p class="mb-5 text-sm" style="color: var(--gc-text-soft);">Saisis ton email pour recevoir un lien de réinitialisation.</p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" data-validate-form>
        @csrf

        <div>
            <label for="email" class="gc-label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="gc-input" />
            @error('email')
                <p class="gc-error">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="gc-btn-primary w-full">
            Envoyer le lien
        </button>

        <a href="{{ route('login') }}" class="gc-link block text-center">Retour au login</a>
    </form>
</x-layouts.auth>
