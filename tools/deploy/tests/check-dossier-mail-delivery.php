<?php

use App\Models\Dossier;
use App\Services\Crm\DossierMailDelivery;
use App\Services\GlobalMailConfiguration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

// Synthetic container only. The callback returns an in-memory receipt, never an SMTP transport.
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
    || getenv('COFRAC2_RUNTIME_TEST') !== '1' || ! is_file('/.dockerenv') || ! is_file('/etc/mysql/fixture.cnf')) {
    fwrite(STDERR, "Fixture SQL jetable uniquement.\n");
    exit(1);
}
chdir('/var/www/cofrac2');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Mail::fake();
Queue::fake();
$app->instance(GlobalMailConfiguration::class, new class extends GlobalMailConfiguration
{
    public function refresh(): void {}
});
config(['crm.v2_enabled' => true, 'mail.default' => 'smtp', 'mail.delivery_enabled' => true, 'simulation.enabled' => false]);
$db = DB::connection();
if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'cofrac2production') {
    throw new RuntimeException('Base de fixture inattendue.');
}
$db->beginTransaction();
try {
    $dossier = Dossier::query()->firstOrFail();
    $activity = $dossier->activities()->create(['kind' => 'mail_queued', 'body' => 'Fixture',
        'changed_fields' => ['delivery_status' => 'queued', 'subject' => 'Fixture SMTP',
            'recipient' => 'recipient@example.invalid', 'body' => 'PRIVATE_BODY_NOT_RETURNED']]);
    $email = (new Email)->from('sender@example.invalid')->to('recipient@example.invalid')->text('Fixture');
    $receipt = new SentMessage(new Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
    $delivery = app(DossierMailDelivery::class);
    if ($delivery->deliver($activity->id, fn () => $receipt) !== $receipt) {
        throw new RuntimeException('Acceptation non tracee.');
    }
    $messages = $delivery->recent($dossier);
    if ($messages[0]['status'] !== 'sent' || $messages[0]['pending'] || str_contains(json_encode($messages), 'PRIVATE_BODY_NOT_RETURNED')) {
        throw new RuntimeException('Projection JSON MySQL incorrecte.');
    }
    if ($delivery->deliver($activity->id, fn () => throw new RuntimeException('Renvoi interdit')) !== null) {
        throw new RuntimeException('Doublon non bloque.');
    }
    $blocked = $dossier->activities()->create(['kind' => 'mail_queued', 'body' => 'Fixture', 'changed_fields' => ['delivery_status' => 'queued']]);
    config(['mail.delivery_enabled' => false]);
    $delivery->deliver($blocked->id, fn () => throw new RuntimeException('Envoi interdit'));
    if ($delivery->recent($dossier)[0]['status'] !== 'blocked') {
        throw new RuntimeException('Blocage mail non respecte.');
    }
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
    echo "OK : suivi MySQL, JSON limite, acceptation, doublon et verrou mail verifies sans SMTP.\n";
} finally {
    $db->rollBack();
}
