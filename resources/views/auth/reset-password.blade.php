<x-layouts.auth>
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label for="email" class="gc-label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username" class="gc-input" />
            @error('email')
                <p class="gc-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="gc-label">Nouveau mot de passe</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="gc-input" />
            @error('password')
                <p class="gc-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="gc-label">Confirmation</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="gc-input" />
        </div>

        <button type="submit" class="gc-btn-primary w-full">
            Réinitialiser le mot de passe
        </button>
    </form>
</x-layouts.auth>
