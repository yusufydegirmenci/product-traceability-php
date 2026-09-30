<?php

declare(strict_types=1);

namespace Traceability\Gs1;

/**
 * DIŞARIYA basılan seri numarasını üretir. Kasıtlı olarak SIRALI DEĞİLDİR —
 * "1000, 1001, 1002..." gibi bir dizi kolayca tahmin edilebilir, biri bir
 * gerçek etiketi görüp yanındaki sayıyı tahmin ederek sahte bir etiket
 * üretebilir (check digit formülü de bilinen bir formül olduğu için
 * tutturulabilir). DSCSA/EU FMD gibi düzenlemeler bu yüzden rastgele
 * seri numarası şart koşuyor.
 *
 * "1000 adet geldi, kaçı gerçekten oluştu" sorusu BU sınıfla değil,
 * EntityRepository'nin lot_position alanıyla (bkz. o dosya) çözülüyor —
 * o alan asla dışa basılmaz, sadece iç sayım/mutabakat içindir.
 */
final class SerialGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // karışabilecek 0/O, 1/I gibi karakterler çıkarıldı

    public static function generate(int $length = 8): string
    {
        $serial = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $serial .= self::ALPHABET[random_int(0, $max)];
        }
        return $serial;
    }
}
