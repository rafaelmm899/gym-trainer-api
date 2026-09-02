<?php

namespace App\Enums\Shared;

use Symfony\Component\HttpFoundation\Response;

/**
 * The `code` of an API error envelope for a framework-level failure. A
 * business-rule violation carries its own identifier from its DomainException
 * subclass instead.
 */
enum ErrorCode: string
{
    case Validation = 'VALIDATION_EXCEPTION';
    case Authentication = 'AUTHENTICATION_EXCEPTION';
    case Authorization = 'AUTHORIZATION_EXCEPTION';
    case NotFound = 'NOT_FOUND_EXCEPTION';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED_EXCEPTION';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case RateLimit = 'RATE_LIMIT_EXCEPTION';
    case Http = 'HTTP_EXCEPTION';
    case Domain = 'DOMAIN_EXCEPTION';
    case Server = 'SERVER_EXCEPTION';

    /**
     * Category for an `HttpExceptionInterface` that no dedicated branch claimed
     * (a bare `abort($status)` included).
     */
    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            Response::HTTP_FORBIDDEN => self::Authorization,
            Response::HTTP_NOT_FOUND => self::NotFound,
            Response::HTTP_METHOD_NOT_ALLOWED => self::MethodNotAllowed,
            419 => self::CsrfTokenMismatch,
            Response::HTTP_TOO_MANY_REQUESTS => self::RateLimit,
            default => self::Http,
        };
    }
}
