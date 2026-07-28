<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\MailSender;
use App\Models\MailTemplate;
use App\Services\MailTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ManagerMailTemplateController extends Controller
{
    public function index(Request $request, MailTemplateRenderer $renderer): View
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $query = MailTemplate::query()
            ->with(['sender:id,name,mail_from_address,logo_path,is_active', 'updatedBy:id,first_name,last_name'])
            ->latest('updated_at');

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        if (($filters['status'] ?? '') === 'active') {
            $query->where('is_active', true);
        }

        if (($filters['status'] ?? '') === 'inactive') {
            $query->where('is_active', false);
        }

        $templates = $query->paginate(20)->withQueryString();
        $mailSenders = MailSender::query()
            ->withCount('templates')
            ->with(['updatedBy:id,first_name,last_name'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('manager.mail-templates.index', [
            'templates' => $templates,
            'mailSenders' => $mailSenders,
            'activeMailSenders' => $mailSenders->where('is_active', true)->values(),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'sampleVariables' => $renderer->sampleData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $payload = $this->validatedPayload($request);
        $payload['created_by_user_id'] = $request->user()?->id;
        $payload['updated_by_user_id'] = $request->user()?->id;

        MailTemplate::query()->create($payload);

        return redirect()->route('manager.mail-templates')->with('status', 'Template de mail créé avec succès.');
    }

    public function update(Request $request, MailTemplate $mailTemplate): RedirectResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $payload = $this->validatedPayload($request, $mailTemplate);
        $payload['updated_by_user_id'] = $request->user()?->id;

        $mailTemplate->update($payload);

        return redirect()->route('manager.mail-templates')->with('status', 'Template de mail mis à jour.');
    }

    public function destroy(Request $request, MailTemplate $mailTemplate): RedirectResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $this->deleteLogoFile($mailTemplate->logo_path);
        $mailTemplate->forceFill(['logo_path' => null])->save();
        $mailTemplate->delete();

        return redirect()->route('manager.mail-templates')->with('status', 'Template de mail supprimé.');
    }

    public function preview(Request $request, MailTemplateRenderer $renderer): JsonResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $payload = $request->validate([
            'mail_template_id' => ['nullable', 'integer', Rule::exists('mail_templates', 'id')->whereNull('deleted_at')],
            'mail_sender_id' => ['nullable', 'integer', Rule::exists('mail_senders', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'subject' => ['required', 'string', 'max:190'],
            'markdown_body' => ['required', 'string', 'max:60000'],
        ]);
        $storedTemplate = ! empty($payload['mail_template_id'])
            ? MailTemplate::query()->with('sender')->find($payload['mail_template_id'])
            : null;
        $sender = ! empty($payload['mail_sender_id'])
            ? MailSender::query()->find($payload['mail_sender_id'])
            : $storedTemplate?->sender;

        $template = new MailTemplate([
            'name' => 'Preview',
            'slug' => 'preview',
            'mail_sender_id' => $sender?->id,
            'subject' => $payload['subject'],
            'markdown_body' => $payload['markdown_body'],
            'logo_path' => $storedTemplate?->logo_path,
            'is_active' => true,
        ]);
        $template->setRelation('sender', $sender);

        return response()->json($renderer->preview($template));
    }

    public function storeSender(Request $request): RedirectResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $payload = $this->validatedSenderPayload($request);
        $payload['logo_path'] = $this->resolveSenderLogoPath($request, $payload['name']);
        $payload['created_by_user_id'] = $request->user()?->id;
        $payload['updated_by_user_id'] = $request->user()?->id;

        MailSender::query()->create($payload);

        return redirect()->route('manager.mail-templates')->with('status', 'Expéditeur créé avec succès.');
    }

    public function updateSender(Request $request, MailSender $mailSender): RedirectResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $payload = $this->validatedSenderPayload($request, $mailSender);
        $previousLogoPath = $mailSender->logo_path;
        $payload['logo_path'] = $this->resolveSenderLogoPath($request, $payload['name'], $mailSender);
        $payload['updated_by_user_id'] = $request->user()?->id;

        $mailSender->update($payload);
        $this->deleteLogoFileIfReplaced($previousLogoPath, $mailSender->logo_path);

        return redirect()->route('manager.mail-templates')->with('status', 'Expéditeur mis à jour.');
    }

    public function destroySender(Request $request, MailSender $mailSender): RedirectResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        if ($mailSender->templates()->exists()) {
            throw ValidationException::withMessages([
                'mail_sender' => 'Impossible de supprimer un expéditeur utilisé par un ou plusieurs templates.',
            ]);
        }

        $this->deleteLogoFile($mailSender->logo_path);
        $mailSender->forceFill(['logo_path' => null])->save();
        $mailSender->delete();

        return redirect()->route('manager.mail-templates')->with('status', 'Expéditeur supprimé.');
    }

    /**
     * @return array{name:string,slug:string,mail_sender_id:int,subject:string,markdown_body:string,is_active:bool}
     */
    private function validatedPayload(Request $request, ?MailTemplate $template = null): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'mail_sender_id' => ['required', 'integer', Rule::exists('mail_senders', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'subject' => ['required', 'string', 'max:190'],
            'markdown_body' => ['required', 'string', 'max:60000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'mail_sender_id.required' => 'Sélectionne un expéditeur pour ce template.',
            'mail_sender_id.exists' => 'L’expéditeur sélectionné est introuvable ou inactif.',
        ]);

        $slug = $template?->slug ?: $this->uniqueSlug($payload['name']);

        return [
            'name' => trim((string) $payload['name']),
            'slug' => $slug,
            'mail_sender_id' => (int) $payload['mail_sender_id'],
            'subject' => trim((string) $payload['subject']),
            'markdown_body' => trim((string) $payload['markdown_body']),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * @return array{name:string,mail_host:string,mail_port:int,mail_username:?string,mail_password:?string,mail_encryption:?string,mail_from_address:string,mail_from_name:string,mail_admin_email:?string,is_active:bool}
     */
    private function validatedSenderPayload(Request $request, ?MailSender $sender = null): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'mail_host' => ['required', 'string', 'max:190'],
            'mail_port' => ['required', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => [$sender ? 'nullable' : 'required', 'string', 'max:1000'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl', ''])],
            'mail_from_address' => ['required', 'email', 'max:190'],
            'mail_from_name' => ['required', 'string', 'max:190'],
            'mail_admin_email' => ['nullable', 'email', 'max:190'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'mail_host.required' => 'Le serveur SMTP est obligatoire.',
            'mail_port.required' => 'Le port SMTP est obligatoire.',
            'mail_port.between' => 'Le port SMTP doit être compris entre 1 et 65535.',
            'mail_password.required' => 'Le mot de passe SMTP est obligatoire à la création.',
            'mail_from_address.required' => 'L’adresse d’expédition est obligatoire.',
            'mail_from_address.email' => 'L’adresse d’expédition doit être une adresse e-mail valide.',
            'mail_admin_email.email' => 'L’adresse admin doit être une adresse e-mail valide.',
            'logo.image' => 'Le logo doit être une image valide.',
            'logo.mimes' => 'Le logo doit être au format JPG ou PNG.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
        ]);

        return [
            'name' => trim((string) $payload['name']),
            'mail_host' => trim((string) $payload['mail_host']),
            'mail_port' => (int) $payload['mail_port'],
            'mail_username' => filled($payload['mail_username'] ?? null) ? trim((string) $payload['mail_username']) : null,
            'mail_password' => filled($payload['mail_password'] ?? null) ? (string) $payload['mail_password'] : $sender?->mail_password,
            'mail_encryption' => filled($payload['mail_encryption'] ?? null) ? (string) $payload['mail_encryption'] : null,
            'mail_from_address' => trim((string) $payload['mail_from_address']),
            'mail_from_name' => trim((string) $payload['mail_from_name']),
            'mail_admin_email' => filled($payload['mail_admin_email'] ?? null) ? trim((string) $payload['mail_admin_email']) : null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function resolveSenderLogoPath(Request $request, string $name, ?MailSender $sender = null): ?string
    {
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $extension = strtolower($file->extension() ?: $file->guessExtension() ?: 'png');
            $filename = sprintf('%s-%s.%s', Str::slug($name) ?: 'expediteur', Str::random(16), $extension);

            return $file->storeAs('mail-sender-logos', $filename, 'public');
        }

        if ($request->boolean('remove_logo')) {
            return null;
        }

        return $sender?->logo_path;
    }

    private function deleteLogoFileIfReplaced(?string $previousLogoPath, ?string $currentLogoPath): void
    {
        if (! $previousLogoPath || $previousLogoPath === $currentLogoPath) {
            return;
        }

        $this->deleteLogoFile($previousLogoPath);
    }

    private function deleteLogoFile(?string $logoPath): void
    {
        if (! $logoPath) {
            return;
        }

        Storage::disk('public')->delete($logoPath);
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            throw ValidationException::withMessages([
                'name' => 'Le nom du template doit permettre de générer un identifiant.',
            ]);
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (MailTemplate::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function canManageMailTemplates(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }
}
