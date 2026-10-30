<?php

namespace App\Http\Requests;

use App\Services\Conversions\DocumentEngine;
use App\Services\Conversions\Engine;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\ImageEngine;
use App\Services\Conversions\MediaEngine;
use App\Services\Conversions\Owner;
use App\Services\Conversions\PipelineException;
use App\Services\Conversions\RecognitionEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreConversionRequest extends FormRequest
{
    public Engine $engine;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(EngineRegistry $registry): array
    {
        $tool = $this->input('tool');
        if (! is_string($tool) || strlen($tool) > 80 || $tool === 'telechargement-autorise') {
            throw new PipelineException('TOOL_UNAVAILABLE');
        }
        $this->engine = $registry->get($tool);
        $options = $this->engine->optionRules();

        return [
            'tool' => ['required', 'string', 'max:80'],
            'file' => $tool === 'fusionner-pdf' ? ['prohibited'] : ['required_without:files', 'prohibits:files', 'file'],
            'files' => $this->engine instanceof MediaEngine || $this->engine instanceof RecognitionEngine ? ['prohibited'] : ['required_without:file', 'prohibits:file', 'array', 'min:'.($tool === 'fusionner-pdf' ? 2 : 1), 'max:'.config('conversion.images.max_batch_files')],
            'files.*' => ['required', 'file'],
            'target_format' => ['required', 'string', 'regex:/^[a-z0-9]{1,12}$/', Rule::in($this->engine->targetFormats())],
            'options' => count($options) ? ['sometimes', 'array:'.implode(',', array_keys($options))] : ['sometimes', 'array', 'size:0'],
            ...collect($options)->mapWithKeys(fn ($rules, $key) => ['options.'.$key => $rules])->all(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->file('files') ?? [$this->file('file')] as $file) {
                $size = filesize($file->getPathname());
                if ($size > Owner::fromRequest($this)->limits()['max_file_bytes']) {
                    throw new PipelineException('FILE_TOO_LARGE', 413);
                }
                if ($size < 1) {
                    $validator->errors()->add('file', 'Le fichier est vide.');

                    return;
                }
                $extension = strtolower($file->getClientOriginalExtension());
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
                if (! in_array($mime, $this->engine->inputFormats()[$extension] ?? [], true)) {
                    $validator->errors()->add('file', 'Le contenu et le format du fichier ne sont pas compatibles.');
                } elseif ($this->engine instanceof ImageEngine || $this->engine instanceof DocumentEngine || $this->engine instanceof MediaEngine || $this->engine instanceof RecognitionEngine) {
                    $this->engine->inspect($file->getPathname());
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'required_without' => 'Sélectionnez au moins un fichier.',
            'prohibits' => 'Envoyez un fichier ou un lot, pas les deux.',
            'files.max' => 'Le lot dépasse le nombre de fichiers autorisé.',
            'integer' => 'Un nombre entier est attendu.',
            'between' => 'La qualité doit être comprise entre 1 et 100.',
            'min' => 'La valeur est inférieure à la limite autorisée.',
            'max' => 'La valeur dépasse la limite autorisée.',
            'file.file' => 'Le fichier n’a pas pu être envoyé.',
            'target_format.in' => 'Ce format de sortie n’est pas pris en charge.',
            'target_format.regex' => 'Le format de sortie est invalide.',
            'options.array' => 'Ces options ne sont pas autorisées.',
            'options.size' => 'Cet outil n’accepte aucune option.',
            'string' => 'Une valeur textuelle est attendue.',
        ];
    }
}
