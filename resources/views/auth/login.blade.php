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
            <div class="relative">
                <input id="password" name="password" type="password" required autocomplete="current-password" class="gc-input pr-12" />
                <button
                    id="toggle-password"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1 transition hover:bg-[color:var(--gc-accent-soft)]"
                    style="color:var(--gc-text-soft);"
                    aria-label="Afficher le mot de passe"
                    aria-pressed="false"
                >
                    <svg id="password-eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                        <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    <svg id="password-eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="hidden h-5 w-5">
                        <path d="M3 3l18 18" />
                        <path d="M10.7 5.2A9.7 9.7 0 0 1 12 5c6 0 9.5 7 9.5 7a17.7 17.7 0 0 1-3.1 4.1" />
                        <path d="M6.5 6.9A17.8 17.8 0 0 0 2.5 12s3.5 7 9.5 7a9.2 9.2 0 0 0 4.1-1" />
                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                    </svg>
                </button>
            </div>
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

    <script>
        const passwordInput = document.getElementById('password');
        const togglePasswordButton = document.getElementById('toggle-password');
        const passwordEyeOpen = document.getElementById('password-eye-open');
        const passwordEyeClosed = document.getElementById('password-eye-closed');

        togglePasswordButton?.addEventListener('click', () => {
            const shouldShowPassword = passwordInput.type === 'password';

            passwordInput.type = shouldShowPassword ? 'text' : 'password';
            togglePasswordButton.setAttribute('aria-pressed', String(shouldShowPassword));
            togglePasswordButton.setAttribute('aria-label', shouldShowPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            passwordEyeOpen.classList.toggle('hidden', shouldShowPassword);
            passwordEyeClosed.classList.toggle('hidden', !shouldShowPassword);
            passwordInput.focus();
        });
    </script>
</x-layouts.auth>
