<?php

namespace Tests\Support;

use App\Models\ConversionJob;
use App\Services\Conversions\Engine;
use App\Services\Conversions\PipelineException;

// No service-provider registration: this class cannot be selected by a production HTTP request.
class FakeEngine implements Engine
{
    public function __construct(public ?\Closure $duringConvert = null) {}

    public function operationType(): string
    {
        return 'test_pipeline';
    }

    public function inputFormats(): array
    {
        return ['txt' => ['text/plain']];
    }

    public function targetFormats(): array
    {
        return ['txt'];
    }

    public function optionRules(): array
    {
        return [];
    }

    public function inspect(string $path): void
    {
        if (str_contains(file_get_contents($path), 'MALFORMED')) {
            throw new PipelineException('INVALID_FILE');
        }
    }

    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void
    {
        if ($this->duringConvert) {
            ($this->duringConvert)($job, $input, $output, $checkpoint);

            return;
        }
        $checkpoint(50);
        file_put_contents($output, file_get_contents($input));
    }

    public function validateOutput(string $path, string $target): void
    {
        if ((new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'text/plain') {
            throw new PipelineException('INVALID_OUTPUT');
        }
    }
}
