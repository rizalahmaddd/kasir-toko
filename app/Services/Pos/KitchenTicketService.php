<?php

namespace App\Services\Pos;

use App\Enums\OrderType;
use App\Events\KitchenTicketCreated;
use App\Models\KitchenTicket;
use App\Models\Sale;
use App\Models\User;
use App\Support\Features;

/**
 * Tiket dapur dibuat dari selisih isi keranjang dengan yang sudah pernah dikirim ke dapur. Keranjang
 * membawa peta "kitchen_sent" (tanda baris => jumlah) bolak-balik lewat transaksi tertunda, jadi open bill
 * yang ditambah bertahap hanya mengirim tambahan barunya.
 */
class KitchenTicketService
{
    /**
     * Penanda baris yang sama di PHP, kasir web, dan mobile: produk, satuan, pilihan tambahan, catatan.
     *
     * @param  array<string, mixed>  $item
     */
    public static function signature(array $item): string
    {
        $modifiers = collect($item['modifiers'] ?? [])->filter(fn ($row) => is_array($row))->pluck('id')->map(fn ($id) => (int) $id)->sort()->implode('-');

        return implode(':', [(int) ($item['product_id'] ?? 0), (int) ($item['unit_id'] ?? 0), $modifiers, trim((string) ($item['note'] ?? ''))]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, float|int|string>  $sent
     * @return array{items: list<array{name: string, quantity: float, unit: string, modifiers: list<string>, note: ?string}>, sent: array<string, float>}
     */
    public static function delta(array $items, array $sent): array
    {
        $lines = [];
        $totals = [];

        foreach ($items as $item) {
            $key = self::signature($item);
            $totals[$key] = round(($totals[$key] ?? 0) + (float) ($item['quantity'] ?? 0), 3);
        }

        foreach ($items as $item) {
            $key = self::signature($item);

            if (! isset($totals[$key])) {
                continue;
            }

            $quantity = round($totals[$key] - (float) ($sent[$key] ?? 0), 3);
            unset($totals[$key]);

            if ($quantity <= 0) {
                continue;
            }

            $lines[] = [
                'name' => mb_substr((string) ($item['name'] ?? $item['product_name'] ?? 'Menu'), 0, 150),
                'quantity' => $quantity,
                'unit' => (string) ($item['unit'] ?? ''),
                'modifiers' => collect($item['modifiers'] ?? [])->filter(fn ($row) => is_array($row))->pluck('name')->filter()->map(fn ($name) => mb_substr((string) $name, 0, 60))->values()->all(),
                'note' => filled($item['note'] ?? null) ? mb_substr((string) $item['note'], 0, 150) : null,
            ];
        }

        $all = [];

        foreach ($items as $item) {
            $key = self::signature($item);
            $all[$key] = round(($all[$key] ?? 0) + (float) ($item['quantity'] ?? 0), 3);
        }

        return ['items' => $lines, 'sent' => $all];
    }

    /**
     * @param  list<array<string, mixed>>  $items  baris keranjang (nama, jumlah, satuan, pilihan, catatan)
     * @param  array<string, float|int|string>  $sent
     */
    public function send(array $items, array $sent, string $label, ?string $orderType, ?User $user, ?Sale $sale = null, ?int $outletId = null): ?KitchenTicket
    {
        if (! Features::enabledAt('business.order-type', $outletId)) {
            return null;
        }

        $delta = self::delta($items, $sent);

        if ($delta['items'] === []) {
            return null;
        }

        $ticket = KitchenTicket::create(array_filter([
            'outlet_id' => $outletId,
            'sale_id' => $sale?->id,
            'user_id' => $user?->id,
            'label' => mb_substr($label, 0, 60),
            'order_type' => OrderType::tryFrom((string) $orderType)?->value,
            'items' => $delta['items'],
            'status' => KitchenTicket::STATUS_PENDING,
        ], fn ($value) => $value !== null));

        KitchenTicketCreated::dispatch($ticket);

        return $ticket;
    }

    /**
     * Label tiket: meja, lalu label transaksi tertunda / nomor antrean.
     */
    public static function label(?string $table, ?string $fallback): string
    {
        return filled($table) ? 'Meja '.trim((string) $table) : (filled($fallback) ? (string) $fallback : 'Pesanan');
    }
}
