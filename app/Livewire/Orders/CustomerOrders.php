<?php

namespace App\Livewire\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Product;
use App\Services\Pos\CustomerOrderService;
use App\Services\Pos\PosException;
use App\Support\PosSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pesanan dengan tanggal ambil & uang muka, dan tiket servis. Pelunasan dilakukan di kasir.
 */
#[Layout('layouts.app', ['heading' => 'Pesanan & Servis'])]
#[Title('Pesanan & Servis')]
class CustomerOrders extends Component
{
    use WithDataTable;

    public string $search = '';

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $type = '';

    /**
     * @var array{type: string, customer_id: string, customer_name: string, customer_phone: string, pickup_at: string, device: string, device_serial: string, complaint: string, notes: string, deposit: string, deposit_method: string, deposit_reference: string}
     */
    public array $form = [];

    /**
     * @var list<array{product_id: string, name: string, quantity: string, price: string, note: string}>
     */
    public array $items = [];

    public string $productSearch = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('orders.manage'), 403);
        $this->resetForm();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function openCreate(string $type = CustomerOrder::TYPE_ORDER): void
    {
        $this->resetForm();
        $this->form['type'] = $type === CustomerOrder::TYPE_SERVICE ? CustomerOrder::TYPE_SERVICE : CustomerOrder::TYPE_ORDER;
        $this->resetValidation();
        $this->dispatch('open-modal', 'order-form');
    }

    public function closeOrderForm(): void
    {
        $this->dispatch('close-modal', 'order-form');
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productChoices(): Collection
    {
        return trim($this->productSearch) === ''
            ? new Collection
            : Product::query()->withOutletData()->where('is_active', true)->search($this->productSearch)->orderBy('name')->limit(8)->get();
    }

    public function addProduct(int $id): void
    {
        $product = Product::query()->withOutletData()->find($id);

        if ($product && count($this->items) < 50) {
            $this->items[] = ['product_id' => (string) $product->id, 'name' => $product->name, 'quantity' => '1', 'price' => (string) $product->effectivePrice(), 'note' => ''];
        }

        $this->productSearch = '';
        unset($this->productChoices);
    }

    public function addFreeLine(): void
    {
        if (count($this->items) < 50) {
            $this->items[] = ['product_id' => '', 'name' => '', 'quantity' => '1', 'price' => '', 'note' => ''];
        }
    }

    public function removeLine(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()->where('is_active', true)->orderBy('name')->limit(300)->get(['id', 'name', 'phone']);
    }

    public function updatedFormCustomerId(string $value): void
    {
        $customer = Customer::query()->find($value);

        if ($customer) {
            $this->form['customer_name'] = $customer->name;
            $this->form['customer_phone'] = (string) $customer->phone;
        }
    }

    public function save(CustomerOrderService $orders)
    {
        abort_unless(auth()->user()->can('orders.manage'), 403);

        $this->form['deposit'] = preg_replace('/\D/', '', $this->form['deposit']) ?? '';
        $this->items = collect($this->items)->map(fn (array $item) => [...$item, 'price' => preg_replace('/\D/', '', (string) $item['price']) ?? '', 'quantity' => str_replace(',', '.', trim((string) $item['quantity']))])->all();

        $validated = $this->validate([
            'form.type' => ['required', Rule::in([CustomerOrder::TYPE_ORDER, CustomerOrder::TYPE_SERVICE])],
            'form.customer_id' => ['nullable', 'integer'],
            'form.customer_name' => ['required', 'string', 'max:100'],
            'form.customer_phone' => ['nullable', 'string', 'max:30'],
            'form.pickup_at' => ['nullable', 'date'],
            'form.device' => ['nullable', 'string', 'max:150'],
            'form.device_serial' => ['nullable', 'string', 'max:64'],
            'form.complaint' => ['nullable', 'string', 'max:1000'],
            'form.notes' => ['nullable', 'string', 'max:1000'],
            'form.deposit' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'form.deposit_method' => ['required', Rule::enum(PaymentMethod::class)],
            'form.deposit_reference' => ['nullable', 'string', 'max:100'],
            'items' => ['array', 'max:50'],
            'items.*.product_id' => ['nullable'],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.price' => ['required', 'integer', 'min:0', 'max:999999999'],
            'items.*.note' => ['nullable', 'string', 'max:150'],
        ], [
            'form.customer_name.required' => 'Isi nama pemesan.',
            'items.*.name.required' => 'Isi nama barang/jasa.',
            'items.*.price.required' => 'Isi harganya (0 bila belum tahu).',
        ]);

        if ($validated['form']['type'] === CustomerOrder::TYPE_ORDER && $validated['items'] === []) {
            $this->addError('items', 'Tambahkan minimal satu barang pesanan.');

            return null;
        }

        try {
            $order = $orders->create(auth()->user(), [
                ...array_map(fn ($value) => $value === '' ? null : $value, $validated['form']),
                'deposit' => (int) ($validated['form']['deposit'] ?: 0),
                'items' => $validated['items'],
            ]);
        } catch (PosException $exception) {
            $this->addError('form.deposit', $exception->getMessage());

            return null;
        }

        $this->dispatch('close-modal', 'order-form');

        return $this->redirectRoute('orders.show', $order, navigate: true);
    }

    private function resetForm(): void
    {
        $this->form = ['type' => CustomerOrder::TYPE_ORDER, 'customer_id' => '', 'customer_name' => '', 'customer_phone' => '', 'pickup_at' => '', 'device' => '', 'device_serial' => '', 'complaint' => '', 'notes' => '', 'deposit' => '', 'deposit_method' => PaymentMethod::Cash->value, 'deposit_reference' => ''];
        $this->items = [];
        $this->productSearch = '';
    }

    public function render()
    {
        $orders = CustomerOrder::query()
            ->withCount('items')
            ->when($this->search, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('number', 'like', "%{$this->search}%")->orWhere('customer_name', 'like', "%{$this->search}%")->orWhere('customer_phone', 'like', "%{$this->search}%")->orWhere('device_serial', 'like', "%{$this->search}%")))
            ->when($this->type !== '', fn (Builder $query) => $query->where('type', $this->type))
            ->when($this->status === 'open', fn (Builder $query) => $query->open())
            ->when(CustomerOrderStatus::tryFrom($this->status), fn (Builder $query, CustomerOrderStatus $status) => $query->where('status', $status->value))
            ->orderByRaw('pickup_at is null')
            ->orderBy('pickup_at')
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.orders.customer-orders', [
            'orders' => $orders,
            'methods' => PosSettings::paymentMethods(),
        ]);
    }
}
