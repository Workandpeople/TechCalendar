<?php

declare(strict_types=1);

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierAppointmentRescheduler;
use App\Services\Crm\DossierLocation;
use App\Services\Crm\DossierMapPreview;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

if (! is_file('/.dockerenv') || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'appointment_address_test') {
    exit(1);
}
require '/workspace/vendor/autoload.php';
$app = require '/workspace/bootstrap/app.php';
$app->useEnvironmentPath('/tmp/cofrac-isolated-no-env');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'appointment_address_test') {
    exit(1);
}
config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
Http::preventStrayRequests();
Mail::fake();
Queue::fake();
$app->make(Kernel::class)->call('migrate:fresh', ['--force' => true]);
$actor = User::factory()->create(['role' => 1]);
$technician = User::factory()->create(['role' => 2]);
$service = Service::create(['type' => 'COFRAC', 'name' => 'ADDRESS-TEST', 'average_duration_minutes' => 25]);
$technician->services()->attach($service);
$dossier = Dossier::create(['reference' => 'ADDRESS-TEST', 'company_name' => 'Test',
    'address' => str_repeat('Parcelle AC 0001 - ', 25).'Entree', 'postal_code' => '12120', 'city' => str_repeat('é', 190)]);
$operation = $dossier->operations()->create(['service_id' => $service->id]);
$operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
$geocoder = Mockery::mock(DossierMapPreview::class);
$geocoder->shouldReceive('coordinates')->andReturn(['coordinates' => ['latitude' => 44.2, 'longitude' => 2.6]]);
$app->instance(DossierMapPreview::class, $geocoder);
$data = ['version' => 1, 'appointment_id' => null, 'updated_at' => null, 'technician_id' => $technician->id,
    'starts_at' => now()->addWeek()->setTime(15, 0)->toIso8601String(), 'duration_minutes' => 25];
$migration = require '/workspace/database/migrations/2026_11_18_090000_expand_appointment_address_length.php';
$migration->down();
try {
    app(DossierAppointmentRescheduler::class)->reschedule($actor, $dossier, $operation, $data);
    throw new RuntimeException('Expected the original schema to reject the long address.');
} catch (QueryException $error) {
    assert($error->errorInfo[0] === '22001' && $error->errorInfo[1] === 1406);
    assert(str_contains($error->getMessage(), "column 'address'"));
}
assert(Appointment::count() === 0 && $dossier->fresh()->version === 1 && $dossier->activities()->count() === 0);
$migration->up();
$appointment = app(DossierAppointmentRescheduler::class)->reschedule($actor, $dossier, $operation, $data);
$address = DossierLocation::address($dossier);
assert($appointment->fresh()->address === $address && mb_strlen($address) > 255);
assert($appointment->duration_minutes === 25 && $appointment->ends_at->format('H:i') === '15:25');
assert(Appointment::count() === 1 && $operation->fresh()->legacy_status === 'RDV ATTENTE VISITE');
$rollbackRefused = false;
try {
    $migration->down();
} catch (RuntimeException $error) {
    assert(str_contains($error->getMessage(), 'Retour refuse'));
    $rollbackRefused = true;
}
assert($rollbackRefused && $appointment->fresh()->address === $address);
$length = DB::selectOne("SELECT CHARACTER_MAXIMUM_LENGTH AS capacity FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'appointment_address_test' AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'address'")->capacity;
assert((int) $length === 1024);
Http::assertNothingSent();
Mail::assertNothingOutgoing();
Queue::assertNothingPushed();
echo json_encode(['original_schema_sqlstate' => '22001', 'original_schema_driver_code' => 1406,
    'failed_attempt_rolled_back' => true, 'new_address_capacity' => (int) $length,
    'address_characters' => mb_strlen($address), 'full_address_preserved' => true,
    'duration_minutes' => 25, 'unsafe_rollback_refused' => $rollbackRefused, 'external_requests' => 0], JSON_THROW_ON_ERROR)."\n";
