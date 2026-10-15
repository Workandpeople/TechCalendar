<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

function cutoverPasswordQuestion(string $prompt): Question
{
    return (new Question($prompt))
        ->setHidden(true)
        ->setHiddenFallback(false)
        ->setTrimmable(false)
        // Keep deliberate spaces, but never hash the terminal's Enter key.
        ->setNormalizer(static fn ($value): string => rtrim((string) $value, "\r\n"));
}

// Allow the terminal input handling to be tested without booting either app.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

// Deployment-only helper. No remote API calls, migrations, or database imports.
$stage = $argv[1] ?? '';
try {
    if ($stage === 'nginx') {
        $source = file_get_contents($argv[2]);
        $replace = static function (string $from, string $to) use (&$source): void {
            if (substr_count($source, $from) !== 1) {
                throw new RuntimeException('Configuration Nginx inattendue : '.$from);
            }
            $source = str_replace($from, $to, $source);
        };
        if (str_contains($source, 'proxy_pass') || str_contains($source, 'X-TechCalendar-Release')) {
            throw new RuntimeException('Vhost deja modifie : controle manuel necessaire.');
        }
        $replace('root /var/www/TechCalendar/public;', 'root /var/www/TechCalendar-v2/public;');
        $replace('location ~ \\.php$ {', 'location = /index.php {');
        $replace('fastcgi_pass unix:/run/php/php8.3-fpm.sock;', 'fastcgi_pass unix:/run/php/techcalendar-v2-demo.sock;');
        $replace('fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;', 'fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;');
        $replace('charset utf-8;', "charset utf-8;\n    add_header X-TechCalendar-Release v2-demo always;");
        if (! str_contains($source, 'client_max_body_size')) {
            $source = str_replace('charset utf-8;', "charset utf-8;\n    client_max_body_size 40m;", $source);
        }
        $replace('location / {', <<<'NGINX'
location ^~ /app/ {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
        proxy_buffering off;
    }

    location ~ \.php$ {
        return 404;
    }

    location / {
NGINX);
        // The exact front-controller location takes priority over the PHP deny rule.
        if (file_put_contents($argv[3], $source) !== strlen($source)) {
            throw new RuntimeException('Ecriture du vhost impossible.');
        }
        $replace('listen 80;', 'listen 127.0.0.1:18081;');
        if (file_put_contents($argv[4], $source) !== strlen($source)) {
            throw new RuntimeException('Ecriture du vhost de controle impossible.');
        }
        exit(0);
    }

    $path = $stage === 'v1-database' ? '/var/www/TechCalendar' : '/var/www/TechCalendar-v2';
    chdir($path);
    require $path.'/vendor/autoload.php';
    $app = require $path.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection();

    if ($stage === 'v1-database') {
        $name = $db->getDatabaseName();
        if ($db->getDriverName() !== 'mysql'
            || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)
            || ! preg_match('/^[a-zA-Z0-9_]+$/', $name)
            || str_ends_with($name, '_simulation')) {
            throw new RuntimeException('Connexion V1 inattendue.');
        }
        if ($db->selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'")->n > 0) {
            throw new RuntimeException('Tables non transactionnelles : sauvegarde manuelle necessaire.');
        }
        echo $name;
        exit(0);
    }

    if (! $app->environment('demo') || $db->getDatabaseName() !== 'techcalendar_v2_demo_simulation') {
        throw new RuntimeException('Cible differente de la demonstration V2.');
    }

    if ($stage === 'admin') {
        if (! stream_isatty(STDIN)) {
            throw new RuntimeException('Un terminal interactif est obligatoire.');
        }
        $admins = User::query()->where('admin', true)->orderBy('id')->get();
        if ($admins->isEmpty()) {
            throw new RuntimeException('Aucun administrateur actif.');
        }
        $input = new ArrayInput([]);
        $output = new ConsoleOutput;
        $helper = new QuestionHelper;
        $choice = new ChoiceQuestion(
            'Compte admin de demo dont le mot de passe doit etre defini',
            $admins->pluck('email')->all(),
            $admins->count() === 1 ? 0 : null,
        );
        $email = $helper->ask($input, $output, $choice);
        $passwordQuestion = cutoverPasswordQuestion('Nouveau mot de passe unique (8 caracteres minimum, saisie masquee) : ');
        $password = $helper->ask($input, $output, $passwordQuestion);
        if (! is_string($password) || mb_strlen($password) < 8 || strlen($password) > 72) {
            throw new RuntimeException('Utiliser au moins 8 caracteres et au maximum 72 octets.');
        }
        $confirmation = cutoverPasswordQuestion('Confirmer ce mot de passe : ');
        if (! hash_equals($password, (string) $helper->ask($input, $output, $confirmation))) {
            throw new RuntimeException('Confirmation differente.');
        }
        $user = $admins->firstWhere('email', $email);
        $db->transaction(static function () use ($user, $password, $db): void {
            $user->forceFill(['password' => $password, 'remember_token' => null, 'must_change_password' => false])->saveQuietly();
            $provider = Auth::guard('web')->getProvider();
            $credentials = ['email' => $user->email, 'password' => $password];
            $saved = $provider->retrieveByCredentials($credentials);
            if (! $saved || $saved->getAuthIdentifier() !== $user->getAuthIdentifier()
                || ! $provider->validateCredentials($saved, $credentials)) {
                throw new RuntimeException('Verification du mot de passe enregistre echouee : modification annulee.');
            }
            $db->table('sessions')->where('user_id', $user->id)->delete();
            $db->table('password_reset_tokens')->where('email', $user->email)->delete();
        });
        unset($password);
        echo "Acces admin V2 mis a jour et verifie par Laravel. Aucun mot de passe affiche ou exporte.\n";
        exit(0);
    }

    if ($stage === 'websocket') {
        $port = (int) ($argv[2] ?? 0);
        if (! in_array($port, [80, 18081], true)) {
            throw new RuntimeException('Port de controle invalide.');
        }
        $host = 'techcalendar.lucas-dinnichert.fr';
        $key = base64_encode(random_bytes(16));
        $appKey = rawurlencode((string) config('broadcasting.connections.reverb.key'));
        $socket = stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 5);
        if (! $socket) {
            throw new RuntimeException('Nginx inaccessible.');
        }
        stream_set_timeout($socket, 5);
        fwrite($socket, "GET /app/{$appKey}?protocol=7&client=js&version=8.4.0&flash=false HTTP/1.1\r\nHost: {$host}\r\nOrigin: https://{$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {$key}\r\n\r\n");
        $headers = '';
        while (($line = fgets($socket, 4096)) !== false && strlen($headers) < 16384) {
            $headers .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        fclose($socket);
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (! str_starts_with($headers, 'HTTP/1.1 101 ')
            || ! preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($accept, '/').'\s*$/mi', $headers)) {
            throw new RuntimeException('Handshake WebSocket refuse.');
        }
        echo "WebSocket via Nginx : OK (101).\n";
        exit(0);
    }
    throw new RuntimeException('Action inconnue.');
} catch (Throwable $exception) {
    // SQL and framework exceptions can contain credentials; never dump them here.
    $detail = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Echec du controle ; consulter les logs prives du CT.';
    fwrite(STDERR, 'STOP ['.$stage.'] : '.$detail."\n");
    exit(1);
}
