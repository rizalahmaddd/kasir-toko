<?php

namespace App\Services\Pos;

use App\Enums\OrderType;
use App\Models\Customer;
use App\Models\HeldOrder;
use App\Models\KitchenTicket;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Transaksi tertunda web & API. Dengan Tipe Pesanan & Meja, pesanan makan di tempat yang bernomor meja
 * menjadi open bill: terlihat semua kasir di outlet itu, dan menunda pesanan lain untuk meja yang sama
 * menggabungkan itemnya. Setiap penundaan mengirim tambahan item ke dapur.
 */
class HeldOrderService
{
    public const MAX_PER_USER = 30;

    public function __construct(private KitchenTicketService $kitchen) {}

    /**
     * Milik akun ini di outlet aktif, ditambah open bill meja dari kasir lain.
     *
     * @return Builder<HeldOrder>
     */
    public function query(User $user): Builder
    {
        $tables = Features::enabledAt('business.order-type');

        return HeldOrder::query()
            ->forOutlet(app(CurrentOutlet::class)->id())
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->when($tables, fn (Builder $query) => $query->orWhereNotNull('table_label')));
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array{order: HeldOrder, merged: bool, ticket: ?KitchenTicket}
     *
     * @throws PosException
     */
    public function hold(User $user, array $cart, string $label = ''): array
    {
        $items = array_values(array_filter($cart['items'] ?? [], 'is_array'));

        if ($items === []) {
            throw new PosException('Keranjang masih kosong.');
        }

        if (count($items) > 300 || strlen((string) json_encode($cart)) > 200_000) {
            throw new PosException('Keranjang terlalu besar untuk ditunda.');
        }

        $tables = Features::enabledAt('business.order-type');
        $orderType = $tables ? OrderType::tryFrom((string) ($cart['orderType'] ?? ''))?->value : null;
        $table = $orderType === OrderType::DineIn->value && filled($cart['table'] ?? null) ? mb_substr(trim((string) $cart['table']), 0, 30) : null;
        $cart['items'] = $items;

        return DB::transaction(function () use ($user, $cart, $label, $orderType, $table) {
            $existing = $table !== null
                ? HeldOrder::query()->forOutlet(app(CurrentOutlet::class)->id())->where('table_label', $table)->lockForUpdate()->first()
                : null;

            if (! $existing) {
                $count = HeldOrder::query()->where('user_id', $user->id)->forOutlet(app(CurrentOutlet::class)->id())->count();

                if ($count >= self::MAX_PER_USER) {
                    throw new PosException('Transaksi tertunda sudah 30. Selesaikan atau hapus sebagian dulu.');
                }
            }

            $merged = $existing ? $this->merge($existing->cart, $cart) : $cart;
            $label = $table !== null ? "Meja {$table}" : (trim($label) ?: (($cart['customer']['name'] ?? null) ?: 'Pesanan #'.(($count ?? 0) + 1)));
            $ticket = $orderType !== null
                ? $this->kitchen->send($merged['items'], (array) ($merged['kitchenSent'] ?? []), KitchenTicketService::label($table, $label), $orderType, $user)
                : null;

            if ($orderType !== null) {
                $merged['kitchenSent'] = KitchenTicketService::delta($merged['items'], [])['sent'];
            }

            $customerId = $merged['customer']['id'] ?? null;
            $attributes = [
                'customer_id' => $customerId && Customer::query()->whereKey($customerId)->exists() ? (int) $customerId : null,
                'label' => mb_substr($label, 0, 60),
                'cart' => $merged,
                'item_count' => count($merged['items']),
                'total' => max(0, (int) ($merged['total'] ?? 0)),
                'order_type' => $orderType,
                'table_label' => $table,
            ];

            if ($existing) {
                $existing->update($attributes);

                return ['order' => $existing, 'merged' => true, 'ticket' => $ticket];
            }

            return ['order' => HeldOrder::create(['user_id' => $user->id, ...$attributes]), 'merged' => false, 'ticket' => $ticket];
        });
    }

    /**
     * Item baru digabung ke baris yang sama persis (produk, satuan, pilihan, catatan, tanpa diskon), selain itu ditambahkan.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function merge(array $current, array $incoming): array
    {
        $items = array_values(array_filter($current['items'] ?? [], 'is_array'));

        foreach ($incoming['items'] as $item) {
            $signature = KitchenTicketService::signature($item);
            $index = collect($items)->search(fn (array $line) => KitchenTicketService::signature($line) === $signature && (int) ($line['discount'] ?? 0) === 0 && (int) ($item['discount'] ?? 0) === 0);

            if ($index === false) {
                $items[] = $item;
            } else {
                $items[$index]['quantity'] = round((float) $items[$index]['quantity'] + (float) $item['quantity'], 3);
            }
        }

        $sent = (array) ($current['kitchenSent'] ?? []);

        foreach ((array) ($incoming['kitchenSent'] ?? []) as $key => $quantity) {
            $sent[$key] = round((float) ($sent[$key] ?? 0) + (float) $quantity, 3);
        }

        return [
            ...$current,
            'items' => $items,
            'kitchenSent' => $sent,
            'customer' => $current['customer'] ?? $incoming['customer'] ?? null,
            'note' => trim(implode(' / ', array_filter([$current['note'] ?? null, $incoming['note'] ?? null]))) ?: null,
            'total' => (int) ($current['total'] ?? 0) + (int) ($incoming['total'] ?? 0),
        ];
    }

    /**
     * Ambil keranjang lalu hapus dari daftar; null bila sudah dilanjutkan di perangkat lain.
     *
     * @return array<string, mixed>|null
     */
    public function resume(User $user, int $id): ?array
    {
        return DB::transaction(function () use ($user, $id) {
            $order = $this->query($user)->lockForUpdate()->find($id);

            if (! $order) {
                return null;
            }

            $cart = $order->cart;
            $order->delete();

            return $cart;
        });
    }
}
