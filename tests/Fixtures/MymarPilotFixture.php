<?php

namespace Tests\Fixtures;

use App\Services\Mymar\MymarPilotConfiguration;
use DateTimeImmutable;
use DateTimeZone;

final class MymarPilotFixture
{
    public static function pilot(array $map = [11 => 101], bool $display = true): MymarPilotConfiguration
    {
        return MymarPilotConfiguration::fromArray(['format' => 'cofrac.mymar-observation-pilot', 'version' => 1,
            'sync_enabled' => true, 'display_enabled' => $display, 'blocking_enabled' => false, 'generation' => str_repeat('a', 32),
            'bindings' => array_map(fn ($id, $target) => ['source_technician_id' => $id, 'cofrac_user_id' => $target], array_keys($map), array_values($map))]);
    }

    public static function source(MymarPilotConfiguration $pilot, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        $from = $now->setTimezone(new DateTimeZone('Europe/Paris'))->setTime(0, 0)->modify('-7 days');
        $tomorrow = $now->setTimezone(new DateTimeZone('Europe/Paris'))->modify('+1 day')->setTime(9, 30);

        return ['format' => 'cofrac.mymar-occupations-source', 'version' => 1, 'complete' => true,
            'generated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours',
            'window_start' => $from->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'window_end' => $from->modify('+28 days')->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'technician_ids' => array_keys($pilot->technicianMap), 'status_ids' => [3, 16],
            'appointments' => [['rdv_id' => 555, 'dossier_id' => 77, 'technician_id' => array_key_first($pilot->technicianMap),
                'status_id' => 16, 'is_old' => 0, 'stored_start' => $tomorrow->format('Y-m-d H:i:s'), 'raw_duration' => 1]]];
    }
}
