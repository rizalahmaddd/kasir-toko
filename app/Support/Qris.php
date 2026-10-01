<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;
use Throwable;

/**
 * QRIS mengikuti format EMVCo Merchant Presented Mode: deretan TLV (2 digit tag, 2 digit
 * panjang, nilai) yang ditutup tag 63 berisi CRC16-CCITT. QR statis bertag 01=11 tanpa nominal;
 * menjadikannya dinamis cukup mengganti 01 jadi 12, menambah tag 54 (nominal), lalu hitung ulang CRC.
 */
class Qris
{
    /**
     * Kunci tag "10" ke atas otomatis jadi integer di array PHP; bandingkan lewat (string)/(int).
     *
     * @return array<int|string, string> tag => nilai, urutan sesuai payload
     */
    public static function parse(string $payload): array
    {
        $tags = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            if ($offset + 4 > $length) {
                throw new InvalidArgumentException('Data QRIS terpotong.');
            }

            $tag = substr($payload, $offset, 2);
            $size = substr($payload, $offset + 2, 2);

            if (! ctype_digit($tag) || ! ctype_digit($size) || $offset + 4 + (int) $size > $length) {
                throw new InvalidArgumentException('Format data QRIS tidak dikenali.');
            }

            $tags[$tag] = substr($payload, $offset + 4, (int) $size);
            $offset += 4 + (int) $size;
        }

        return $tags;
    }

    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Payload QRIS yang lolos cek format, CRC, dan punya identitas merchant. Mengembalikan pesan
     * kesalahan yang bisa ditampilkan, atau null kalau valid.
     */
    public static function problem(string $payload): ?string
    {
        $payload = trim($payload);

        if (! str_starts_with($payload, '000201')) {
            return 'Ini bukan kode QRIS. Pastikan yang diunggah adalah QRIS toko dari bank atau e-wallet.';
        }

        try {
            $tags = self::parse($payload);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        if (! isset($tags['63']) || ! str_ends_with($payload, '6304'.$tags['63'])) {
            return 'Kode QRIS tidak punya kode pengecekan (CRC).';
        }

        if (self::crc16(substr($payload, 0, -4)) !== strtoupper($tags['63'])) {
            return 'Kode pengecekan QRIS tidak cocok. Gambar mungkin rusak atau bukan QRIS asli.';
        }

        $hasMerchantAccount = collect(array_keys($tags))->contains(fn (int|string $tag) => (int) $tag >= 26 && (int) $tag <= 51);

        if (! $hasMerchantAccount || blank($tags['59'] ?? null)) {
            return 'Data merchant di QRIS tidak lengkap.';
        }

        if (($tags['53'] ?? null) !== '360') {
            return 'QRIS ini bukan untuk mata uang Rupiah.';
        }

        return null;
    }

    public static function isDynamic(string $payload): bool
    {
        return (self::parse($payload)['01'] ?? null) === '12';
    }

    /**
     * @return array{name: string, city: string}
     */
    public static function merchant(string $payload): array
    {
        $tags = self::parse($payload);

        return ['name' => trim($tags['59'] ?? ''), 'city' => trim($tags['60'] ?? '')];
    }

    /**
     * QRIS dinamis dengan nominal tetap. Tag tip (55–57) dibuang supaya pembeli tidak bisa
     * menambah atau diminta nominal lain di aplikasinya.
     */
    public static function withAmount(string $payload, int $amount): string
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal QRIS harus lebih dari 0.');
        }

        $tags = self::parse(trim($payload));
        unset($tags['54'], $tags['55'], $tags['56'], $tags['57'], $tags['63']);
        $tags['01'] = '12';
        $tags['54'] = (string) $amount;

        uksort($tags, fn (int|string $a, int|string $b) => (int) $a <=> (int) $b);

        $body = '';
        foreach ($tags as $tag => $value) {
            $body .= $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
        }

        $body .= '6304';

        return $body.self::crc16($body);
    }

    /**
     * Membaca isi QR dari foto/tangkapan layar QRIS. Mengembalikan null kalau tidak ada QR yang terbaca.
     */
    public static function readImage(string $path): ?string
    {
        try {
            $result = (new QRCode(new QROptions(['readerUseImagickIfAvailable' => false, 'readerIncreaseContrast' => true])))->readFromFile($path);
        } catch (Throwable) {
            return null;
        }

        $data = trim((string) $result);

        return $data !== '' ? $data : null;
    }

    public static function svg(string $payload): string
    {
        $options = new QROptions([
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'eccLevel' => EccLevel::M,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
