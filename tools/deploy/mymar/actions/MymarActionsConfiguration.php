<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use RuntimeException;

/** Identity proofs stay root-private; the runtime only receives approved numeric pairs. */
final class MymarActionsConfiguration
{
    public static function bindings(array $approved): array
    {
        if (! array_is_list($approved) || $approved === [] || count($approved) > 200) {
            throw new RuntimeException('mymar.invalid_prepared_bindings');
        }
        $result = $sources = $targets = [];
        foreach ($approved as $binding) {
            if (! is_array($binding) || count($binding) !== 4 || ! is_int($binding['source_technician_id'] ?? null)
                || $binding['source_technician_id'] < 1 || ! is_int($binding['cofrac_user_id'] ?? null) || $binding['cofrac_user_id'] < 1
                || ! is_string($binding['source_identity_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $binding['source_identity_sha256'])
                || ! is_string($binding['target_identity_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $binding['target_identity_sha256'])
                || isset($sources[$binding['source_technician_id']]) || isset($targets[$binding['cofrac_user_id']])) {
                throw new RuntimeException('mymar.invalid_prepared_bindings');
            }
            $sources[$binding['source_technician_id']] = true;
            $targets[$binding['cofrac_user_id']] = true;
            $result[] = ['source_technician_id' => $binding['source_technician_id'], 'cofrac_user_id' => $binding['cofrac_user_id']];
        }

        return $result;
    }
}
