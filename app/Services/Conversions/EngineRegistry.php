<?php

namespace App\Services\Conversions;

use App\Services\External\CommonsEngine;
use App\Services\External\SourceRegistry;
use App\Services\Settings;

class EngineRegistry
{
    public function get(string $tool): Engine
    {
        if (! app(Settings::class)->toolEnabled($tool)) {
            throw new PipelineException('TOOL_UNAVAILABLE');
        }
        if ($tool === 'supprimer-fond' && BackgroundEngine::available()) {
            return new BackgroundEngine;
        }
        if (in_array($tool, RecognitionEngine::TOOLS, true) && RecognitionEngine::available($tool)) {
            return new RecognitionEngine($tool);
        }
        if ($tool === 'telechargement-autorise' && app(SourceRegistry::class)->enabled()) {
            return new CommonsEngine;
        }
        if (in_array($tool, MediaEngine::TOOLS, true) && MediaEngine::available($tool)) {
            return new MediaEngine($tool);
        }
        if (in_array($tool, DocumentEngine::TOOLS, true) && DocumentEngine::available($tool)) {
            return new DocumentEngine($tool);
        }
        $operations = ['convertir-image' => 'convert', 'compresser-image' => 'compress', 'redimensionner-image' => 'resize'];
        if (isset($operations[$tool]) && ImageEngine::available()) {
            return new ImageEngine($operations[$tool]);
        }
        throw new PipelineException('TOOL_UNAVAILABLE');
    }
}
