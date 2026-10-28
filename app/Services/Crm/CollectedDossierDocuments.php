<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use App\Support\CofracText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Read the collected files in place; the existing archive remains the source of ownership. */
class CollectedDossierDocuments
{
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp',
        'doc', 'docx', 'xls', 'xlsx', 'xltx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'txt', 'csv', 'zip', '7z', 'mp4', 'mov'];

    private ?array $manifest = null;

    private ?string $root = null;

    public function forDossiers(User $actor, Collection $dossiers): array
    {
        foreach ($dossiers as $dossier) {
            Gate::forUser($actor)->authorize('view', $dossier);
        }
        if ($dossiers->isEmpty() || $this->paths() === []) {
            return [];
        }

        $result = [];
        $seen = [];
        $records = DB::table('legacy_records')->where('source_table', 'dossier_document')
            ->whereNull('hidden_at')->whereIn('dossier_id', $dossiers->pluck('id')->unique())->orderByDesc('source_id')->get();
        foreach ($records as $record) {
            if ($actor->isExternal() && ! app(ClientAccess::class)->canReadLegacyDocument($actor, $this->payload($record), $this->override($record), $this->delegataireOverride($record))) {
                continue;
            }
            $document = $this->describe($record);
            if ($document['url'] === null) {
                continue;
            }
            if ($actor->isExternal()) {
                $document['url'] = route('client.dossiers.archive', [$record->dossier_id, $record->source_id]);
            }
            $path = $this->payload($record)['path'];
            if (isset($seen[$record->dossier_id][$path])) {
                continue;
            }
            $seen[$record->dossier_id][$path] = true;
            $result[$record->dossier_id][] = $document;
        }

        return $result;
    }

    /** The caller must first authorize access to the owning dossier. Never expose a disk path. */
    public function describe(object $record, array $authors = []): array
    {
        $source = $this->payload($record);
        $path = $this->resolve($source['path'] ?? null);
        $name = is_string($source['name'] ?? null) && trim($source['name']) !== ''
            ? trim($source['name']) : 'Document #'.$record->source_id;
        $visibility = app(ClientAccess::class)->legacyDocumentVisibility($source, $this->override($record), $this->delegataireOverride($record));

        return [
            'id' => 'collected-'.$record->source_id,
            'source_id' => $record->source_id,
            'name' => CofracText::normalize($name),
            'description' => CofracText::normalize($source['comment'] ?? $source['description'] ?? $source['commentaire'] ?? null),
            'created_at' => $source['created_at'] ?? null,
            'author_id' => $source['author_id'] ?? null,
            'author_name' => $authors[$source['author_id'] ?? ''] ?? (isset($source['author_id']) ? 'Auteur Cofrac #'.$source['author_id'] : 'Auteur non renseigné'),
            'control_reference' => $source['reference_controle'] ?? null,
            'outcome' => $source['status'] ?? null,
            'inspected_on' => $source['date_rdv'] ?? null,
            'issued_on' => $source['date_emission'] ?? null,
            'url' => $path !== null ? route('v2.dossiers.collected-documents.download', [$record->dossier_id, $record->source_id]) : null,
            'previewable' => $path !== null && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif'], true),
            'size' => $path !== null ? filesize($path) : null,
            'scope' => 'Archive Cofrac',
            'is_private' => ! $visibility['client_visible'],
            ...$visibility,
            'is_delegataire' => $visibility['delegataire_visible'],
            'availability' => $path !== null ? 'available' : 'unavailable',
        ];
    }

    public function authors(Collection $records): array
    {
        $ids = $records->map(fn ($record) => $this->payload($record)['author_id'] ?? null)->filter()->unique();

        return DB::table('legacy_records')->where('source_table', 'users')->whereIn('source_id', $ids)->get(['source_id', 'payload'])
            ->mapWithKeys(function ($record): array {
                $source = $this->payload($record);
                $name = trim((string) (($source['nom_societe'] ?? '') ?: ($source['name'] ?? '')));

                return [$record->source_id => CofracText::normalize($name) ?: 'Auteur Cofrac #'.$record->source_id];
            })->all();
    }

    public function download(User $actor, Dossier $dossier, int $sourceId, bool $preview = false): BinaryFileResponse
    {
        Gate::forUser($actor)->authorize('viewDocuments', $dossier);
        $record = DB::table('legacy_records')->where('source_table', 'dossier_document')
            ->whereNull('hidden_at')->where('dossier_id', $dossier->id)->where('source_id', $sourceId)->first();
        abort_unless($record, 404);
        $source = $this->payload($record);
        $path = $this->resolve($source['path'] ?? null);
        abort_unless($path !== null, 404, 'Fichier non collecté, vide ou indisponible.');
        abort_if($actor->isExternal() && ! app(ClientAccess::class)->canReadLegacyDocument($actor, $source, $this->override($record), $this->delegataireOverride($record)), 404);
        $name = preg_replace('/[^\pL\pN ._-]/u', '_', $this->describe($record)['name']) ?: 'Document';
        $name = mb_substr(trim($name, '. '), 0, 160) ?: 'Document';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! str_ends_with(strtolower($name), '.'.$extension)) {
            $name .= '.'.$extension;
        }

        $mime = $preview ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : 'application/octet-stream';
        $inline = $preview && DocumentResponseHeaders::previewable($mime);

        // Only sniffed PDF/raster images can be viewed. Never render archived HTML/SVG.
        return response()->download($path, $name, DocumentResponseHeaders::forFile($mime, $inline),
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT)->setPrivate();
    }

    public function share(User $actor, Dossier $dossier, int $sourceId, bool $visible, ?bool $delegataireVisible = null): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $sourceId, $visible, $delegataireVisible): void {
            $record = DB::table('legacy_records')->where('source_table', 'dossier_document')
                ->whereNull('hidden_at')->where('dossier_id', $dossier->id)->where('source_id', $sourceId)->lockForUpdate()->first();
            abort_unless($record, 404);
            DB::table('legacy_records')->where('id', $record->id)->update(['client_visible_override' => $visible,
                'delegataire_visible_override' => $visible && ($delegataireVisible ?? $visible)]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_visibility',
                'body' => 'Accès « '.ContentAudience::LABELS[ContentAudience::key($visible, $visible && ($delegataireVisible ?? $visible))].' » pour le document Cofrac #'.$sourceId,
                'changed_fields' => ['source_document_id' => $sourceId, 'client_visible' => $visible, 'delegataire_visible' => $visible && ($delegataireVisible ?? $visible)]]);
        });
    }

    private function override(object $record): ?bool
    {
        return isset($record->client_visible_override) ? (bool) $record->client_visible_override : null;
    }

    private function delegataireOverride(object $record): ?bool
    {
        return isset($record->delegataire_visible_override) ? (bool) $record->delegataire_visible_override : null;
    }

    public function remove(User $actor, Dossier $dossier, int $sourceId): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $sourceId): void {
            $record = DB::table('legacy_records')->where('source_table', 'dossier_document')->whereNull('hidden_at')
                ->where('dossier_id', $dossier->id)->where('source_id', $sourceId)->lockForUpdate()->first();
            abort_unless($record, 404);
            DB::table('legacy_records')->where('id', $record->id)->update(['hidden_at' => now()]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_removed',
                'body' => 'Document Cofrac #'.$sourceId.' retiré. Archive conservée pour traçabilité.']);
        });
    }

    private function payload(object $record): array
    {
        $payload = json_decode($record->payload, true);

        return is_array($payload) ? $payload : [];
    }

    private function paths(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $this->manifest = [];
        if (! config('crm.v2_enabled')) {
            return [];
        }
        $configuredRoot = (string) config('crm.collected_documents_root');
        $manifestPath = (string) config('crm.collected_documents_manifest');
        $root = $configuredRoot !== '' ? realpath($configuredRoot) : false;
        $public = realpath(public_path());
        if (! $root || ! is_dir($root) || is_link($configuredRoot)
            || ($public && ($root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)))
            || ! is_file($manifestPath) || ! is_readable($manifestPath)
            || filesize($manifestPath) > 16 * 1024 * 1024) {
            return [];
        }
        $content = file_get_contents($manifestPath);
        if ($content === false || ! str_ends_with($content, "\0")) {
            return [];
        }
        $this->root = $root;
        foreach (explode("\0", $content) as $path) {
            if ($this->safeRelativePath($path)) {
                $this->manifest[$path] = true;
            }
        }

        return $this->manifest;
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F\\\\:]/', $path)
            || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || str_starts_with($part, '.')) {
                return false;
            }
        }

        return true;
    }

    private function resolve(mixed $relative): ?string
    {
        if (! is_string($relative) || ! isset($this->paths()[$relative])) {
            return null;
        }
        $path = $this->root;
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            if (is_link($path)) {
                return null;
            }
        }
        if (! is_file($path) || ! is_readable($path) || realpath($path) !== $path || filesize($path) <= 0) {
            return null;
        }

        return $path;
    }
}
