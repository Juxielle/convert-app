<?php

namespace App\Services\Conversions;

interface BudgetedEngine extends Engine
{
    public function maxOutputBytes(string $path, array $options): int;
}
