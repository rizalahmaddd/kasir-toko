<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response
        ->assertSeeVolt('pages.auth.forgot-password')
        ->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    Volt::test('pages.auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendPasswordResetLink');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('an unknown email gets the same answer as a registered one', function () {
    Notification::fake();

    Volt::test('pages.auth.forgot-password')
        ->set('email', 'tidak.terdaftar@example.test')
        ->call('sendPasswordResetLink')
        ->assertHasNoErrors()
        ->assertSet('email', '')
        ->assertSee(__(Password::RESET_LINK_SENT));

    Notification::assertNothingSent();
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    Volt::test('pages.auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendPasswordResetLink');

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response
            ->assertSeeVolt('pages.auth.reset-password')
            ->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->createToken('hp');

    Volt::test('pages.auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendPasswordResetLink');

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $component = Volt::test('pages.auth.reset-password', ['token' => $notification->token])
            ->set('email', $user->email)
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('resetPassword');

        $component
            ->assertRedirect('/login')
            ->assertHasNoErrors();

        return true;
    });

    expect($user->tokens()->count())->toBe(0);
});
