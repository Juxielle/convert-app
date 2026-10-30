<?php

namespace App\Services\Conversions;

use App\Models\ConversionJob;

interface Engine
{
    public function operationType(): string;

    /** Extension => detected MIME allowlist; never trust the client MIME. */
    public function inputFormats(): array;

    public function targetFormats(): array;

    /** Flat validated option keys and their Laravel validation rules. */
    public function optionRules(): array;

    /** Implementations must bound parsing complexity before conversion. */
    public function inspect(string $path): void;

    /** Write only to $output. Call $checkpoint regularly; it throws on cancellation/timeout. */
    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void;

    /** Must validate the actual output structure and format. */
    public function validateOutput(string $path, string $target): void;
}
