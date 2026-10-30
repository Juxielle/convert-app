<?php

namespace App\Services\Conversions;

use App\Models\ConversionJob;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DocumentEngine implements BudgetedEngine
{
    public const TOOLS = ['office-vers-pdf', 'pdf-vers-images', 'fusionner-pdf', 'diviser-pdf', 'tourner-pdf', 'compresser-pdf', 'csv-vers-xlsx', 'xlsx-vers-csv'];

    public function __construct(public string $tool) {}

    public static function available(string $tool): bool
    {
        $verified = app()->environment('testing') && config('conversion.documents.testing_ready');
        if (! $verified && is_file(base_path('.tools/documents-ready.json'))) {
            $proof = json_decode(file_get_contents(base_path('.tools/documents-ready.json')), true);
            $verified = is_array($proof) && in_array($tool, $proof['tools'] ?? [], true)
                && ($proof['worker_hash'] ?? '') === hash_file('sha256', base_path('bin/document-worker.py'))
                && ($proof['requirements_hash'] ?? '') === hash_file('sha256', base_path('requirements-documents.lock.txt'))
                && ($proof['python'] ?? '') === config('conversion.documents.python')
                && ($proof['office'] ?? '') === config('conversion.documents.libreoffice');
        }

        return config('conversion.documents.enabled') && is_file(config('conversion.documents.python'))
            && $verified && function_exists('proc_open')
            && ($tool !== 'office-vers-pdf' || is_file(config('conversion.documents.libreoffice')));
    }

    public function operationType(): string
    {
        return in_array($this->tool, ['csv-vers-xlsx', 'xlsx-vers-csv', 'office-vers-pdf'], true) ? 'document' : 'pdf';
    }

    public function inputFormats(): array
    {
        return match ($this->tool) {
            'office-vers-pdf' => ['docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation']],
            'csv-vers-xlsx' => ['csv' => ['text/plain', 'text/csv', 'application/csv']],
            'xlsx-vers-csv' => ['xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']],
            default => ['pdf' => ['application/pdf']],
        };
    }

    public function targetFormats(): array
    {
        return [match ($this->tool) {
            'csv-vers-xlsx' => 'xlsx', 'xlsx-vers-csv' => 'csv', 'pdf-vers-images' => 'zip', default => 'pdf'
        }];
    }

    public function optionRules(): array
    {
        return match ($this->tool) {
            'diviser-pdf' => ['first_page' => ['required', 'integer', 'min:1', 'max:'.config('conversion.documents.max_pages')], 'last_page' => ['required', 'integer', 'gte:options.first_page', 'max:'.config('conversion.documents.max_pages')]],
            'tourner-pdf' => ['angle' => ['required', 'integer', 'in:90,180,270']],
            'pdf-vers-images' => ['dpi' => ['sometimes', 'integer', 'in:72,96,150']],
            'xlsx-vers-csv' => ['sheet' => ['sometimes', 'integer', 'between:1,20']],
            default => [],
        };
    }

    public function inspect(string $path): void
    {
        if (filesize($path) > config('conversion.documents.max_input_bytes')) {
            throw new PipelineException('FILE_TOO_LARGE', 413);
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        // Uploaded temporary names have no reliable suffix; content inspection must work independently.
        $stream = fopen($path, 'rb');
        $signature = fread($stream, 8);
        fclose($stream);
        if (str_starts_with($signature, 'PK')) {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true || $zip->numFiles > config('conversion.documents.max_zip_entries')) {
                throw new PipelineException('INVALID_DOCUMENT');
            }
            try {
                $size = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->statIndex($i);
                    $size += $entry['size'];
                    if ($size > config('conversion.documents.max_unpacked_bytes') || $entry['size'] > max(1, $entry['comp_size']) * 200) {
                        throw new PipelineException('DOCUMENT_TOO_COMPLEX');
                    }
                    if (($entry['encryption_method'] ?? 0) !== 0) {
                        throw new PipelineException('PROTECTED_DOCUMENT');
                    }
                    if (preg_match('~(^/|\.\.|:|\\\\|\.(exe|vbs|js)$)~i', $entry['name'])
                        || (str_ends_with(strtolower($entry['name']), '.bin') && (! str_contains(strtolower($entry['name']), '/printersettings/') || $entry['size'] > 65536))) {
                        throw new PipelineException('UNSAFE_DOCUMENT');
                    }
                }
                if ($this->tool !== 'fusionner-pdf' && $zip->locateName('[Content_Types].xml') === false) {
                    throw new PipelineException('INVALID_DOCUMENT');
                }
            } finally {
                $zip->close();
            }
        } elseif (in_array($this->tool, ['csv-vers-xlsx'], true)) {
            // CSV content/row validation happens in the isolated worker.
        } elseif (! str_starts_with($signature, '%PDF-')) {
            throw new PipelineException('INVALID_DOCUMENT');
        }
    }

    public function maxOutputBytes(string $path, array $options): int
    {
        return config('conversion.documents.max_output_bytes');
    }

    public function convert(ConversionJob $job, string $input, string $output, callable $checkpoint): void
    {
        $process = new Process([config('conversion.documents.python'), '-I', base_path('bin/document-worker.py')], dirname($input));
        $process->setInput(json_encode(['input' => $input, 'output' => $output, 'tool' => $this->tool,
            'source' => $job->source_format, 'target' => $job->target_format, 'options' => $job->options_json,
            'limits' => collect(config('conversion.documents'))->except(['python', 'libreoffice'])->all(),
            'libreoffice' => config('conversion.documents.libreoffice'), 'timeout' => $job->timeout_seconds,
            'max_bytes' => $job->max_output_bytes, 'lease' => Storage::disk(config('conversion.disk'))->path('leases/'.$job->id.'.lock')], JSON_THROW_ON_ERROR));
        $process->setTimeout($job->timeout_seconds);
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $checkpoint();
                $workingBytes = 0;
                clearstatcache();
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($input), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
                    // LibreOffice removes temporary profiles while this inventory is in progress.
                    $path = $file->getPathname();
                    $size = @filesize($path);
                    if ($size !== false && str_replace('\\', '/', $path) !== str_replace('\\', '/', $input)) {
                        $workingBytes += $size;
                    }
                }
                if ($workingBytes > $job->max_output_bytes) {
                    throw new PipelineException('OUTPUT_LIMIT_EXCEEDED');
                }
                usleep(100000);
            }
            $process->checkTimeout();
            if (! $process->isSuccessful() || trim($process->getOutput()) !== 'OK') {
                $code = trim($process->getOutput());
                throw new PipelineException(in_array($code, ['INVALID_DOCUMENT', 'PROTECTED_DOCUMENT', 'DOCUMENT_TOO_COMPLEX', 'UNSAFE_DOCUMENT', 'EXTERNAL_REFERENCE', 'INVALID_OPTIONS', 'OUTPUT_LIMIT_EXCEEDED', 'PRIVILEGED_WORKER'], true) ? $code : 'INVALID_DOCUMENT');
            }
        } catch (ProcessTimedOutException) {
            throw new PipelineException('PROCESS_TIMEOUT');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function validateOutput(string $path, string $target): void
    {
        if (filesize($path) < 1 || filesize($path) > config('conversion.documents.max_output_bytes')) {
            throw new PipelineException('INVALID_OUTPUT');
        }
        if ($target === 'pdf' && file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
            throw new PipelineException('INVALID_OUTPUT');
        }
        if (in_array($target, ['zip', 'xlsx'], true)) {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                throw new PipelineException('INVALID_OUTPUT');
            }
            $zip->close();
        }
    }
}
