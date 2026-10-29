<x-layouts.app>
    @php
        $profileValue = static function (string $key, mixed $fallback): string {
            $value = old($key, $fallback);
            return is_scalar($value) ? (string) $value : (string) $fallback;
        };
        $selectedStatuses = old('statuses', $errors->notifications->any() ? [] : $preferences['statuses']);
        $selectedStatuses = is_array($selectedStatuses) ? $selectedStatuses : [];
    @endphp
    <div class="mx-auto max-w-5xl space-y-5 pb-16">
        <x-workspace-heading title="Mon profil" eyebrow="Mon compte" description="Vos informations, votre sécurité et vos préférences de notification.">
            <a href="{{ route('dashboard') }}" class="gc-btn-secondary">Retour à mon espace</a>
        </x-workspace-heading>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        <nav aria-label="Sections du profil" class="flex flex-wrap gap-2 text-sm">
            <a class="gc-btn-secondary" href="#informations"><x-nav-icon name="users" /> Mes informations</a>
            <a class="gc-btn-secondary" href="#securite"><x-nav-icon name="lock" /> Mot de passe</a>
            @if ($account->isExternal())<a class="gc-btn-secondary" href="#notifications"><x-nav-icon name="mail" /> Notifications</a>@endif
        </nav>

        <div class="grid items-start gap-5 lg:grid-cols-2">
            <section id="informations" class="scroll-mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="profile-heading">
                <h2 id="profile-heading" class="text-lg font-semibold">Mes informations</h2>
                <p class="mt-1 text-sm text-slate-500">Ces informations concernent votre compte personnel.</p>
                <form method="POST" action="{{ route('profile.update') }}" class="mt-5 space-y-4">
                    @csrf @method('PUT')
                    @if ($errors->profile->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">@foreach ($errors->profile->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="profile-first-name" class="gc-label">Prénom</label><input id="profile-first-name" name="first_name" class="gc-input" autocomplete="given-name" maxlength="120" value="{{ $profileValue('first_name', $account->first_name) }}"></div>
                        <div><label for="profile-last-name" class="gc-label">Nom</label><input id="profile-last-name" name="last_name" class="gc-input" autocomplete="family-name" maxlength="120" required value="{{ $profileValue('last_name', $account->last_name) }}"></div>
                    </div>
                    <div><label for="profile-phone" class="gc-label">Téléphone</label><input id="profile-phone" name="phone" type="tel" class="gc-input" autocomplete="tel" maxlength="200" value="{{ $profileValue('phone', $account->phone) }}"></div>
                    <div><label for="profile-email" class="gc-label">Email de connexion</label><input id="profile-email" type="email" class="gc-input bg-slate-50" readonly value="{{ $account->email }}" aria-describedby="profile-email-help"><p id="profile-email-help" class="mt-1 text-xs text-slate-500">Pour changer votre adresse de connexion, contactez notre équipe.</p></div>
                    <button class="gc-btn-primary" type="submit">Enregistrer mes informations</button>
                </form>
                @if ($account->externalClient)
                    <div class="mt-5 space-y-2 border-t border-slate-100 pt-4 text-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mon organisation · {{ \App\Models\ExternalDelegataire::KINDS[$account->externalClient->kind] ?? 'Compte externe' }}</p>
                        <p class="font-semibold">{{ $account->externalClient->name }}</p>
                        @if ($account->externalClient->email)<p class="break-all text-slate-600">{{ $account->externalClient->email }}</p>@endif
                        @if ($account->externalClient->phone)<p class="text-slate-600">{{ $account->externalClient->phone }}</p>@endif
                        <p class="text-xs text-slate-500">Les informations et droits de l’organisation sont gérés par notre équipe.</p>
                    </div>
                @endif
            </section>

            <section id="securite" class="scroll-mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="password-heading">
                <h2 id="password-heading" class="text-lg font-semibold">Changer mon mot de passe</h2>
                <p class="mt-1 text-sm text-slate-500">Le changement est immédiat, sans attendre un email de réinitialisation.</p>
                <form method="POST" action="{{ route('profile.password') }}" class="mt-5 space-y-4">
                    @csrf @method('PUT')
                    @if ($errors->password->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">@foreach ($errors->password->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                    <div><label class="gc-label" for="current-password">Mot de passe actuel</label><input id="current-password" name="current_password" type="password" class="gc-input" required autocomplete="current-password"></div>
                    <div><label class="gc-label" for="profile-password">Nouveau mot de passe</label><input id="profile-password" name="password" type="password" class="gc-input" required autocomplete="new-password" maxlength="72" aria-describedby="profile-password-help"><p id="profile-password-help" class="mt-1 text-xs text-slate-500">8 caractères minimum, une majuscule, une minuscule et un chiffre.</p></div>
                    <div><label class="gc-label" for="profile-password-confirmation">Confirmer le nouveau mot de passe</label><input id="profile-password-confirmation" name="password_confirmation" type="password" class="gc-input" required autocomplete="new-password" maxlength="72"></div>
                    <button class="gc-btn-primary" type="submit">Modifier mon mot de passe</button>
                </form>
            </section>
        </div>

        @if ($account->isExternal())
            <section id="notifications" class="scroll-mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="notifications-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 id="notifications-heading" class="text-lg font-semibold">Mes notifications par email</h2><p class="mt-1 text-sm text-slate-500">Choisissez ce qui mérite un email, envoyé à {{ $account->email }}.</p></div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $preferences['email_enabled'] ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $preferences['email_enabled'] ? 'Emails activés' : 'Emails désactivés' }}</span>
                </div>
                <form method="POST" action="{{ route('profile.notifications') }}" class="mt-5 space-y-5">
                    @csrf @method('PUT')
                    @if ($errors->notifications->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">@foreach ($errors->notifications->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                    <input type="hidden" name="email_enabled" value="0">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-cyan-200 bg-cyan-50 p-4">
                        <input type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-cyan-700" name="email_enabled" value="1" @checked(in_array(old('email_enabled', $preferences['email_enabled']), [true, 1, '1'], true))>
                        <span><strong class="block text-sm">Recevoir les notifications par email</strong><span class="text-xs text-slate-600">À chaque événement sélectionné, après traitement par le service d’envoi. Aucun SMS.</span></span>
                    </label>
                    <fieldset>
                        <legend class="text-sm font-semibold">Nouveaux éléments partagés</legend>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach (['comment_added' => ['Un nouveau commentaire', 'Uniquement les commentaires que votre compte peut lire.'], 'document_added' => ['Un nouveau document', 'Uniquement les documents partagés avec votre compte.']] as $key => [$label, $description])
                                <input type="hidden" name="{{ $key }}" value="0">
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3"><input type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-cyan-700" name="{{ $key }}" value="1" @checked(in_array(old($key, $preferences[$key]), [true, 1, '1'], true))><span><span class="block text-sm font-medium">{{ $label }}</span><span class="text-xs text-slate-500">{{ $description }}</span></span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="text-sm font-semibold">Quand un dossier passe dans un de ces statuts</legend>
                        <p class="mt-1 text-xs text-slate-500">Seules les catégories accessibles à votre organisation sont proposées.</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @forelse ($notificationCategories as $key => $category)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm"><input type="checkbox" name="statuses[]" class="h-4 w-4 shrink-0 accent-cyan-700" value="{{ $key }}" @checked(in_array($key, $selectedStatuses, true))>{{ $category['label'] }}</label>
                            @empty<p class="text-sm text-slate-500">Aucune catégorie de dossier accessible actuellement.</p>@endforelse
                        </div>
                    </fieldset>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                        <p class="max-w-2xl text-xs text-slate-500">Vos réglages ne changent pas vos droits d’accès. Aucun contenu privé ni pièce jointe n’est transmis par ces notifications. Vous n’êtes pas notifié de vos propres ajouts.</p>
                        <button type="submit" class="gc-btn-primary">Enregistrer mes notifications</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
