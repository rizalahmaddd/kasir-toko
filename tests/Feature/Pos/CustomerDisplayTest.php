<?php

use App\Events\CustomerDisplayUpdated;
use App\Livewire\Pos\Cashier;
use App\Livewire\Settings\CustomerDisplaySettingsPage;
use App\Livewire\Settings\PosSettingsPage;
use App\Models\Setting;
use App\Support\Features;
use App\Support\PosSettings;
use App\Support\Qris;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function sampleStaticQris(): string
{
    $body = '00020101021126570011ID.DANA.WWW011893600915302259148102090225914810303UMI'
        .'51440014ID.CO.QRIS.WWW0215ID10200176114730303UMI5204581253033605802ID5914TOKO MAJU JAYA6006MALANG6105651456304';

    return $body.Qris::crc16($body);
}

test('the cashier pushes state to the cache and signals the paired screen', function () {
    Event::fake([CustomerDisplayUpdated::class]);
    $cashier = actingAsRole('kasir');
    $key = $cashier->displayKey();

    $this->postJson(route('pos.display.push'), [
        'seq' => 1700000000000,
        'stage' => 'cart',
        'items' => [['name' => 'Kopi', 'qty' => 2, 'unit' => 'gelas', 'price' => 15000, 'total' => 30000, 'secret' => 'x']],
        'total' => 30000,
        'evil' => str_repeat('a', 5000),
    ])->assertNoContent();

    Event::assertDispatched(CustomerDisplayUpdated::class, fn ($event) => $event->displayKey === $key);

    auth()->logout();
    $state = $this->getJson(route('display.state', $key))->assertOk()->json();

    expect($state['stage'])->toBe('cart')
        ->and($state['total'])->toBe(30000)
        ->and($state['items'][0])->not->toHaveKey('secret')
        ->and($state)->not->toHaveKey('evil');
});

test('the display opens without login only with a valid key', function () {
    $cashier = actingAsRole('kasir');
    $key = $cashier->displayKey();
    auth()->logout();

    $this->get(route('display.show', $key))->assertOk()->assertSee($key, false);
    $this->get(route('display.show', str_repeat('x', 40)))->assertNotFound();

    $cashier->rotateDisplayKey();
    $this->get(route('display.state', $key))->assertNotFound();
});

test('the display is closed when switched off in settings or feature toggles', function () {
    $key = actingAsRole('kasir')->displayKey();

    Setting::put('display.enabled', '0');
    $this->get(route('display.show', $key))->assertNotFound();
    $this->postJson(route('pos.display.push'), ['stage' => 'idle'])->assertNotFound();

    Setting::put('display.enabled', '1');
    Features::setDisabled(['pos.customer-display']);
    $this->postJson(route('pos.display.push'), ['stage' => 'idle'])->assertForbidden();
    auth()->logout();
    $this->get(route('display.show', $key))->assertNotFound();
});

test('the screen and the cashier get a dynamic qris with the amount', function () {
    Setting::put('pos.qris_payload', sampleStaticQris());
    $cashier = actingAsRole('kasir');

    $this->get(route('display.qris', [$cashier->displayKey(), 'amount' => 25000]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
    $this->get(route('display.qris', [$cashier->displayKey(), 'amount' => 0]))->assertNotFound();

    $qr = Livewire::test(Cashier::class)->instance()->qrisSvg(25000);
    expect($qr['merchant'])->toBe('TOKO MAJU JAYA')
        ->and($qr['svg'])->toContain('<svg');

    Setting::put('pos.qris_payload', '');
    expect(Livewire::test(Cashier::class)->instance()->qrisSvg(25000))->toBeNull();
});

test('admin uploads a static qris image and invalid codes are explained', function () {
    actingAsAdmin();
    $png = (new QRCode(new QROptions([
        'outputInterface' => QRGdImagePNG::class, 'outputBase64' => false, 'scale' => 6,
    ])))->render(sampleStaticQris());

    Livewire::test(PosSettingsPage::class)
        ->set('qrisImage', UploadedFile::fake()->createWithContent('qris.png', $png))
        ->assertHasNoErrors()
        ->assertSee('TOKO MAJU JAYA')
        ->call('save');

    expect(PosSettings::qrisPayload())->toBe(sampleStaticQris());

    Livewire::test(PosSettingsPage::class)
        ->set('qrisText', Qris::withAmount(sampleStaticQris(), 5000))
        ->call('applyQrisText')
        ->assertHasErrors('qrisText')
        ->set('qrisText', 'bukan qris')
        ->call('applyQrisText')
        ->assertHasErrors('qrisText');
});

test('display settings save texts and slides', function () {
    Storage::fake('public');
    actingAsAdmin();

    Livewire::test(CustomerDisplaySettingsPage::class)
        ->set('theme', 'light')
        ->set('welcome', 'Halo, selamat berbelanja')
        ->set('promoText', 'Diskon 10% setiap Senin')
        ->set('newSlides', [UploadedFile::fake()->image('promo.jpg', 1920, 1080)])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('display.theme'))->toBe('light')
        ->and(Setting::get('display.promo_text'))->toBe('Diskon 10% setiap Senin')
        ->and(json_decode(Setting::get('display.slides'), true))->toHaveCount(1);

    actingAsRole('kasir');
    $this->get(route('settings.customer-display'))->assertForbidden();
});
