<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminSettingsRequest;
use App\Models\AuditLog;
use App\Models\ConversionJob;
use App\Models\ToolSetting;
use App\Services\Conversions\PipelineLock;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        $recent = ConversionJob::where('created_at', '>=', now()->subDay());
        $total = (clone $recent)->count();
        $failed = (clone $recent)->where('status', 'failed')->count();
        $durations = (clone $recent)->whereNotNull('started_at')->whereNotNull('completed_at')->latest()->limit(1000)->get(['started_at', 'completed_at']);

        return response()->json(['data' => [
            'states' => ConversionJob::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status'),
            'jobs_24h' => $total, 'failure_rate_24h' => $total ? round($failed / $total * 100, 1) : 0,
            'mean_processing_seconds' => $durations->count() ? round($durations->avg(fn ($job) => max(0, $job->started_at->diffInSeconds($job->completed_at))), 2) : null,
            'duration_sample_size' => $durations->count(), 'reserved_bytes' => (int) ConversionJob::sum('reserved_bytes'),
            'free_disk_bytes' => @disk_free_space(storage_path('app')) ?: null,
            'worker_last_seen' => Cache::get('conversion-worker-heartbeat'),
            'external_worker_last_seen' => Cache::get('external-worker-heartbeat'),
            'memory_php_bytes' => memory_get_usage(true),
            'memory_note' => 'Mémoire du processus PHP de cette requête ; la RAM totale des workers n’est pas mesurée ici.',
        ]]);
    }

    public function jobs(): JsonResponse
    {
        // Metadata only: no paths, names, owner hashes, payloads, URLs or download privilege.
        $jobs = ConversionJob::latest()->paginate(20, ['id', 'user_id', 'tool', 'status', 'error_code', 'input_size', 'output_size', 'created_at', 'started_at', 'completed_at', 'expires_at']);

        return response()->json($jobs);
    }

    public function settings(Settings $settings): JsonResponse
    {
        $tools = [];
        foreach (config('conversion.tools') as $tool) {
            $tools[$tool['slug']] = $settings->toolEnabled($tool['slug']);
        }

        return response()->json(['data' => ['limits' => ['guest' => $settings->limits('guest'), 'registered' => $settings->limits('registered')], 'tools' => $tools]]);
    }

    public function update(AdminSettingsRequest $request, PipelineLock $lock, Settings $settings): JsonResponse
    {
        $lock->run(function () use ($request, $settings): void {
            foreach ($request->validated('limits', []) as $role => $changes) {
                $values = array_replace($settings->limits($role), $changes);
                if ($values['hourly_jobs'] > $values['daily_jobs'] || $values['storage_bytes'] < $values['max_file_bytes']) {
                    throw ValidationException::withMessages(['limits.'.$role => ['Le quota journalier doit couvrir le quota horaire et le stockage doit couvrir un fichier.']]);
                }
                ToolSetting::updateOrCreate(['key' => 'limits.'.$role], ['value' => $values]);
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'limits.updated', 'target' => $role, 'metadata' => $changes, 'created_at' => now()]);
            }
            foreach ($request->validated('tools', []) as $tool => $enabled) {
                ToolSetting::updateOrCreate(['key' => 'tools.'.$tool.'.enabled'], ['value' => (bool) $enabled]);
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'tool.updated', 'target' => $tool, 'metadata' => ['enabled' => (bool) $enabled], 'created_at' => now()]);
            }
        });

        return $this->settings($settings);
    }

    public function audit(): JsonResponse
    {
        return response()->json(AuditLog::latest('id')->paginate(20));
    }
}
