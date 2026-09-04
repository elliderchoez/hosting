<?php

namespace App\Actions\Github;

use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Exception;

class CloneRepositoryAction
{
    /**
     * Clone a public GitHub repository.
     *
     * @param Project $project
     * @param string $destinationPath
     * @return array{success: bool, output: string}
     */
    public function execute(Project $project, string $destinationPath): array
    {
        // 1. Clean existing folder if it exists
        if (is_dir($destinationPath) || file_exists($destinationPath)) {
            $rmProcess = Process::fromShellCommandLine('rm -rf ' . escapeshellarg($destinationPath));
            $rmProcess->run();
            if (is_dir($destinationPath)) {
                File::deleteDirectory($destinationPath);
            }
        }

        // Create directory structure if needed
        File::makeDirectory(dirname($destinationPath), 0755, true, true);

        $branch = $project->branch ?: 'main';
        $command = [
            'git', 
            'clone', 
            '--depth', '1', 
            '-b', $branch, 
            $project->github_repo_url, 
            $destinationPath
        ];

        $process = new Process($command);
        $process->setTimeout(180); // 3 minutes timeout for cloning

        try {
            $process->mustRun();
            
            return [
                'success' => true,
                'output' => "Repositorio clonado con éxito.\n" . $process->getOutput() . "\n" . $process->getErrorOutput()
            ];
        } catch (ProcessFailedException $exception) {
            $errorOutput = $process->getErrorOutput() . ' ' . $process->getOutput();
            
            // Si la rama no existe (ej: se indicó 'main' pero el repositorio usa 'master' o viceversa)
            if (str_contains(strtolower($errorOutput), 'not found') || str_contains(strtolower($errorOutput), 'no encontrada')) {
                // Limpiar la carpeta antes del reintento
                if (is_dir($destinationPath)) {
                    File::deleteDirectory($destinationPath);
                }
                
                $fallbackCmd = [
                    'git', 
                    'clone', 
                    '--depth', '1', 
                    $project->github_repo_url, 
                    $destinationPath
                ];
                
                $fallbackProc = new Process($fallbackCmd);
                $fallbackProc->setTimeout(180);
                $fallbackProc->run();
                
                if ($fallbackProc->isSuccessful()) {
                    return [
                        'success' => true,
                        'output' => "Aviso: Rama '{$branch}' no encontrada. Se clonó exitosamente la rama principal por defecto del repositorio.\n" . $fallbackProc->getOutput()
                    ];
                }
            }

            return [
                'success' => false,
                'output' => "Error al clonar el repositorio: " . $exception->getMessage() . "\n" . $process->getErrorOutput()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'output' => "Ocurrió un error inesperado al clonar: " . $e->getMessage()
            ];
        }
    }
}
