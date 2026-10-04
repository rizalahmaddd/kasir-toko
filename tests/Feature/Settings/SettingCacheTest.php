<?php

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

test('a setting written while model events are muted is read back fresh', function () {
    Setting::put('accounting.locked_until', null);
    expect(Setting::get('accounting.locked_until'))->toBeNull();

    Model::withoutEvents(fn () => Setting::put('accounting.locked_until', '2026-08-31'));

    expect(Setting::get('accounting.locked_until'))->toBe('2026-08-31');
});
