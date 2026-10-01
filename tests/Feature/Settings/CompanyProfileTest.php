<?php

use App\Livewire\Settings\CompanyProfile;
use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guests cannot view the company profile settings page', function () {
    $this->get(route('settings.company-profile'))->assertRedirect('/login');
});

test('roles without the company settings permission cannot view the page', function () {
    actingAsRole('staff');

    $this->get(route('settings.company-profile'))->assertForbidden();
});

test('admin can update the letterhead used on printed documents', function () {
    actingAsAdmin();

    Livewire::test(CompanyProfile::class)
        ->set('company_name', 'PT Contoh Baru')
        ->set('company_tagline', 'Tagline Baru')
        ->set('company_address', 'Jl. Baru No. 1')
        ->set('company_phone', '021-123456')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('company_name'))->toBe('PT Contoh Baru')
        ->and(Setting::get('company_tagline'))->toBe('Tagline Baru');
});

test('company name is required', function () {
    actingAsAdmin();

    Livewire::test(CompanyProfile::class)
        ->set('company_name', '')
        ->call('save')
        ->assertHasErrors(['company_name' => 'required']);
});

test('the app name and tagline from settings replace the brand across the layout', function () {
    actingAsAdmin();

    Livewire::test(CompanyProfile::class)
        ->set('app_name', 'Tani Makmur')
        ->set('app_tagline', 'Gudang & Distribusi')
        ->call('saveBranding')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.company-profile'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<title>Dashboard - Tani Makmur</title>', false)
        ->assertSee('Gudang &amp; Distribusi', false)
        ->assertDontSee('Starter Kit');
});

test('the layout falls back to the company name when no app name is set', function () {
    Setting::put('company_name', 'CV Sumber Rejeki');
    actingAsAdmin();

    $this->get(route('dashboard'))->assertOk()->assertSee('<title>Dashboard - CV Sumber Rejeki</title>', false);
});

test('an uploaded logo is served publicly and replaces the default mark', function () {
    Storage::fake('local');
    actingAsAdmin();

    Livewire::test(CompanyProfile::class)
        ->set('app_name', 'Tani Makmur')
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
        ->call('saveBranding')
        ->assertHasNoErrors();

    $path = Branding::logoPath();
    Storage::disk('local')->assertExists($path);

    $this->get(route('dashboard'))->assertSee(Branding::logoUrl(), false);

    auth()->logout();
    $this->get(route('login'))->assertSee(Branding::logoUrl(), false);
    $this->get(Branding::logoUrl())->assertOk();
});

test('replacing or removing the logo deletes the previous file', function () {
    Storage::fake('local');
    actingAsAdmin();

    Livewire::test(CompanyProfile::class)
        ->set('app_name', 'Tani Makmur')
        ->set('logo', UploadedFile::fake()->image('first.png'))
        ->call('saveBranding');
    $first = Branding::logoPath();

    Livewire::test(CompanyProfile::class)
        ->set('logo', UploadedFile::fake()->image('second.png'))
        ->call('saveBranding');
    $second = Branding::logoPath();

    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);

    Livewire::test(CompanyProfile::class)->call('removeLogo');

    Storage::disk('local')->assertMissing($second);
    expect(Branding::logoPath())->toBeNull();
    $this->get(route('branding.logo'))->assertNotFound();
});

test('svg logos are rejected because they can carry scripts', function () {
    Storage::fake('local');
    actingAsAdmin();

    Livewire::test(CompanyProfile::class)
        ->set('app_name', 'Tani Makmur')
        ->set('logo', UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'))
        ->call('saveBranding')
        ->assertHasErrors('logo');
});

test('only roles with the company settings permission can change branding', function () {
    actingAsRole('staff');

    Livewire::test(CompanyProfile::class)->assertForbidden();
});

test('any signed-in account can load the logo', function () {
    Storage::fake('local');
    Storage::disk('local')->put('branding/logo-test.png', UploadedFile::fake()->image('logo.png')->getContent());
    Setting::put(Branding::LOGO_KEY, 'branding/logo-test.png');
    actingAsRole('staff');

    $this->get(Branding::logoUrl())->assertOk();
});

test('each settings section opens from its tab and survives a reload through the URL', function () {
    actingAsAdmin();

    $this->get(route('settings.company-profile'))->assertOk()->assertSee('Branding Aplikasi')->assertDontSee('Pratinjau Kop Surat');
    $this->get(route('settings.company-profile', ['tab' => 'kop-surat']))->assertOk()->assertSee('Pratinjau Kop Surat');
    $this->get(route('settings.company-profile', ['tab' => 'tidak-ada']))->assertOk()->assertSee('Branding Aplikasi');

    Livewire::test(CompanyProfile::class)
        ->set('tab', 'kop-surat')
        ->assertSee('Simpan Kop Surat');
});
