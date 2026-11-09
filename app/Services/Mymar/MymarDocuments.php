<?php

namespace App\Services\Mymar;

use App\Models\User;
use Cofrac2\Deploy\Mymar\MymarDocumentFiles;
use Cofrac2\Deploy\Mymar\MymarDocumentSource;
use PDO;
use RuntimeException;

/** Same-host, SELECT-only adapter. No MYMAR bootstrap, source credentials or public file URL. */
class MymarDocuments
{
    public function __construct(private MymarDocumentSettings $settings, private MymarActionSettings $mapping) {}

    public function catalogue(User $actor, int $rdv, ?int $technicianId = null): array
    {
        return $this->readCatalogue($rdv, $this->technician($actor, $technicianId));
    }

    public function open(User $actor, int $rdv, int $document, ?int $technicianId = null): array
    {
        $sourceId = $this->technician($actor, $technicianId);
        $catalogue = $this->readCatalogue($rdv, $sourceId);
        $metadata = collect($catalogue['documents'])->firstWhere('id', $document);
        abort_if($metadata === null, 404);
        // Opening rechecks the current source assignment, status and document classification.
        $stream = $this->openSource($rdv, $sourceId, $document);

        return ['stream' => $stream, 'name' => $metadata['name'], 'bytes' => fstat($stream)['size']];
    }

    protected function readCatalogue(int $rdv, int $sourceId): array
    {
        return $this->source($sourceId)->catalogue($rdv, $sourceId);
    }

    protected function openSource(int $rdv, int $sourceId, int $document)
    {
        return $this->source($sourceId)->open($rdv, $sourceId, $document);
    }

    private function technician(User $actor, ?int $technicianId): int
    {
        abort_unless(config('crm.v2_enabled') && ! $actor->trashed() && ! $actor->isExternal(), 403);
        $office = $actor->admin || in_array($actor->role, [0, 1], true);
        abort_unless($office || $actor->role === 2, 403);
        abort_unless($office || $technicianId === null || $technicianId === $actor->id, 404);
        $id = $office ? ($technicianId ?? $actor->id) : $actor->id;
        $bindings = $this->mapping->load()['bindings'] ?? [];
        $link = collect($bindings)->firstWhere('cofrac_user_id', $id);
        abort_unless($link && User::query()->internal()->where('role', 2)->where('admin', false)->whereKey($id)->exists(), 404);

        return $link['source_technician_id'];
    }

    private function source(int $sourceId): MymarDocumentSource
    {
        $config = $this->settings->load();
        if ($config === null) {
            throw new RuntimeException('mymar.documents_not_configured');
        }
        foreach (['MymarDocumentFailure', 'MymarDocumentPolicy', 'MymarDocumentFiles', 'MymarDocumentSqlAccess', 'MymarDocumentSource'] as $class) {
            require_once base_path('tools/deploy/mymar/documents/'.$class.'.php');
        }
        $database = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4',
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 2]);

        return new MymarDocumentSource($database, new MymarDocumentFiles($config['uploads_root']), [$sourceId], 0, enabled: true);
    }
}
