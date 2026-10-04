<?php

namespace App\Livewire\MasterData;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\Category;
use App\Support\TenantRule;
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

    public function save(): void
    {
        $this->authorizeManage();

        $validated = $this->validate();
        $isEditing = (bool) $this->editingId;

        if ($isEditing) {
            Category::findOrFail($this->editingId)->update($validated);
        } else {
            Category::create($validated);
        }

        $this->closeModal();
        $this->notify($isEditing ? 'Kategori diperbarui.' : 'Kategori ditambahkan.');
    }

    public function render()
    {
        $query = Category::query()
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
        ]);
    }

    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['name']);
        $this->sort_order = '0';
        $this->is_active = true;
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->sort_order = (string) $record->sort_order;
        $this->is_active = $record->is_active;
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
        ];
    }
}
