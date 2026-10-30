<?php

namespace App\Services\Conversions;

use RuntimeException;

class PipelineException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($errorCode);
    }
}
