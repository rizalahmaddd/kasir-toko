<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SumoPodPaymentService
{
    public const DEFAULT_API_URL = 'https://api-pay.sumopod.com/api/v1/payments';

    public function apiKey(): ?string
    {
        $setting = Setting::platform('sumopod.api_key');

        return filled($setting) ? trim($setting) : config('services.sumopod.api_key');
    }

    public function webhookSecret(): ?string
    {
        $setting = Setting::platform('sumopod.webhook_secret');

        return filled($setting) ? trim($setting) : config('services.sumopod.webhook_secret');
    }

    public function apiUrl(): string
    {
        $setting = Setting::platform('sumopod.api_url');

        return filled($setting) ? trim($setting) : config('services.sumopod.api_url', self::DEFAULT_API_URL);
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    /**
     * Membuat payment link QRIS di SumoPod untuk invoice langganan.
     *
     * @return array{payment_id: string, order_id: string, amount: int, fee: int, net_amount: int, payment_link_url: string, status: string, expires_at: string}
     */
    public function createPayment(SubscriptionInvoice $invoice, ?string $successReturnUrl = null, ?string $cancelReturnUrl = null): array
    {
        $apiKey = $this->apiKey();

        if (blank($apiKey)) {
            throw new RuntimeException('API Key SumoPod belum dikonfigurasi.');
        }

        $successUrl = $successReturnUrl ?: url('/pengaturan/langganan?status=success');
        $cancelUrl = $cancelReturnUrl ?: url('/pengaturan/langganan?status=cancelled');

        // SumoPod validator requires https:// and a valid domain (cannot be plain localhost or 127.0.0.1 HTTP)
        if (str_starts_with($successUrl, 'http://localhost') || str_starts_with($successUrl, 'http://127.0.0.1')) {
            $successUrl = 'https://kasirtoko.com/pengaturan/langganan?status=success';
            $cancelUrl = 'https://kasirtoko.com/pengaturan/langganan?status=cancelled';
        }

        $payload = [
            'order_id' => $invoice->invoice_number,
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'expires_in_hours' => 72, // 3x24 jam
            'success_return_url' => $successUrl,
            'cancel_return_url' => $cancelUrl,
            'payment_method_type_code' => 'QRIS',
        ];

        /** @var Response $response */
        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Api-Key' => $apiKey,
            ])
            ->post($this->apiUrl(), $payload);

        if (! $response->successful()) {
            Log::error('SumoPod Payment Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'invoice' => $invoice->invoice_number,
            ]);

            throw new RuntimeException('Gagal membuat tautan pembayaran QRIS: '.($response->json('message') ?? $response->body()));
        }

        $data = $response->json();

        $invoice->update([
            'payment_gateway_ref' => $data['payment_id'] ?? null,
            'payment_url' => $data['payment_link_url'] ?? null,
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : now()->addHours(72),
            'metadata' => $data,
        ]);

        return $data;
    }

    /**
     * Memverifikasi tanda tangan webhook dari SumoPod (Svix standard HMAC SHA-256).
     */
    public function verifyWebhookSignature(?string $svixId, ?string $svixTimestamp, ?string $svixSignature, string $rawBody): bool
    {
        $secret = $this->webhookSecret();

        if (blank($secret) || blank($svixId) || blank($svixTimestamp) || blank($svixSignature)) {
            return false;
        }

        // Cegah serangan replay: tolak timestamp webhook yang melebihi batas toleransi 5 menit (300 detik)
        if (! app()->environment('testing') && abs(now()->timestamp - (int) $svixTimestamp) > 300) {
            Log::warning('SumoPod Webhook: Svix timestamp expired or outside tolerance window', [
                'svix_id' => $svixId,
                'svix_timestamp' => $svixTimestamp,
            ]);

            return false;
        }

        try {
            // Secret biasanya berawalan 'whsec_' dan di-base64 encode
            $secretBase64 = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
            $secretBytes = base64_decode($secretBase64, true);

            if ($secretBytes === false) {
                // Fallback jika secret disimpan sebagai string mentah
                $secretBytes = $secret;
            }

            $signedContent = "{$svixId}.{$svixTimestamp}.{$rawBody}";
            $computedHash = hash_hmac('sha256', $signedContent, $secretBytes, true);
            $expectedSignature = base64_encode($computedHash);

            // svix-signature header berisi daftar yang dipisah spasi, mis. "v1,signature1 v1,signature2"
            $signatures = array_map(function (string $part) {
                $sub = explode(',', trim($part));

                return $sub[1] ?? $sub[0];
            }, explode(' ', $svixSignature));

            foreach ($signatures as $sig) {
                if (hash_equals($expectedSignature, $sig)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('SumoPod Webhook Signature Verification Exception: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Verifikasi opsional jika menggunakan header x-webhook-token (whtok_...).
     */
    public function verifyWebhookToken(?string $receivedToken): bool
    {
        $secret = $this->webhookSecret();

        if (blank($secret) || blank($receivedToken)) {
            return false;
        }

        return hash_equals($secret, $receivedToken);
    }
}
