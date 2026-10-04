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
            ->whereIn('status', ['running', 'sleeping', 'stopped'])
            ->orderByRaw("CASE 
                WHEN status = 'running' THEN 1 
                WHEN status = 'sleeping' THEN 2 
                ELSE 3 
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
        $student = User::with([
            'profile',
            'projects' => function ($query) {
                $query->where('is_backend_service', false)
                      ->whereIn('status', ['running', 'sleeping', 'stopped'])
                      ->orderByRaw("CASE WHEN status = 'running' THEN 1 WHEN status = 'sleeping' THEN 2 ELSE 3 END")
                      ->orderBy('updated_at', 'desc');
            }
        ])->findOrFail($userId);

        return Inertia::render('StudentProfile', [
            'student' => $student,
            'auth' => [
                'user' => auth()->user(),
            ],
        ]);
    }

    /**
     * Display the student's printable curriculum vitae.
     */
    public function studentCv(string $userId)
    {
        $student = User::with([
            'profile',
            'projects' => function ($query) {
                $query->where('is_backend_service', false);
            }
        ])->findOrFail($userId);

        $profile = $student->profile ?? new \App\Models\Profile(['skills' => [], 'education' => []]);

        return view('cv_template', [
            'user' => $student,
            'profile' => $profile,
            'projects' => $student->projects
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
        $domain = env('APP_DOMAIN', 'nexus-academic.software');

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

                // Pre-calentar el backend enlazado en Traefik para que el frontend no encuentre errores 502 al conectar
                $backendHost = "{$backend->subdomain}.localhost";
                for ($k = 0; $k < 15; $k++) {
                    if ($this->checkTraefikRouting($backendHost)) {
                        break;
                    }
                    usleep(300000);
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

        // 2. Si el proyecto principal ya está corriendo, verificar que Traefik responda HTTP 200 (no 502)
        if ($project->status === 'running' && $this->isContainerRunning($containerName)) {
            $project->last_visited_at = now();
            $project->save();

            $host = "{$project->subdomain}.localhost";
            for ($j = 0; $j < 15; $j++) {
                if ($this->checkTraefikRouting($host)) {
                    break;
                }
                usleep(400000);
            }

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

            // Esperar a que el proxy inverso Traefik sincronice las etiquetas del contenedor y enrute el host con respuesta HTTP exitosa
            $host = "{$project->subdomain}.localhost";
            for ($j = 0; $j < 25; $j++) {
                if ($this->checkTraefikRouting($host)) {
                    break;
                }
                usleep(500000); // 500ms entre intentos (hasta 12.5 segundos máx)
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
            case 'ruby':
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
     * Comprobar si el proxy inverso Traefik ya resolvió y enrutó el subdominio y el servicio responde HTTP exitoso (no 404 ni 502 Bad Gateway).
     */
    private function checkTraefikRouting(string $host): bool
    {
        $ch = curl_init("http://127.0.0.1/");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Host: {$host}"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1000);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Si la ruta raíz devuelve error (404 o 502/503), aún no está listo
        if ($httpCode < 200 || $httpCode >= 400) {
            return false;
        }

        // Si la aplicación posee un backend API interno en proxy (monorepos como Jira o suites),
        // verificar que las rutas de API no respondan 502 Bad Gateway mientras el backend inicia
        foreach (['/currentUser', '/api', '/authentication/guest'] as $endpoint) {
            $chApi = curl_init("http://127.0.0.1{$endpoint}");
            curl_setopt($chApi, CURLOPT_HTTPHEADER, ["Host: {$host}"]);
            curl_setopt($chApi, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chApi, CURLOPT_TIMEOUT_MS, 1000);
            curl_setopt($chApi, CURLOPT_CONNECTTIMEOUT_MS, 800);
            $apiRes = curl_exec($chApi);
            $apiCode = curl_getinfo($chApi, CURLINFO_HTTP_CODE);

            if ($apiCode === 502 || $apiCode === 503 || $apiCode === 504) {
                return false;
            }
        }

        return true;
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
