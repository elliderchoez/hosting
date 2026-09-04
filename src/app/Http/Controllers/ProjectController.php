<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Deployment;
use App\Models\Profile;
use App\Jobs\BuildProjectJob;
use App\Actions\Docker\StopProjectContainerAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class ProjectController extends Controller
{
    /**
     * Display the student's dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Load or create student profile
        $profile = Profile::firstOrCreate(
            ['user_id' => $user->id],
            ['bio' => '', 'skills' => [], 'education' => []]
        );

        // Load student's projects with their latest deployment and backend relationship
        $projects = Project::where('user_id', $user->id)
            ->with([
                'backendProject',
                'deployments' => function ($query) {
                    $query->latest()->limit(5);
                }
            ])
            ->get();

        // Sincronizar el estado físico de Docker con la Base de Datos en lote (1 sola llamada CLI)
        $runningContainers = $this->getRunningContainerNames();
        foreach ($projects as $project) {
            if ($project->status === 'building') {
                continue;
            }
            
            $containerName = "project-{$project->id}";
            $isPhysicalRunning = in_array($containerName, $runningContainers, true);
            
            if ($isPhysicalRunning && $project->status !== 'running') {
                $project->status = 'running';
                $project->save();
            } elseif (!$isPhysicalRunning && $project->status === 'running') {
                $project->status = 'stopped';
                $project->save();
            }
        }

        return Inertia::render('Dashboard', [
            'profile' => $profile,
            'projects' => $projects,
            'status' => session('status')
        ]);
    }

    /**
     * Registrar un nuevo proyecto de estudiante e iniciar la cola de compilación.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $attachToProjectId = $request->input('attach_to_project_id');
        $parentProject = null;
        if ($attachToProjectId) {
            $parentProject = Project::where('user_id', $user->id)->findOrFail($attachToProjectId);
        }

        // Si es un proyecto principal (no un backend satélite), validar límite de 3 aplicaciones
        if (!$parentProject) {
            $mainProjectCount = Project::where('user_id', $user->id)->where('is_backend_service', false)->count();
            if ($mainProjectCount >= 3) {
                return redirect()->back()->withErrors(['error' => 'Has alcanzado el límite máximo de 3 aplicaciones principales.']);
            }
        }

        $isFolderUpload = $request->hasFile('folder_files');

        // 2. Validar entrada según el tipo de subida
        $rules = [
            'name' => 'required|string|max:100',
            'subdomain' => 'required|string|max:63|alpha_dash|unique:projects,subdomain',
            'root_dir' => 'nullable|string|max:255',
            'env_vars' => 'nullable|string',
            'attach_to_project_id' => 'nullable|uuid|exists:projects,id',
        ];

        if (!$isFolderUpload) {
            $rules['github_repo_url'] = [
                'required', 
                'string', 
                'url',
                'regex:/^https:\/\/github\.com\/[a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+$/i'
            ];
            $rules['branch'] = 'required|string|max:50';
        } else {
            $rules['folder_files'] = 'required|array';
            $rules['folder_paths'] = 'required|array';
        }

        $request->validate($rules);

        // Limpiar el formato del subdominio (minúsculas)
        $subdomain = Str::lower($request->subdomain);

        // 3. Crear el registro del proyecto en la base de datos
        $isBackendService = $parentProject !== null;
        $project = Project::create([
            'user_id' => $user->id,
            'is_backend_service' => $isBackendService,
            'name' => $request->name,
            'subdomain' => $subdomain,
            'github_repo_url' => $isFolderUpload ? 'Subido localmente' : $request->github_repo_url,
            'branch' => $isFolderUpload ? 'local' : $request->branch,
            'root_dir' => $request->root_dir,
            'env_vars' => $request->env_vars,
            'status' => 'building',
        ]);

        // Si se desplegó como backend de una aplicación existente, vincularlo y recompilar frontend
        if ($parentProject) {
            $parentProject->backend_project_id = $project->id;
            $scheme = request()->getScheme() ?: 'http';
            $backendApiUrl = "{$scheme}://{$subdomain}.uleam-academic.software/api";
            $vars = [
                "VITE_API_URL={$backendApiUrl}",
                "REACT_APP_API_URL={$backendApiUrl}",
                "NEXT_PUBLIC_API_URL={$backendApiUrl}",
                "API_URL={$backendApiUrl}",
                "BACKEND_URL={$scheme}://{$subdomain}.uleam-academic.software"
            ];
            $parentProject->env_vars = implode("\n", $vars);
            $parentProject->save();

            // Recompilar el frontend automáticamente para que Vite integre las nuevas variables de entorno
            $parentDeployment = Deployment::create([
                'project_id' => $parentProject->id,
                'status' => 'queued',
                'build_log' => 'Recompilando frontend para integrar la URL del nuevo backend API...',
            ]);
            BuildProjectJob::dispatch($parentDeployment);
        }

        // 4. Si es subida de carpeta local o archivo ZIP
        if ($isFolderUpload) {
            $projectPath = storage_path("app/projects/project-{$project->id}");

            // Crear el directorio limpio
            if (File::exists($projectPath)) {
                File::deleteDirectory($projectPath);
            }
            File::makeDirectory($projectPath, 0755, true, true);

            $files = $request->file('folder_files');
            $paths = $request->input('folder_paths');

            // Verificar si es un archivo .zip único
            if (count($files) === 1 && strtolower($files[0]->getClientOriginalExtension()) === 'zip') {
                $zipFile = $files[0];
                
                // Validar tamaño del archivo ZIP (máximo 50MB)
                if ($zipFile->getSize() > 52428800) {
                    $project->delete();
                    return redirect()->back()->withErrors(['error' => 'El archivo ZIP supera el límite permitido de 50MB.']);
                }

                $zip = new \ZipArchive;
                if ($zip->open($zipFile->getRealPath()) === true) {
                    $zip->extractTo($projectPath);
                    $zip->close();
                } else {
                    $project->delete();
                    return redirect()->back()->withErrors(['error' => 'No se pudo descomprimir el archivo ZIP. Asegúrate de que no esté dañado o encriptado con contraseña.']);
                }
            } else {
                // Estructura de directorio convencional
                // Validar tamaño acumulado (máximo 50MB = 52428800 bytes)
                $totalSize = 0;
                foreach ($files as $file) {
                    $totalSize += $file->getSize();
                }
                if ($totalSize > 52428800) {
                    $project->delete();
                    return redirect()->back()->withErrors(['error' => 'El tamaño total de los archivos del proyecto supera el límite permitido de 50MB.']);
                }

                foreach ($files as $index => $file) {
                    $relativePath = $paths[$index] ?? null;
                    if (!$relativePath) {
                        continue;
                    }

                    // Sanitizar la ruta relativa para prevenir ataques de Directory Traversal
                    $relativePath = preg_replace('#\.\.[\\/]#', '', $relativePath);
                    $relativePath = ltrim($relativePath, './\\ ');
                    $relativePath = str_replace('\\', '/', $relativePath);

                    $fullFilePath = $projectPath . '/' . $relativePath;

                    // Crear los directorios padres si no existen
                    File::makeDirectory(dirname($fullFilePath), 0755, true, true);

                    // Mover el archivo a su ubicación correcta
                    $file->move(dirname($fullFilePath), basename($fullFilePath));
                }
            }
        }

        // 5. Crear el despliegue inicial en cola
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'build_log' => $isFolderUpload ? 'Archivos locales guardados. Iniciando análisis y compilación...' : 'Build encolado. Esperando clonación de Git...',
        ]);

        // 6. Despachar el trabajo de compilación en segundo plano
        BuildProjectJob::dispatch($deployment);

        $msg = $parentProject 
            ? "Servicio Backend '{$project->name}' registrado e integrado a '{$parentProject->name}'."
            : "Proyecto registrado. Compilación iniciada en segundo plano.";

        return redirect()->route('dashboard')->with('status', $msg);
    }

    /**
     * Vincular o desvincular un servicio backend a una aplicación frontend.
     */
    public function linkBackend(Request $request, Project $project): RedirectResponse
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $request->validate([
            'backend_project_id' => 'nullable|uuid|exists:projects,id'
        ]);

        $backendId = $request->input('backend_project_id');
        if ($backendId) {
            $backend = Project::where('user_id', Auth::id())->findOrFail($backendId);
            $backend->is_backend_service = true;
            $backend->save();

            $project->backend_project_id = $backend->id;
            $scheme = request()->getScheme() ?: 'http';
            $backendApiUrl = "{$scheme}://{$backend->subdomain}.uleam-academic.software/api";
            $vars = [
                "VITE_API_URL={$backendApiUrl}",
                "REACT_APP_API_URL={$backendApiUrl}",
                "NEXT_PUBLIC_API_URL={$backendApiUrl}",
                "API_URL={$backendApiUrl}",
                "BACKEND_URL={$scheme}://{$backend->subdomain}.uleam-academic.software"
            ];
            $project->env_vars = implode("\n", $vars);
            $project->save();

            // Recompilar el frontend automáticamente para que Vite integre las nuevas variables de entorno
            $parentDeployment = Deployment::create([
                'project_id' => $project->id,
                'status' => 'queued',
                'build_log' => "Recompilando frontend para integrar la URL del backend '{$backend->name}'...",
            ]);
            BuildProjectJob::dispatch($parentDeployment);

            return redirect()->back()->with('status', "Backend '{$backend->name}' vinculado exitosamente a '{$project->name}'.");
        } else {
            if ($project->backend_project_id) {
                $oldBackend = Project::find($project->backend_project_id);
                if ($oldBackend) {
                    $oldBackend->is_backend_service = false;
                    $oldBackend->save();
                }
            }
            $project->backend_project_id = null;
            $project->save();
            return redirect()->back()->with('status', "Backend desvinculado.");
        }
    }

    /**
     * Trigger a rebuild for an existing project.
     */
    public function rebuild(Project $project): RedirectResponse
    {
        // Authorize
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $project->status = 'building';
        $project->save();

        // Create new deployment
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'build_log' => 'Build de actualización encolado...',
        ]);

        // Trigger job
        BuildProjectJob::dispatch($deployment);

        return redirect()->route('dashboard')->with('status', 'Reconstrucción encolada en segundo plano.');
    }

    /**
     * Delete a project, stop its container, and clean its files.
     */
    public function destroy(Project $project, StopProjectContainerAction $stopAction): RedirectResponse
    {
        // Authorize
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        // 1. Stop and remove Docker container
        $stopAction->execute($project);

        // 2. Clean project storage directory
        $projectPath = storage_path("app/projects/project-{$project->id}");
        if (File::exists($projectPath)) {
            File::deleteDirectory($projectPath);
        }

        // Clean database and database user if present
        if (!empty($project->db_name)) {
            try {
                $dbname = $project->db_name;
                $dbuser = $project->db_user;
                if ($project->db_driver === 'mysql') {
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("DROP DATABASE IF EXISTS {$dbname};");
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("DROP USER IF EXISTS '{$dbuser}'@'%';");
                } else {
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("DROP DATABASE IF EXISTS {$dbname};");
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("DROP USER IF EXISTS {$dbuser};");
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Error dropping student database {$project->db_name}: " . $e->getMessage());
            }
        }

        // 3. Delete from database
        $project->delete();

        return redirect()->route('dashboard')->with('status', 'Proyecto eliminado exitosamente.');
    }

    /**
     * Obtener los nombres de todos los contenedores Docker que están corriendo actualmente.
     * Realiza una sola llamada CLI en lugar de N llamadas secuenciales.
     */
    private function getRunningContainerNames(): array
    {
        try {
            $process = new \Symfony\Component\Process\Process(['docker', 'ps', '--format', '{{.Names}}']);
            $process->run();
            if ($process->isSuccessful()) {
                return array_filter(array_map('trim', explode("\n", $process->getOutput())));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Error obteniendo contenedores Docker: " . $e->getMessage());
        }
        return [];
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
     * Update demo instructions for the evaluator.
     */
    public function updateInstructions(\Illuminate\Http\Request $request, Project $project): \Illuminate\Http\RedirectResponse
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $request->validate([
            'demo_instructions' => 'nullable|string|max:1000'
        ]);

        $project->update([
            'demo_instructions' => $request->demo_instructions
        ]);

        return redirect()->route('dashboard')->with('status', 'Instrucciones de la demo actualizadas con éxito.');
    }
}
