<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class Develop extends Command
{
    protected $signature = 'app:dev {--host=127.0.0.1} {--port=8000}';

    protected $description = 'Start the local server, conversion workers and scheduler together';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is only available in the local environment.');

            return self::FAILURE;
        }

        $this->call('conversions:reconcile');
        $commands = [
            'web' => ['serve', '--host='.$this->option('host'), '--port='.$this->option('port'), '--tries=1'],
            'conversions' => ['queue:work', 'database', '--queue=conversions', '--sleep=1', '--timeout=240', '--memory=256'],
            'external' => ['queue:work', 'database', '--queue=external-downloads', '--sleep=1', '--timeout=240', '--memory=256'],
            'scheduler' => ['schedule:work'],
        ];
        $processes = [];
        // Also release children when the terminal interrupts this command.
        $stop = static function () use (&$processes): void {
            foreach (array_reverse($processes) as $process) {
                if ($process->isRunning()) {
                    $process->stop(3);
                }
            }
        };
        register_shutdown_function($stop);

        try {
            foreach ($commands as $name => $arguments) {
                $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments], base_path());
                $process->setTimeout(null);
                $processes[$name] = $process;
                $process->start(function ($type, $buffer) use ($name): void {
                    $this->output->write('['.$name.'] '.$buffer);
                });
            }
            $this->info('Local application and workers started. Keep this terminal open.');
            while (true) {
                foreach ($processes as $name => $process) {
                    if (! $process->isRunning()) {
                        $this->error($name.' stopped (exit '.$process->getExitCode().'). Restart app:dev.');

                        return self::FAILURE;
                    }
                }
                usleep(200000);
            }
        } finally {
            $stop();
        }
    }
}
