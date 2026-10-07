<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Deployment;
use App\Actions\Docker\StartProjectContainerAction;
use App\Actions\Docker\StopProjectContainerAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Process\Process;
use Illuminate\Http\JsonResponse;

class ContainerController extends Controller
{
    /**
     * Start a stopped or sleeping project container.
     */
    public function start(Project $project, StartProjectContainerAction $startAction): JsonResponse
    {
        if ($project->user_id !== Auth::id()) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if ($project->is_suspended) {
            return response()->json([
                'success' => false,
                'status' => 'stopped',
                'error' => 'El proyecto está pausado temporalmente por la administración institucional: ' . ($project->suspension_reason ?: 'Consulte con soporte.'),
            ], 403);
        }

        $projectPath = storage_path("app/projects/project-{$project->id}");
        $domain = env('APP_DOMAIN', 'nexus-academic.software');

        $result = $startAction->execute($project, $projectPath, $domain);

        if ($result['success']) {
            $project->status = 'running';
            $project->container_id = $result['container_id'];
            $project->last_visited_at = now();
            $project->save();

            return response()->json([
                'success' => true,
                'status' => 'running',
                'message' => 'Contenedor iniciado correctamente.'
            ]);
        }

        return response()->json([
            'success' => false,
            'status' => 'stopped',
            'error' => 'Error al iniciar contenedor.',
            'log' => $result['output']
        ], 500);
    }

    /**
     * Stop a running project container.
     */
    public function stop(Project $project, StopProjectContainerAction $stopAction): JsonResponse
    {
        if ($project->user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $result = $stopAction->execute($project);

        if ($result['success']) {
            $project->status = 'stopped';
            $project->container_id = null;
            $project->save();

            return response()->json([
                'success' => true,
                'status' => 'stopped',
                'message' => 'Contenedor detenido correctamente.'
            ]);
        }

        return response()->json([
            'success' => false,
            'status' => 'running',
            'error' => 'Error al detener contenedor.',
            'log' => $result['output']
        ], 500);
    }

    /**
     * Get real-time compilation and execution logs of the project.
     */
    public function logs(Project $project): JsonResponse
    {
        if ($project->user_id !== Auth::id() && !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // 1. Get compilation logs (latest deployment)
        $latestDeployment = Deployment::where('project_id', $project->id)
            ->latest()
            ->first();

        $buildLog = $latestDeployment ? $latestDeployment->build_log : "No hay registros de compilación.";

        // 2. Get container execution logs (from Docker daemon)
        $containerLog = "El contenedor está apagado.";
        if ($project->status === 'running') {
            $containerName = "project-{$project->id}";
            $process = new Process(['docker', 'logs', '--tail', '100', $containerName]);
            $process->run();
            
            $containerLog = $process->getOutput() ?: $process->getErrorOutput();
            if (empty($containerLog)) {
                $containerLog = "El contenedor está corriendo pero no ha generado logs todavía.";
            }
        } elseif ($project->status === 'sleeping') {
            $containerLog = "El contenedor está suspendido (Auto-Sleep). Se reactivará con la siguiente visita HTTP.";
        }

        return response()->json([
            'build_log' => $buildLog,
            'container_log' => $containerLog,
            'status' => $project->status
        ]);
    }
}
