<?php

use App\Support\Qris;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

function staticQris(): string
{
    $body = '000201'.'010211'
        .'26570011ID.DANA.WWW011893600915302259148102090225914810303UMI'
        .'51440014ID.CO.QRIS.WWW0215ID10200176114730303UMI'
        .'5204581253033605802ID5914TOKO MAJU JAYA6006MALANG6105651456304';

    return $body.Qris::crc16($body);
}

test('crc16 matches the CCITT-FALSE check value', function () {
    expect(Qris::crc16('123456789'))->toBe('29B1');
});

test('a static qris becomes dynamic with the amount and a valid crc', function () {
    $dynamic = Qris::withAmount(staticQris(), 125500);
    $tags = Qris::parse($dynamic);

    expect(Qris::problem(staticQris()))->toBeNull()
        ->and(Qris::problem($dynamic))->toBeNull()
        ->and($tags['01'])->toBe('12')
        ->and($tags['54'])->toBe('125500')
        ->and(Qris::isDynamic($dynamic))->toBeTrue()
        ->and((string) array_key_last($tags))->toBe('63')
        ->and(Qris::merchant($dynamic))->toBe(['name' => 'TOKO MAJU JAYA', 'city' => 'MALANG']);
});

test('changing the amount again replaces the previous one and drops tip prompts', function () {
    $withTip = substr(staticQris(), 0, -8).'550201';
    $withTip .= '6304'.Qris::crc16($withTip.'6304');

    $tags = Qris::parse(Qris::withAmount(Qris::withAmount($withTip, 1000), 2000));

    expect($tags['54'])->toBe('2000')
        ->and($tags)->not->toHaveKey('55');
});

test('broken or foreign codes are rejected with a readable reason', function () {
    expect(Qris::problem('https://example.com'))->toContain('bukan kode QRIS')
        ->and(Qris::problem(substr(staticQris(), 0, -1).'0'))->not->toBeNull();
});

test('the qris payload is read back from an uploaded image', function () {
    $path = tempnam(sys_get_temp_dir(), 'qris').'.png';
    file_put_contents($path, (new QRCode(new QROptions(['outputInterface' => QRGdImagePNG::class, 'outputBase64' => false, 'scale' => 6])))->render(staticQris()));

    expect(Qris::readImage($path))->toBe(staticQris());

    @unlink($path);
});
