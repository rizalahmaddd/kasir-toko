<?php

namespace App\Support;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR code sebagai SVG inline: tajam di kertas berapa pun ukurannya dan tidak butuh ekstensi gd/imagick.
 */
class QrCodeSvg
{
    public static function render(string $data): string
    {
        $options = new QROptions([
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($data);
    }
}
