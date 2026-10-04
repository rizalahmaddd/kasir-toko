<?php

use App\Models\Customer;
use App\Models\User;
use App\Support\Features;
use Livewire\Volt\Volt;

/**
 * @return list<array{label: string, sub: ?string, url: string, flags: list<array{label: string, color: string}>}>
 */
function searchGroup(array $results, string $group): array
{
    return collect($results)->firstWhere('group', $group)['items'] ?? [];
}

test('matching part of a result label is bolded and the rest stays escaped', function () {
    actingAsAdmin();
    Customer::factory()->create(['name' => 'PT Sinar <Jaya>']);

    Volt::test('layout.global-search')
        ->call('openSearch')
        ->set('query', 'sina')
        ->assertSeeHtml('PT <mark class="bg-transparent font-semibold text-slate-50">Sina</mark>r &lt;Jaya&gt;');
});

test('a customer is found by name or phone and opens its detail page', function () {
    actingAsRole('staff');
    $customer = Customer::factory()->create(['name' => 'PT Kencana', 'phone' => '081299990000', 'is_active' => false]);

    $byName = searchGroup(Volt::test('layout.global-search')->set('query', 'kencana')->get('results'), 'Pelanggan');
    $byPhone = searchGroup(Volt::test('layout.global-search')->set('query', '9999')->get('results'), 'Pelanggan');

    expect($byName[0]['url'])->toBe(route('master-data.customers.show', $customer))
        ->and($byName[0]['flags'])->toBe([['label' => 'Nonaktif', 'color' => 'slate']])
        ->and($byPhone[0]['label'])->toBe('PT Kencana');
});

test('customers are hidden from the search when their feature is off', function () {
    actingAsAdmin();
    Customer::factory()->create(['name' => 'PT Tersembunyi']);
    Features::setDisabled(['master-data.customers']);

    expect(searchGroup(Volt::test('layout.global-search')->set('query', 'tersembunyi')->get('results'), 'Pelanggan'))->toBe([]);
});

test('users are only searchable by roles that manage user roles', function () {
    User::factory()->create(['name' => 'Rahmat Hidayat', 'username' => 'rahmat']);

    actingAsAdmin();
    expect(searchGroup(Volt::test('layout.global-search')->set('query', 'rahmat')->get('results'), 'Pengguna'))->toHaveCount(1);

    actingAsRole('staff');
    expect(searchGroup(Volt::test('layout.global-search')->set('query', 'rahmat')->get('results'), 'Pengguna'))->toBe([]);
});

test('menus are searchable by partial words across group and label', function () {
    actingAsAdmin();

    $results = Volt::test('layout.global-search')->set('query', 'lap aktiv')->get('results');

    expect(collect(searchGroup($results, 'Menu'))->pluck('url')->all())->toBe([route('reports.activity-log')]);
});

test('menus the user cannot access are left out of the search', function () {
    actingAsRole('staff');

    $results = Volt::test('layout.global-search')->set('query', 'pengaturan')->get('results');

    expect(searchGroup($results, 'Menu'))->toBe([]);
});
