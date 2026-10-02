<?php

namespace App\Http\Controllers;

use App\Events\CustomerDisplayUpdated;
use App\Models\User;
use App\Support\Branding;
use App\Support\CurrentTenant;
use App\Support\CustomerDisplaySettings;
use App\Support\Features;
use App\Support\PosSettings;
use App\Support\Qris;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Layar pelanggan: dibuka tanpa login di perangkat yang menghadap pembeli, dikenali lewat kunci
 * rahasia per kasir. Kasir mengirim keadaan keranjang ke push(); layar membacanya lewat state(),
 * dipicu sinyal Reverb atau polling kalau Reverb tidak tersambung.
 */
class CustomerDisplayController extends Controller
{
    private const STAGES = ['idle', 'cart', 'payment', 'qris', 'done'];

    public static function cacheKey(User $user): string
    {
        return "customer-display:{$user->id}";
    }

    public function show(string $key): View
    {
        $user = $this->resolve($key);

        return view('display.show', [
            'config' => [
                'key' => $key,
                'userId' => $user->id,
                'stateUrl' => route('display.state', $key),
                'qrisUrl' => route('display.qris', $key),
                'hasQris' => PosSettings::qrisPayload() !== null,
                'merchant' => ($payload = PosSettings::qrisPayload()) ? Qris::merchant($payload)['name'] : null,
                'storeName' => Branding::companyName(),
                'logo' => Branding::logoUrl(),
                ...CustomerDisplaySettings::forScreen(),
            ],
        ]);
    }

    public function state(string $key): JsonResponse
    {
        $user = $this->resolve($key);

        return response()->json(Cache::get(self::cacheKey($user), ['stage' => 'idle', 'seq' => 0]))
            ->header('Cache-Control', 'no-store');
    }

    public function qris(Request $request, string $key): Response
    {
        $this->resolve($key);

        return self::qrisResponse($request);
    }

    /**
     * QR dinamis untuk nominal di query string. Dipakai layar pelanggan dan modal bayar kasir.
     */
    public static function qrisResponse(Request $request): Response
    {
        $amount = (int) $request->query('amount');
        $payload = PosSettings::qrisPayload();

        abort_unless($payload !== null && $amount > 0 && $amount <= 999_999_999_999, 404);

        return response(Qris::svg(Qris::withAmount($payload, $amount)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function push(Request $request): Response
    {
        abort_unless(CustomerDisplaySettings::enabled() && Features::enabled('pos.customer-display'), 404);

        $user = $request->user();
        $state = $this->normalize($request->all());

        Cache::put(self::cacheKey($user), $state, now()->addHours(12));

        if ($user->display_key) {
            CustomerDisplayUpdated::dispatch($user->display_key, $state['seq']);
        }

        return response()->noContent();
    }

    /**
     * Layar dibuka tanpa login, jadi tenant diaktifkan dari pemilik kunci sebelum fitur dan
     * pengaturan layar (milik toko itu) dibaca.
     */
    private function resolve(string $key): User
    {
        abort_unless(strlen($key) === 40, 404);

        $user = User::query()->where('display_key', $key)->firstOrFail();
        app(CurrentTenant::class)->set($user->tenant_id);

        abort_unless(Features::enabled('pos.customer-display') && CustomerDisplaySettings::enabled(), 404);

        return $user;
    }

    /**
     * Hanya bidang yang dipakai layar, dengan batas panjang, supaya isi cache tidak bisa dibengkakkan.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $money = fn (mixed $value): int => max(0, min(999_999_999_999, (int) $value));
        $text = fn (mixed $value, int $max = 150): ?string => is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
        $stage = in_array($input['stage'] ?? null, self::STAGES, true) ? $input['stage'] : 'idle';

        $items = collect(is_array($input['items'] ?? null) ? $input['items'] : [])
            ->filter(fn ($item) => is_array($item))
            ->take(-60)
            ->map(fn (array $item) => [
                'name' => $text($item['name'] ?? '') ?? '-',
                'qty' => round(max(0, min(99999, (float) ($item['qty'] ?? 0))), 3),
                'unit' => $text($item['unit'] ?? '', 20),
                'price' => $money($item['price'] ?? 0),
                'discount' => $money($item['discount'] ?? 0),
                'total' => $money($item['total'] ?? 0),
                'note' => $text($item['note'] ?? null),
            ])
            ->values()
            ->all();

        $section = fn (string $name, array $fields) => is_array($input[$name] ?? null)
            ? collect(Arr::only($input[$name], $fields))->map(fn ($value, $field) => in_array($field, ['method', 'number'], true) ? $text($value, 30) : (is_bool($value) ? $value : $money($value)))->all()
            : null;

        return [
            'seq' => max(0, min(PHP_INT_MAX, (int) ($input['seq'] ?? 0))),
            'stage' => $stage,
            'customer' => $text($input['customer'] ?? null, 80),
            'items' => $items,
            'count' => round(max(0, (float) ($input['count'] ?? 0)), 3),
            'lines' => max(0, (int) ($input['lines'] ?? count($items))),
            'subtotal' => $money($input['subtotal'] ?? 0),
            'discount' => $money($input['discount'] ?? 0),
            'tax' => $money($input['tax'] ?? 0),
            'taxLabel' => $text($input['taxLabel'] ?? null, 30),
            'total' => $money($input['total'] ?? 0),
            'payment' => $section('payment', ['method', 'received', 'change', 'shortfall', 'credit']),
            'qris' => $section('qris', ['amount']),
            'done' => $section('done', ['number', 'total', 'change', 'due']),
        ];
    }
}
