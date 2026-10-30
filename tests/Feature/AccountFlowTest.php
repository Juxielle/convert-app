<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountFlowTest extends TestCase
{
    public function test_real_http_session_csrf_conversion_ownership_and_admin_flow(): void
    {
        $directory = storage_path('framework/testing/http-flow-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        touch($directory.'/database.sqlite');
        $previous = config('database.connections.sqlite.database');
        $server = null;
        try {
            config(['database.connections.sqlite.database' => $directory.'/database.sqlite']);
            DB::purge();
            Artisan::call('migrate', ['--force' => true]);
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $server = new Process([config('conversion.media.python'), base_path('tests/Support/http-server.py'), $directory, PHP_BINARY, '127.0.0.1:'.$port, base_path('tests/Support/http-router.php')], base_path(), [
                'APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite',
                'DB_URL' => false, 'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database',
                'HTTP_TEST_STORAGE' => $directory.'/files', 'APP_DEBUG' => 'false',
            ]);
            $server->setTimeout(60)->start();
            $client = new Client(['base_uri' => 'http://127.0.0.1:'.$port, 'cookies' => new CookieJar, 'http_errors' => false, 'timeout' => 15]);
            $deadline = microtime(true) + 10;
            do {
                try {
                    $page = $client->get('/connexion');
                    break;
                } catch (ConnectException) {
                    usleep(100000);
                }
            } while (microtime(true) < $deadline);
            $this->assertSame(200, $page->getStatusCode());
            preg_match('/name="csrf-token" content="([^"]+)"/', (string) $page->getBody(), $match);
            $headers = ['Accept' => 'application/json', 'X-CSRF-TOKEN' => $match[1]];
            $this->assertSame(419, $client->post('/api/v1/auth/login', ['json' => ['email' => 'any@example.test']])->getStatusCode());
            $response = $client->post('/api/v1/auth/register', ['headers' => $headers, 'json' => ['name' => 'HTTP Test', 'email' => 'http@example.test', 'password' => 'An HTTP password 123!', 'password_confirmation' => 'An HTTP password 123!']]);
            $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            $data = json_decode((string) $response->getBody(), true)['data'];
            $headers['X-CSRF-TOKEN'] = $data['csrf_token'];
            $this->assertSame('user', $data['user']['role']);
            $this->assertSame(403, $client->get('/api/v1/admin/jobs', ['headers' => $headers])->getStatusCode());
            $image = imagecreatetruecolor(8, 8);
            ob_start();
            imagepng($image);
            $png = ob_get_clean();
            imagedestroy($image);
            $response = $client->post('/api/v1/conversions', ['headers' => $headers, 'multipart' => [
                ['name' => 'tool', 'contents' => 'convertir-image'], ['name' => 'target_format', 'contents' => 'webp'],
                ['name' => 'file', 'contents' => $png, 'filename' => 'private.png', 'headers' => ['Content-Type' => 'image/png']],
            ]]);
            $this->assertSame(202, $response->getStatusCode(), (string) $response->getBody());
            $id = json_decode((string) $response->getBody(), true)['data']['id'];
            $worker = new Process([PHP_BINARY, base_path('tests/Support/http-worker.php'), $directory], base_path(), ['APP_ENV' => 'local']);
            $worker->setTimeout(20)->mustRun();
            $download = $client->get('/api/v1/jobs/'.$id.'/download', ['headers' => $headers]);
            $this->assertSame(200, $download->getStatusCode());
            $this->assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
            $this->assertNotFalse(imagecreatefromstring((string) $download->getBody()));
            $stranger = new Client(['base_uri' => 'http://127.0.0.1:'.$port, 'cookies' => new CookieJar, 'http_errors' => false]);
            $this->assertSame(404, $stranger->get('/api/v1/jobs/'.$id.'/download', ['headers' => ['Accept' => 'application/json']])->getStatusCode());
            $user = User::where('email', 'http@example.test')->firstOrFail();
            $user->forceFill(['role' => 'admin'])->save();
            $overview = $client->get('/api/v1/admin/overview', ['headers' => $headers]);
            $this->assertSame(200, $overview->getStatusCode());
            $this->assertSame(1, json_decode((string) $overview->getBody(), true)['data']['states']['completed']);
            $this->assertSame(200, $client->patch('/api/v1/admin/settings', ['headers' => $headers, 'json' => ['tools' => ['convertir-image' => false]]])->getStatusCode());
            $this->assertSame(200, $client->delete('/api/v1/jobs/'.$id, ['headers' => $headers])->getStatusCode());
            $logout = $client->post('/api/v1/auth/logout', ['headers' => $headers, 'json' => []]);
            $this->assertSame(200, $logout->getStatusCode());
            $headers['X-CSRF-TOKEN'] = json_decode((string) $logout->getBody(), true)['data']['csrf_token'];
            $this->assertSame(401, $client->get('/api/v1/admin/jobs', ['headers' => $headers])->getStatusCode());
            $this->assertSame(200, $client->post('/api/v1/auth/login', ['headers' => $headers, 'json' => ['email' => $user->email, 'password' => 'An HTTP password 123!']])->getStatusCode());
        } finally {
            if ($server?->isRunning()) {
                file_put_contents($directory.'/server.stop', 'stop');
                $server->wait();
            }
            DB::purge();
            config(['database.connections.sqlite.database' => $previous]);
            // Only this test's unique directory inside storage/framework/testing is removed.
            File::deleteDirectory($directory);
        }
    }
}
