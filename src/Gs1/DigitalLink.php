<?php

declare(strict_types=1);

namespace Traceability\Gs1;

/**
 * Builds the two representations of the same GS1 identifiers your printed
 * label needs:
 *
 *   1) The raw GS1 Application Identifier ("AI") element string — this is
 *      what actually gets ENCODED inside the barcode/2D symbol.
 *         Example: (01)18690001234563(10)LOT2409(17)261231(21)000042
 *
 *   2) A GS1 Digital Link URI — the same data expressed as a resolvable URL,
 *      so the exact same code a warehouse scanner reads can also be opened
 *      by a customer's phone camera to show "is this product genuine".
 *         Example: https://id.example.com/01/18690001234563/10/LOT2409/21/000042
 *
 * NOTE ON PHYSICAL BARCODE RENDERING: turning the AI string below into actual
 * printable bars/pixels (GS1-128 or GS1 DataMatrix/QR) should be done with a
 * mature, battle-tested library (e.g. a Composer package such as
 * picqer/php-barcode-generator for 1D GS1-128, or endroid/qr-code +
 * a GS1 Digital Link payload for 2D). Hand-rolling a barcode symbol encoder
 * is exactly the kind of place a subtle, hard-to-notice bug produces labels
 * that don't scan on 25,000+ physical units — not worth the risk when
 * solid, free libraries already exist. This class only produces the
 * (already correct, check-digit-validated) DATA that such a library encodes.
 */
final class DigitalLink
{
    public function __construct(
        private string $resolverBaseUrl = 'https://id.example.com'
    ) {
    }

    public function buildElementString(
        string $gtin14,
        string $lotOrBatch,
        ?string $expiryYYMMDD = null,
        ?string $serial = null
    ): string {
        if (!CheckDigit::validate($gtin14) || strlen($gtin14) !== 14) {
            throw new \InvalidArgumentException("Invalid GTIN-14 (failed check digit): {$gtin14}");
        }

        $s = "(01){$gtin14}(10){$lotOrBatch}";
        if ($expiryYYMMDD !== null) {
            $s .= "(17){$expiryYYMMDD}";
        }
        if ($serial !== null) {
            $s .= "(21){$serial}";
        }
        return $s;
    }

    public function buildUri(string $gtin14, string $lotOrBatch, ?string $serial = null): string
    {
        if (!CheckDigit::validate($gtin14) || strlen($gtin14) !== 14) {
            throw new \InvalidArgumentException("Invalid GTIN-14 (failed check digit): {$gtin14}");
        }

        $uri = rtrim($this->resolverBaseUrl, '/') . "/01/{$gtin14}/10/" . rawurlencode($lotOrBatch);
        if ($serial !== null) {
            $uri .= '/21/' . rawurlencode($serial);
        }
        return $uri;
    }

    /**
     * Pulls the GTIN back out of a printed element string (e.g. when a return
     * scan comes in and must be validated BEFORE any database lookup happens).
     * Returns null if the string doesn't even have a well-formed (01)... block —
     * that alone is a strong signal of a hand-typed/fabricated code.
     */
    public static function extractGtin(string $elementString): ?string
    {
        if (preg_match('/\(01\)(\d{14})/', $elementString, $m) !== 1) {
            return null;
        }
        return $m[1];
    }
}
