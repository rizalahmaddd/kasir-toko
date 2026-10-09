<?php

namespace App\Livewire\MasterData;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Services\ModifierGroupService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Grup pilihan tambahan (modifier) beserta opsinya dan produk yang memakainya. Opsi yang dihapus
 * di-soft delete supaya transaksi tertunda & antrean offline yang masih memakainya tetap bisa dicatat.
 */
#[Layout('layouts.app', ['heading' => 'Pilihan Tambahan'])]
#[Title('Pilihan Tambahan')]
class ModifierGroups extends Component
{
    use WithCrudActions;

    public string $name = '';

    public string $min_select = '0';

    public string $max_select = '1';

    public bool $is_active = true;

    /**
     * @var list<array{id: ?int, name: string, price: string, product_id: string, ingredient_quantity: string, is_active: bool}>
     */
    public array $options = [];

    /**
     * @var list<int|string>
     */
    public array $productIds = [];

    public string $productSearch = '';

    public function addOption(): void
    {
        if (count($this->options) < 30) {
            $this->options[] = ['id' => null, 'name' => '', 'price' => '', 'product_id' => '', 'ingredient_quantity' => '', 'is_active' => true];
        }
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productChoices(): Collection
    {
        return Product::query()
            ->where('is_active', true)
            ->search($this->productSearch)
            ->orderBy('name')
            ->limit(60)
            ->get(['id', 'name', 'unit', 'track_stock']);
    }

    /**
     * Bahan yang bisa dipotong stoknya: produk yang stoknya dilacak.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function ingredientChoices(): Collection
    {
        return Product::query()->where('track_stock', true)->orderBy('name')->limit(300)->get(['id', 'name', 'unit']);
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function selectedProducts(): Collection
    {
        return Product::query()->whereIn('id', array_map('intval', $this->productIds))->orderBy('name')->get(['id', 'name']);
    }

    public function toggleProduct(int $id): void
    {
        $ids = array_map('intval', $this->productIds);
        $this->productIds = in_array($id, $ids, true) ? array_values(array_diff($ids, [$id])) : [...$ids, $id];
        unset($this->selectedProducts);
    }

    public function save(ModifierGroupService $groups): void
    {
        $this->authorizeManage();
        $this->options = collect($this->options)->map(fn (array $option) => [
            ...$option,
            'name' => trim((string) $option['name']),
            'price' => preg_replace('/\D/', '', (string) $option['price']) ?? '',
            'ingredient_quantity' => str_replace(',', '.', trim((string) $option['ingredient_quantity'])),
        ])->values()->all();

        $validated = $this->validate();
        $isEditing = (bool) $this->editingId;

        $groups->save($this->editingId ? ModifierGroup::findOrFail($this->editingId) : null, [
            ...$validated,
            'max_select' => $validated['max_select'] === '' ? null : $validated['max_select'],
            'product_ids' => $this->productIds,
        ]);

        $this->closeModal();
        $this->notify($isEditing ? 'Grup pilihan diperbarui.' : 'Grup pilihan ditambahkan.');
    }

    public function render()
    {
        $query = ModifierGroup::query()
            ->with('modifiers')
            ->withCount('products')
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"));

        $this->applySorting($query, ['name' => 'name', 'products_count' => 'products_count', 'is_active' => 'is_active'], 'name', 'asc');

        return view('livewire.master-data.modifier-groups', [
            'groups' => $query->paginate($this->perPage),
        ]);
    }

    protected function modelClass(): string
    {
        return ModifierGroup::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'options', 'productIds', 'productSearch']);
        $this->min_select = '0';
        $this->max_select = '1';
        $this->is_active = true;
        $this->addOption();
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->min_select = (string) $record->min_select;
        $this->max_select = $record->max_select === null ? '' : (string) $record->max_select;
        $this->is_active = $record->is_active;
        $this->options = $record->modifiers()->get()->map(fn ($modifier) => [
            'id' => $modifier->id,
            'name' => $modifier->name,
            'price' => $modifier->price ? (string) $modifier->price : '',
            'product_id' => (string) $modifier->product_id,
            'ingredient_quantity' => $modifier->ingredient_quantity === null ? '' : rtrim(rtrim((string) $modifier->ingredient_quantity, '0'), '.'),
            'is_active' => $modifier->is_active,
        ])->all();
        $this->productIds = $record->products()->pluck('products.id')->all();
        $this->productSearch = '';
    }

    protected function rules(): array
    {
        return collect(ModifierGroupService::rules($this->editingId))->except(['product_ids', 'product_ids.*'])->all();
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ModifierGroupService::messages();
    }
}
