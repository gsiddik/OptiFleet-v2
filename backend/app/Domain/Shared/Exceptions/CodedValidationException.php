<?php

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\Support\Messages;
use Illuminate\Validation\ValidationException;

/**
 * A validation error that also carries a machine-readable code per field (i18n structural preparation):
 * the response keeps the usual `message` / `errors` text and adds `codes` ({field: {code, params}}, code =
 * EN-ID dataset key), so a client branches on the code — never on the English text.
 */
class CodedValidationException extends ValidationException
{
    /** @var array<string, array{code: string, params: array}> */
    private array $codes = [];

    /** @param  array<string, scalar|array|null>  $params */
    public static function forField(string $field, string $key, array $params = []): static
    {
        $exception = static::withMessages([$field => Messages::text($key, $params)]);
        $exception->codes[$field] = Messages::make($key, $params);

        return $exception;
    }

    /** @return array<string, array{code: string, params: array}> */
    public function codes(): array
    {
        return $this->codes;
    }
}
