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
            // No dormir servicios backend si su frontend vinculado sigue activo
            if ($project->is_backend_service) {
                $frontend = Project::where('backend_project_id', $project->id)->first();
                if ($frontend && ($frontend->status === 'running' || ($frontend->last_visited_at && abs(now()->diffInMinutes($frontend->last_visited_at)) < 15))) {
                    $this->info("Servicio backend con frontend activo ({$frontend->name}). Manteniendo encendido.");
                    continue;
                }
            }

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

            // 2. Comprobar tiempo de inactividad
            $idleMinutes = $project->last_visited_at ? (int) abs(now()->diffInMinutes($project->last_visited_at)) : 999;
            $updatedMinutes = $project->updated_at ? (int) abs(now()->diffInMinutes($project->updated_at)) : 999;
            $effectiveIdleMinutes = min($idleMinutes, $updatedMinutes);
            $this->info("Container idle time: $idleMinutes minutes (effective: $effectiveIdleMinutes min).");

            // Si fue visitado o iniciado/actualizado hace menos de 15 minutos, se mantiene activo
            if ($effectiveIdleMinutes < 15) {
                Cache::put($cacheKey, $rxValue, now()->addHours(24));
                $this->info("Container was active recently ($effectiveIdleMinutes min ago). Keeping alive.");
                continue;
            }

            // Si es la primera medición de RX tras el periodo de gracia, inicializar y esperar al siguiente ciclo
            if ($lastRx === null) {
                Cache::put($cacheKey, $rxValue, now()->addHours(24));
                $this->info("Initial RX measurement recorded for {$project->name}. Waiting for next check cycle.");
                continue;
            }

            // Si han pasado 15 minutos o más, verificar si hubo tráfico sustancial
            $currentBytes = $this->parseBytes($rxValue);
            $lastBytes = $this->parseBytes($lastRx);
            $bytesDiff = max(0, $currentBytes - $lastBytes);

            // Menos de 40 KB de tráfico se considera ruido de red de Docker (ARP / multicast / pings)
            if ($bytesDiff < 40960) {
                $this->warn("Inactivity threshold (10 min) reached for {$project->name} (Traffic diff: {$bytesDiff}B). Putting to sleep...");
                $result = $stopAction->execute($project);
                
                if ($result['success']) {
                    $project->status = 'sleeping';
                    $project->save();
                    $this->info("Container put to sleep successfully.");
                    Cache::forget($cacheKey);
                } else {
                    $this->error("Failed to stop container: " . $result['output']);
                }
            } else {
                // Tráfico real sustancial detectado
                $this->info("Substantial active traffic detected ({$bytesDiff}B). Updating last visited timestamp.");
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

    /**
     * Parse Docker NetIO string value (e.g. "1.24kB", "2.5MB", "500B") to bytes.
     */
    private function parseBytes(string $val): float
    {
        $val = trim($val);
        $unit = strtoupper(preg_replace('/[0-9.]/', '', $val));
        $num = (float) preg_replace('/[^0-9.]/', '', $val);

        return match ($unit) {
            'B' => $num,
            'KB', 'KIB' => $num * 1024,
            'MB', 'MIB' => $num * 1024 * 1024,
            'GB', 'GIB' => $num * 1024 * 1024 * 1024,
            default => $num,
        };
    }
}
