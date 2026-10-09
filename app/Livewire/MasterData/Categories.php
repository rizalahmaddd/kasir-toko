<?php

namespace App\Livewire\MasterData;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\Category;
use App\Models\Outlet;
use App\Support\CurrentOutlet;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Kategori Produk'])]
#[Title('Kategori Produk')]
class Categories extends Component
{
    use WithCrudActions;

    public string $name = '';

    public string $sort_order = '0';

    public bool $is_active = true;

    /**
     * Kosong = kategori dijual di semua outlet.
     *
     * @var list<string>
     */
    public array $outlet_ids = [];

    public function save(): void
    {
        $this->authorizeManage();

        $validated = $this->validate();
        $isEditing = (bool) $this->editingId;
        $outletIds = array_map('intval', $validated['outlet_ids'] ?? []);
        unset($validated['outlet_ids']);

        if ($isEditing) {
            $category = Category::findOrFail($this->editingId);
            $category->update($validated);
        } else {
            $category = Category::create($validated);
        }

        $category->restrictToOutletsWithin($outletIds, app(CurrentOutlet::class)->restrictedTo());

        $this->closeModal();
        $this->notify($isEditing ? 'Kategori diperbarui.' : 'Kategori ditambahkan.');
    }

    public function render()
    {
        $query = Category::query()
            ->with('outlets')
            ->withCount('products')
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"));

        $this->applySorting($query, [
            'name' => 'name',
            'sort_order' => 'sort_order',
            'products_count' => 'products_count',
            'is_active' => 'is_active',
        ], 'sort_order', 'asc');

        return view('livewire.master-data.categories', [
            'categories' => $query->orderBy('name')->paginate($this->perPage),
            'outletOptions' => app(CurrentOutlet::class)->isMultiOutlet() ? $this->manageableOutlets() : collect(),
        ]);
    }

    /**
     * @return Collection<int, Outlet>
     */
    private function manageableOutlets(): Collection
    {
        $restricted = app(CurrentOutlet::class)->restrictedTo();

        return Outlet::query()->when($restricted !== null, fn ($query) => $query->whereIn('id', $restricted))->byPriority()->get(['id', 'name']);
    }

    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'outlet_ids']);
        $this->sort_order = '0';
        $this->is_active = true;
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->sort_order = (string) $record->sort_order;
        $this->is_active = $record->is_active;
        $this->outlet_ids = $record->outlets()->pluck('outlets.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Produk tidak ikut terhapus: kategorinya dikosongkan lewat nullOnDelete, jadi cukup diberi tahu.
     */
    protected function guardDelete($record): ?string
    {
        return null;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', TenantRule::unique('categories', 'name')->ignore($this->editingId)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'outlet_ids' => ['array'],
            'outlet_ids.*' => ['integer', TenantRule::exists('outlets', 'id')],
        ];
    }
}
