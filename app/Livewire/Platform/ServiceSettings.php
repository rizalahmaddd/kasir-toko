<?php

namespace App\Livewire\Platform;

use App\Models\Setting;
use App\Support\SaasSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Pengaturan Layanan'])]
#[Title('Pengaturan Layanan')]
class ServiceSettings extends Component
{
    public bool $registrationOpen = true;

    public string $trialDays = '';

    public function mount(): void
    {
        $this->registrationOpen = SaasSettings::registrationOpen();
        $this->trialDays = (string) SaasSettings::trialDays();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage-platform'), 403);

        $validated = $this->validate([
            'registrationOpen' => ['boolean'],
            'trialDays' => ['required', 'integer', 'min:1', 'max:90'],
        ], [], ['trialDays' => 'lama uji coba']);

        Setting::put(SaasSettings::REGISTRATION_OPEN, $validated['registrationOpen'] ? '1' : '0');
        Setting::put(SaasSettings::TRIAL_DAYS, (string) (int) $validated['trialDays']);

        $this->dispatch('notify', message: 'Pengaturan layanan disimpan.');
    }

    public function render()
    {
        return view('livewire.platform.service-settings', [
            'plans' => config('saas.plans'),
        ]);
    }
}
