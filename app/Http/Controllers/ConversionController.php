<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversionRequest;
use App\Http\Resources\ConversionJobResource;
use App\Models\ConversionJob;
use App\Services\Conversions\Owner;
use App\Services\Conversions\Pipeline;
use App\Services\Conversions\PipelineException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ConversionController extends Controller
{
    public function store(StoreConversionRequest $request, Pipeline $pipeline)
    {
        $validated = $request->validated();
        if ($validated['tool'] === 'fusionner-pdf') {
            $job = $pipeline->admitMerge(Owner::fromRequest($request), $request->file('files'), $request->engine);

            return ConversionJobResource::collection(collect([$job]))->response()->setStatusCode(202);
        }
        if ($request->hasFile('files')) {
            $jobs = $pipeline->admitBatch(Owner::fromRequest($request), $request->file('files'), $validated['tool'],
                $validated['target_format'], $validated['options'] ?? [], $request->engine);

            return ConversionJobResource::collection(collect($jobs))->response()->setStatusCode(202);
        }
        $job = $pipeline->admit(Owner::fromRequest($request), $request->file('file'), $validated['tool'],
            $validated['target_format'], $validated['options'] ?? [], $request->engine);

        return (new ConversionJobResource($job))->response()->setStatusCode(202);
    }

    public function index(Request $request)
    {
        $owner = Owner::fromRequest($request);
        $jobs = ConversionJob::where('owner_key', $owner->key)
            ->whereNull('delete_requested_at')->where('created_at', '>=', now()->subHours($owner->limits()['history_hours'] ?? 24))
            ->where(fn ($query) => $query->whereNull('history_expires_at')->orWhere('history_expires_at', '>', now()))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return ConversionJobResource::collection($jobs);
    }

    public function show(ConversionJob $conversion)
    {
        Gate::authorize('access', $conversion);

        return new ConversionJobResource($conversion);
    }

    public function usage(Request $request, Pipeline $pipeline)
    {
        $owner = Owner::fromRequest($request);

        $limits = $owner->limits();
        $used = $pipeline->usage($owner);

        return response()->json(['data' => ['limits' => $limits, 'used' => $used, 'quota_used' => isset($limits['monthly_jobs']) ? $used['monthly_jobs'] : $used['daily_jobs'], 'entitlements' => $owner->entitlements(), 'external_used' => $pipeline->usage($owner, true), 'window' => 'rolling']]);
    }

    public function cancel(ConversionJob $conversion, Pipeline $pipeline)
    {
        Gate::authorize('access', $conversion);

        return (new ConversionJobResource($pipeline->cancel($conversion)))->response()->setStatusCode(202);
    }

    public function destroy(ConversionJob $conversion, Pipeline $pipeline)
    {
        Gate::authorize('access', $conversion);
        $result = $pipeline->cancel($conversion, true);

        return response()->json(['data' => ['deleted' => ! $result->exists, 'pending' => $result->exists]], $result->exists ? 202 : 200);
    }

    public function download(ConversionJob $conversion, Pipeline $pipeline)
    {
        Gate::authorize('access', $conversion);
        // Read into an open handle while locked: cleanup cannot unlink before we acquire the file.
        $result = $pipeline->lock->run(function () use ($conversion) {
            $conversion->refresh();
            if ($conversion->status !== 'completed' || $conversion->expires_at->isPast() || $conversion->delete_requested_at || ! $conversion->output_path) {
                throw new PipelineException('RESULT_UNAVAILABLE', 404);
            }
            try {
                $stream = Storage::disk(config('conversion.disk'))->readStream($conversion->output_path);
            } catch (\Throwable) {
                throw new PipelineException('RESULT_UNAVAILABLE', 404);
            }
            if (! is_resource($stream)) {
                throw new PipelineException('RESULT_UNAVAILABLE', 404);
            }

            return [$stream, pathinfo($conversion->original_name, PATHINFO_FILENAME).'.'.$conversion->target_format];
        });

        return response()->streamDownload(function () use ($result) {
            try {
                fpassthru($result[0]);
            } finally {
                fclose($result[0]);
            }
        }, $result[1], ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
