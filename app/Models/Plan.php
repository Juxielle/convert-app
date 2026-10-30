<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'name', 'description', 'active', 'currency', 'monthly_price', 'yearly_price', 'billing_periods', 'features', 'quotas', 'allowed_tools', 'display_order', 'trial_days', 'trial_enabled', 'entitlements'])]
class Plan extends Model
{
    protected function casts(): array
    {
        return ['is_free' => 'boolean', 'active' => 'boolean', 'monthly_price' => 'integer', 'yearly_price' => 'integer', 'billing_periods' => 'array', 'features' => 'array', 'quotas' => 'array', 'allowed_tools' => 'array', 'trial_enabled' => 'boolean', 'trial_days' => 'integer', 'entitlements' => 'array'];
    }
}
