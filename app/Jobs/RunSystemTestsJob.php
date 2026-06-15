<?php

namespace App\Jobs;

use App\Models\SystemTestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use Throwable;

class RunSystemTestsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(private readonly int $runId)
    {
    }

    public function handle(): void
    {
        $run = SystemTestRun::query()->findOrFail($this->runId);
        $command = $this->commandForSuite((string) $run->suite);

        $run->update([
            'status' => SystemTestRun::STATUS_RUNNING,
            'command' => $command,
            'started_at' => now(),
            'finished_at' => null,
            'exit_code' => null,
            'output' => null,
            'error_message' => null,
        ]);

        try {
            $process = new Process($command, base_path(), null, null, $this->timeout);
            $process->run();

            $output = trim($process->getOutput().PHP_EOL.$process->getErrorOutput());

            $run->update([
                'status' => $process->isSuccessful() ? SystemTestRun::STATUS_PASSED : SystemTestRun::STATUS_FAILED,
                'exit_code' => $process->getExitCode(),
                'output' => $this->truncateOutput($output),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => SystemTestRun::STATUS_ERROR,
                'error_message' => $exception->getMessage(),
                'output' => $this->truncateOutput($exception->getTraceAsString()),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<int, string>
     */
    private function commandForSuite(string $suite): array
    {
        $command = [PHP_BINARY, 'artisan', 'test', '--compact'];

        if ($suite === SystemTestRun::SUITE_UNIT) {
            $command[] = '--testsuite=Unit';
        }

        if ($suite === SystemTestRun::SUITE_FEATURE) {
            $command[] = '--testsuite=Feature';
        }

        return $command;
    }

    private function truncateOutput(string $output): string
    {
        $limit = 120_000;

        if (strlen($output) <= $limit) {
            return $output;
        }

        return substr($output, -$limit);
    }
}
