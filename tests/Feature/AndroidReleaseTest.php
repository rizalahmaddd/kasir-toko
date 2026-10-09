<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('local');
    config(['services.app_release.upload_token' => 'rahasia-ci']);
});

function uploadApk(string $version, string $abi, string $token = 'rahasia-ci'): TestResponse
{
    return test()->withToken($token)->post(route('app-releases.android.store'), [
        'version' => $version,
        'abi' => $abi,
        'apk' => UploadedFile::fake()->create("app-{$abi}-release.apk", 512, 'application/vnd.android.package-archive'),
    ], ['Accept' => 'application/json']);
}

test('uploaded apks stay hidden until the release is published', function () {
    uploadApk('1.2.0', 'arm64-v8a')->assertCreated();

    $this->get(route('app.download'))->assertOk()->assertSee('Aplikasi Android belum tersedia');
    $this->get(route('app.download.apk', 'arm64-v8a'))->assertNotFound();

    uploadApk('1.2.0', 'armeabi-v7a')->assertCreated();
    $this->withToken('rahasia-ci')->postJson(route('app-releases.android.publish'), ['version' => '1.2.0', 'build' => 12])
        ->assertOk()
        ->assertJsonPath('release.files.0.abi', 'arm64-v8a')
        ->assertJsonCount(2, 'release.files');

    $this->get(route('app.download'))->assertOk()->assertSee('Versi 1.2.0')->assertSee('build 12');
    $this->get(route('app.download.apk', 'armeabi-v7a'))
        ->assertOk()
        ->assertDownload('kasirtoko-1.2.0-armeabi-v7a.apk');
    $this->get(route('app.download.apk', 'x86_64'))->assertNotFound();
});

test('publishing a new version removes the previous release files', function () {
    uploadApk('1.2.0', 'arm64-v8a');
    $this->withToken('rahasia-ci')->postJson(route('app-releases.android.publish'), ['version' => '1.2.0'])->assertOk();

    uploadApk('1.3.0', 'arm64-v8a');
    $this->withToken('rahasia-ci')->postJson(route('app-releases.android.publish'), ['version' => '1.3.0'])->assertOk();

    Storage::disk('local')->assertMissing('app-releases/android/1.2.0/kasirtoko-1.2.0-arm64-v8a.apk');
    $this->get(route('app.download.apk', 'arm64-v8a'))->assertDownload('kasirtoko-1.3.0-arm64-v8a.apk');
});

test('publishing a version without uploaded apks is rejected', function () {
    $this->withToken('rahasia-ci')->postJson(route('app-releases.android.publish'), ['version' => '9.9.9'])
        ->assertUnprocessable();
});

test('upload requires the configured token', function () {
    uploadApk('1.2.0', 'arm64-v8a', 'salah')->assertUnauthorized();

    config(['services.app_release.upload_token' => null]);
    uploadApk('1.2.0', 'arm64-v8a', '')->assertNotFound();

    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('upload rejects unknown abis and unsafe versions', function () {
    uploadApk('1.2.0', 'mips')->assertJsonValidationErrors('abi');
    uploadApk('../../etc', 'arm64-v8a')->assertJsonValidationErrors('version');
});
