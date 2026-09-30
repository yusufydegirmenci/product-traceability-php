<?php

declare(strict_types=1);

namespace Traceability\Gs1;

/**
 * GS1 Mod-10 check digit — the same algorithm used for GTIN, SSCC and GLN.
 * Spec: multiply each digit (excluding the check digit) by alternating
 * weights 3 and 1 starting from the RIGHTMOST digit, sum, then
 * check digit = (10 - (sum mod 10)) mod 10.
 */
final class CheckDigit
{
    public static function compute(string $digitsWithoutCheck): int
    {
        if ($digitsWithoutCheck === '' || !ctype_digit($digitsWithoutCheck)) {
            throw new \InvalidArgumentException('GS1 check digit input must be a non-empty numeric string.');
        }

        $sum = 0;
        $weight = 3; // rightmost digit always gets weight 3
        for ($i = strlen($digitsWithoutCheck) - 1; $i >= 0; $i--) {
            $sum += ((int) $digitsWithoutCheck[$i]) * $weight;
            $weight = ($weight === 3) ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function appendCheckDigit(string $digitsWithoutCheck): string
    {
        return $digitsWithoutCheck . self::compute($digitsWithoutCheck);
    }

    /** Validates a full code (body + its own check digit), e.g. a 14-digit GTIN. */
    public static function validate(string $fullCodeWithCheckDigit): bool
    {
        if (!ctype_digit($fullCodeWithCheckDigit) || strlen($fullCodeWithCheckDigit) < 2) {
            return false;
        }
        $body = substr($fullCodeWithCheckDigit, 0, -1);
        $providedCheck = (int) substr($fullCodeWithCheckDigit, -1);

        return self::compute($body) === $providedCheck;
    }
}
