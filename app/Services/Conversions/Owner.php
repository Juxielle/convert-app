<?php

namespace App\Services\Conversions;

use App\Models\User;
use App\Services\PlanCatalog;
use Illuminate\Http\Request;

final readonly class Owner
{
    public function __construct(public string $key, public ?int $userId, public ?string $sessionHash) {}

    public static function fromRequest(Request $request): self
    {
        if ($request->user()) {
            return new self(hash('sha256', 'user:'.$request->user()->getAuthIdentifier()), (int) $request->user()->getAuthIdentifier(), null);
        }
        $secret = $request->session()->get('conversion_owner');
        if (! is_string($secret) || strlen($secret) !== 64) {
            $secret = bin2hex(random_bytes(32));
            $request->session()->put('conversion_owner', $secret);
        }
        $hash = hash('sha256', $secret);

        return new self(hash('sha256', 'guest:'.$hash), null, $hash);
    }

    public function limits(): array
    {
        return app(PlanCatalog::class)->limits($this->userId ? User::find($this->userId) : null);
    }

    public function authorizeTool(string $tool): void
    {
        app(PlanCatalog::class)->authorizeTool($this->userId ? User::find($this->userId) : null, $tool);
    }

    public function entitlements(): array
    {
        return app(PlanCatalog::class)->plan($this->userId ? User::find($this->userId) : null)->entitlements ?? [];
    }
}
