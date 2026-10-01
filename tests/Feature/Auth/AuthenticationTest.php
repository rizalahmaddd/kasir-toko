<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.login');
});

test('users can authenticate with their username, email, or phone number', function (string $login) {
    User::factory()->create([
        'username' => 'budi.santoso',
        'email' => 'budi@example.test',
        'phone' => '081234567890',
    ]);

    $component = Volt::test('pages.auth.login')
        ->set('form.login', $login)
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
})->with([
    'username' => ['budi.santoso'],
    'username in different case' => ['Budi.Santoso'],
    'email' => ['budi@example.test'],
    'local phone format' => ['0812-3456-7890'],
    'international phone format' => ['+62 812 3456 7890'],
]);

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.login', $user->username)
        ->set('form.password', 'wrong-password');

    $component->call('login');

    $component
        ->assertHasErrors(['form.login'])
        ->assertNoRedirect();

    $this->assertGuest();
});

test('navigation menu can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get('/dashboard');

    $response
        ->assertOk()
        ->assertSeeVolt('layout.navigation');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('layout.navigation');

    $component->call('logout');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
});

test('redirects keep https when forwarded by the proxy', function () {
    $response = $this->get('/dashboard', ['X-Forwarded-Proto' => 'https']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://')->toEndWith('/login');
});
