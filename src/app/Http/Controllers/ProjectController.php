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

        // Load student's projects with their latest deployment
        $projects = Project::where('user_id', $user->id)
            ->with(['deployments' => function ($query) {
                $query->latest()->limit(5);
            }])
            ->get();

        // Sincronizar el estado físico de Docker con la Base de Datos
        foreach ($projects as $project) {
            if ($project->status === 'building') {
                continue;
            }
            
            $containerName = "project-{$project->id}";
            $isPhysicalRunning = $this->isContainerRunning($containerName);
            
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

        // 1. Limitar a un máximo de 3 proyectos por estudiante
        $projectCount = Project::where('user_id', $user->id)->count();
        if ($projectCount >= 3) {
            return redirect()->back()->withErrors(['error' => 'Has alcanzado el límite máximo de 3 proyectos.']);
        }

        $isFolderUpload = $request->hasFile('folder_files');

        // 2. Validar entrada según el tipo de subida
        $rules = [
            'name' => 'required|string|max:100',
            'subdomain' => 'required|string|max:63|alpha_dash|unique:projects,subdomain',
            'root_dir' => 'nullable|string|max:255',
            'env_vars' => 'nullable|string',
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
        $project = Project::create([
            'user_id' => $user->id,
            'name' => $request->name,
            'subdomain' => $subdomain,
            'github_repo_url' => $isFolderUpload ? 'Subido localmente' : $request->github_repo_url,
            'branch' => $isFolderUpload ? 'local' : $request->branch,
            'root_dir' => $request->root_dir,
            'env_vars' => $request->env_vars,
            'status' => 'building',
        ]);

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

        return redirect()->route('dashboard')->with('status', 'Proyecto registrado. Compilación iniciada en segundo plano.');
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
