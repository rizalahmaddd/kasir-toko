<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('issues a token for username, email, or phone logins', function (string $login) {
    User::factory()->create(['username' => 'budi', 'email' => 'budi@example.com', 'phone' => '081234567890', 'password' => 'secret-pass']);

    $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'secret-pass', 'device_name' => 'Pixel 8'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'roles', 'permissions', 'enabled_features']]]);
})->with(['budi', 'BUDI@example.com', '+62 812-3456-7890']);

it('rejects a wrong password and locks out after repeated failures', function () {
    User::factory()->create(['username' => 'budi', 'password' => 'secret-pass']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'budi', 'password' => 'wrong', 'device_name' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login' => trans('auth.failed')]);
    }

    $this->postJson('/api/v1/auth/login', ['login' => 'budi', 'password' => 'secret-pass', 'device_name' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('login');
});

it('logs in with a WhatsApp OTP', function () {
    Http::fake(['*' => Http::response(['status' => true])]);
    config(['services.fonnte.token' => 'test-token']);
    User::factory()->create(['username' => 'budi', 'phone' => '081234567890']);

    $challenge = $this->postJson('/api/v1/auth/otp/send', ['login' => 'budi'])
        ->assertOk()
        ->json('data');

    $this->postJson('/api/v1/auth/otp/verify', ['otp_token' => $challenge['otp_token'], 'otp' => '000000', 'device_name' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('otp');

    $this->postJson('/api/v1/auth/otp/verify', ['otp_token' => $challenge['otp_token'], 'otp' => '123456', 'device_name' => 'x'])
        ->assertOk()
        ->assertJsonPath('data.user.username', 'budi');
});

it('rejects a tampered otp token', function () {
    $this->postJson('/api/v1/auth/otp/verify', ['otp_token' => 'not-encrypted', 'otp' => '123456', 'device_name' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('otp_token');
});

it('requires a token for protected endpoints', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('returns the current user with effective permissions', function () {
    $user = User::factory()->create();
    $user->assignRole(seededRole('admin'));
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.roles', ['admin'])
        ->assertJsonPath('data.is_superadmin', false)
        ->assertJsonFragment(['master-data.manage']);
});

it('revokes only the current token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;
    $user->createToken('tablet');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['tablet']);
});

it('changes the password after checking the current one', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertJsonValidationErrors('current_password');

    $this->putJson('/api/v1/auth/password', ['current_password' => 'old-password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertNoContent();
});
