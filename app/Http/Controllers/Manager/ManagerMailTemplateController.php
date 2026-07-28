<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\MailTemplate;
use App\Services\MailTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->with(['updatedBy:id,first_name,last_name'])
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

        return view('manager.mail-templates.index', [
            'templates' => $templates,
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

        $mailTemplate->delete();

        return redirect()->route('manager.mail-templates')->with('status', 'Template de mail supprimé.');
    }

    public function preview(Request $request, MailTemplateRenderer $renderer): JsonResponse
    {
        abort_unless($this->canManageMailTemplates($request), 403);

        $payload = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'markdown_body' => ['required', 'string', 'max:60000'],
        ]);

        $template = new MailTemplate([
            'name' => 'Preview',
            'slug' => 'preview',
            'subject' => $payload['subject'],
            'markdown_body' => $payload['markdown_body'],
            'is_active' => true,
        ]);

        return response()->json($renderer->preview($template));
    }

    /**
     * @return array{name:string,slug:string,subject:string,markdown_body:string,is_active:bool}
     */
    private function validatedPayload(Request $request, ?MailTemplate $template = null): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190'],
            'subject' => ['required', 'string', 'max:190'],
            'markdown_body' => ['required', 'string', 'max:60000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slug = $this->normalizedSlug($payload['slug'] ?? null, $payload['name']);
        $slugExists = MailTemplate::withTrashed()
            ->where('slug', $slug)
            ->when($template, fn ($query) => $query->whereKeyNot($template->id))
            ->exists();

        if ($slugExists) {
            throw ValidationException::withMessages([
                'slug' => 'Ce slug est déjà utilisé par un autre template de mail.',
            ]);
        }

        return [
            'name' => trim((string) $payload['name']),
            'slug' => $slug,
            'subject' => trim((string) $payload['subject']),
            'markdown_body' => trim((string) $payload['markdown_body']),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function normalizedSlug(?string $slug, string $fallback): string
    {
        $normalized = Str::slug(filled($slug) ? $slug : $fallback);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'slug' => 'Le slug du template est obligatoire.',
            ]);
        }

        return $normalized;
    }

    private function canManageMailTemplates(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }
}
