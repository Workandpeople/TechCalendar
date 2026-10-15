<?php

declare(strict_types=1);

$source = $argv[1];
$directory = $argv[2];
$tables = [];
foreach (glob($directory.'/*.jsonl') as $file) {
    $count = 0;
    $handle = fopen($file, 'rb');
    while (($line = fgets($handle)) !== false) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (! isset($row['id']) || array_intersect(['password', 'remember_token', 'api_token', 'access_token'], array_keys($row))) {
            throw new RuntimeException('Invalid or unsafe source row.');
        }
        $count++;
    }
    fclose($handle);
    $tables[pathinfo($file, PATHINFO_FILENAME)] = ['count' => $count, 'sha256' => hash_file('sha256', $file)];
}
if (count($tables) !== 21) {
    throw new RuntimeException('Incomplete snapshot.');
}
$manifest = [
    'format' => 'techcalendar.coffrac-snapshot', 'version' => 1, 'complete' => true,
    'prepared_at' => gmdate(DATE_ATOM), 'dump_sha256' => hash_file('sha256', $source),
    'timestamp_timezone' => 'UTC', 'appointment_timezone' => 'Europe/Paris',
    'tables' => $tables,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($directory.'/manifest.json', $json, LOCK_EX) !== strlen($json)) {
    throw new RuntimeException('Could not write manifest.');
}
echo "Snapshot verified: {$directory}\n";
