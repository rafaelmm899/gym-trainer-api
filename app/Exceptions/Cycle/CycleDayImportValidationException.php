<?php

namespace App\Exceptions\Cycle;

use App\Enums\Shared\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The uploaded day-import workbook has one or more invalid rows, or could not
 * be read as a spreadsheet at all. Thrown from `CycleDayImportService`, which
 * cannot express this as a Form Request rule because matching a row's
 * `exercise` cell against the day's own `day_exercises` needs data only the
 * Service has loaded. `ApiExceptionRenderer` gives it the same
 * `VALIDATION_EXCEPTION` / `data.errors` shape as a Form Request failure —
 * this genuinely is validation, just validation that cannot happen at the
 * Form Request layer.
 */
final class CycleDayImportValidationException extends DomainException
{
    protected string $errorCode = ErrorCode::Validation->value;

    protected int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The uploaded file is invalid.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
