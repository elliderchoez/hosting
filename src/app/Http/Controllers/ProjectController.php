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
        $isFullstack = $request->input('deployment_mode') === 'fullstack' && !$parentProject;

        // Si es despliegue de Suite Fullstack (Frontend + Backend en un solo paso)
        if ($isFullstack) {
            $backendSubdomainCandidate = Str::lower($request->input('backend_subdomain'));

            // Auto-limpieza de seguridad: si existe un backend huérfano con este subdominio sin frontend asociado, limpiarlo
            if ($backendSubdomainCandidate) {
                $orphanedBackend = Project::where('user_id', $user->id)
                    ->where('subdomain', $backendSubdomainCandidate)
                    ->where('is_backend_service', true)
                    ->first();
                if ($orphanedBackend) {
                    $isLinked = Project::where('backend_project_id', $orphanedBackend->id)->exists();
                    if (!$isLinked) {
                        $this->cleanupProjectResources($orphanedBackend, app(StopProjectContainerAction::class));
                        $orphanedBackend->delete();
                    }
                }
            }

            $fullstackSource = $request->input('fullstack_source', 'monorepo');
            $rules = [
                'name' => 'required|string|max:100',
                'subdomain' => 'required|string|max:63|alpha_dash|unique:projects,subdomain',
                'backend_subdomain' => 'required|string|max:63|alpha_dash|unique:projects,subdomain|different:subdomain',
                'frontend_dir' => 'nullable|string|max:255',
                'backend_dir' => 'nullable|string|max:255',
                'category' => 'required|string|max:100',
            ];

            if (!$isFolderUpload) {
                $rules['github_repo_url'] = [
                    'required', 
                    'string', 
                    'url',
                    'regex:/^https:\/\/github\.com\/[a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+$/i'
                ];
                $rules['branch'] = 'required|string|max:50';

                if ($fullstackSource === 'separate') {
                    $rules['backend_github_repo_url'] = [
                        'required', 
                        'string', 
                        'url',
                        'regex:/^https:\/\/github\.com\/[a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+$/i'
                    ];
                    $rules['backend_branch'] = 'nullable|string|max:50';
                }
            } else {
                $rules['folder_files'] = 'required|array';
                $rules['folder_paths'] = 'required|array';
            }

            $request->validate($rules, [
                'category.required' => 'Elige una categoría para el proyecto.',
                'name.required' => 'El nombre del proyecto es obligatorio.',
                'subdomain.required' => 'El subdominio es obligatorio.',
                'subdomain.unique' => 'Este subdominio ya está en uso.',
                'backend_subdomain.required' => 'El subdominio del backend es obligatorio.',
                'backend_subdomain.unique' => 'Este subdominio de backend ya está en uso.',
            ]);

            $frontendSubdomain = Str::lower($request->subdomain);
            $backendSubdomain = Str::lower($request->backend_subdomain);
            $scheme = request()->getScheme() ?: 'http';
            $baseDomain = config('app.env') === 'production' ? env('APP_DOMAIN', 'nexus-academic.software') : 'localhost';
            $backendApiUrl = "{$scheme}://{$backendSubdomain}.{$baseDomain}/api";

            // 1. Crear el Backend (Servicio API satélite)
            $backendRepo = ($fullstackSource === 'separate' && !$isFolderUpload)
                ? $request->backend_github_repo_url
                : ($isFolderUpload ? 'Subido localmente' : $request->github_repo_url);
            $backendBranch = ($fullstackSource === 'separate' && !$isFolderUpload)
                ? ($request->backend_branch ?: 'main')
                : ($isFolderUpload ? 'local' : $request->branch);
            $backendRootDir = $request->input('backend_dir') ?: 'backend';

            $backendProject = Project::create([
                'user_id' => $user->id,
                'is_backend_service' => true,
                'name' => "{$request->name} (API Backend)",
                'subdomain' => $backendSubdomain,
                'github_repo_url' => $backendRepo,
                'branch' => $backendBranch,
                'root_dir' => $backendRootDir,
                'env_vars' => null,
                'category' => $request->input('category', 'Herramientas y Calculadoras'),
                'status' => 'building',
            ]);

            // 2. Crear el Frontend (Aplicación principal enlazada al Backend)
            $vars = [
                "VITE_API_URL={$backendApiUrl}",
                "REACT_APP_API_URL={$backendApiUrl}",
                "NEXT_PUBLIC_API_URL={$backendApiUrl}",
                "API_URL={$backendApiUrl}",
                "BACKEND_URL={$scheme}://{$backendSubdomain}.{$baseDomain}"
            ];
            $frontendRootDir = $request->input('frontend_dir') ?: 'frontend';

            $frontendProject = Project::create([
                'user_id' => $user->id,
                'is_backend_service' => false,
                'backend_project_id' => $backendProject->id,
                'name' => $request->name,
                'subdomain' => $frontendSubdomain,
                'github_repo_url' => $isFolderUpload ? 'Subido localmente' : $request->github_repo_url,
                'branch' => $isFolderUpload ? 'local' : $request->branch,
                'root_dir' => $frontendRootDir,
                'env_vars' => implode("\n", $vars),
                'category' => $request->input('category', 'Herramientas y Calculadoras'),
                'status' => 'building',
            ]);

            // Si es subida local o archivo ZIP, replicar archivos a ambos proyectos
            if ($isFolderUpload) {
                $backendPath = storage_path("app/projects/project-{$backendProject->id}");
                $frontendPath = storage_path("app/projects/project-{$frontendProject->id}");
                $this->saveUploadedFiles($request, $backendProject, $backendPath);
                $this->saveUploadedFiles($request, $frontendProject, $frontendPath);
            }

            // Despachar builds de ambos componentes
            $backendDeployment = Deployment::create([
                'project_id' => $backendProject->id,
                'status' => 'queued',
                'build_log' => $isFolderUpload ? 'Archivos locales guardados. Iniciando análisis y compilación de Backend...' : 'Build de Backend encolado. Esperando clonación de Git...',
            ]);
            BuildProjectJob::dispatch($backendDeployment);

            $frontendDeployment = Deployment::create([
                'project_id' => $frontendProject->id,
                'status' => 'queued',
                'build_log' => $isFolderUpload ? 'Archivos locales guardados. Iniciando análisis y compilación de Frontend...' : 'Build de Frontend encolado. Esperando clonación de Git...',
            ]);
            BuildProjectJob::dispatch($frontendDeployment);

            return redirect()->route('dashboard')->with('status', "Suite Fullstack '{$request->name}' registrada con éxito. Compilación de Frontend y Backend iniciada en segundo plano.");
        }

        // 2. Despliegue Estándar (Monolito o Aplicación Individual)
        $rules = [
            'name' => 'required|string|max:100',
            'subdomain' => 'required|string|max:63|alpha_dash|unique:projects,subdomain',
            'root_dir' => 'nullable|string|max:255',
            'env_vars' => 'nullable|string',
            'attach_to_project_id' => 'nullable|uuid|exists:projects,id',
            'category' => 'required|string|max:100',
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

        $request->validate($rules, [
            'category.required' => 'Elige una categoría para el proyecto.',
            'name.required' => 'El nombre del proyecto es obligatorio.',
            'subdomain.required' => 'El subdominio es obligatorio.',
            'subdomain.unique' => 'Este subdominio ya está en uso.',
        ]);

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
            'category' => $request->input('category', 'Herramientas y Calculadoras'),
            'status' => 'building',
        ]);

        // Si se desplegó como backend de una aplicación existente, vincularlo y recompilar frontend
        if ($parentProject) {
            $parentProject->backend_project_id = $project->id;
            $scheme = request()->getScheme() ?: 'http';
            $baseDomain = config('app.env') === 'production' ? env('APP_DOMAIN', 'nexus-academic.software') : 'localhost';
            $backendApiUrl = "{$scheme}://{$subdomain}.{$baseDomain}/api";
            $vars = [
                "VITE_API_URL={$backendApiUrl}",
                "REACT_APP_API_URL={$backendApiUrl}",
                "NEXT_PUBLIC_API_URL={$backendApiUrl}",
                "API_URL={$backendApiUrl}",
                "BACKEND_URL={$scheme}://{$subdomain}.{$baseDomain}"
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
            $this->saveUploadedFiles($request, $project, $projectPath);
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
     * Guardar archivos subidos mediante carpeta local o archivo ZIP.
     */
    private function saveUploadedFiles(Request $request, Project $project, string $projectPath): void
    {
        // Crear el directorio limpio
        if (File::exists($projectPath)) {
            File::deleteDirectory($projectPath);
        }
        File::makeDirectory($projectPath, 0755, true, true);

        $files = $request->file('folder_files');
        $paths = $request->input('folder_paths');

        if (count($files) === 1 && strtolower($files[0]->getClientOriginalExtension()) === 'zip') {
            $zipFile = $files[0];
            $zip = new \ZipArchive;
            if ($zip->open($zipFile->getRealPath()) === true) {
                $zip->extractTo($projectPath);
                $zip->close();
            }
        } else {
            foreach ($files as $index => $file) {
                $relativePath = $paths[$index] ?? null;
                if (!$relativePath) {
                    continue;
                }
                $relativePath = preg_replace('#\.\.[\\/]#', '', $relativePath);
                $relativePath = ltrim($relativePath, './\\ ');
                $relativePath = str_replace('\\', '/', $relativePath);

                $fullFilePath = $projectPath . '/' . $relativePath;
                File::makeDirectory(dirname($fullFilePath), 0755, true, true);
                $file->move(dirname($fullFilePath), basename($fullFilePath));
            }
        }
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
            $baseDomain = config('app.env') === 'production' ? env('APP_DOMAIN', 'nexus-academic.software') : 'localhost';
            $backendApiUrl = "{$scheme}://{$backend->subdomain}.{$baseDomain}/api";
            $vars = [
                "VITE_API_URL={$backendApiUrl}",
                "REACT_APP_API_URL={$backendApiUrl}",
                "NEXT_PUBLIC_API_URL={$backendApiUrl}",
                "API_URL={$backendApiUrl}",
                "BACKEND_URL={$scheme}://{$backend->subdomain}.{$baseDomain}"
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

        // 1. Si el proyecto tiene un backend enlazado (servicio satélite), eliminarlo en cascada
        if ($project->backend_project_id) {
            $backend = Project::where('user_id', Auth::id())->find($project->backend_project_id);
            if ($backend && $backend->is_backend_service) {
                $this->cleanupProjectResources($backend, $stopAction);
                $backend->delete();
            }
        }

        // 2. Limpiar recursos del proyecto principal
        $this->cleanupProjectResources($project, $stopAction);
        $project->delete();

        return redirect()->route('dashboard')->with('status', 'Proyecto eliminado exitosamente.');
    }

    /**
     * Detener contenedor, limpiar archivos y eliminar base de datos de un proyecto.
     */
    private function cleanupProjectResources(Project $project, StopProjectContainerAction $stopAction): void
    {
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
