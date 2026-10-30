<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversionJobResource;
use App\Services\Conversions\Owner;
use App\Services\Conversions\Pipeline;
use App\Services\External\CommonsEngine;
use App\Services\External\CommonsSource;
use App\Services\External\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class ExternalController extends Controller
{
    public function sources(SourceRegistry $registry, Request $request): JsonResponse
    {
        $limits = collect(config('external'))->only(['hourly_jobs', 'daily_jobs', 'max_bytes', 'timeout_seconds'])->all();
        $role = Owner::fromRequest($request)->limits();
        foreach (['hourly_jobs', 'daily_jobs', 'timeout_seconds'] as $key) {
            $limits[$key] = min($limits[$key], $role[$key]);
        }
        $limits['max_bytes'] = min($limits['max_bytes'], $role['max_file_bytes']);

        return response()->json(['data' => ['enabled' => $registry->enabled(), 'sources' => $registry->approved(),
            'limits' => $limits,
            'message' => $registry->enabled() ? 'Wikimedia Commons : fichiers WebM CC0 1.0 uniquement.' : 'Le téléchargement externe est désactivé ou sa vérification est nécessaire.']]);
    }

    public function store(Request $request, SourceRegistry $registry, Pipeline $pipeline)
    {
        if ($registry->enabled()) {
            $values = $request->validate(['url' => ['required', 'string', 'max:1200'], 'rights_confirmed' => ['required', 'accepted']]);
            $title = app(CommonsSource::class)->title($values['url']);
            $temporary = tmpfile();
            try {
                fwrite($temporary, json_encode(['title' => $title], JSON_THROW_ON_ERROR));
                $file = new UploadedFile(stream_get_meta_data($temporary)['uri'], pathinfo(substr($title, 5), PATHINFO_FILENAME).'.json', 'application/json', null, true);
                $job = $pipeline->admit(Owner::fromRequest($request), $file, 'telechargement-autorise', 'webm', [], new CommonsEngine);

                return (new ConversionJobResource($job))->response()->setStatusCode(202);
            } finally {
                fclose($temporary);
            }
        }

        // Do not parse, resolve, persist or log an untrusted URL while no source is approved.
        return response()->json(['error' => ['code' => 'EXTERNAL_DISABLED', 'message' => 'Le téléchargement externe est désactivé. Aucune source n’est autorisée.']], 503);
    }
}
