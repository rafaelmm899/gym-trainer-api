<?php

namespace App\Exceptions;

use App\Enums\Shared\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps any thrown {@see Throwable} to the API's normalized error envelope
 * — `{ "data": { "code": string, "message": string, "errors"?: array } }`.
 *
 * Every response body in this API is wrapped in a top-level `data` key: success
 * bodies through JSON Resources, errors through here. There is no unwrapped
 * path — an unrecognised throwable still yields the envelope; with `app.debug`
 * on it carries `exception` / `file` / `line` / `trace` alongside `message`.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => $this->envelope(
                ErrorCode::Validation,
                $e->getMessage(),
                $e->status,
                ['errors' => $e->errors()],
            ),
            $e instanceof AuthenticationException => $this->envelope(
                ErrorCode::Authentication,
                $e->getMessage(),
                Response::HTTP_UNAUTHORIZED,
            ),
            $e instanceof AuthorizationException => $this->envelope(
                ErrorCode::Authorization,
                $e->getMessage() ?: 'This action is unauthorized.',
                Response::HTTP_FORBIDDEN,
            ),
            $e instanceof ModelNotFoundException => $this->envelope(
                ErrorCode::NotFound,
                'Resource not found.',
                Response::HTTP_NOT_FOUND,
            ),
            $e instanceof TokenMismatchException => $this->envelope(
                ErrorCode::CsrfTokenMismatch,
                $e->getMessage() ?: 'CSRF token mismatch.',
                419,
            ),
            $e instanceof ThrottleRequestsException => $this->envelope(
                ErrorCode::RateLimit,
                $e->getMessage() ?: 'Too many requests.',
                Response::HTTP_TOO_MANY_REQUESTS,
                headers: $e->getHeaders(),
            ),
            $e instanceof DomainException => $this->envelope(
                $e->errorCode(),
                $e->getMessage(),
                $e->statusCode(),
            ),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),
            default => $this->fromUnhandled($e),
        };
    }

    private function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $message = $status === Response::HTTP_NOT_FOUND
            ? 'Resource not found.'
            : ($e->getMessage() ?: 'HTTP error.');

        return $this->envelope(
            ErrorCode::fromHttpStatus($status),
            $message,
            $status,
            headers: $e->getHeaders(),
        );
    }

    private function fromUnhandled(Throwable $e): JsonResponse
    {
        if (! (bool) config('app.debug')) {
            return $this->envelope(ErrorCode::Server, 'Server error.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->envelope(
            ErrorCode::Server,
            $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_map(
                    static fn (array $frame): array => Arr::except($frame, ['args']),
                    $e->getTrace(),
                ),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    private function envelope(ErrorCode|string $code, string $message, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['data' => array_merge([
                'code' => $code instanceof ErrorCode ? $code->value : $code,
                'message' => $message,
            ], $extra)],
            $status,
            $headers,
        );
    }
}
