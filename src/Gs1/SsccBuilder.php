<?php

declare(strict_types=1);

namespace Traceability\Gs1;

/**
 * SSCC (Serial Shipping Container Code) — GS1'in bir KOLİYİ/PALETİ
 * tanımlamak için kullandığı standart kod. GTIN "bu ürün nedir" sorusuna
 * cevap verirken, SSCC "bu fiziksel koli hangisidir" sorusuna cevap verir
 * — ikisi kasıtlı olarak birbirinden bağımsızdır, çünkü bir koli birden
 * fazla siparişin/ürünün karışımını içerebilir (aynı adrese giden 2
 * siparişin tek koliye konması gibi).
 *
 * Yapı: 1 uzatma hanesi + GS1 Company Prefix + seri referans + check digit = 18 hane.
 */
final class SsccBuilder
{
    public function __construct(
        private string $gs1CompanyPrefix,
        private string $extensionDigit = '0'
    ) {
    }

    public function build(string $serialReference): string
    {
        $body = $this->extensionDigit . $this->gs1CompanyPrefix . $serialReference;
        if (strlen($body) !== 17) {
            throw new \InvalidArgumentException(sprintf(
                'Uzatma hanesi + company prefix + seri referans toplamda 17 hane olmalı, %d hane geldi ("%s").',
                strlen($body),
                $body
            ));
        }
        return CheckDigit::appendCheckDigit($body);
    }

    public static function randomSerialReference(int $length = 8): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }
        return $digits;
    }
}
