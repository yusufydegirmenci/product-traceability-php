<?php

declare(strict_types=1);

namespace Traceability\Tests\Unit;

use Traceability\Tests\TestCase;
use Traceability\Gs1\CheckDigit;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

final class Gs1CheckDigitTest extends TestCase
{
    public function testComputeProducesValidatableDigit(): void
    {
        $digit = CheckDigit::compute('1234567890123');
        $this->assertTrue(CheckDigit::validate('1234567890123' . $digit));
    }

    public function testValidateRejectsTamperedDigit(): void
    {
        $digit = CheckDigit::compute('1234567890123');
        $wrongDigit = ($digit + 1) % 10;
        $this->assertFalse(CheckDigit::validate('1234567890123' . $wrongDigit));
    }

    public function testGtinBuilderProducesValidGtin(): void
    {
        $builder = new GtinBuilder('8690000');
        $gtin = $builder->build('00001');
        $this->assertTrue(CheckDigit::validate($gtin));
        $this->assertSame(14, strlen($gtin));
    }

    public function testDigitalLinkExtractGtinReturnsNullForGarbage(): void
    {
        $this->assertNull(DigitalLink::extractGtin('(01)NOTAVALIDGTIN0(10)X'));
    }

    public function testDigitalLinkExtractGtinRoundTrips(): void
    {
        $builder = new GtinBuilder('8690000');
        $gtin = $builder->build('00002');
        $link = new DigitalLink();
        $code = $link->buildElementString($gtin, 'LOT-TEST-01');
        $this->assertSame($gtin, DigitalLink::extractGtin($code));
    }
}
