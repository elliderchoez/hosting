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
     * @return array{success: bool, output: string}
     */
    public function execute(Project $project): array
    {
        $containerName = "project-{$project->id}";
        $output = "Deteniendo contenedor $containerName...\n";

        try {
            // Detener y eliminar el contenedor de forma inmediata (forzado)
            $rmProcess = new Process(['docker', 'rm', '-f', $containerName]);
            $rmProcess->run();
            $output .= $rmProcess->getOutput() . "\n" . $rmProcess->getErrorOutput();

            // Restablecer la base de datos a su estado original (estrategia de limpieza automatizada)
            if (!empty($project->db_name)) {
                try {
                    \Illuminate\Support\Facades\Artisan::call('projects:reset-databases', [
                        '--project' => $project->id
                    ]);
                    $output .= "Base de datos del proyecto restablecida a su estado de fábrica para liberar espacio.\n";
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
