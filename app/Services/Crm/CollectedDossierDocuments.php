<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Read the collected files in place; the existing archive remains the source of ownership. */
class CollectedDossierDocuments
{
    private const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp',
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
            ->whereIn('dossier_id', $dossiers->pluck('id')->unique())->orderByDesc('source_id')->get();
        foreach ($records as $record) {
            if ($actor->isExternal() && ! app(ClientAccess::class)->canReadLegacyDocument($actor, $this->payload($record), $this->override($record))) {
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
    public function describe(object $record): array
    {
        $source = $this->payload($record);
        $path = $this->resolve($source['path'] ?? null);
        $name = is_string($source['name'] ?? null) && trim($source['name']) !== ''
            ? trim($source['name']) : 'Document #'.$record->source_id;
        $visibility = app(ClientAccess::class)->legacyDocumentVisibility($source, $this->override($record));

        return [
            'id' => 'collected-'.$record->source_id,
            'source_id' => $record->source_id,
            'name' => $name,
            'url' => $path !== null ? route('v2.dossiers.collected-documents.download', [$record->dossier_id, $record->source_id]) : null,
            'size' => $path !== null ? filesize($path) : null,
            'scope' => 'Archive Coffrac',
            'is_private' => ! $visibility['client_visible'],
            ...$visibility,
            'is_delegataire' => $visibility['delegataire_visible'],
            'availability' => $path !== null ? 'available' : 'unavailable',
        ];
    }

    public function download(User $actor, Dossier $dossier, int $sourceId): BinaryFileResponse
    {
        Gate::forUser($actor)->authorize('view', $dossier);
        $record = DB::table('legacy_records')->where('source_table', 'dossier_document')
            ->where('dossier_id', $dossier->id)->where('source_id', $sourceId)->first();
        abort_unless($record, 404);
        $source = $this->payload($record);
        $path = $this->resolve($source['path'] ?? null);
        abort_unless($path !== null, 404, 'Fichier non collecté, vide ou indisponible.');
        abort_if($actor->isExternal() && ! app(ClientAccess::class)->canReadLegacyDocument($actor, $source, $this->override($record)), 404);
        $name = preg_replace('/[^\pL\pN ._-]/u', '_', $this->describe($record)['name']) ?: 'Document';
        $name = mb_substr(trim($name, '. '), 0, 160) ?: 'Document';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! str_ends_with(strtolower($name), '.'.$extension)) {
            $name .= '.'.$extension;
        }

        // Historical files are untrusted: no inline HTML, execution, public URL or remote fallback.
        return response()->download($path, $name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], ResponseHeaderBag::DISPOSITION_ATTACHMENT)->setPrivate();
    }

    public function share(User $actor, Dossier $dossier, int $sourceId, bool $visible): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $sourceId, $visible): void {
            $record = DB::table('legacy_records')->where('source_table', 'dossier_document')
                ->where('dossier_id', $dossier->id)->where('source_id', $sourceId)->lockForUpdate()->first();
            abort_unless($record, 404);
            DB::table('legacy_records')->where('id', $record->id)->update(['client_visible_override' => $visible]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'document_visibility',
                'body' => ($visible ? 'Partage client activé' : 'Partage client retiré').' pour le document Coffrac #'.$sourceId,
                'changed_fields' => ['source_document_id' => $sourceId, 'client_visible' => $visible]]);
        });
    }

    private function override(object $record): ?bool
    {
        return isset($record->client_visible_override) ? (bool) $record->client_visible_override : null;
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
