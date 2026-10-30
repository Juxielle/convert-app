<?php

namespace App\Services;

use App\Models\ToolSetting;
use Illuminate\Support\Facades\Schema;

class Settings
{
    public function get(string $key, mixed $default): mixed
    {
        // Allows the public catalog to render before the additive migration is run.
        if (! Schema::hasTable('tool_settings')) {
            return $default;
        }

        return ToolSetting::find($key)?->value ?? $default;
    }

    public function toolEnabled(string $tool): bool
    {
        return $this->get('tools.'.$tool.'.enabled', true) === true;
    }

    public function limits(string $role): array
    {
        $limits = config('conversion.limits.'.$role);
        $stored = $this->get('limits.'.$role, []);

        return array_replace($limits, is_array($stored) ? array_intersect_key($stored, $limits) : []);
    }
}
