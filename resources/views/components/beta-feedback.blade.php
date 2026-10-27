<div data-beta-feedback>
    <button type="button" class="gc-beta-trigger" data-beta-open aria-controls="beta-feedback-dialog" aria-haspopup="dialog"><span aria-hidden="true">β</span> Bêta</button>
    <dialog id="beta-feedback-dialog" class="gc-beta-dialog" aria-labelledby="beta-feedback-title">
        <header class="gc-beta-heading">
            <div><p>VOTRE RETOUR COMPTE</p><h2 id="beta-feedback-title" tabindex="-1">Améliorons votre espace</h2></div>
            <button type="button" data-beta-close class="gc-beta-close" aria-label="Fermer le formulaire">Fermer</button>
        </header>
        <form action="{{ route('support.feedback') }}" method="POST" enctype="multipart/form-data" data-beta-form>
            @csrf
            <input type="hidden" name="submission_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="page_path" value="/">
            <input type="hidden" name="viewport" value="">
            <fieldset data-beta-fields>
                <label class="gc-beta-label" for="beta-kind">Votre retour concerne</label>
                <select id="beta-kind" name="kind" required><option value="bug">Une erreur ou un problème</option><option value="improvement">Une demande d’amélioration</option></select>
                <label class="gc-beta-label" for="beta-title">En quelques mots</label>
                <input id="beta-title" name="title" type="text" required minlength="3" maxlength="160" placeholder="Ex. : impossible d’ouvrir un document">
                <label class="gc-beta-label" for="beta-message">Que s’est-il passé, ou que souhaitez-vous améliorer ?</label>
                <textarea id="beta-message" name="message" required minlength="10" maxlength="10000" rows="5" placeholder="Précisez les étapes, le résultat obtenu et ce que vous attendiez."></textarea>
                <label class="gc-beta-upload" for="beta-screenshots"><strong>Joindre des captures d’écran</strong><span>PNG, JPEG ou WebP · 3 captures · 4 Mo maximum chacune</span>
                    <input id="beta-screenshots" type="file" name="screenshots[]" accept="image/png,image/jpeg,image/webp" multiple data-beta-files>
                </label>
                <div class="gc-beta-previews" data-beta-previews aria-live="polite"></div>
                <p class="gc-beta-privacy">Le développeur recevra votre message, les captures choisies, votre identité de compte, la page et le navigateur utilisés. Aucun cookie, mot de passe ou contenu de formulaire n’est joint automatiquement. Vérifiez que vos captures ne contiennent pas d’informations sensibles.</p>
            </fieldset>
            <p data-beta-status class="gc-beta-status" role="status" aria-live="polite"></p>
            <footer><button type="button" data-beta-close class="gc-beta-close">Annuler</button><button type="submit" class="gc-beta-submit" data-beta-submit>Envoyer mon retour</button></footer>
        </form>
    </dialog>
</div>
