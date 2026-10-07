<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Project;
use App\Models\Deployment;
use App\Models\ContactLog;
use App\Actions\Docker\StopProjectContainerAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class AdminController extends Controller
{
    /**
     * Display the Admin Central Console.
     */
    public function index(Request $request): Response
    {
        $telemetry = $this->getTelemetryData();
        $metrics = $this->getInstitutionalMetrics();

        // Directorio de estudiantes con conteo de aplicaciones activas (principales, de 3 permitidas)
        $students = User::where('role', 'student')
            ->withCount(['projects as active_projects_count' => function ($query) {
                $query->where('is_backend_service', false);
            }])
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'blocked_reason', 'created_at']);

        // Proyectos globales para moderación y soporte
        $projects = Project::with([
                'user:id,name,email',
                'backendProject:id,name,subdomain',
                'deployments' => function ($query) {
                    $query->latest()->limit(1);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'telemetry' => $telemetry,
            'metrics' => $metrics,
            'students' => $students,
            'projects' => $projects,
        ]);
    }

    /**
     * Return real-time host telemetry as JSON for live polling.
     */
    public function telemetry(): JsonResponse
    {
        return response()->json($this->getTelemetryData());
    }

    /**
     * Safe Docker Prune:
     * strictly runs 'docker image prune -f' to remove old dangling layers
     * while guaranteeing library caches (uleam_*_cache) remain 100% intact.
     */
    public function pruneDockerImages(): JsonResponse
    {
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
            Cache::put('docker_last_prune_trigger', 'manual', now()->addDays(30));

            return response()->json([
                'success' => true,
                'message' => "Limpieza segura completada con éxito. Espacio recuperado: {$reclaimedSpace}. Todas las imágenes etiquetadas y cachés institucionales (uleam_*_cache) se mantuvieron 100% intactas.",
                'reclaimed' => $reclaimedSpace,
                'raw_output' => $output,
                'telemetry' => $this->getTelemetryData(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error durante la ejecución de prune: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Switch de visibilidad en vitrina (ocultar/mostrar en la portada pública).
     */
    public function toggleShowcaseVisibility(Project $project): RedirectResponse
    {
        $project->is_visible_in_showcase = ! $project->is_visible_in_showcase;
        $project->save();

        $statusMsg = $project->is_visible_in_showcase
            ? "El proyecto '{$project->name}' ahora es visible en la Vitrina Pública."
            : "El proyecto '{$project->name}' ha sido ocultado de la Vitrina Pública (el estudiante conserva su proyecto).";

        return back()->with('status', $statusMsg);
    }

    /**
     * Pausar proyecto por infracción normativa (plagio, phishing, contenido prohibido).
     */
    public function suspendProject(
        Request $request,
        Project $project,
        StopProjectContainerAction $stopAction
    ): RedirectResponse {
        $request->validate([
            'reason' => 'required|string|min:4|max:1000',
        ], [
            'reason.required' => 'Debes especificar el motivo de la infracción para el estudiante.',
        ]);

        // Si el contenedor está encendido, detenerlo inmediatamente por seguridad
        if ($project->status === 'running') {
            $stopAction->execute($project);
            $project->status = 'stopped';
            $project->container_id = null;
        }

        $project->is_suspended = true;
        $project->suspension_reason = strip_tags(trim($request->reason));
        $project->save();

        return back()->with('status', "El proyecto '{$project->name}' ha sido pausado por infracción normativa.");
    }

    /**
     * Levantar la pausa de un proyecto por parte del administrador.
     */
    public function unsuspendProject(Project $project): RedirectResponse
    {
        $project->is_suspended = false;
        $project->suspension_reason = null;
        $project->save();

        return back()->with('status', "La suspensión del proyecto '{$project->name}' ha sido levantada satisfactoriamente.");
    }

    /**
     * Acceso global a logs de compilación (build_log) y logs de contenedor para dar soporte técnico.
     */
    public function projectBuildLogs(Project $project): JsonResponse
    {
        // 1. Cargar relación de usuario si no está presente
        $project->loadMissing('user');

        // 2. Obtener última compilación
        $deployments = Deployment::where('project_id', $project->id)
            ->latest()
            ->limit(10)
            ->get();

        $latestDeployment = $deployments->first();
        $buildLog = $latestDeployment ? $latestDeployment->build_log : "No hay registros de compilación registrados para este proyecto.";

        // 3. Obtener logs actuales del contenedor Docker
        if ($project->status === 'sleeping') {
            $containerLog = "El contenedor se encuentra actualmente en modo reposo (Auto-Sleep). Su proceso se reanudará en milisegundos cuando un usuario visite el subdominio.";
        } elseif ($project->status === 'stopped') {
            $containerLog = "El contenedor se encuentra apagado actualmente. No hay proceso de runtime activo.";
        } else {
            $containerName = "project-{$project->id}";
            $process = new Process(['docker', 'logs', '--tail', '120', $containerName]);
            $process->run();
            $containerLog = $process->getOutput() ?: $process->getErrorOutput();

            if (empty(trim($containerLog)) || str_contains($containerLog, 'No such container')) {
                $containerLog = "El contenedor no está activo en este momento en el Docker Daemon o no ha emitido salidas recientes.";
            }
        }

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'subdomain' => $project->subdomain,
                'language' => $project->language,
                'framework' => $project->framework,
                'status' => $project->status,
                'student' => $project->user ? $project->user->name : 'Estudiante ULEAM',
                'student_email' => $project->user ? $project->user->email : '',
            ],
            'build_log' => $buildLog,
            'container_log' => $containerLog,
            'deployments' => $deployments,
        ]);
    }

    /**
     * Inactivar / Bloquear cuenta de estudiante.
     */
    public function toggleUserStatus(Request $request, User $user): RedirectResponse
    {
        // Evitar que el administrador se bloquee a sí mismo
        if ($user->id === Auth::id()) {
            return back()->withErrors(['error' => 'No puedes bloquear tu propia cuenta administrativa.']);
        }

        $willBeActive = ! $user->is_active;

        if (! $willBeActive) {
            $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);
            $user->is_active = false;
            $user->blocked_reason = $request->reason ? strip_tags(trim($request->reason)) : 'Cuenta inhabilitada por administración institucional.';

            // Cerrar cualquier sesión activa de este usuario de inmediato
            try {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } catch (\Exception $e) {
                // Silently continue if sessions table driver is different
            }

            $message = "La cuenta del estudiante {$user->name} ({$user->email}) ha sido bloqueada.";
        } else {
            $user->is_active = true;
            $user->blocked_reason = null;
            $message = "La cuenta del estudiante {$user->name} ({$user->email}) ha sido reactivada.";
        }

        $user->save();

        return back()->with('status', $message);
    }

    /**
     * Recopilar telemetría del sistema host en tiempo real.
     */
    private function getTelemetryData(): array
    {
        // 1. Monitor del Disco del Servidor
        $diskPath = '/';
        $totalDiskBytes = @disk_total_space($diskPath) ?: (100 * 1024 * 1024 * 1024);
        $freeDiskBytes = @disk_free_space($diskPath) ?: (40 * 1024 * 1024 * 1024);
        $usedDiskBytes = max(0, $totalDiskBytes - $freeDiskBytes);

        $diskTotalGb = round($totalDiskBytes / 1073741824, 2);
        $diskFreeGb = round($freeDiskBytes / 1073741824, 2);
        $diskUsedGb = round($usedDiskBytes / 1073741824, 2);
        $diskUsedPercent = $diskTotalGb > 0 ? round(($diskUsedGb / $diskTotalGb) * 100, 1) : 0;

        // Auto-limpieza preventiva si el almacenamiento alcanza o supera el 80%
        if ($diskUsedPercent >= 80) {
            $lastPrune = Cache::get('docker_last_prune_at');
            $canAutoPrune = ! $lastPrune || now()->diffInMinutes(\Carbon\Carbon::parse($lastPrune)) >= 30;

            if ($canAutoPrune) {
                try {
                    $pruneProc = new Process(['docker', 'image', 'prune', '-f']);
                    $pruneProc->setTimeout(180);
                    $pruneProc->run();

                    $pOutput = $pruneProc->getOutput();
                    $reclaimed = '0 B';
                    if (preg_match('/Total reclaimed space:\s*([^\r\n]+)/i', $pOutput, $matches)) {
                        $reclaimed = trim($matches[1]);
                    }

                    Cache::put('docker_last_prune_at', now()->format('Y-m-d H:i:s'), now()->addDays(30));
                    Cache::put('docker_last_prune_reclaimed', $reclaimed, now()->addDays(30));
                    Cache::put('docker_last_prune_trigger', 'auto_threshold', now()->addDays(30));

                    // Recalcular métricas de disco tras liberar espacio
                    $freeDiskBytes = @disk_free_space($diskPath) ?: $freeDiskBytes;
                    $usedDiskBytes = max(0, $totalDiskBytes - $freeDiskBytes);
                    $diskFreeGb = round($freeDiskBytes / 1073741824, 2);
                    $diskUsedGb = round($usedDiskBytes / 1073741824, 2);
                    $diskUsedPercent = $diskTotalGb > 0 ? round(($diskUsedGb / $diskTotalGb) * 100, 1) : 0;
                } catch (\Exception $e) {
                    // Silently continue to prevent breaking telemetry
                }
            }
        }

        // 2. Monitor de RAM de la Máquina Virtual
        $ramTotalMb = 0;
        $ramFreeMb = 0;
        $ramUsedMb = 0;
        $ramUsedPercent = 0;

        $memInfo = @file_get_contents('/proc/meminfo');
        if ($memInfo) {
            preg_match('/MemTotal:\s+(\d+)/', $memInfo, $memTotalMatch);
            preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $memAvailMatch);
            preg_match('/MemFree:\s+(\d+)/', $memInfo, $memFreeMatch);
            preg_match('/Buffers:\s+(\d+)/', $memInfo, $buffersMatch);
            preg_match('/Cached:\s+(\d+)/', $memInfo, $cachedMatch);

            $totalKb = (int) ($memTotalMatch[1] ?? 0);
            $availKb = (int) ($memAvailMatch[1] ?? (($memFreeMatch[1] ?? 0) + ($buffersMatch[1] ?? 0) + ($cachedMatch[1] ?? 0)));
            $usedKb = max(0, $totalKb - $availKb);

            $ramTotalMb = round($totalKb / 1024, 1);
            $ramFreeMb = round($availKb / 1024, 1);
            $ramUsedMb = round($usedKb / 1024, 1);
            $ramUsedPercent = $ramTotalMb > 0 ? round(($ramUsedMb / $ramTotalMb) * 100, 1) : 0;
        }

        // 3. Monitor de CPU de la VM
        $load = sys_getloadavg() ?: [0, 0, 0];
        $cores = 1;
        $nproc = @shell_exec('nproc');
        if ($nproc && is_numeric(trim($nproc))) {
            $cores = (int) trim($nproc);
        }
        $cpuPercent = min(100, round(($load[0] / max(1, $cores)) * 100, 1));

        // 4. Estado de Contenedores
        $runningCount = Project::where('status', 'running')->count();
        $sleepingCount = Project::where('status', 'sleeping')->count();
        $stoppedCount = Project::where('status', 'stopped')->count();
        $buildingCount = Project::where('status', 'building')->count();
        $suspendedCount = Project::where('is_suspended', true)->count();
        $totalProjectsCount = Project::count();

        // Contenedores físicos activos en el daemon Docker
        $dockerPs = @shell_exec('docker ps -q 2>/dev/null | wc -l');
        $dockerPhysicalCount = is_numeric(trim($dockerPs ?? '')) ? (int) trim($dockerPs) : $runningCount;

        return [
            'disk' => [
                'total_gb' => $diskTotalGb,
                'free_gb' => $diskFreeGb,
                'used_gb' => $diskUsedGb,
                'used_percent' => $diskUsedPercent,
                'status' => $diskUsedPercent > 85 ? 'danger' : ($diskUsedPercent > 70 ? 'warning' : 'healthy'),
            ],
            'ram' => [
                'total_mb' => $ramTotalMb,
                'used_mb' => $ramUsedMb,
                'free_mb' => $ramFreeMb,
                'used_percent' => $ramUsedPercent,
                'status' => $ramUsedPercent > 85 ? 'danger' : ($ramUsedPercent > 70 ? 'warning' : 'healthy'),
            ],
            'cpu' => [
                'load_1m' => round($load[0], 2),
                'load_5m' => round($load[1], 2),
                'load_15m' => round($load[2], 2),
                'cores' => $cores,
                'used_percent' => $cpuPercent,
                'status' => $cpuPercent > 85 ? 'danger' : ($cpuPercent > 70 ? 'warning' : 'healthy'),
            ],
            'containers' => [
                'running' => $runningCount,
                'sleeping' => $sleepingCount,
                'stopped' => $stoppedCount,
                'building' => $buildingCount,
                'suspended' => $suspendedCount,
                'total' => $totalProjectsCount,
                'docker_physical' => $dockerPhysicalCount,
            ],
            'auto_prune' => [
                'threshold_percent' => 80,
                'last_run_at' => Cache::get('docker_last_prune_at'),
                'last_reclaimed' => Cache::get('docker_last_prune_reclaimed'),
                'last_trigger' => Cache::get('docker_last_prune_trigger', 'none'),
            ],
            'timestamp' => now()->format('H:i:s'),
        ];
    }

    /**
     * Recopilar métricas institucionales cuantitativas para informes de carrera.
     */
    private function getInstitutionalMetrics(): array
    {
        $totalProjects = Project::count();
        $mainProjects = Project::where('is_backend_service', false)->count();
        $backendServices = Project::where('is_backend_service', true)->count();

        // Desglose por lenguajes de programación
        $languagesBreakdown = Project::select('language', DB::raw('count(*) as count'))
            ->groupBy('language')
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) use ($totalProjects) {
                return [
                    'language' => $item->language ?: 'desconocido',
                    'count' => (int) $item->count,
                    'percentage' => $totalProjects > 0 ? round(($item->count / $totalProjects) * 100, 1) : 0,
                ];
            });

        // Desglose por categorías temáticas
        $categoriesBreakdown = Project::select('category', DB::raw('count(*) as count'))
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderBy('count', 'desc')
            ->get();

        // Número de demostraciones evaluadas en la vitrina
        $totalDemoRuns = (int) Project::sum('demo_runs_count');
        $projectsEvaluatedCount = Project::where('demo_runs_count', '>', 0)->count();

        // Métricas de reclutadores y vinculación externa
        $totalContacts = ContactLog::count();

        // Métricas de estudiantes
        $totalStudents = User::where('role', 'student')->count();
        $activeStudents = User::where('role', 'student')
            ->whereHas('projects')
            ->count();

        return [
            'total_projects' => $totalProjects,
            'main_projects' => $mainProjects,
            'backend_services' => $backendServices,
            'languages_breakdown' => $languagesBreakdown,
            'categories_breakdown' => $categoriesBreakdown,
            'total_demo_runs' => $totalDemoRuns,
            'projects_evaluated_count' => $projectsEvaluatedCount,
            'total_contacts' => $totalContacts,
            'total_students' => $totalStudents,
            'active_students' => $activeStudents,
        ];
    }
}
