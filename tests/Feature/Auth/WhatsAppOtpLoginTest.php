<?php

use App\Models\User;
use App\Services\FonnteService;
use App\Services\WhatsAppOtpService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

test('whatsapp login tab can be rendered', function () {
    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSee('WhatsApp OTP')
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
        ->assertSet('otpUserId', $user->id)
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
        ->assertSet('otpUserId', $user->id);
});

test('requesting otp fails when account is not found', function () {
    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', 'tidak.ada@example.test')
        ->call('sendOtp');

    $component
        ->assertHasErrors(['waIdentifier'])
        ->assertSet('otpStep', 1);
});

test('requesting otp fails when user has no registered phone number', function () {
    User::factory()->create([
        'username' => 'tanpa.hp',
        'phone' => null,
    ]);

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('waIdentifier', 'tanpa.hp')
        ->call('sendOtp');

    $component
        ->assertHasErrors(['waIdentifier'])
        ->assertSet('otpStep', 1);
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
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->call('setLoginMode', 'whatsapp')
        ->set('otpUserId', $user->id)
        ->set('otpStep', 2)
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
