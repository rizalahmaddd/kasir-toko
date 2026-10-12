<?php

namespace App\Http\Controllers;

use App\Enums\OrderType;
use App\Http\Middleware\IdentifySelfOrderContext;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Services\Pos\ModifierService;
use App\Services\Pos\PosException;
use App\Services\Pos\SelfOrderGate;
use App\Services\Pos\SelfOrderService;
use App\Support\Branding;
use App\Support\Features;
use App\Support\OutletIdentity;
use App\Support\PosSettings;
use App\Support\Qris;
use App\Support\Turnstile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Halaman pesanan QR untuk pelanggan: halaman Blade + endpoint JSON, bukan Livewire, supaya setiap request
 * melewati IdentifySelfOrderContext. Tenant dan outlet selalu berasal dari token di URL.
 */
class SelfOrderController extends Controller
{
    public const DEVICE_COOKIE = 'so_device';

    public function __construct(private SelfOrderService $selfOrders) {}

    public function table(Request $request, string $tableToken): Response
    {
        return $this->menuPage($request, $tableToken);
    }

    public function outlet(Request $request, string $outletToken): Response
    {
        return $this->menuPage($request, $outletToken);
    }

    public function menu(Request $request): JsonResponse
    {
        $outlet = $this->outletOf($request);
        $products = $this->selfOrders->catalog($outlet->id);
        $categoryIds = $products->pluck('category_id')->filter()->unique();

        return response()->json([
            'open' => SelfOrderGate::closedReason($outlet->id) === null,
            'closed_message' => SelfOrderGate::message(SelfOrderGate::closedReason($outlet->id)),
            'quota_message' => $this->selfOrders->quotaMessage($outlet, $request->attributes->get(IdentifySelfOrderContext::TABLE), $this->deviceId($request)),
            'categories' => Category::query()->whereIn('id', $categoryIds)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])->all(),
            'products' => $products->map(fn (Product $product) => $this->productPayload($product, $outlet->id))->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function quote(Request $request): JsonResponse
    {
        $outlet = $this->outletOf($request);
        $data = $this->validated($request, $this->itemRules());

        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            return response()->json(['ok' => true, 'quote' => $this->selfOrders->quote($data['items'], $this->orderType($request), $outlet->id)]);
        } catch (PosException $exception) {
            return $this->rejected($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $outlet = $this->outletOf($request);

        // Kolom jebakan: manusia tidak melihatnya, bot pengisi form mengisinya.
        if (filled($request->input('website'))) {
            return response()->json(['ok' => false, 'message' => 'Pesanan tidak bisa dikirim.'], 422);
        }

        $data = $this->validated($request, [
            'client_uuid' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'min:2', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:25'],
            'payment_method' => ['nullable', 'in:'.SelfOrder::METHOD_CASHIER.','.SelfOrder::METHOD_QRIS],
            'notes' => ['nullable', 'string', 'max:200'],
            ...$this->itemRules(),
        ], ['customer_name.required' => 'Isi nama pemesan supaya kasir bisa memanggil Anda.', 'customer_name.min' => 'Nama pemesan minimal 2 huruf.']);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        // Cookie perangkat dipasang saat halaman menu dibuka; tanpa itu kuota per perangkat tidak bisa dihitung.
        if ($this->deviceId($request) === null) {
            return response()->json(['ok' => false, 'message' => 'Muat ulang halaman menu lalu kirim pesanan lagi.', 'reason' => 'device'], 422);
        }

        if (! Turnstile::verify($request->input('captcha_token'), $request->ip())) {
            return response()->json(['ok' => false, 'message' => 'Verifikasi keamanan gagal. Muat ulang halaman lalu coba lagi.', 'reason' => 'captcha'], 422);
        }

        try {
            $order = $this->selfOrders->submit($outlet, $request->attributes->get(IdentifySelfOrderContext::TABLE), $this->deviceId($request), $data);
        } catch (PosException $exception) {
            return $this->rejected($exception);
        }

        return response()->json([
            'ok' => true,
            'session_token' => $order->session_token,
            'status_url' => route('self-order.status', $order->session_token),
            'number' => $order->number,
        ], 201);
    }

    public function status(Request $request): View
    {
        $order = $this->orderOf($request);
        $outlet = $this->outletOf($request);

        return view('self-order.status', [
            'storeName' => Branding::companyName(),
            'logo' => Branding::logoUrl(),
            'config' => [
                'sessionToken' => $order->session_token,
                'stateUrl' => route('self-order.state', $order->session_token),
                'qrisUrl' => route('self-order.qris', $order->session_token),
                'cancelUrl' => route('self-order.cancel', $order->session_token),
                'menuUrl' => $this->menuUrl($order, $outlet),
                'merchant' => ($payload = PosSettings::qrisPayload()) ? Qris::merchant($payload)['name'] : null,
                'state' => $this->selfOrders->state($order),
            ],
        ]);
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->selfOrders->state($this->orderOf($request)))->header('Cache-Control', 'no-store');
    }

    /**
     * QR dinamis senilai total pesanan, jadi pelanggan tidak mengetik nominalnya sendiri.
     */
    public function qris(Request $request): Response
    {
        $order = $this->orderOf($request);
        $payload = PosSettings::qrisPayload();

        abort_unless($payload !== null && $order->isQris() && $order->total > 0, 404);

        return response(Qris::svg(Qris::withAmount($payload, $order->total)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            $order = $this->selfOrders->cancelByCustomer($this->orderOf($request));
        } catch (PosException $exception) {
            return $this->rejected($exception);
        }

        return response()->json(['ok' => true, 'state' => $this->selfOrders->state($order)]);
    }

    private function menuPage(Request $request, string $token): Response
    {
        $outlet = $this->outletOf($request);
        $table = $request->attributes->get(IdentifySelfOrderContext::TABLE);
        $closedReason = SelfOrderGate::closedReason($outlet->id);
        $identity = OutletIdentity::for($outlet);

        if (! $request->cookie(self::DEVICE_COOKIE)) {
            Cookie::queue(self::DEVICE_COOKIE, Str::random(40), 60 * 24 * 30);
        }

        return response()->view('self-order.menu', [
            'storeName' => Branding::companyName(),
            'logo' => Branding::logoUrl(),
            'outletName' => $identity['name'],
            'table' => $table,
            'captchaSiteKey' => Turnstile::siteKey(),
            'config' => [
                'token' => $token,
                'storageKey' => 'self-order:'.$token,
                'menuUrl' => route('self-order.menu', $token),
                'quoteUrl' => route('self-order.quote', $token),
                'orderUrl' => route('self-order.order', $token),
                'statusUrlTemplate' => route('self-order.status', '__TOKEN__'),
                'stateUrlTemplate' => route('self-order.state', '__TOKEN__'),
                'table' => $table?->name,
                'orderType' => $this->orderType($request),
                'open' => $closedReason === null,
                'closedMessage' => SelfOrderGate::message($closedReason),
                'qris' => $this->selfOrders->qrisAvailable(),
                'merchant' => ($payload = PosSettings::qrisPayload()) ? Qris::merchant($payload)['name'] : null,
                'payFirst' => $table === null || PosSettings::selfOrderDineInPayment() === 'before',
                'taxRate' => PosSettings::taxRate(),
                'taxLabel' => PosSettings::taxLabel(),
                'serviceRate' => PosSettings::serviceChargeRate($this->orderType($request)),
                'nearExpiryPercent' => PosSettings::nearExpiryDiscountPercent(),
                'maxLines' => SelfOrderService::MAX_LINES,
                'captchaSiteKey' => Turnstile::siteKey(),
            ],
        ]);
    }

    /**
     * Stok tidak dikirim sebagai angka: pelanggan cukup tahu menunya masih ada atau habis.
     *
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, int $outletId): array
    {
        $soldOut = $product->track_stock && ! PosSettings::allowNegativeStock() && $product->outletStock() <= 0;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'category_id' => $product->category_id,
            'unit' => $product->unit,
            'price' => $product->effectivePrice(),
            'image' => $product->imageUrl(),
            'sold_out' => $soldOut,
            'max' => $product->track_stock && ! PosSettings::allowNegativeStock() ? max(0, (int) floor($product->outletStock())) : null,
            'tiers' => Features::enabledAt('business.tiered-price', $outletId) ? $product->priceTiers->map(fn ($tier) => ['min' => (float) $tier->min_quantity, 'price' => (int) $tier->price])->values()->all() : [],
            'near_expiry' => (float) ($product->getAttribute('near_expiry_quantity') ?? 0),
            'modifier_groups' => Features::enabledAt('business.modifiers', $outletId) ? ModifierService::payload($product) : [],
            'variant' => $product->variantLabel(),
            'variants' => $product->relationLoaded('variants') ? $product->variants->map(fn (Product $child) => $this->productPayload($child, $outletId))->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.SelfOrderService::MAX_LINES],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.note' => ['nullable', 'string', 'max:150'],
            'items.*.modifiers' => ['nullable', 'array', 'max:20'],
            'items.*.modifiers.*' => ['integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>|JsonResponse
     */
    private function validated(Request $request, array $rules, array $messages = []): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules, ['items.required' => 'Keranjang masih kosong.', 'items.max' => 'Satu pesanan maksimal '.SelfOrderService::MAX_LINES.' baris menu.', ...$messages]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => $validator->errors()->first(), 'reason' => 'validation', 'errors' => $validator->errors()], 422);
        }

        return $validator->validated();
    }

    private function rejected(PosException $exception): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $exception->getMessage(), 'reason' => $exception->reason, 'context' => (object) $exception->context], 422);
    }

    private function orderType(Request $request): string
    {
        return $request->attributes->get(IdentifySelfOrderContext::TABLE) ? OrderType::DineIn->value : OrderType::TakeAway->value;
    }

    private function deviceId(Request $request): ?string
    {
        $device = $request->cookie(self::DEVICE_COOKIE);

        return is_string($device) && strlen($device) === 40 ? $device : null;
    }

    private function menuUrl(SelfOrder $order, Outlet $outlet): string
    {
        $table = $order->dining_table_id ? DiningTable::query()->whereKey($order->dining_table_id)->where('is_active', true)->first() : null;

        return $table ? route('self-order.table', $table->token) : route('self-order.outlet', $outlet->selfOrderToken());
    }

    private function outletOf(Request $request): Outlet
    {
        return $request->attributes->get(IdentifySelfOrderContext::OUTLET);
    }

    private function orderOf(Request $request): SelfOrder
    {
        return $request->attributes->get(IdentifySelfOrderContext::ORDER) ?? abort(404);
    }
}
