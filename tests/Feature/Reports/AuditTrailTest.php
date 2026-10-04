<?php

use App\Livewire\Reports\ActivityLogReport;
use App\Models\Concerns\Auditable;
use App\Models\Customer;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;

function auditLogsFor(object $subject): Collection
{
    return Activity::query()->inLog(AuditTrail::LOG_NAME)->forSubject($subject)->orderBy('id')->get();
}

test('creating a record logs a full snapshot with who, where, and which batch', function () {
    $user = actingAsAdmin();

    $customer = Customer::factory()->create(['name' => 'PT Sinar Jaya']);

    $log = auditLogsFor($customer)->sole();

    expect($log->event)->toBe('created')
        ->and($log->causer->is($user))->toBeTrue()
        ->and($log->attribute_changes['attributes'])->toMatchArray(['id' => $customer->id, 'name' => 'PT Sinar Jaya'])
        ->and($log->attribute_changes)->not->toHaveKey('old')
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and($log->batch_uuid)->not->toBeNull()
        ->and($log->description)->toContain('Pelanggan')->toContain('PT Sinar Jaya');
});

test('updating a record logs only the changed columns with their before and after values', function () {
    actingAsAdmin();
    $customer = Customer::factory()->create(['name' => 'Lama', 'payment_term_days' => 30]);

    $customer->update(['name' => 'Baru', 'payment_term_days' => 30]);

    $log = auditLogsFor($customer)->last();

    expect($log->event)->toBe('updated')
        ->and($log->attribute_changes['old'])->toBe(['name' => 'Lama'])
        ->and($log->attribute_changes['attributes'])->toBe(['name' => 'Baru']);
});

test('saving without real changes or only touching timestamps does not add a log', function () {
    actingAsAdmin();
    $customer = Customer::factory()->create();

    $customer->update(['name' => $customer->name]);
    $customer->touch();

    expect(auditLogsFor($customer))->toHaveCount(1);
});

test('deleting keeps the last full snapshot and restoring is logged too', function () {
    actingAsAdmin();
    $customer = Customer::factory()->create(['code' => 'CUST-77']);

    $customer->delete();
    $customer->restore();

    [, $deleted, $restored] = auditLogsFor($customer)->all();

    expect($deleted->event)->toBe('deleted')
        ->and($deleted->attribute_changes['old'])->toMatchArray(['code' => 'CUST-77'])
        ->and($deleted->attribute_changes['old']['deleted_at'])->not->toBeNull()
        ->and($restored->event)->toBe('restored');
});

test('passwords and remember tokens never reach the audit log', function () {
    actingAsAdmin();
    $user = User::factory()->create();

    $user->update(['password' => 'rahasia-baru-123']);
    $user->forceFill(['remember_token' => 'token-rahasia'])->save();

    $json = auditLogsFor($user)->pluck('attribute_changes')->toJson();

    expect($json)->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('rahasia');
});

test('audit log entries cannot be edited or deleted through the model', function () {
    $log = activity('settings')->log('Pengaturan diubah.');

    $log->description = 'Dipalsukan.';
    $log->save();
    $log->delete();

    expect(Activity::find($log->id)->description)->toBe('Pengaturan diubah.');
});

test('successful and failed logins are logged without storing the password', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')->set('form.login', $user->username)->set('form.password', 'salah-total')->call('login');
    Volt::test('pages.auth.login')->set('form.login', $user->username)->set('form.password', 'password')->call('login');

    $failed = Activity::query()->inLog('auth')->forEvent('login_failed')->sole();
    $login = Activity::query()->inLog('auth')->forEvent('login')->sole();

    expect($failed->properties['credentials'])->toBe(['username' => $user->username])
        ->and($failed->properties->toJson())->not->toContain('salah-total')
        ->and($login->causer->is($user))->toBeTrue();
});

test('exporting a report is recorded', function () {
    $owner = actingAsAdmin();

    Livewire::test(ActivityLogReport::class)->call('export', 'csv');

    $export = Activity::query()->inLog('export')->sole();

    expect($export->event)->toBe('exported')
        ->and($export->causer->is($owner))->toBeTrue()
        ->and($export->properties['format'])->toBe('csv');
});

test('the report detail shows before and after values and can trace the whole batch', function () {
    actingAsAdmin();
    $customer = Customer::factory()->create(['name' => 'Nama Awal']);
    $customer->update(['name' => 'Nama Revisi']);
    $update = auditLogsFor($customer)->last();
    activity('settings')->log('Aktivitas di luar batch.');
    Activity::query()->latest('id')->first()->forceFill(['batch_uuid' => fake()->uuid()])->saveQuietly();

    Livewire::test(ActivityLogReport::class)
        ->call('showDetail', $update->id)
        ->assertSee('Sebelum')
        ->assertSee('Nama Awal')
        ->assertSee('Nama Revisi')
        ->call('traceBatch', $update->batch_uuid)
        ->assertSee('Pelanggan')
        ->assertDontSee('Aktivitas di luar batch.');
});

test('tracing a record shows its full history regardless of the date range', function () {
    actingAsAdmin();
    $this->travelTo(now()->subYear());
    $customer = Customer::factory()->create(['name' => 'Pelanggan Tahun Lalu']);
    $this->travelBack();

    Livewire::test(ActivityLogReport::class)
        ->assertDontSee('Pelanggan Tahun Lalu')
        ->call('traceSubject', Customer::class, (string) $customer->id)
        ->assertSee('Pelanggan Tahun Lalu');
});

test('every audited model has an integer key so it fits the bigint subject_id column', function () {
    $auditedModels = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path): string => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class): bool => in_array(Auditable::class, class_uses_recursive($class), true));

    expect($auditedModels)->not->toBeEmpty();

    $auditedModels->each(fn (string $class) => expect((new $class)->getKeyType())->toBe('int', "{$class} has a non-integer key"));
});
