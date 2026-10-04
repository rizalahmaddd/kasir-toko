<?php

use App\Models\User;
use App\Services\FonnteService;
use App\Services\WhatsAppOtpService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;

test('whatsapp login tab can be rendered when fonnte token is configured', function () {
    \Illuminate\Support\Facades\Config::set('services.fonnte.token', 'test-token');

    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSee('WhatsApp OTP')
        ->assertSeeVolt('pages.auth.login');
});

test('whatsapp login tab is hidden when fonnte token is not configured', function () {
    \Illuminate\Support\Facades\Config::set('services.fonnte.token', null);

    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertDontSee('WhatsApp OTP')
        ->assertSee('Masuk ke Akun')
        ->assertSeeVolt('pages.auth.login');
});

test('users can request whatsapp otp with registered phone number', function () {
    $user = User::factory()->create([
        'username' => 'budi.santoso',
        'phone' => '081234567890',
    ]);

    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->assertSet('loginMode', 'whatsapp')
        ->set('waIdentifier', '081234567890')
        ->call('sendOtp');

    $component
        ->assertHasNoErrors()
        ->assertSet('otpStep', 2)
        ->assertSet('otpChallenge', (string) $user->id)
        ->assertSee('0812 •••• 7890');

    expect(Cache::has("wa_otp_{$user->id}"))->toBeTrue();
});

test('users can request whatsapp otp using their username or email', function () {
    $user = User::factory()->create([
        'username' => 'dewi.lestari',
        'email' => 'dewi@example.test',
        'phone' => '089876543210',
    ]);

    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', 'dewi@example.test')
        ->call('sendOtp');

    $component
        ->assertHasNoErrors()
        ->assertSet('otpStep', 2)
        ->assertSet('otpChallenge', (string) $user->id);
});

test('requesting otp for an unknown account looks the same as for a registered one', function () {
    Http::fake();

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', 'tidak.ada@example.test')
        ->call('sendOtp')
        ->assertHasNoErrors()
        ->assertSet('otpStep', 2)
        ->set('otp', '123456')
        ->call('verifyOtp')
        ->assertHasErrors(['otp']);

    expect($component->errors()->first('otp'))->toBe('Kode OTP salah. Sisa kesempatan: 4 kali.');

    $component->call('backToOtpIdentifier')->call('sendOtp')->assertHasErrors(['waIdentifier']);

    Http::assertNothingSent();
    $this->assertGuest();
});

test('requesting otp for an account without a phone number sends nothing and reveals nothing', function () {
    Http::fake();
    User::factory()->create([
        'username' => 'tanpa.hp',
        'phone' => null,
    ]);

    Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', 'tanpa.hp')
        ->call('sendOtp')
        ->assertHasNoErrors()
        ->assertSet('otpStep', 2)
        ->assertSet('maskedPhone', 'nomor WhatsApp akun ini');

    Http::assertNothingSent();
});

test('users are rate-limited on resending otp within cooldown period', function () {
    $user = User::factory()->create([
        'phone' => '081122334455',
    ]);

    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', '081122334455')
        ->call('sendOtp')
        ->assertHasNoErrors();

    // Coba kirim lagi saat cooldown masih aktif
    $component->call('sendOtp')
        ->assertHasErrors(['waIdentifier']);
});

test('users can authenticate with valid whatsapp otp', function () {
    $user = User::factory()->create([
        'phone' => '081987654321',
    ]);

    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', '081987654321')
        ->call('sendOtp')
        ->assertHasNoErrors()
        ->set('otp', '123456') // Testing OTP default '123456'
        ->call('verifyOtp');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('users cannot authenticate with wrong otp', function () {
    $user = User::factory()->create([
        'phone' => '081555666777',
    ]);

    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', '081555666777')
        ->call('sendOtp')
        ->assertHasNoErrors()
        ->set('otp', '000000') // Salah
        ->call('verifyOtp');

    $component
        ->assertHasErrors(['otp'])
        ->assertNoRedirect();

    $this->assertGuest();
});

test('users cannot authenticate with expired or non-existent otp', function () {
    $user = User::factory()->create(['phone' => '081234567890']);
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', '081234567890')
        ->call('sendOtp');

    Cache::forget("wa_otp_{$user->id}");

    $component
        ->set('otp', '123456')
        ->call('verifyOtp');

    $component
        ->assertHasErrors(['otp'])
        ->assertNoRedirect();

    $this->assertGuest();
});

test('fonnte service sends http request with authorization header', function () {
    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    $fonnte = new FonnteService(token: 'mock-token-123', url: 'https://api.fonnte.com/send');
    $result = $fonnte->send('081234567890', 'Pesan uji coba');

    expect($result['status'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.fonnte.com/send'
            && $request->hasHeader('Authorization', 'mock-token-123')
            && $request['target'] === '081234567890';
    });
});

test('requesting otp fails when fonnte gateway returns error', function () {
    $user = User::factory()->create([
        'phone' => '081234567890',
    ]);

    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'Device disconnected'], 200),
    ]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', '081234567890')
        ->call('sendOtp');

    $component
        ->assertHasErrors(['waIdentifier'])
        ->assertSet('otpStep', 1);

    expect(Cache::has("wa_otp_{$user->id}"))->toBeFalse();
});

test('fonnte returns error when token is blank', function () {
    $fonnte = new FonnteService(token: '', url: 'https://api.fonnte.com/send');
    $result = $fonnte->send('081234567890', 'Test');

    expect($result['status'])->toBeFalse()
        ->and($result['message'])->toContain('FONNTE_TOKEN');
});

test('a new otp gets a fresh set of attempts after the previous one ran out', function () {
    $user = User::factory()->create(['phone' => '081222333444']);
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $otp = app(WhatsAppOtpService::class);

    $otp->sendOtp('081222333444');
    foreach (range(1, 5) as $attempt) {
        rescue(fn () => $otp->verifyOtp($user->id, '000000'), report: false);
    }

    Cache::forget("wa_otp_cooldown_{$user->id}");
    $otp->sendOtp('081222333444');

    expect($otp->verifyOtp($user->id, '123456')->is($user))->toBeTrue();
});

test('the otp user cannot be swapped from the browser', function () {
    $victim = User::factory()->create();

    Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('otpChallenge', (string) $victim->id);
})->throws(CannotUpdateLockedPropertyException::class);

test('one address cannot request otp codes without limit', function () {
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $users = User::factory()->count(6)->sequence(fn ($sequence) => ['phone' => '08123456780'.$sequence->index])->create();

    $component = Volt::test('pages.auth.login')->call('setLoginMode', 'whatsapp');

    foreach ($users->take(5) as $user) {
        $component->set('waIdentifier', $user->phone)->call('sendOtp')->assertHasNoErrors();
    }

    $component->set('waIdentifier', $users->last()->phone)->call('sendOtp')->assertHasErrors(['waIdentifier']);

    expect(Cache::has("wa_otp_{$users->last()->id}"))->toBeFalse();
});
