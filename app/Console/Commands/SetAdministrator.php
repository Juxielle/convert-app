<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetAdministrator extends Command
{
    protected $signature = 'accounts:admin {email : Adresse d’un compte existant} {--revoke : Retirer le rôle administrateur}';

    protected $description = 'Attribue ou retire un rôle admin depuis le terminal sécurisé de l’hôte.';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user) {
            $this->error('Compte introuvable. Créez d’abord un compte via l’application.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user): void {
            $user->forceFill(['role' => $this->option('revoke') ? 'user' : 'admin'])->save();
            AuditLog::create(['user_id' => $user->id, 'action' => 'role.cli_updated', 'target' => (string) $user->id,
                'metadata' => ['role' => $user->role, 'via' => 'trusted-cli'], 'created_at' => now()]);
        });
        $this->info('Rôle mis à jour et action journalisée.');

        return self::SUCCESS;
    }
}
