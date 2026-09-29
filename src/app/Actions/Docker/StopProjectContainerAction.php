<?php

namespace App\Actions\Docker;

use App\Models\Project;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Exception;

class StopProjectContainerAction
{
    /**
     * Stop and remove a student project container.
     *
     * @param Project $project
     * @param bool $resetDatabase
     * @return array{success: bool, output: string}
     */
    public function execute(Project $project, bool $resetDatabase = false): array
    {
        $containerName = "project-{$project->id}";
        $output = "Deteniendo contenedor $containerName...\n";

        try {
            // Detener y eliminar el contenedor de forma inmediata (forzado)
            $rmProcess = new Process(['docker', 'rm', '-f', $containerName]);
            $rmProcess->run();
            $output .= $rmProcess->getOutput() . "\n" . $rmProcess->getErrorOutput();

            // Restablecer la base de datos solo si se solicita explícitamente y en segundo plano
            if ($resetDatabase && !empty($project->db_name)) {
                try {
                    $artisanPath = base_path('artisan');
                    $cmd = "nohup php " . escapeshellarg($artisanPath) . " projects:reset-databases --project=" . escapeshellarg($project->id) . " > /dev/null 2>&1 &";
                    exec($cmd);
                    $output .= "Restablecimiento de base de datos lanzado en segundo plano.\n";
                } catch (\Exception $e) {
                    $output .= "Advertencia al restablecer base de datos: " . $e->getMessage() . "\n";
                }
            }

            return [
                'success' => true,
                'output' => $output . "Contenedor detenido y eliminado con éxito.\n"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'output' => $output . "Error al detener el contenedor: " . $e->getMessage()
            ];
        }
    }
}
