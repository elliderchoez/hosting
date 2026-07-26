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
        if (File::exists($destinationPath)) {
            File::deleteDirectory($destinationPath);
        }

        // Create directory structure if needed
        File::makeDirectory(dirname($destinationPath), 0755, true, true);

        // 2. Prepare git clone command (shallow clone depth 1 for speed and disk conservation)
        $command = [
            'git', 
            'clone', 
            '--depth', '1', 
            '-b', $project->branch, 
            $project->github_repo_url, 
            $destinationPath
        ];

        $process = new Process($command);
        $process->setTimeout(120); // 2 minutes timeout for cloning

        try {
            $process->mustRun();
            
            return [
                'success' => true,
                'output' => "Repositorio clonado con éxito.\n" . $process->getOutput() . "\n" . $process->getErrorOutput()
            ];
        } catch (ProcessFailedException $exception) {
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
