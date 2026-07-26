<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Actions\Docker\StopProjectContainerAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class AutoSleepContainers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:auto-sleep';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Puts idle student containers to sleep based on network traffic inactivity.';

    /**
     * Execute the console command.
     */
    public function handle(StopProjectContainerAction $stopAction): int
    {
        $this->info("Starting auto-sleep check...");

        // Get all currently running projects
        $runningProjects = Project::where('status', 'running')->get();

        if ($runningProjects->isEmpty()) {
            $this->info("No running containers found.");
            return 0;
        }

        foreach ($runningProjects as $project) {
            $containerName = "project-{$project->id}";
            $this->info("Checking container: $containerName (Project: {$project->name})");

            // 1. Get container Network IO
            $netIo = $this->getContainerNetIo($containerName);
            if ($netIo === null) {
                // Container is registered as running but is actually stopped/missing in Docker
                $this->warn("Container $containerName is missing in Docker. Updating status to stopped.");
                $project->status = 'stopped';
                $project->save();
                continue;
            }

            // Extract Input (Rx) bytes from NetIO format (e.g. "1.24kB / 0B" -> "1.24kB")
            $rxValue = $netIo['rx'];

            // 2. Check if RX bytes have increased since last check
            $cacheKey = "project-{$project->id}-last-rx";
            $lastRx = Cache::get($cacheKey);

            $this->info("Current RX: $rxValue | Last recorded RX: " . ($lastRx ?? 'None'));

            if ($lastRx !== null && $lastRx === $rxValue) {
                // No new traffic has arrived since last check.
                // Check if the idle time exceeds the threshold (15 minutes)
                $idleMinutes = now()->diffInMinutes($project->last_visited_at);
                $this->info("Container has been idle for $idleMinutes minutes.");

                if ($idleMinutes >= 15) {
                    $this->warn("Inactivity threshold reached. Putting container $containerName to sleep...");
                    $result = $stopAction->execute($project);
                    
                    if ($result['success']) {
                        $project->status = 'sleeping';
                        $project->save();
                        $this->info("Container put to sleep successfully.");
                        Cache::forget($cacheKey);
                    } else {
                        $this->error("Failed to stop container: " . $result['output']);
                    }
                }
            } else {
                // Traffic detected! Update last visited time and cache the new RX value
                $this->info("New traffic detected. Updating last visited timestamp.");
                $project->last_visited_at = now();
                $project->save();
                
                Cache::put($cacheKey, $rxValue, now()->addHours(24));
            }
        }

        $this->info("Auto-sleep check completed.");
        return 0;
    }

    /**
     * Get Network IO bytes for a container.
     *
     * @return array{rx: string, tx: string}|null
     */
    private function getContainerNetIo(string $containerName): ?array
    {
        // docker stats --no-stream --format "{{.NetIO}}" [container_name]
        $process = new Process([
            'docker', 'stats', '--no-stream', 
            '--format', '{{.NetIO}}', 
            $containerName
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());
        if (empty($output) || str_contains($output, '---')) {
            return null;
        }

        // Split output by slash (e.g., "1.24kB / 2.3MB" -> ["1.24kB", "2.3MB"])
        $parts = explode('/', $output);
        if (count($parts) === 2) {
            return [
                'rx' => trim($parts[0]),
                'tx' => trim($parts[1])
            ];
        }

        return null;
    }
}
