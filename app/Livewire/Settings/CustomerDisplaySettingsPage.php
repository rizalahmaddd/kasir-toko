<?php

namespace App\Livewire\Settings;

use App\Events\CustomerDisplayUpdated;
use App\Models\Setting;
use App\Support\CurrentTenant;
use App\Support\CustomerDisplaySettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app', ['heading' => 'Layar Pelanggan'])]
#[Title('Layar Pelanggan')]
class CustomerDisplaySettingsPage extends Component
{
    use WithFileUploads;

    public bool $enabled = true;

    public string $theme = 'dark';

    public string $welcome = '';

    public string $promoText = '';

    public bool $showItems = true;

    public string $thankYouSeconds = '6';

    public string $slideSeconds = '8';

    /** @var list<string> */
    public array $slides = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $newSlides = [];

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->enabled = CustomerDisplaySettings::enabled();
        $this->theme = CustomerDisplaySettings::theme();
        $this->welcome = CustomerDisplaySettings::get('display.welcome');
        $this->promoText = CustomerDisplaySettings::get('display.promo_text');
        $this->showItems = CustomerDisplaySettings::get('display.show_items') === '1';
        $this->thankYouSeconds = CustomerDisplaySettings::get('display.thank_you_seconds');
        $this->slideSeconds = CustomerDisplaySettings::get('display.slide_seconds');
        $this->slides = CustomerDisplaySettings::slides();
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()->can('settings.pos.manage'), 403);
    }

    public function updatedNewSlides(): void
    {
        $this->authorizeAccess();

        $this->validate([
            'newSlides' => ['array', 'max:'.CustomerDisplaySettings::MAX_SLIDES],
            'newSlides.*' => ['image', 'max:4096'],
        ], ['newSlides.*.image' => 'Slide harus berupa gambar.', 'newSlides.*.max' => 'Ukuran gambar maksimal 4 MB.']);

        $room = CustomerDisplaySettings::MAX_SLIDES - count($this->slides);

        foreach (array_slice($this->newSlides, 0, max(0, $room)) as $file) {
            $this->slides[] = $file->store(app(CurrentTenant::class)->storagePath('display'), 'public');
        }

        if (count($this->newSlides) > $room) {
            $this->dispatch('notify', message: 'Maksimal '.CustomerDisplaySettings::MAX_SLIDES.' gambar; sisanya tidak diunggah.', type: 'error');
        }

        $this->reset('newSlides');
    }

    public function removeSlide(int $index): void
    {
        $this->authorizeAccess();

        unset($this->slides[$index]);
        $this->slides = array_values($this->slides);
    }

    public function moveSlide(int $index, int $direction): void
    {
        $this->authorizeAccess();

        $target = $index + $direction;

        if (isset($this->slides[$index], $this->slides[$target])) {
            [$this->slides[$index], $this->slides[$target]] = [$this->slides[$target], $this->slides[$index]];
        }
    }

    public function save(): void
    {
        $this->authorizeAccess();

        $validated = $this->validate([
            'enabled' => ['boolean'],
            'theme' => ['required', Rule::in(['dark', 'light'])],
            'welcome' => ['required', 'string', 'max:150'],
            'promoText' => ['nullable', 'string', 'max:300'],
            'showItems' => ['boolean'],
            'thankYouSeconds' => ['required', 'integer', 'min:2', 'max:60'],
            'slideSeconds' => ['required', 'integer', 'min:3', 'max:60'],
        ], ['welcome.required' => 'Tulis kalimat sambutan untuk pembeli.']);

        $removed = array_diff(CustomerDisplaySettings::slides(), $this->slides);
        Storage::disk('public')->delete($removed);

        Setting::putMany([
            'display.enabled' => $validated['enabled'] ? '1' : '0',
            'display.theme' => $validated['theme'],
            'display.welcome' => trim($validated['welcome']),
            'display.promo_text' => trim((string) $validated['promoText']),
            'display.show_items' => $validated['showItems'] ? '1' : '0',
            'display.thank_you_seconds' => (string) $validated['thankYouSeconds'],
            'display.slide_seconds' => (string) $validated['slideSeconds'],
            'display.slides' => json_encode(array_values($this->slides)),
        ]);

        if ($user = auth()->user()) {
            if ($user->display_key) {
                CustomerDisplayUpdated::dispatch($user->display_key, time());
            }
            $this->js("try { new BroadcastChannel('pos-display-".$user->id."').postMessage({ type: 'theme', theme: ".json_encode($validated['theme']).' }); } catch(e) {}');
        }

        $this->dispatch('notify', message: 'Pengaturan layar pelanggan disimpan dan langsung diterapkan.');
    }

    public function openPreview(): void
    {
        $this->authorizeAccess();

        $this->js('window.open('.json_encode(route('display.show', auth()->user()->displayKey())).", 'customer-display', 'popup,width=1280,height=800')");
    }

    public function render()
    {
        return view('livewire.settings.customer-display-settings');
    }
}
