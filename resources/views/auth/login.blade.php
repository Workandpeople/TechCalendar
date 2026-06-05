<x-layouts.auth>
    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="gc-label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="gc-input" />
            @error('email')
                <p class="gc-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="gc-label">Mot de passe</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="gc-input" />
            @error('password')
                <p class="gc-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between gap-4">
            <label class="inline-flex items-center gap-2 text-sm" style="color: var(--gc-text);">
                <input type="checkbox" name="remember" class="gc-check">
                Se souvenir de moi
            </label>

            <a href="{{ route('password.request') }}" class="gc-link">Mot de passe oublié ?</a>
        </div>

        <button type="submit" class="gc-btn-primary w-full">
            Se connecter
        </button>
    </form>
</x-layouts.auth>
