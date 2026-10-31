<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use RuntimeException;

/** Static inspection only: none of the source files are included or evaluated. */
final class MymarWorkflowAudit
{
    private const FILES = [
        'routes/api.php', 'routes/web.php', 'app/Http/Kernel.php',
        'app/Http/Controllers/Controller.php', 'app/Http/Controllers/ApiController.php',
        'app/Http/Controllers/DossierController.php',
        'app/Models/Dossier.php', 'app/Models/DossierRDV.php',
        'app/Models/DossierHistorique.php', 'app/Models/Client.php',
        'app/Models/Status.php', 'app/Providers/AppServiceProvider.php',
        'app/Providers/EventServiceProvider.php', 'app/Providers/AuthServiceProvider.php',
        'app/Providers/RouteServiceProvider.php',
    ];

    private const METHODS = [
        '__construct', 'boot', 'booted', 'register', 'ajouterRDV', 'modifierRDV',
        'changeStatus', 'changeStatusPost', 'changeRDVPost', 'changeTechnicien',
        'changeTechnicienPost', 'changeTechnician',
    ];

    private const STRINGS = [
        'id', 'dossier_id', 'status_id', 'technicien_id', 'date_rdv', 'heure_rdv',
        'duree', 'rdv_duree', 'is_old', 'author_id', 'userId', 'sub_statut',
        'sub_status_changed', 'status_changed', 'commentaire', 'commentaire_status',
        'date_rappel', 'created_at', 'updated_at', 'deleted_at', 'mar_type',
        'dossiers', 'dossier_rdv', 'dossier_historiques', 'status', 'clients',
        'client_id', 'owner_id', 'name', 'address', 'adresse', 'lat', 'lng',
        'partenaire_id', 'mandataire_id', 'enabled', 'partenair_ids_notif',
        'auth', 'api', 'web', 'auth:sanctum', 'verified', ' ', ':', '=',
        'Y-m-d H:i:s', 'Y-m-d', 'd/m/Y H:i', 'H:i', 'H:i:s',
    ];

    public static function sources(string $root): array
    {
        if (realpath($root) !== $root || is_link($root)) {
            throw new RuntimeException('Indirect source root refused.');
        }
        $paths = self::FILES;
        foreach (['app/Observers', 'app/Listeners'] as $directory) {
            if (is_link($root.'/'.$directory)) {
                throw new RuntimeException('Indirect source directory refused.');
            }
            foreach (glob($root.'/'.$directory.'/*.php') ?: [] as $path) {
                $name = basename($path);
                if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*\.php$/D', $name)) {
                    throw new RuntimeException('Unexpected source filename.');
                }
                $paths[] = $directory.'/'.$name;
            }
        }
        if (count($paths) > 64) {
            throw new RuntimeException('Source count limit.');
        }
        $proof = $files = [];
        $bytes = 0;
        foreach ($paths as $path) {
            $source = MymarReadOnlyAudit::read($root, $path);
            if ($source === null) {
                if (file_exists($root.'/'.$path) || is_link($root.'/'.$path)) {
                    throw new RuntimeException('Existing source file refused.');
                }
                $proof[$path] = null;

                continue;
            }
            if (($bytes += strlen($source)) > 8388608) {
                throw new RuntimeException('Source size limit.');
            }
            $proof[$path] = hash('sha256', $source);
            $files[$path] = ['sha256' => $proof[$path], 'bytes' => strlen($source),
                'business_facts' => MymarReadOnlyAudit::facts($source),
                'status_conditions' => self::statusConditions($source),
                ...self::methods($source, str_starts_with($path, 'app/Models/')
                    || str_starts_with($path, 'app/Observers/') || str_starts_with($path, 'app/Listeners/'))];
            if (str_starts_with($path, 'routes/') || str_starts_with($path, 'app/Models/')
                || str_starts_with($path, 'app/Providers/') || $path === 'app/Http/Kernel.php') {
                if (strlen($source) > 131072) {
                    throw new RuntimeException('Source structure limit.');
                }
                $files[$path]['structure_redacted'] = self::redact($source);
            }
        }
        foreach (['routes/api.php', 'routes/web.php', 'app/Http/Controllers/ApiController.php',
            'app/Http/Controllers/DossierController.php', 'app/Models/Dossier.php',
            'app/Models/DossierRDV.php', 'app/Models/DossierHistorique.php'] as $required) {
            if (! isset($files[$required])) {
                throw new RuntimeException('Required workflow source missing.');
            }
        }

        return ['files' => $files, 'proof' => $proof, 'source_bytes_read' => $bytes];
    }

    public static function unchanged(string $root, array $proof): bool
    {
        foreach ($proof as $path => $hash) {
            $source = MymarReadOnlyAudit::read($root, $path);
            if ($hash === null ? file_exists($root.'/'.$path) || is_link($root.'/'.$path)
                : $source === null || ! hash_equals($hash, hash('sha256', $source))) {
                return false;
            }
        }

        return true;
    }

    public static function methods(string $source, bool $all = false): array
    {
        $tokens = token_get_all($source);
        $names = $excerpts = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                $j++;
            }
            if (! is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING) {
                continue;
            }
            $name = $tokens[$j][1];
            if (count($names) >= 512) {
                throw new RuntimeException('Method count limit.');
            }
            $names[] = $name;
            if (! $all && ! in_array($name, self::METHODS, true)) {
                continue;
            }
            while (isset($tokens[$j]) && ! in_array($tokens[$j], ['{', ';'], true)) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '{') {
                continue;
            }
            $depth = 1;
            for ($end = $j + 1; isset($tokens[$end]) && $depth > 0; $end++) {
                if ($tokens[$end] === '{' || (is_array($tokens[$end])
                    && in_array($tokens[$end][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($tokens[$end] === '}') {
                    $depth--;
                }
            }
            if ($depth !== 0) {
                throw new RuntimeException('Incomplete method.');
            }
            $body = implode('', array_map(static fn ($part) => is_array($part) ? $part[1] : $part, array_slice($tokens, $i, $end - $i)));
            if (strlen($body) > 65536 || count($excerpts) >= 80) {
                throw new RuntimeException('Method excerpt limit.');
            }
            $excerpts[] = ['method' => $name, 'line' => $token[2], 'source_sha256' => hash('sha256', $body),
                'code_redacted' => self::redact('<?php '.$body)];
        }

        return ['methods' => array_values(array_unique($names)), 'mutation_excerpts' => $excerpts];
    }

    public static function redact(string $source): string
    {
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $result .= $token;

                continue;
            }
            [$type, $value] = $token;
            if (in_array($type, [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                $result .= str_repeat("\n", substr_count($value, "\n"));
            } elseif ($type === T_CONSTANT_ENCAPSED_STRING) {
                // Whitelist exact quoted spellings; do not decode escape sequences or evaluate strings.
                $literal = substr($value, 1, -1);
                $result .= in_array($literal, [...self::STRINGS, ...self::METHODS], true) ? var_export($literal, true) : "'[MASKED]'";
            } elseif (in_array($type, [T_LNUMBER, T_DNUMBER], true)) {
                $result .= '0/*masked: see business_facts*/';
            } elseif (in_array($type, [T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML, T_NUM_STRING], true)) {
                $result .= '[MASKED]';
            } else {
                $result .= $value;
            }
        }

        return trim($result);
    }

    public static function statusConditions(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn ($token) => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $conditions = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'in_array'
                || ($tokens[$i + 1] ?? null) !== '(' || ($tokens[$i + 5] ?? null) !== ','
                || ($tokens[$i + 6] ?? null) !== '[') {
                continue;
            }
            $variable = $tokens[$i + 2] ?? null;
            $operator = $tokens[$i + 3] ?? null;
            $field = $tokens[$i + 4] ?? null;
            if (! is_array($variable) || $variable[0] !== T_VARIABLE
                || ! is_array($operator) || $operator[0] !== T_OBJECT_OPERATOR
                || ! is_array($field) || $field[0] !== T_STRING
                || ! in_array($field[1], ['status_id', 'status_changed', 'mar_type'], true)) {
                continue;
            }
            $values = [];
            $j = $i + 7;
            while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_LNUMBER
                && preg_match('/^[0-9]{1,3}$/D', $tokens[$j][1]) && count($values) < 32) {
                $values[] = (int) $tokens[$j++][1];
                if (($tokens[$j] ?? null) === ',') {
                    $j++;
                } else {
                    break;
                }
            }
            if (($tokens[$j] ?? null) === ']' && $values !== []) {
                $conditions[] = ['line' => $token[2], 'field' => $field[1], 'in_values' => $values];
            }
        }

        return $conditions;
    }

    public static function metadataSql(): string
    {
        return <<<'SQL'
SET SESSION max_execution_time=1500;
SET SESSION lock_wait_timeout=2;
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT JSON_OBJECT('section','table','table',TABLE_NAME,'engine',ENGINE)
 FROM information_schema.TABLES WHERE TABLE_SCHEMA='MYMAR' AND TABLE_TYPE='BASE TABLE'
 AND TABLE_NAME IN ('dossiers','dossier_rdv','dossier_historiques','clients','status','users') ORDER BY TABLE_NAME LIMIT 7;
SELECT JSON_OBJECT('section','column','table',TABLE_NAME,'column',COLUMN_NAME,'type',DATA_TYPE,'nullable',IS_NULLABLE,'has_default',COLUMN_DEFAULT IS NOT NULL)
 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR'
 AND TABLE_NAME IN ('dossiers','dossier_rdv','dossier_historiques','clients','status','users') ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT 601;
SELECT JSON_OBJECT('section','foreign_key','table',TABLE_NAME,'column',COLUMN_NAME,'target_schema',REFERENCED_TABLE_SCHEMA,'target_table',REFERENCED_TABLE_NAME,'target_column',REFERENCED_COLUMN_NAME)
 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='MYMAR' AND REFERENCED_TABLE_NAME IS NOT NULL
 AND TABLE_NAME IN ('dossiers','dossier_rdv','dossier_historiques','clients') ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT 101;
SELECT JSON_OBJECT('section','trigger','table',EVENT_OBJECT_TABLE,'event',EVENT_MANIPULATION,'timing',ACTION_TIMING)
 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='MYMAR'
 AND EVENT_OBJECT_TABLE IN ('dossiers','dossier_rdv','dossier_historiques','clients') ORDER BY EVENT_OBJECT_TABLE,EVENT_MANIPULATION LIMIT 51;
ROLLBACK;
SQL;
    }
}
