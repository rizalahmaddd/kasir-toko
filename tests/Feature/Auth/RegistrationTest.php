<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Livewire\Volt\Volt;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.register');
});

test('new users can register', function () {
    $component = Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('username', 'Test.User')
        ->set('email', 'test@example.com')
        ->set('phone', '+62 812-3456-7890')
        ->set('password', 'password')
        ->set('password_confirmation', 'password');

    $component->call('register');

    $component->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::sole())
        ->username->toBe('test.user')
        ->phone->toBe('081234567890');
});

test('a username must start with a letter so it is never mistaken for a phone number', function () {
    Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('username', '0812345')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasErrors(['username' => 'regex']);

    $this->assertGuest();
});
