<?php

namespace App\Exceptions;

/**
 * A `DomainException` that carries field-level validation errors — content a
 * Form Request cannot check because it depends on data only a Service loads
 * (e.g. matching an uploaded row against records the caller doesn't control).
 * `ApiExceptionRenderer` renders any exception carrying this interface with
 * the same `VALIDATION_EXCEPTION` code and `{field: [msg]}` `data.errors` map
 * as a Form Request failure — this genuinely is validation, just validation
 * that cannot happen at the Form Request layer.
 */
interface CarriesValidationErrors
{
    /**
     * @return array<string, list<string>>
     */
    public function errors(): array;
}
