<?php

namespace App\Http\Controllers;

use App\Services\Conversions\DocumentEngine;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\ImageEngine;
use App\Services\Conversions\MediaEngine;
use App\Services\Conversions\PipelineException;
use App\Services\Conversions\RecognitionEngine;
use App\Services\Conversions\RecognitionLanguages;
use App\Services\External\CommonsEngine;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;

class ToolController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->tools()]);
    }

    public function show(string $slug): JsonResponse
    {
        $tool = collect($this->tools())->firstWhere('slug', $slug);
        abort_unless($tool, 404);

        return response()->json(['data' => $tool]);
    }

    public function limits(): JsonResponse
    {
        return response()->json(['data' => [
            'enforced' => true,
            'message' => 'Les quotas sont contrôlés côté serveur.',
            'plans' => ['guest' => app(Settings::class)->limits('guest'), 'registered' => app(Settings::class)->limits('registered')],
        ]]);
    }

    private function tools(): array
    {
        return array_map(function (array $tool): array {
            try {
                $engine = app(EngineRegistry::class)->get($tool['slug']);
            } catch (PipelineException) {
                $engine = null;
            }

            return [...$tool,
                'required_plan' => in_array($tool['slug'], config('billing.advanced_tools'), true) ? 'pro' : null,
                'status' => $engine ? 'available' : ($tool['category'] === 'external' ? 'disabled' : 'planned'),
                'enabled' => $engine !== null,
                'source_formats' => $engine instanceof CommonsEngine ? ['commons-url'] : ($engine ? array_keys($engine->inputFormats()) : []),
                'target_formats' => $engine ? $engine->targetFormats() : [],
                'image_limits' => $engine instanceof ImageEngine ? collect(config('conversion.images'))->except('php_binary')->all() : null,
                'document_limits' => $engine instanceof DocumentEngine ? collect(config('conversion.documents'))->except(['python', 'libreoffice'])->all() : null,
                'media_limits' => $engine instanceof MediaEngine ? collect(config('conversion.media'))->except(['python', 'ffmpeg', 'ffprobe'])->all() : null,
                'recognition_limits' => $engine instanceof RecognitionEngine ? collect(config('recognition'))->except(['python', 'tesseract', 'tessdata', 'whisper', 'model'])->all() : null,
                'recognition_languages' => $engine instanceof RecognitionEngine ? RecognitionLanguages::options($tool['slug']) : null,
            ];
        }, config('conversion.tools'));
    }
}
