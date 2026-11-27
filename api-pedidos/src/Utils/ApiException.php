<?php

declare(strict_types=1);

namespace App\Utils;

use RuntimeException;
use Throwable;

final class ApiException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $errorCode,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            0,
            $previous
        );
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}