<?php

// Disposable fixture only: build the exact reader upgrade payload and fake deployed source.
if (! is_file('/.dockerenv') || ! is_dir('/reader-update-package') || ! is_file('/var/lib/cofrac2-production/RUNTIME-PREPARED.json')) {
    exit(1);
}
umask(0077);
$writer = ($argv[1] ?? null) === '--actions';
$out = $writer ? '/actions-update-package' : '/reader-update-package';
mkdir($out.'/code', 0700);
mkdir($out.'/validation', 0700);
$files = ['entry.php' => $writer ? 'tools/deploy/mymar/actions/entry.php' : 'tools/deploy/mymar/booking-http/entry.php'];
foreach (['MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy', 'MymarReadWindow'] as $class) {
    $files[$class.'.php'] = 'app/Services/Mymar/'.$class.'.php';
}
foreach (['MymarBookingFailure', 'MymarBookingSqlAccess', 'MymarBookingSource', 'MymarBookingGateway'] as $class) {
    $files[$class.'.php'] = 'tools/deploy/mymar/booking/'.$class.'.php';
}
$hashes = [];
foreach ($files as $name => $source) {
    copy('/workspace/'.$source, $out.'/code/'.$name);
    @mkdir(dirname('/var/www/cofrac2/'.$source), 0700, true);
    copy('/workspace/'.$source, '/var/www/cofrac2/'.$source);
    $hashes[$name] = hash_file('sha256', $out.'/code/'.$name);
}
foreach (['MymarObservation', 'MymarReadWindow', 'MymarCachedObservation', 'MymarCalendarLabels', 'MymarPendingBookingPage'] as $class) {
    copy('/workspace/app/Services/Mymar/'.$class.'.php', $out.'/validation/'.$class.'.php');
}
$runtime = json_decode(file_get_contents('/var/lib/cofrac2-production/RUNTIME-PREPARED.json'), true, 16, JSON_THROW_ON_ERROR);
file_put_contents($out.'/REQUEST.json', json_encode(['format' => $writer ? 'cofrac.mymar-actions-code-update' : 'cofrac.mymar-reader-code-update', 'version' => 1,
    'target' => $writer ? 'actions' : 'reader', 'revision' => $runtime['revision'], 'files' => $hashes], JSON_THROW_ON_ERROR));
