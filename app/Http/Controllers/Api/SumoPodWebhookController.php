<?php

namespace App\Http\Controllers\Api;

use App\Events\SubscriptionPaymentCompleted;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\SumoPodPaymentService;
use App\Services\TenantSubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SumoPodWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        SumoPodPaymentService $paymentService,
        TenantSubscriptionManager $subscriptionManager
    ): JsonResponse {
        $rawBody = $request->getContent();

        $svixId = $request->header('svix-id');
        $svixTimestamp = $request->header('svix-timestamp');
        $svixSignature = $request->header('svix-signature');
        $webhookToken = $request->header('x-webhook-token');

        $hasValidSignature = $paymentService->verifyWebhookSignature($svixId, $svixTimestamp, $svixSignature, $rawBody)
            || $paymentService->verifyWebhookToken($webhookToken);

        // Pada testing/local boleh lewati verifikasi jika secret belum diset
        if (! $hasValidSignature && ! app()->environment('local', 'testing')) {
            Log::warning('SumoPod Webhook: Invalid signature', [
                'ip' => $request->ip(),
                'svix_id' => $svixId,
            ]);

            return response()->json(['message' => 'Invalid webhook signature'], 401);
        }

        $payload = json_decode($rawBody, true) ?: $request->all();
        $eventType = $payload['event_type'] ?? null;
        $data = $payload['data'] ?? [];

        Log::info('SumoPod Webhook Received', [
            'event' => $eventType,
            'order_id' => $data['order_id'] ?? null,
        ]);

        if ($eventType === 'payment.test') {
            return response()->json(['message' => 'Webhook test event acknowledged successfully'], 200);
        }

        $orderId = $data['order_id'] ?? null;

        if (blank($orderId)) {
            return response()->json(['message' => 'Missing order_id'], 400);
        }

        /** @var SubscriptionInvoice|null $invoice */
        $invoice = SubscriptionInvoice::withoutGlobalScopes()
            ->where('invoice_number', $orderId)
            ->first();

        if (! $invoice) {
            Log::warning("SumoPod Webhook: Invoice {$orderId} not found");

            return response()->json(['message' => 'Invoice not found'], 200);
        }

        if ($eventType === 'payment.completed') {
            if ($invoice->isPaid()) {
                return response()->json(['message' => 'Invoice already paid'], 200);
            }

            // Validasi jumlah pembayaran agar tidak terjadi eksploitasi underpayment
            $paidAmount = isset($data['amount']) ? (int) $data['amount'] : null;
            if ($paidAmount !== null && $paidAmount < (int) $invoice->amount) {
                Log::warning('SumoPod Webhook: Underpaid payment attempt', [
                    'order_id' => $orderId,
                    'expected' => $invoice->amount,
                    'received' => $paidAmount,
                ]);

                return response()->json(['message' => 'Paid amount does not match invoice amount'], 400);
            }

            DB::transaction(function () use ($invoice, $data, $subscriptionManager) {
                $invoice->update([
                    'status' => SubscriptionInvoice::STATUS_PAID,
                    'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                    'payment_gateway_ref' => $data['payment_id'] ?? $invoice->payment_gateway_ref,
                    'metadata' => array_merge((array) $invoice->metadata, $data),
                ]);

                $tenant = $invoice->tenant;

                if (! $tenant) {
                    return;
                }

                $months = (int) ($invoice->period_months ?: 1);
                $isLifetime = $invoice->plan === Tenant::PLAN_LIFETIME || $invoice->period_months === 0;

                // Perpanjangan dihitung akumulatif dari akhir masa aktif yang masih berjalan,
                // atau dari hari ini jika masa aktif sudah lewat / belum diatur.
                // Jika paket lifetime / permanen, access_ends_at adalah null.
                $from = $tenant->accessEndsAt()?->isFuture() ? $tenant->accessEndsAt() : now();
                $newEndsAt = $isLifetime ? null : $from->copy()->addMonths($months)->endOfDay();

                $note = $isLifetime
                    ? "Pembayaran paket {$invoice->planLabel()} (Permanen / Seumur Hidup) via SumoPod QRIS"
                    : "Pembayaran paket {$invoice->planLabel()} ({$months} bulan) via SumoPod QRIS";

                $subscriptionManager->update($tenant, [
                    'name' => $tenant->name,
                    'plan' => $invoice->plan,
                    'status' => Tenant::STATUS_ACTIVE,
                    'access_ends_at' => $newEndsAt,
                ], $invoice->amount, $note);

                SubscriptionPaymentCompleted::dispatch($invoice, $tenant, $newEndsAt);
            });

            return response()->json(['message' => 'Payment processed and subscription extended'], 200);
        }

        if ($eventType === 'payment.expired' && $invoice->isPending()) {
            $invoice->update([
                'status' => SubscriptionInvoice::STATUS_EXPIRED,
                'metadata' => array_merge((array) $invoice->metadata, $data),
            ]);

            return response()->json(['message' => 'Invoice marked as expired'], 200);
        }

        if ($eventType === 'payment.failed' && $invoice->isPending()) {
            $invoice->update([
                'status' => SubscriptionInvoice::STATUS_FAILED,
                'metadata' => array_merge((array) $invoice->metadata, $data),
            ]);

            return response()->json(['message' => 'Invoice marked as failed'], 200);
        }

        return response()->json(['message' => 'Event unhandled but acknowledged'], 200);
    }
}
