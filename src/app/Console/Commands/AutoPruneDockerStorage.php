<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AutoPruneDockerStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docker:auto-prune-disk {--threshold=80 : Porcentaje de uso de disco para activar la limpieza preventiva} {--force : Forzar la limpieza sin importar el umbral}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitorea el almacenamiento y ejecuta docker image prune -f de forma preventiva al superar el 80% de disco.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');
        $force = (bool) $this->option('force');

        $diskPath = '/';
        $totalBytes = @disk_total_space($diskPath) ?: 1;
        $freeBytes = @disk_free_space($diskPath) ?: 0;
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $usedPercent = round(($usedBytes / $totalBytes) * 100, 1);

        $this->info("Uso de disco actual: {$usedPercent}% (Umbral preventivo: {$threshold}%)");

        if (! $force && $usedPercent < $threshold) {
            $this->line("El disco está por debajo del umbral de {$threshold}%. No se requiere limpieza.");
            return 0;
        }

        $this->warn("El almacenamiento supera el {$threshold}% ({$usedPercent}% ocupado) o se forzó la ejecución. Iniciando limpieza preventiva segura de imágenes Docker sin tag...");

        try {
            $process = new Process(['docker', 'image', 'prune', '-f']);
            $process->setTimeout(180);
            $process->run();

            $output = $process->getOutput() . "\n" . $process->getErrorOutput();
            $reclaimedSpace = '0 B';

            if (preg_match('/Total reclaimed space:\s*([^\r\n]+)/i', $output, $matches)) {
                $reclaimedSpace = trim($matches[1]);
            }

            $now = now()->format('Y-m-d H:i:s');
            Cache::put('docker_last_prune_at', $now, now()->addDays(30));
            Cache::put('docker_last_prune_reclaimed', $reclaimedSpace, now()->addDays(30));
            Cache::put('docker_last_prune_trigger', $force ? 'manual' : 'auto_threshold', now()->addDays(30));

            Log::info("Limpieza preventiva de imágenes Docker completada al alcanzar {$usedPercent}% de disco. Espacio recuperado: {$reclaimedSpace}.");
            $this->info("Limpieza completada con éxito. Espacio recuperado: {$reclaimedSpace}.");

            return 0;
        } catch (\Exception $e) {
            Log::error("Error durante auto-prune de Docker: " . $e->getMessage());
            $this->error("Error al ejecutar docker image prune: " . $e->getMessage());
            return 1;
        }
    }
}
