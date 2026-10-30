<?php

namespace Tests\Support;

use App\Services\Conversions\Engine;
use App\Services\Conversions\EngineRegistry;

class FakeEngineRegistry extends EngineRegistry
{
    public function __construct(public Engine $engine = new FakeEngine) {}

    public function get(string $tool): Engine
    {
        return $tool === 'pipeline-test' ? $this->engine : parent::get($tool);
    }
}
