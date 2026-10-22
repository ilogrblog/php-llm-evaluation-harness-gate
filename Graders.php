<?php
declare(strict_types=1);

/**
 * Three deterministic graders, versioned so a change invalidates comparison
 * with an older accepted run. None of them establishes that a reply is correct,
 * kind or complete. They check the property named in their own version string.
 */
final class Graders
{
    /** Required substring, compared case-insensitively. */
    public static function contains(array $case, string $output): array
    {
        $needle = $case['expect']['contains'] ?? null;
        if (!is_string($needle) || $needle === '') {
            throw new RuntimeException('case_missing_contains_expectation');
        }
        $found = mb_stripos($output, $needle) !== false;

        return [
            'pass' => $found,
            'score' => $found ? 1.0 : 0.0,
            'reason' => $found ? 'substring_present' : 'substring_absent',
        ];
    }

    /** No forbidden phrase appears. An empty or absent list passes. */
    public static function forbids(array $case, string $output): array
    {
        $forbidden = $case['expect']['forbidden'] ?? [];
        if (!is_array($forbidden)) {
            throw new RuntimeException('case_invalid_forbidden_expectation');
        }
        foreach ($forbidden as $phrase) {
            if (!is_string($phrase) || $phrase === '') {
                throw new RuntimeException('case_invalid_forbidden_expectation');
            }
            if (mb_stripos($output, $phrase) !== false) {
                return ['pass' => false, 'score' => 0.0, 'reason' => 'forbidden_phrase_present'];
            }
        }

        return ['pass' => true, 'score' => 1.0, 'reason' => 'no_forbidden_phrase'];
    }

    /**
     * Advisory length band. Registered with blocking set to false, so it
     * reports a signal without acquiring a veto over a release.
     */
    public static function length(array $case, string $output): array
    {
        $length = mb_strlen($output);
        $inBand = $length >= 40 && $length <= 400;

        return [
            'pass' => $inBand,
            'score' => $inBand ? 1.0 : 0.0,
            'reason' => 'length_' . $length,
        ];
    }

    /** @return array<string, array{version: string, blocking: bool, fn: callable}> */
    public static function registry(): array
    {
        return [
            'contains' => ['version' => 'contains-v1', 'blocking' => true, 'fn' => [self::class, 'contains']],
            'forbids' => ['version' => 'forbids-v1', 'blocking' => true, 'fn' => [self::class, 'forbids']],
            'length' => ['version' => 'length-v1', 'blocking' => false, 'fn' => [self::class, 'length']],
        ];
    }
}
