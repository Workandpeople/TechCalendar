<?php

declare(strict_types=1);

// Verify an already generated local package, without SSH, Laravel or HTTP.
if (PHP_SAPI !== 'cli' || $argc !== 2 || realpath($argv[1]) !== $argv[1]) {
    exit(1);
}
$directory = $argv[1];
$deploy = file_get_contents($directory.'/deploy.sh');
$setup = file_get_contents($directory.'/setup-pilot.sh');
$request = json_decode(file_get_contents($directory.'/REQUEST.json'), true, 32, JSON_THROW_ON_ERROR);
$checks = [
    'checks_before_maintenance' => strpos($deploy, 'phase=maintenance') > strpos($deploy, '"$CHECK/application.json"'),
    'release_recovery_retained' => str_contains($deploy, '"$current" = "$prepared"') && str_contains($deploy, 'recovery-open.log'),
    'both_domains_checked' => substr_count($deploy, 'for domain in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com') === 2,
    'no_unrelated_geocoding_or_gateway_replacement' => ! str_contains($deploy, 'geo.api.gouv.fr') && ! str_contains($deploy, 'prepare-disabled.php'),
    'no_sources_modified' => ! str_contains($setup, 'ACCOUNT UNLOCK') && ! str_contains($setup, 'ACCOUNT LOCK') && ! str_contains($setup, 'git '),
    'first_sync_before_display' => strpos($setup, 'app_cli mymar:sync-observation') < strpos($setup, '--display "$RUN"'),
    'isolated_timer_only' => ! str_contains($setup, 'schedule:run') && str_contains($setup, 'cofrac2-mymar-observation.timer'),
    'safe_failure_path' => str_contains($setup, '--disable "$RUN"') && ! str_contains($setup, 'configure-production-runtime.sh'),
    'pinned_request' => ($request['format'] ?? null) === 'cofrac.mymar-cache-pilot-request'
        && ($request['target_revision'] ?? null) === trim(file_get_contents($directory.'/revision.txt')),
    'no_credentials_in_request' => ! preg_match('/password|token|database_user|database_host|email/i', json_encode($request)),
];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'OK' : 'FAIL')." : $label\n";
}
exit(in_array(false, $checks, true) ? 1 : 0);
