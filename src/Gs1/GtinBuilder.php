<?php

declare(strict_types=1);

namespace Traceability\Gs1;

/**
 * Builds a 14-digit GTIN from a GS1 Company Prefix (assigned to your organization
 * by the local GS1 member organisation once you register) + an item reference
 * you assign per product/variant. This does NOT invent a proprietary numbering
 * scheme — it follows the real GS1 GTIN-14 structure so the code is recognised
 * by carriers, customs and any GS1-compliant scanner worldwide.
 */
final class GtinBuilder
{
    public function __construct(
        private string $gs1CompanyPrefix, // e.g. issued by GS1 Turkey/MERSIS-linked registration
        private string $indicatorDigit = '1' // 0-8 for cases/inner packs, 9 for variable measure; 0 or 1 for a base unit
    ) {
    }

    /**
     * @param string $itemReference Zero-padded reference you assign per product (e.g. "00123")
     */
    public function build(string $itemReference): string
    {
        $body = $this->indicatorDigit . $this->gs1CompanyPrefix . $itemReference;

        $expectedBodyLength = 13; // 14-digit GTIN minus its own check digit
        if (strlen($body) !== $expectedBodyLength) {
            throw new \InvalidArgumentException(sprintf(
                'Indicator + company prefix + item reference must total %d digits, got %d ("%s"). '
                . 'Adjust the item reference padding to fit your assigned GS1 Company Prefix length.',
                $expectedBodyLength,
                strlen($body),
                $body
            ));
        }

        return CheckDigit::appendCheckDigit($body);
    }
}
