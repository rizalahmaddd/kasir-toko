<?php

namespace App\Livewire\Settings;

use App\Support\Features;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Pengaturan Fitur'])]
#[Title('Pengaturan Fitur')]
class FeatureToggles extends Component
{
    /**
     * @var array<string, array{on: bool, features: array<string, bool>}>
     */
    public array $state = [];

    /**
     * @var array<string, array{on: bool, features: array<string, bool>}>
     */
    public array $initialState = [];

    public string $search = '';

    public string $statusFilter = 'all'; // all, active, inactive

    public string $categoryFilter = 'all'; // all, atau kunci Features::MODULE_CATEGORIES

    /**
     * @var array<string, bool>
     */
    public array $collapsed = [];

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
        $this->fillState();
        $this->initialState = $this->state;
    }

    public function enableAll(): void
    {
        foreach (Features::MODULES as $module => $definition) {
            $this->state[$module]['on'] = true;
            foreach (array_keys($definition['features']) as $feature) {
                $this->state[$module]['features'][$feature] = true;
            }
        }
    }

    public function disableAll(): void
    {
        foreach (Features::MODULES as $module => $definition) {
            $this->state[$module]['on'] = false;
        }
    }

    public function enableModule(string $moduleKey): void
    {
        if (! isset(Features::MODULES[$moduleKey])) {
            return;
        }

        $this->state[$moduleKey]['on'] = true;
        foreach (array_keys(Features::MODULES[$moduleKey]['features']) as $feature) {
            $this->state[$moduleKey]['features'][$feature] = true;
        }
    }

    public function disableModule(string $moduleKey): void
    {
        if (! isset(Features::MODULES[$moduleKey])) {
            return;
        }

        $this->state[$moduleKey]['on'] = false;
    }

    public function toggleAllInModule(string $moduleKey, bool $enabled): void
    {
        if (! isset(Features::MODULES[$moduleKey])) {
            return;
        }

        $this->state[$moduleKey]['on'] = true;
        foreach (array_keys(Features::MODULES[$moduleKey]['features']) as $feature) {
            $this->state[$moduleKey]['features'][$feature] = $enabled;
        }
    }

    public function resetChanges(): void
    {
        $this->state = $this->initialState;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->categoryFilter = 'all';
    }

    public function toggleCollapse(string $moduleKey): void
    {
        if (isset($this->collapsed[$moduleKey])) {
            unset($this->collapsed[$moduleKey]);
        } else {
            $this->collapsed[$moduleKey] = true;
        }
    }

    public function expandAllModules(): void
    {
        $this->collapsed = [];
    }

    public function collapseAllModules(): void
    {
        $this->collapsed = array_fill_keys(array_keys(Features::MODULES), true);
    }

    #[Computed]
    public function changesCount(): int
    {
        $count = 0;

        foreach (Features::MODULES as $module => $definition) {
            $initialOn = $this->initialState[$module]['on'] ?? true;
            $currentOn = $this->state[$module]['on'] ?? true;
            if ($initialOn !== $currentOn) {
                $count++;
            }

            foreach (array_keys($definition['features']) as $feature) {
                $initialFeat = $this->initialState[$module]['features'][$feature] ?? true;
                $currentFeat = $this->state[$module]['features'][$feature] ?? true;
                if ($initialFeat !== $currentFeat) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();

        $disabled = [];

        foreach (Features::MODULES as $module => $definition) {
            if (! ($this->state[$module]['on'] ?? true)) {
                $disabled[] = $module;
            }

            foreach (array_keys($definition['features']) as $feature) {
                if (! ($this->state[$module]['features'][$feature] ?? true)) {
                    $disabled[] = "{$module}.{$feature}";
                }
            }
        }

        $before = Features::disabledKeys();
        Features::setDisabled($disabled);
        $after = Features::disabledKeys();

        $turnedOff = array_values(array_diff($after, $before));
        $turnedOn = array_values(array_diff($before, $after));

        if ($turnedOff !== [] || $turnedOn !== []) {
            activity('settings')->causedBy(Auth::user())
                ->withProperties(['disabled' => $turnedOff, 'enabled' => $turnedOn])
                ->log('Pengaturan fitur diubah: '.collect([
                    $turnedOff ? 'dimatikan '.$this->describe($turnedOff) : null,
                    $turnedOn ? 'dinyalakan '.$this->describe($turnedOn) : null,
                ])->filter()->implode('; ').'.');
        }

        $this->initialState = $this->state;

        // Reload penuh (bukan navigate) supaya sidebar di-render ulang sesuai sakelar baru.
        session()->flash('notify', ['message' => __('Pengaturan fitur tersimpan.'), 'type' => 'success']);
        $this->redirectRoute('settings.features');
    }

    public function render(): View
    {
        $filteredModules = $this->getFilteredModules();

        $totalModules = count(Features::MODULES);
        $activeModulesCount = collect(Features::MODULES)->filter(fn ($m, $k) => $this->state[$k]['on'] ?? true)->count();

        $allFeaturesCount = 0;
        $activeFeaturesCount = 0;

        foreach (Features::MODULES as $modKey => $mod) {
            $modOn = $this->state[$modKey]['on'] ?? true;
            foreach (array_keys($mod['features']) as $featKey) {
                $allFeaturesCount++;
                if ($modOn && ($this->state[$modKey]['features'][$featKey] ?? true)) {
                    $activeFeaturesCount++;
                }
            }
        }

        $disabledFeaturesCount = $allFeaturesCount - $activeFeaturesCount;

        return view('livewire.settings.feature-toggles', [
            'modules' => Features::MODULES,
            'categories' => Features::MODULE_CATEGORIES,
            'filteredModules' => $filteredModules,
            'totalModules' => $totalModules,
            'activeModulesCount' => $activeModulesCount,
            'totalFeatures' => $allFeaturesCount,
            'activeFeaturesCount' => $activeFeaturesCount,
            'disabledFeaturesCount' => $disabledFeaturesCount,
            'changesCount' => $this->changesCount,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getFilteredModules(): array
    {
        $filtered = [];
        $search = mb_strtolower(trim($this->search));

        foreach (Features::MODULES as $moduleKey => $module) {
            // Category filter
            if ($this->categoryFilter !== 'all' && ($module['category'] ?? '') !== $this->categoryFilter) {
                continue;
            }

            $moduleOn = $this->state[$moduleKey]['on'] ?? true;
            $matchingFeatures = [];

            foreach ($module['features'] as $featureKey => $feature) {
                $featureOn = $this->state[$moduleKey]['features'][$featureKey] ?? true;
                $effectiveActive = $moduleOn && $featureOn;

                // Status filter
                if ($this->statusFilter === 'active' && ! $effectiveActive) {
                    continue;
                }
                if ($this->statusFilter === 'inactive' && $effectiveActive) {
                    continue;
                }

                // Search filter
                if ($search !== '') {
                    $matchesModule = str_contains(mb_strtolower($module['label']), $search)
                        || str_contains(mb_strtolower($module['description'] ?? ''), $search);
                    $matchesFeature = str_contains(mb_strtolower($feature['label']), $search)
                        || str_contains(mb_strtolower($featureKey), $search)
                        || str_contains(mb_strtolower($feature['description'] ?? ''), $search);

                    if (! $matchesModule && ! $matchesFeature) {
                        continue;
                    }
                }

                $matchingFeatures[$featureKey] = $feature;
            }

            if ($search !== '' || $this->statusFilter !== 'all') {
                if (empty($matchingFeatures)) {
                    continue;
                }
            }

            $filtered[$moduleKey] = array_merge($module, [
                'features' => $matchingFeatures,
            ]);
        }

        return $filtered;
    }

    protected function fillState(): void
    {
        $disabled = Features::disabledKeys();

        foreach (Features::MODULES as $module => $definition) {
            $this->state[$module] = [
                'on' => ! in_array($module, $disabled, true),
                'features' => collect($definition['features'])
                    ->mapWithKeys(fn (array $feature, string $key) => [$key => ! in_array("{$module}.{$key}", $disabled, true)])
                    ->all(),
            ];
        }
    }

    /**
     * @param  list<string>  $keys
     */
    protected function describe(array $keys): string
    {
        return collect($keys)->map(function (string $key): string {
            [$module, $feature] = array_pad(explode('.', $key, 2), 2, null);
            $moduleLabel = Features::MODULES[$module]['label'];

            return $feature === null
                ? "modul {$moduleLabel}"
                : $moduleLabel.' › '.Features::MODULES[$module]['features'][$feature]['label'];
        })->implode(', ');
    }

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()->isSuperAdmin(), 403);
    }
}
