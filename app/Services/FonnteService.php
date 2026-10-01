<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FonnteService
{
    public function __construct(
        protected ?string $token = null,
        protected ?string $url = null
    ) {
        $this->token = $token ?? (string) (config('services.fonnte.token') ?: (app()->environment('testing') ? 'testing-token' : ''));
        $this->url = $url ?? (string) config('services.fonnte.url', 'https://api.fonnte.com/send');
    }

    /**
     * Kirim pesan WhatsApp menggunakan API Fonnte.
     *
     * @return array{status: bool, message: string, raw?: array<string, mixed>}
     */
    public function send(string $target, string $message): array
    {
        if (blank($this->token)) {
            Log::warning('Fonnte token belum dikonfigurasi di .env (FONNTE_TOKEN). Pesan WhatsApp tidak dikirim.', [
                'target' => $target,
            ]);

            return [
                'status' => false,
                'message' => 'Token Fonnte belum dikonfigurasi di file .env (FONNTE_TOKEN). Silakan isi token Fonnte Anda terlebih dahulu.',
            ];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => $this->token,
                ])
                ->asForm()
                ->post($this->url, [
                    'target' => $target,
                    'message' => $message,
                    'countryCode' => '62',
                ]);

            $result = $response->json();

            if ($response->successful() && is_array($result) && ($result['status'] ?? false)) {
                return [
                    'status' => true,
                    'message' => 'Pesan WhatsApp berhasil dikirim.',
                    'raw' => $result,
                ];
            }

            $reason = is_array($result) ? ($result['reason'] ?? 'Gagal dari API Fonnte') : $response->body();
            Log::warning('Fonnte API response gagal: '.$reason, [
                'target' => $target,
                'status_code' => $response->status(),
            ]);

            return [
                'status' => false,
                'message' => 'Gagal mengirim pesan WhatsApp: '.$reason,
                'raw' => is_array($result) ? $result : [],
            ];
        } catch (\Throwable $e) {
            Log::error('Fonnte API exception: '.$e->getMessage(), [
                'target' => $target,
                'exception' => $e,
            ]);

            return [
                'status' => false,
                'message' => 'Koneksi ke gateway WhatsApp gagal: '.$e->getMessage(),
            ];
        }
    }
}
