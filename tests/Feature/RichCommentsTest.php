<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Support\CommentContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'RICH-COMMENTS',
        'name' => 'Client', 'kind' => 'client', 'is_active' => true]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults('client')]);
    $this->dossier = Dossier::create(['reference' => 'RICH-COMMENTS', 'company_name' => 'Test',
        'address' => 'Rue Test', 'city' => 'Paris', 'client_id' => $this->organisation->id]);
    $this->dossier->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
});

it('stores rich comments safely and preserves their appearance in both dossier spaces', function (bool $external) {
    $body = '<p>Bonjour <strong>équipe</strong></p><p><span class="ql-font-serif" style="color:#b91c1c;font-size:18px;position:fixed" onclick="attack()">Urgent</span></p>';
    $this->actingAs($external ? $this->external : $this->staff)
        ->post(route($external ? 'client.dossiers.comments' : 'v2.dossiers.comments.store', $this->dossier), ['body' => $body, 'audience' => 'client'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $stored = $this->dossier->comments()->sole();
    expect($stored->body)->toBe(CommentContent::html($body))->not->toContain('onclick', 'position', 'attack()');
    foreach ([$this->staff, $this->external] as $user) {
        $this->actingAs($user)->get(route($user->isExternal() ? 'client.dossiers.show' : 'v2.dossiers.show', $this->dossier))
            ->assertOk()->assertSee('<strong>équipe</strong>', false)->assertSee('font-family: Georgia, serif', false)
            ->assertSee('color: #b91c1c', false)->assertSee('font-size: 18px', false)
            ->assertSee('data-comment-editor', false)->assertDontSee('onclick="attack()"', false);
    }
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
})->with([false, true]);

it('rejects formatted empty comments and still enforces external permissions', function () {
    $this->actingAs($this->external)->post(route('client.dossiers.comments', $this->dossier), ['body' => '<p><br></p>'])
        ->assertSessionHasErrors('body');
    $other = Dossier::create(['reference' => 'OTHER-RICH', 'company_name' => 'Other', 'address' => 'Rue Autre', 'city' => 'Paris']);
    $this->post(route('client.dossiers.comments', $other), ['body' => '<p>Non autorisé</p>'])->assertForbidden();
    expect($this->dossier->comments()->count())->toBe(0)->and($other->comments()->count())->toBe(0);
});

it('renders plain tooltip content and retains literal entities safely', function () {
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => '<p><span style="color:#b91c1c;font-size:22px">Appeler</span> <b>demain</b></p>', 'client_visible' => true]);
    $html = Blade::render('@include("crm.dossiers.comments-preview")', ['dossier' => $this->dossier->load('comments.author')->loadCount('comments')]);
    expect($html)->toContain('Appeler demain')->not->toContain('style=', '<span style', '<b>demain</b>');
    $html = Blade::render('<x-comment-body :body="$body" />', ['body' => '&lt;img src=x onerror=example&gt;']);
    expect($html)->toContain('&lt;img src=x onerror=example&gt;')->not->toContain('<img src=');
});

it('renders a local-only editor preview when explicitly requested', function () {
    $html = Blade::render('<form id="comment-preview"><label for="preview-comment" class="gc-label">Votre commentaire</label><x-comment-editor id="preview-comment" required value="Texte de démonstration" /><button class="gc-btn-primary" type="submit">Enregistrer</button></form><section><h2>Commentaire enregistré</h2><div id="preview-result"><x-comment-excerpt body="Première ligne du commentaire.&#10;Deuxième ligne.&#10;Troisième ligne." /></div></section>');
    expect($html)->toContain('aria-label="Police"', 'aria-label="Couleur du texte"', 'data-comment-input', 'maxlength="10000"');
    $path = getenv('COMMENT_PREVIEW_PATH');
    if ($path && str_starts_with($path, '/tmp/cofrac-comment-preview-') && is_dir($path)) {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = '/build/'.$manifest['resources/css/app.css']['file'];
        $js = '/build/'.$manifest['resources/js/app.js']['file'];
        file_put_contents($path.'/index.html', '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Commentaires · Démonstration locale</title><link rel="stylesheet" href="'.$css.'"><body><main class="gc-workspace gc-dossier-detail" style="max-width:900px;margin:30px auto;padding:20px"><h1>Commentaires du dossier</h1>'.$html.'</main><script type="module" src="'.$js.'"></script><script>document.querySelector("form").addEventListener("submit",e=>{e.preventDefault();const input=document.getElementById("preview-comment");if(input.value)document.getElementById("preview-result").innerHTML=window.TechCalendarComments.html(input.value);});</script></body></html>');
    }
});
