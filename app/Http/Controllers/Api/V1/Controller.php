<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

abstract class Controller extends BaseController
{
    protected const MAX_PER_PAGE = 100;

    /**
     * Services report broken business rules (insufficient balance, closed period, wrong status) as
     * RuntimeException/InvalidArgumentException with a user-facing message, the same way the
     * Livewire pages catch them. The API returns those as 422 instead of a 500.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    protected function attempt(Closure $action, string $errorKey = 'message'): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException|RuntimeException $e) {
            if (! $this->isDomainException($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([$errorKey => $e->getMessage()]);
        }
    }

    /**
     * Master data deletion, same checks as the web CRUD pages: the model's own guard first, then
     * the database foreign keys as the last safety net.
     */
    protected function deleteRecord(Model $record): Response
    {
        if (method_exists($record, 'deletionBlockedReason') && ($reason = $record->deletionBlockedReason())) {
            throw ValidationException::withMessages(['message' => $reason]);
        }

        try {
            $record->delete();
        } catch (QueryException) {
            throw ValidationException::withMessages(['message' => __('Data ini masih dipakai di tempat lain, tidak bisa dihapus.')]);
        }

        return response()->noContent();
    }

    /**
     * Services often trust the page to only show a button in the right status, so the API checks
     * it itself; approving the same document twice must not run its side effects twice.
     *
     * @param  list<string>  $statuses
     */
    protected function ensureStatus(Model $record, array $statuses): void
    {
        if (! in_array($record->getAttribute('status'), $statuses, true)) {
            $label = method_exists($record, 'statusLabel') ? $record->statusLabel() : $record->getAttribute('status');

            throw ValidationException::withMessages(['message' => __('Aksi ini tidak bisa dilakukan pada status :status.', ['status' => $label])]);
        }
    }

    protected function created(JsonResource $resource): JsonResponse
    {
        return $resource->response()->setStatusCode(201);
    }

    protected function perPage(Request $request, int $default = 15): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) $request->integer('per_page', $default)));
    }

    /**
     * Framework exceptions such as HttpException and ModelNotFoundException also extend
     * RuntimeException; they must keep their own status code.
     */
    private function isDomainException(\Throwable $e): bool
    {
        return in_array($e::class, [RuntimeException::class, InvalidArgumentException::class], true)
            || str_starts_with($e::class, 'App\\Exceptions\\');
    }
}
