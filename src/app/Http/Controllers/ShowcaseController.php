<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Project;
use App\Models\ContactLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class ShowcaseController extends Controller
{
    /**
     * Display the public vitrina of student projects.
     */
    public function index(): Response
    {
        $projects = Project::with(['user.profile'])
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
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'company' => 'required|string|max:255',
            'message' => 'required|string|min:10',
        ]);

        // Simular o guardar el contacto (En producción se enviará correo)
        // Para simplificar, si el reclutador no está logueado en una sesión especial,
        // guardamos su contacto en la tabla contact_logs asociándolo con el estudiante
        // y simulando un reclutador temporal si no existe.
        
        // Registrar en logs de auditoría de tesis
        ContactLog::create([
            'recruiter_id' => null, // Opcional si es anónimo/formulario de contacto directo
            'student_id' => $studentId,
            'message' => "De: {$request->name} ({$request->company}) - Email: {$request->email}\n\nMensaje:\n{$request->message}"
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
        
        // Si el estado en BD es 'running' y el contenedor en Docker está activo, abrimos al instante
        if ($project->status === 'running' && $this->isContainerRunning($containerName)) {
            return response()->json(['success' => true]);
        }

        // Si está en sleeping/stopped o el contenedor no estaba activo, lo encendemos
        $projectPath = storage_path("app/projects/project-{$project->id}");
        $domain = env('APP_DOMAIN', 'uleam-academic.software');
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
            for ($i = 0; $i < 40; $i++) {
                if ($this->checkPort($containerIp, $port)) {
                    $isReady = true;
                    break;
                }
                usleep(500000); // Esperar 500ms antes del siguiente intento
            }
            
            if (!$isReady) {
                // Obtener los logs de error del contenedor
                $logProcess = new \Symfony\Component\Process\Process(['docker', 'logs', '--tail', '50', $containerName]);
                $logProcess->run();
                $containerLogs = $logProcess->getOutput() . "\n" . $logProcess->getErrorOutput();

                // Detener y eliminar el contenedor fallido
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
     * Reset the demo database publicly when a recruiter clicks "Cerrar".
     */
    public function stopDemo(Project $project): \Illuminate\Http\JsonResponse
    {
        session_write_close();

        // Reset databases for ALL projects belonging to the same student/user
        // to ensure frontend + backend/API projects are reset together.
        $studentProjects = Project::where('user_id', $project->user_id)
            ->whereNotNull('db_name')
            ->get();

        foreach ($studentProjects as $p) {
            try {
                \Illuminate\Support\Facades\Artisan::queue('projects:reset-databases', [
                    '--project' => $p->id
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Error queuing database reset for project {$p->id}: " . $e->getMessage());
            }
        }

        return response()->json(['success' => true]);
    }
}
