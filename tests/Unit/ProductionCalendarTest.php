<?php

require_once __DIR__.'/../../tools/deploy/lib/ProductionCalendar.php';

use Cofrac2\Deploy\ProductionCalendar;

it('toggles only the calendar engine and keeps all other production settings intact', function () {
    $env = "APP_ENV=production\nMAIL_PASSWORD=secret\nSMSFACTOR_MODE=simulate\n";
    $native = ProductionCalendar::environment($env, 'native');
    expect($native)->toBe($env."COFRAC_CALENDAR_ENGINE=native\n")
        ->and(ProductionCalendar::environment($native, 'fullcalendar'))->toBe($env."COFRAC_CALENDAR_ENGINE=fullcalendar\n")
        ->and(ProductionCalendar::environment($native, 'native'))->toBe($native);
});

it('refuses unexpected engines and ambiguous calendar settings', function () {
    expect(fn () => ProductionCalendar::environment('', 'other'))->toThrow(RuntimeException::class);
    expect(fn () => ProductionCalendar::environment("COFRAC_CALENDAR_ENGINE=native\nexport COFRAC_CALENDAR_ENGINE=fullcalendar\n", 'native'))->toThrow(RuntimeException::class);
});
