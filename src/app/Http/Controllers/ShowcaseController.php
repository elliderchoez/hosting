<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Project;
use App\Models\ContactLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;

class ShowcaseController extends Controller
{
    /**
     * Display the public vitrina of student projects.
     */
    public function index(): Response
    {
        $projects = Project::with(['user.profile', 'backendProject'])
            ->where('is_backend_service', false)
            ->whereIn('status', ['running', 'sleeping'])
            ->orderByRaw("CASE 
                WHEN status = 'running' THEN 1 
                ELSE 2 
            END")
            ->orderBy('updated_at', 'desc')
            ->get();

        return Inertia::render('Welcome', [
            'projects' => $projects,
            'canLogin' => true,
            'canRegister' => true,
        ]);
    }

    /**
     * Display a specific student's public profile.
     */
    public function studentProfile(string $userId): Response
    {
        $student = User::with(['profile', 'projects'])->findOrFail($userId);

        return Inertia::render('StudentProfile', [
            'student' => $student
        ]);
    }

    /**
     * Record a contact request from a recruiter to a student.
     */
    public function contactStudent(Request $request, string $studentId): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:150', new \App\Rules\CleanContentRule(1, 'nombre')],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['required', 'string', 'max:150', new \App\Rules\CleanContentRule(1, 'nombre de empresa')],
            'message' => ['required', 'string', 'min:5', 'max:3000', new \App\Rules\CleanContentRule(1, 'mensaje')],
        ]);

        $cleanName = strip_tags(trim($request->name));
        $cleanEmail = strip_tags(trim($request->email));
        $cleanCompany = strip_tags(trim($request->company));
        $cleanMessage = strip_tags(trim($request->message));

        ContactLog::create([
            'recruiter_id' => null,
            'student_id' => $studentId,
            'sender_name' => $cleanName,
            'sender_email' => $cleanEmail,
            'sender_company' => $cleanCompany,
            'message' => $cleanMessage,
        ]);

        return redirect()->back()->with('status', '¡Tu mensaje ha sido enviado exitosamente al estudiante!');
    }

    /**
     * Start the demo container publicly when a recruiter clicks "Ejecutar demo".
     */
    public function startDemo(
        Project $project, 
        \App\Actions\Docker\StartProjectContainerAction $startAction,
        \App\Actions\Docker\StopProjectContainerAction $stopAction
    ): \Illuminate\Http\JsonResponse {
        session_write_close();
        $containerName = "project-{$project->id}";
        $domain = env('APP_DOMAIN', 'uleam-academic.software');

        // 1. Si el proyecto tiene un backend enlazado (ej: suite frontend + backend), asegurar que esté activo
        if ($project->backend_project_id) {
            $backend = Project::find($project->backend_project_id);
            if ($backend) {
                $backendContainer = "project-{$backend->id}";
                if (!$this->isContainerRunning($backendContainer)) {
                    $backendPath = storage_path("app/projects/project-{$backend->id}");
                    $backendResult = $startAction->execute($backend, $backendPath, $domain);
                    if ($backendResult['success']) {
                        $backend->status = 'running';
                        $backend->container_id = $backendResult['container_id'];
                        $backend->last_visited_at = now();
                        $backend->save();
                    }
                }
            }
        }

        $projectPath = storage_path("app/projects/project-{$project->id}");
        $resetLock = $projectPath . '/.resetting';

        // Si se estaba ejecutando una limpieza de base de datos en segundo plano, esperar a que culmine
        if (File::exists($resetLock)) {
            for ($i = 0; $i < 30; $i++) {
                if (!File::exists($resetLock)) {
                    break;
                }
                usleep(500000);
            }
        }

        // 2. Si el proyecto principal ya está corriendo, respondemos de inmediato
        if ($project->status === 'running' && $this->isContainerRunning($containerName)) {
            $project->last_visited_at = now();
            $project->save();
            return response()->json(['success' => true]);
        }

        // 3. Iniciar el proyecto principal si estaba detenido
        $result = $startAction->execute($project, $projectPath, $domain);
        
        if ($result['success']) {
            $project->status = 'running';
            $project->container_id = $result['container_id'];
            $project->last_visited_at = now();
            $project->save();
            
            // Esperar a que la aplicación interna esté lista y respondiendo en su puerto
            $containerIp = $this->getContainerIp($result['container_id']);
            $port = $this->getProjectPort($project, $projectPath);
            
            $isReady = false;
            for ($i = 0; $i < 60; $i++) {
                if ($this->checkPort($containerIp, $port)) {
                    $isReady = true;
                    break;
                }
                usleep(500000); // Esperar 500ms antes del siguiente intento (hasta 30 segundos)
            }
            
            if (!$isReady) {
                $logProcess = new \Symfony\Component\Process\Process(['docker', 'logs', '--tail', '50', $containerName]);
                $logProcess->run();
                $containerLogs = $logProcess->getOutput() . "\n" . $logProcess->getErrorOutput();

                $stopAction->execute($project);

                return response()->json([
                    'success' => false,
                    'error' => "El contenedor inició, pero la aplicación interna no abrió el puerto {$port} a tiempo. Posible error de inicialización o base de datos.",
                    'logs' => $containerLogs
                ], 500);
            }
            
            return response()->json(['success' => true]);
        }
        return response()->json(['success' => false, 'error' => $result['output']], 500);
    }

    /**
     * Obteer el puerto interno según el lenguaje del proyecto.
     */
    private function getProjectPort(\App\Models\Project $project, string $projectPath): int
    {
        switch ($project->language) {
            case 'nodejs':
                return 3000;
            case 'python':
                return 5000;
            case 'java':
                return 8080;
            case 'dotnet':
                return 80;
            case 'dockerfile':
                $dockerfilePath = \Illuminate\Support\Facades\File::exists($projectPath . '/Dockerfile')
                    ? $projectPath . '/Dockerfile'
                    : $projectPath . '/dockerfile';
                if (\Illuminate\Support\Facades\File::exists($dockerfilePath)) {
                    $content = \Illuminate\Support\Facades\File::get($dockerfilePath);
                    if (preg_match('/^EXPOSE\s+(\d+)/mi', $content, $matches)) {
                        return (int) $matches[1];
                    }
                }
                return 8080;
            case 'php':
            default:
                return 80;
        }
    }

    /**
     * Comprobar si el contenedor está corriendo físicamente en Docker.
     */
    private function isContainerRunning(string $containerName): bool
    {
        $process = new \Symfony\Component\Process\Process(['docker', 'inspect', '-f', '{{.State.Running}}', $containerName]);
        $process->run();
        return trim($process->getOutput()) === 'true';
    }

    /**
     * Obtener la dirección IP del contenedor dentro de la red privada uleam_academic_network.
     */
    private function getContainerIp(string $containerId): string
    {
        $process = new \Symfony\Component\Process\Process([
            'docker', 'inspect', $containerId,
            '--format={{json .NetworkSettings.Networks.uleam_academic_network.IPAddress}}'
        ]);
        $process->run();
        return trim($process->getOutput(), " \t\n\r\0\x0B\"");
    }

    /**
     * Comprobar si el puerto del contenedor está abierto y aceptando conexiones TCP.
     */
    private function checkPort(string $ip, int $port): bool
    {
        $connection = @fsockopen($ip, $port, $errno, $errstr, 1.0);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Reset the demo database in background when a recruiter clicks "Cerrar".
     */
    public function stopDemo(Project $project): \Illuminate\Http\JsonResponse
    {
        session_write_close();

        // Restablecer únicamente el proyecto actual y su backend enlazado (si aplica)
        $projectsToReset = collect([$project]);
        if ($project->backend_project_id) {
            $backend = Project::find($project->backend_project_id);
            if ($backend) {
                $projectsToReset->push($backend);
            }
        }

        foreach ($projectsToReset as $p) {
            if (!$p->db_name) {
                continue;
            }
            try {
                $artisanPath = base_path('artisan');
                $cmd = "nohup php " . escapeshellarg($artisanPath) . " projects:reset-databases --project=" . escapeshellarg($p->id) . " > /dev/null 2>&1 &";
                exec($cmd);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Error lanzando reset en background {$p->id}: " . $e->getMessage());
            }
        }

        return response()->json(['success' => true]);
    }
}
