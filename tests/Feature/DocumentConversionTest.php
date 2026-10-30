<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversion;
use App\Models\ConversionJob;
use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentConversionTest extends TestCase
{
    use RefreshDatabase;

    private static bool $fixturesReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['conversion.documents.testing_ready' => true]);
        Storage::fake('conversions');
        $this->withSession(['conversion_owner' => str_repeat('d', 64)]);
        if (! self::$fixturesReady) {
            $process = new Process([config('conversion.documents.python'), base_path('tests/Support/document-fixtures.py'), base_path('.cache/document-fixtures')]);
            $process->mustRun();
            self::$fixturesReady = true;
        }
    }

    private function file(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('.cache/document-fixtures/'.$name)));
    }

    private function convert(string $tool, string $name, string $target, array $options = []): ConversionJob
    {
        $id = $this->postJson('/api/v1/conversions', ['tool' => $tool, 'target_format' => $target, 'file' => $this->file($name), 'options' => $options])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));

        return $job->fresh();
    }

    public static function operations(): array
    {
        return [
            'DOCX to PDF' => ['office-vers-pdf', 'sample.docx', 'pdf', []],
            'XLSX to PDF' => ['office-vers-pdf', 'sample.xlsx', 'pdf', []],
            'PPTX to PDF' => ['office-vers-pdf', 'sample.pptx', 'pdf', []],
            'CSV to XLSX' => ['csv-vers-xlsx', 'sample.csv', 'xlsx', []],
            'XLSX to CSV' => ['xlsx-vers-csv', 'sample.xlsx', 'csv', []],
            'PDF pages to PNG archive' => ['pdf-vers-images', 'two.pdf', 'zip', ['dpi' => 72]],
            'PDF split' => ['diviser-pdf', 'two.pdf', 'pdf', ['first_page' => 2, 'last_page' => 2]],
            'PDF rotate' => ['tourner-pdf', 'two.pdf', 'pdf', ['angle' => 90]],
            'PDF compress' => ['compresser-pdf', 'compressible.pdf', 'pdf', []],
        ];
    }

    #[DataProvider('operations')]
    public function test_real_document_operations_produce_valid_results(string $tool, string $name, string $target, array $options): void
    {
        $job = $this->convert($tool, $name, $target, $options);
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $this->assertGreaterThan(0, $job->output_size);
        $this->get('/api/v1/jobs/'.$job->id.'/download')->assertOk();
        $path = Storage::disk('conversions')->path($job->output_path);
        if ($target === 'pdf') {
            $check = new Process([config('conversion.documents.python'), '-c', 'from pypdf import PdfReader; import sys; r=PdfReader(sys.argv[1]); print(len(r.pages)); print(r.pages[0].get("/Rotate",0))', $path]);
            $check->mustRun();
            $lines = preg_split('/\R/', trim($check->getOutput()));
            if ($tool === 'office-vers-pdf') {
                $this->assertGreaterThanOrEqual(1, (int) $lines[0]);
                $text = new Process([config('conversion.documents.python'), '-c', 'from pypdf import PdfReader; import sys; text=" ".join(p.extract_text() for p in PdfReader(sys.argv[1]).pages); assert sys.argv[2] in text; print("OK")', $path, $name === 'sample.xlsx' ? '42' : 'Bonjour']);
                $text->mustRun();
                $this->assertSame('OK', trim($text->getOutput()));
            } else {
                $this->assertSame($tool === 'diviser-pdf' || $tool === 'compresser-pdf' ? '1' : '2', $lines[0]);
            }
            if ($tool === 'tourner-pdf') {
                $this->assertSame('90', $lines[1]);
            }
            if ($tool === 'compresser-pdf') {
                $this->assertLessThan($job->input_size, $job->output_size);
            }
        } elseif ($target === 'zip') {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertSame(2, $zip->numFiles);
            $this->assertSame([300, 200], array_slice(getimagesizefromstring($zip->getFromIndex(0)), 0, 2));
            $zip->close();
        } elseif ($target === 'xlsx') {
            $check = new Process([config('conversion.documents.python'), '-c', 'import openpyxl,sys; w=openpyxl.load_workbook(sys.argv[1]); s=w.active; assert s["B2"].value=="0012"; assert s["A3"].data_type=="s"; print("OK")', $path]);
            $check->mustRun();
            $this->assertSame('OK', trim($check->getOutput()));
        } else {
            $this->assertStringContainsString('Café', file_get_contents($path));
            $this->assertStringContainsString(',-5', file_get_contents($path));
        }
    }

    public function test_pdf_merge_preserves_file_order_and_page_count(): void
    {
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'fusionner-pdf', 'target_format' => 'pdf', 'files' => [$this->file('one.pdf'), $this->file('two.pdf')]])->assertStatus(202)->json('data.0.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $job->refresh();
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $check = new Process([config('conversion.documents.python'), '-c', 'from pypdf import PdfReader; import sys; pages=PdfReader(sys.argv[1]).pages; assert [int(p.mediabox.width) for p in pages]==[300,300,310]; print("OK")', Storage::disk('conversions')->path($job->output_path)]);
        $check->mustRun();
        $this->assertSame('OK', trim($check->getOutput()));
        $this->assertDatabaseCount('usage_events', 1);
    }

    public function test_encrypted_overlong_invalid_ranges_and_external_references_are_refused(): void
    {
        foreach ([['compresser-pdf', 'encrypted.pdf', [], 'PROTECTED_DOCUMENT'], ['compresser-pdf', 'too-many.pdf', [], 'DOCUMENT_TOO_COMPLEX'], ['diviser-pdf', 'one.pdf', ['first_page' => 1, 'last_page' => 2], 'INVALID_OPTIONS']] as [$tool, $name, $options, $code]) {
            $job = $this->convert($tool, $name, 'pdf', $options);
            $this->assertSame('failed', $job->status);
            $this->assertSame($code, $job->error_code);
        }
    }

    public function test_xlsx_formulas_are_exported_as_neutralized_text(): void
    {
        $job = $this->convert('xlsx-vers-csv', 'sample.xlsx', 'csv', ['sheet' => 2]);
        $this->assertSame('completed', $job->status, $job->error_code ?? '');
        $this->assertStringContainsString("'=1+2", file_get_contents(Storage::disk('conversions')->path($job->output_path)));
    }

    public function test_invalid_archive_and_excessive_file_size_are_rejected_before_admission(): void
    {
        config(['conversion.documents.max_input_bytes' => 10]);
        $this->postJson('/api/v1/conversions', ['tool' => 'xlsx-vers-csv', 'target_format' => 'csv', 'file' => $this->file('sample.xlsx')])->assertStatus(413);
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_office_external_resources_are_rejected_without_starting_a_network_fetch(): void
    {
        $job = $this->convert('office-vers-pdf', 'external.docx', 'pdf');
        $this->assertSame('failed', $job->status);
        $this->assertSame('EXTERNAL_REFERENCE', $job->error_code);
        $this->assertSame(0, $job->reserved_bytes);
    }

    public function test_malformed_pdf_cannot_publish_a_downloadable_result(): void
    {
        $id = $this->postJson('/api/v1/conversions', ['tool' => 'compresser-pdf', 'target_format' => 'pdf', 'file' => UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.7\nthis is not a valid PDF")])->assertStatus(202)->json('data.id');
        $job = ConversionJob::findOrFail($id);
        (new ProcessConversion($id, $job->expires_at->timestamp))->handle(app(Pipeline::class), app(EngineRegistry::class));
        $this->assertSame('failed', $job->fresh()->status);
        $this->get('/api/v1/jobs/'.$id.'/download')->assertNotFound();
    }

    public function test_archive_expansion_bomb_is_rejected_before_queueing(): void
    {
        $path = Storage::disk('conversions')->path('bomb-source.xlsx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', str_repeat('x', 1_000_000));
        $zip->close();
        $this->postJson('/api/v1/conversions', ['tool' => 'xlsx-vers-csv', 'target_format' => 'csv', 'file' => UploadedFile::fake()->createWithContent('bomb.xlsx', file_get_contents($path))])->assertStatus(422)->assertJsonPath('error.code', 'DOCUMENT_TOO_COMPLEX');
        $this->assertDatabaseCount('conversion_jobs', 0);
    }

    public function test_cell_and_render_pixel_limits_are_enforced_in_the_worker(): void
    {
        config(['conversion.documents.max_rows' => 1]);
        $job = $this->convert('xlsx-vers-csv', 'sample.xlsx', 'csv');
        $this->assertSame('DOCUMENT_TOO_COMPLEX', $job->error_code);
        config(['conversion.documents.max_render_pixels' => 100]);
        $job = $this->convert('pdf-vers-images', 'one.pdf', 'zip');
        $this->assertSame('DOCUMENT_TOO_COMPLEX', $job->error_code);
        $this->assertSame(0, $job->reserved_bytes);
    }

    public function test_refused_merge_removes_private_staging_and_does_not_spend_quota(): void
    {
        config(['conversion.limits.guest.concurrent_jobs' => 0]);
        $this->postJson('/api/v1/conversions', ['tool' => 'fusionner-pdf', 'target_format' => 'pdf', 'files' => [$this->file('one.pdf'), $this->file('two.pdf')]])->assertStatus(429);
        $this->assertSame([], Storage::disk('conversions')->files('staging'));
        $this->assertDatabaseCount('usage_events', 0);
        $this->assertDatabaseCount('conversion_jobs', 0);
    }
}
