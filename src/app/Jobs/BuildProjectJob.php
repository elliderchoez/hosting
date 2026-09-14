<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\Project;
use App\Actions\Github\CloneRepositoryAction;
use App\Actions\Docker\DetectLanguageAction;
use App\Actions\Docker\BuildProjectAction;
use App\Actions\Docker\StartProjectContainerAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Exception;

class BuildProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes timeout to ensure ample headroom for complex fullstack apps

    protected Deployment $deployment;

    /**
     * Create a new job instance.
     */
    public function __construct(Deployment $deployment)
    {
        $this->deployment = $deployment;
    }

    /**
     * Execute the job.
     */
    public function handle(
        CloneRepositoryAction $cloneAction,
        DetectLanguageAction $detectLanguageAction,
        BuildProjectAction $buildAction,
        StartProjectContainerAction $startAction
    ): void {
        $deployment = $this->deployment;
        $project = $deployment->project;

        $deployment->status = 'building';
        $deployment->save();

        $project->status = 'building';
        $project->save();

        $startTime = microtime(true);
        $logs = "=== INICIO DE COMPILACIÓN ===\n";
        $logs .= "Fecha: " . now()->toDateTimeString() . "\n";
        $logs .= "Repositorio: {$project->github_repo_url} (Rama: {$project->branch})\n\n";

        // Paths
        $projectPath = storage_path("app/projects/project-{$project->id}");
        $domain = env('APP_DOMAIN', 'nexus-academic.software');

        // Detener cualquier contenedor previo para liberar locks de archivos montados
        $containerName = "project-{$project->id}";
        (new Process(['docker', 'rm', '-f', $containerName]))->run();

        try {
            // Paso 1: Clonar el repositorio de Git o verificar archivos subidos localmente
            if ($project->github_repo_url === 'Subido localmente') {
                $logs .= "--- PASO 1: Subida de Carpeta Local ---\n";
                $logs .= "Proyecto subido de forma local. Omitiendo clonación desde GitHub.\n\n";
            } else {
                $logs .= "--- PASO 1: Clonando repositorio de GitHub ---\n";
                $cloneResult = $cloneAction->execute($project, $projectPath);
                $logs .= $cloneResult['output'] . "\n";

                if (!$cloneResult['success']) {
                    $this->failBuild($project, $deployment, $logs, $startTime);
                    return;
                }
            }

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 1.5: Optimizar y aplanar estructuras de proyecto anidadas
            $logs .= "--- PASO 1.5: Optimizando Estructura del Proyecto ---\n";
            $flattenLog = $this->flattenDirectoryStructure($project, $projectPath);
            $logs .= $flattenLog . "\n\n";

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 2: Detectar Lenguaje/Entorno
            $logs .= "--- PASO 2: Detectando Lenguaje/Entorno ---\n";
            $language = $detectLanguageAction->execute($projectPath);
            if (!$language) {
                $logs .= "Advertencia: No se detectaron archivos de lenguajes específicos. Configurando servidor estático por defecto (PHP).\n";
                $language = 'php';
            }
            $logs .= "Lenguaje detectado: $language\n\n";
            $project->language = $language;
            $project->save();

            $deployment->build_log = $logs;
            $deployment->save();

            // Aprovisionar base de datos
            $this->provisionDatabase($project, $projectPath, $logs);

            // Sincronizar automáticamente credenciales de base de datos en archivos .env
            $this->syncProjectEnvironmentVariables($project, $projectPath, $logs);

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 3: Compilar / Instalar Dependencias
            $logs .= "--- PASO 3: Instalando y Compilando Dependencias ---\n";
            if (!empty($project->env_vars)) {
                $logs .= "Inyectando variables de entorno personalizadas...\n";
                \Illuminate\Support\Facades\File::put($projectPath . '/.env', $project->env_vars);
            }

            // Inyectar configuración de allowedHosts para proyectos Vite
            foreach (['/vite.config.js', '/vite.config.ts'] as $file) {
                $configPath = $projectPath . $file;
                if (\Illuminate\Support\Facades\File::exists($configPath)) {
                    $content = \Illuminate\Support\Facades\File::get($configPath);
                    if (strpos($content, 'allowedHosts') === false) {
                        $logs .= "Optimizando configuración de Vite (allowedHosts) para permitir acceso externo...\n";
                        $content = str_replace(
                            'defineConfig({',
                            "defineConfig({\n  preview: { allowedHosts: true },",
                            $content
                        );
                        \Illuminate\Support\Facades\File::put($configPath, $content);
                    }
                }
                    // Auto-parchear config/cors.php de proyectos Laravel para aceptar
            // cualquier origen *.localhost y *.nexus-academic.software automáticamente,
            // eliminando la necesidad de que el estudiante configure FRONTEND_URL.
            $corsConfigPath = $projectPath . '/config/cors.php';
            if (\Illuminate\Support\Facades\File::exists($corsConfigPath)) {
                $corsContent = \Illuminate\Support\Facades\File::get($corsConfigPath);
                $needsPatch = strpos($corsContent, 'localhost') === false
                    || (strpos($corsContent, 'nexus-academic') === false && strpos($corsContent, 'uleam-academic') === false);
                if ($needsPatch) {
                    $logs .= "Detectado config/cors.php en proyecto Laravel. Habilitando CORS automático para dominios de la plataforma...\n";
                    // Insertar patrones al inicio del array 'allowed_origins_patterns'
                    $corsContent = preg_replace(
                        "/('allowed_origins_patterns'\s*=>\s*\[)/",
                        "$1\n        '#^https?://.*\\.localhost\$#',\n        '#^https?://.*\\.nexus-academic\\.software\$#',\n        '#^https?://.*\\.uleam-academic\\.software\$#',",
                        $corsContent
                    );
                    // También agregar wildcard al array 'allowed_origins' si usa env()
                    if (strpos($corsContent, "env('FRONTEND_URL'") !== false) {
                        $corsContent = preg_replace(
                            "/('allowed_origins'\s*=>\s*\[)/",
                            "$1\n        'http://localhost',\n        'http://localhost:5173',",
                            $corsContent
                        );
                    }
                    \Illuminate\Support\Facades\File::put($corsConfigPath, $corsContent);
                    $logs .= "CORS habilitado automáticamente para *.localhost y *.nexus-academic.software.\n";
                }
            }          }

            $buildResult = $buildAction->execute($project, $projectPath);
            $logs .= $buildResult['output'] . "\n";

            if (!$buildResult['success']) {
                $this->failBuild($project, $deployment, $logs, $startTime);
                return;
            }

            $deployment->build_log = $logs;
            $deployment->save();

            // Importar archivos SQL y correr migraciones si es necesario
            $this->importSqlFiles($project, $projectPath, $logs);
            $this->runFrameworkMigrations($project, $projectPath, $logs);

            // Asegurar symlink de almacenamiento público (storage:link) para proyectos Laravel/PHP
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/storage/app/public') || \Illuminate\Support\Facades\File::exists($projectPath . '/artisan')) {
                \Illuminate\Support\Facades\File::makeDirectory($projectPath . '/public', 0777, true, true);
                \Illuminate\Support\Facades\File::makeDirectory($projectPath . '/storage/app/public', 0777, true, true);
                if (!is_link($projectPath . '/public/storage') && !is_dir($projectPath . '/public/storage')) {
                    @symlink('../storage/app/public', $projectPath . '/public/storage');
                    $logs .= "Enlace simbólico de almacenamiento público (storage:link) creado exitosamente.\n";
                }
            }

            // Asegurar permisos de escritura para proyectos PHP/Laravel
            if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/storage')) {
                @chmod($projectPath . '/storage', 0777);
                $chmodCmd = new Process(['chmod', '-R', '777', $projectPath . '/storage']);
                $chmodCmd->run();
            }
            if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/bootstrap/cache')) {
                @chmod($projectPath . '/bootstrap/cache', 0777);
                $chmodCmd2 = new Process(['chmod', '-R', '777', $projectPath . '/bootstrap/cache']);
                $chmodCmd2->run();
            }

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 4: Levantar contenedor Docker en Sandbox seguro (gVisor)
            $logs .= "--- PASO 4: Iniciando Contenedor Sandbox de Docker (gVisor) ---\n";
            $startResult = $startAction->execute($project, $projectPath, $domain);
            $logs .= $startResult['output'] . "\n";

            if (!$startResult['success']) {
                $this->failBuild($project, $deployment, $logs, $startTime);
                return;
            }

            // ¡Éxito!
            $endTime = microtime(true);
            $duration = (int)($endTime - $startTime);

            $project->status = 'running';
            $project->container_id = $startResult['container_id'];
            $project->last_visited_at = now();
            $project->save();

            // Generar snapshot inicial universal para cualquier aplicación (Node, monorepos, TypeORM, Prisma, etc.)
            if (!empty($project->db_name)) {
                $this->generateInitialDatabaseSnapshot($project, $projectPath);
            }

            $deployment->status = 'success';
            $deployment->build_log = $logs . "\n=== COMPILACIÓN EXITOSA ===";
            $deployment->duration_seconds = $duration;
            $deployment->save();

        } catch (Exception $e) {
            if ($e->getCode() === 422) {
                $logs .= "\nError de Validación:\n{$e->getMessage()}\n";
            } else {
                $logs .= "\nExcepción Crítica: " . $e->getMessage() . "\n" . $e->getTraceAsString();
            }
            $this->failBuild($project, $deployment, $logs, $startTime);
        }
    }

    /**
     * Marcar compilación como fallida y actualizar estado.
     */
    private function failBuild(Project $project, Deployment $deployment, string $logs, float $startTime): void
    {
        $endTime = microtime(true);
        $duration = (int)($endTime - $startTime);

        $project->status = 'failed';
        $project->save();

        $deployment->status = 'failed';
        $deployment->build_log = $logs . "\n=== COMPILACIÓN FALLIDA ===";
        $deployment->duration_seconds = $duration;
        $deployment->save();
    }

    /**
     * Automatically scan and flatten the project directory structure if the actual code
     * is located inside a nested folder rather than the root.
     */
    private function flattenDirectoryStructure(Project $project, string $projectPath): string
    {
        if (!\Illuminate\Support\Facades\File::isDirectory($projectPath)) {
            return "Optimización de estructura omitida: No es un directorio válido.";
        }

        $bestPath = $projectPath;
        $relativeBestPath = '';

        if (!empty($project->root_dir)) {
            $rootDirClean = trim($project->root_dir, '/\\');
            $targetPath = $projectPath . '/' . $rootDirClean;

            if (\Illuminate\Support\Facades\File::isDirectory($targetPath)) {
                $bestPath = $targetPath;
                $relativeBestPath = $rootDirClean;
                $log = "Subdirectorio de compilación configurado manualmente: '{$relativeBestPath}'\n";
            } else {
                $log = "Advertencia: El subdirectorio configurado '{$rootDirClean}' no existe. Usando escaneo automático...\n";
            }
        }

        if ($bestPath === $projectPath) {
            // Find the best project directory recursively (up to depth 3)
            $bestResult = $this->findBestProjectDir($projectPath, $projectPath, 3, 0);
            $bestPath = $bestResult['path'];
            $bestScore = $bestResult['score'];

            if ($bestScore < 5) {
                return "No se detectaron suficientes indicadores de proyecto anidado (Puntaje: {$bestScore}). Manteniendo estructura original.";
            }

            // If the best path is already the root directory, no action needed
            if (realpath($bestPath) === realpath($projectPath)) {
                return "Los archivos del proyecto ya están en la raíz. No requiere optimización.";
            }

            $relativeBestPath = str_replace($projectPath . '/', '', $bestPath);
            $log = "Directorio de proyecto anidado detectado automáticamente: '{$relativeBestPath}' (Puntaje: {$bestScore})\n";
        }

        $log .= "Promoviendo archivos desde '{$relativeBestPath}' hacia la raíz del proyecto...\n";

        try {
            $tempPath = storage_path("app/projects/temp_flatten_" . uniqid());
            \Illuminate\Support\Facades\File::makeDirectory($tempPath, 0755, true, true);

            // Move everything from bestPath to tempPath
            $nestedDirs = \Illuminate\Support\Facades\File::directories($bestPath);
            $nestedFiles = \Illuminate\Support\Facades\File::files($bestPath);

            foreach ($nestedDirs as $nestedDir) {
                \Illuminate\Support\Facades\File::moveDirectory($nestedDir, $tempPath . '/' . basename($nestedDir), true);
            }
            foreach ($nestedFiles as $nestedFile) {
                \Illuminate\Support\Facades\File::move($nestedFile, $tempPath . '/' . basename($nestedFile));
            }

            // Clean up the original project root (except .git and *.sql files)
            $allRootDirs = \Illuminate\Support\Facades\File::directories($projectPath);
            $allRootFiles = \Illuminate\Support\Facades\File::files($projectPath);

            foreach ($allRootDirs as $rootDir) {
                if (basename($rootDir) !== '.git') {
                    \Illuminate\Support\Facades\File::deleteDirectory($rootDir);
                }
            }
            foreach ($allRootFiles as $rootFile) {
                $ext = strtolower(pathinfo($rootFile, PATHINFO_EXTENSION));
                if ($ext !== 'sql') {
                    \Illuminate\Support\Facades\File::delete($rootFile);
                }
            }

            // Move everything from tempPath back to projectPath
            $tempDirs = \Illuminate\Support\Facades\File::directories($tempPath);
            $tempFiles = \Illuminate\Support\Facades\File::files($tempPath);

            foreach ($tempDirs as $tempDir) {
                \Illuminate\Support\Facades\File::moveDirectory($tempDir, $projectPath . '/' . basename($tempDir), true);
            }
            foreach ($tempFiles as $tempFile) {
                \Illuminate\Support\Facades\File::move($tempFile, $projectPath . '/' . basename($tempFile));
            }

            // Delete the temp directory
            \Illuminate\Support\Facades\File::deleteDirectory($tempPath);

            $log .= "Archivos reorganizados con éxito en la raíz del proyecto.";
        } catch (\Exception $e) {
            $log .= "Fallo al promover archivos de la carpeta anidada: " . $e->getMessage();
        }

        return $log;
    }

    /**
     * Recursively scan directories to find the folder that contains project configuration/code files.
     */
    private function findBestProjectDir(string $basePath, string $currentPath, int $maxDepth, int $currentDepth): array
    {
        $bestPath = $currentPath;
        $bestScore = $this->calculateProjectScore($currentPath);

        if ($currentDepth >= $maxDepth) {
            return ['path' => $bestPath, 'score' => $bestScore];
        }

        $dirs = \Illuminate\Support\Facades\File::directories($currentPath);
        foreach ($dirs as $dir) {
            $dirName = basename($dir);
            // Skip hidden directories and dependency/output folders
            if (str_starts_with($dirName, '.') || in_array(strtolower($dirName), ['node_modules', 'vendor', 'storage', 'bootstrap', 'tests', 'dist', 'build', '.venv', 'venv', 'env'])) {
                continue;
            }

            $result = $this->findBestProjectDir($basePath, $dir, $maxDepth, $currentDepth + 1);
            if ($result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestPath = $result['path'];
            }
        }

        return ['path' => $bestPath, 'score' => $bestScore];
    }

    /**
     * Score a directory based on the presence of typical programming framework/language files.
     */
    private function calculateProjectScore(string $dir): int
    {
        if (!\Illuminate\Support\Facades\File::isDirectory($dir)) {
            return 0;
        }

        // Strong markers (framework definitions / lock files) -> Score 10
        $strongMarkers = [
            'composer.json', 'artisan',
            'package.json',
            'requirements.txt', 'Pipfile', 'manage.py', 'pyproject.toml',
            'pom.xml', 'build.gradle', 'build.gradle.kts', 'gradlew',
            'Program.cs', 'Startup.cs',
            'Dockerfile', 'dockerfile'
        ];
        foreach ($strongMarkers as $marker) {
            if (\Illuminate\Support\Facades\File::exists($dir . '/' . $marker)) {
                return 10;
            }
        }

        // Check for .csproj or .sln files in directory -> Score 10
        $dirFiles = \Illuminate\Support\Facades\File::files($dir);
        foreach ($dirFiles as $file) {
            $ext = strtolower($file->getExtension());
            if ($ext === 'csproj' || $ext === 'sln' || $ext === 'fsproj') {
                return 10;
            }
        }

        // Medium markers (entry points / typical index files) -> Score 5
        $mediumMarkers = [
            'index.php',
            'server.js', 'index.js', 'app.js',
            'main.py', 'app.py'
        ];
        foreach ($mediumMarkers as $marker) {
            if (\Illuminate\Support\Facades\File::exists($dir . '/' . $marker)) {
                return 5;
            }
        }

        // Weak markers (raw source code files) -> Score 1
        foreach ($dirFiles as $file) {
            $name = $file->getFilename();
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['php', 'py', 'java', 'cs'])) {
                return 1;
            }
            if (in_array($ext, ['js', 'ts', 'jsx', 'tsx'])) {
                // Skip common JS build files to avoid false positives
                if (!preg_match('/(config|webpack|babel|gulpfile|postcss|tailwind)\.js$/i', $name)) {
                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * Provision a database for the project (MySQL or PostgreSQL).
     */
    private function provisionDatabase(Project $project, string $projectPath, &$logs): void
    {
        $logs .= "--- PASO 2.5: Aprovisionamiento de Base de Datos ---\n";
        
        // 1. Detect dialect (mysql vs pgsql)
        $driver = $this->detectDbDriver($project, $projectPath);
        $project->db_driver = $driver;
        $logs .= "Motor de base de datos detectado/seleccionado: " . strtoupper($driver) . "\n";

        // --- VALIDACIÓN DE BASE DE DATOS REQUERIDA (TESIS) ---
        // 1. Verificar si existen archivos .sql recursivamente (excluyendo vendor y node_modules)
        $sqlFiles = $this->getProjectScanFiles($projectPath, '*.sql');
        $hasSql = !empty($sqlFiles);

        // 2. Verificar si tiene migraciones o auto-sincronización (Laravel, Django, TypeORM, Prisma, Sequelize, Knex, Drizzle, Mongoose, etc.)
        $hasMigrations = false;
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/artisan') || 
            \Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')) {
            $hasMigrations = true;
        }

        // Buscar en todos los package.json (raíz y subcarpetas como api, client, backend, server)
        $pkgFiles = $this->getProjectScanFiles($projectPath, 'package.json');
        $requiresDb = false;

        foreach ($pkgFiles as $pkgFile) {
            $packageJson = json_decode(@file_get_contents($pkgFile->getRealPath()), true);
            if ($packageJson) {
                $deps = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
                
                // Si usa drivers de BD
                if (isset($deps['pg']) || isset($deps['mysql']) || isset($deps['mysql2']) || isset($deps['mongoose']) || isset($deps['mongodb']) || isset($deps['sequelize']) || isset($deps['typeorm']) || isset($deps['prisma']) || isset($deps['@prisma/client']) || isset($deps['knex']) || isset($deps['drizzle-orm'])) {
                    $requiresDb = true;
                }

                // Si usa ORMs con auto-sincronización o migraciones
                if (
                    isset($deps['typeorm']) || 
                    isset($deps['prisma']) || 
                    isset($deps['@prisma/client']) || 
                    isset($deps['sequelize']) || 
                    isset($deps['knex']) || 
                    isset($deps['drizzle-orm']) || 
                    isset($deps['mongoose']) || 
                    isset($deps['mongodb']) || 
                    isset($deps['mikro-orm']) || 
                    isset($deps['@mikro-orm/core'])
                ) {
                    $hasMigrations = true;
                }
            }
        }

        // Archivos de configuración de ORMs comunes
        if (
            \Illuminate\Support\Facades\File::exists($projectPath . '/.sequelizerc') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/ormconfig.json') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/ormconfig.js') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/prisma/schema.prisma') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/knexfile.js') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/drizzle.config.ts')
        ) {
            $hasMigrations = true;
        }

        $requirementsPath = $projectPath . '/requirements.txt';
        if (\Illuminate\Support\Facades\File::exists($requirementsPath)) {
            $reqs = \Illuminate\Support\Facades\File::get($requirementsPath);
            if (stripos($reqs, 'psycopg2') !== false || stripos($reqs, 'mysqlclient') !== false || stripos($reqs, 'sqlalchemy') !== false || stripos($reqs, 'django') !== false || stripos($reqs, 'pymongo') !== false || stripos($reqs, 'mongoengine') !== false || stripos($reqs, 'tortoise') !== false || stripos($reqs, 'peewee') !== false) {
                $requiresDb = true;
            }
        }
        if ($project->language === 'php') {
            $phpFiles = $this->getProjectScanFiles($projectPath, '*.php');
            foreach ($phpFiles as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content && (stripos($content, 'new PDO') !== false || stripos($content, 'mysqli_connect') !== false || stripos($content, 'pg_connect') !== false || stripos($content, 'DB_CONNECTION') !== false)) {
                    $requiresDb = true;
                    break;
                }
            }
        }

        if ($requiresDb && $driver !== 'mongodb' && !$hasSql && !$hasMigrations) {
            $errorMsg = "No se pudo desplegar el proyecto porque falta el archivo de base de datos SQL o archivos de migraciones. Por favor, revisa el proyecto y vuélvelo a subir.";
            throw new \Exception($errorMsg, 422);
        }
        // ----------------------------------------------------
        
        // 2. Generate credentials if not present
        if (empty($project->db_name)) {
            $uuidClean = str_replace('-', '_', $project->id);
            $project->db_name = "db_{$uuidClean}";
            // MySQL limits usernames to 32 characters. We use a shorter prefix and a truncated UUID (26 characters total)
            $project->db_user = "u_" . substr($uuidClean, 0, 24);
            $project->db_password = \Illuminate\Support\Str::random(24);
            $logs .= "Generando nuevas credenciales de base de datos para el proyecto.\n";
        } else {
            $logs .= "Usando credenciales de base de datos existentes.\n";
        }
        $project->save();

        $dbname = $project->db_name;
        $dbuser = $project->db_user;
        $dbpass = $project->db_password;

        try {
            if ($driver === 'mongodb') {
                $logs .= "Asegurando base de datos MongoDB central '{$dbname}'...\n";
                
                // Detectar binario disponible (mongo en v4.4/v5 o mongosh en v6+)
                $checkCli = new \Symfony\Component\Process\Process(['docker', 'exec', 'uleam_mongodb_students', 'which', 'mongosh']);
                $checkCli->run();
                $mongoCli = $checkCli->isSuccessful() ? 'mongosh' : 'mongo';

                $command = [
                    'docker', 'exec', 'uleam_mongodb_students', $mongoCli,
                    '-u', 'root', '-p', 'uleam_mongo_pass',
                    '--authenticationDatabase', 'admin',
                    '--eval', "
                        db = db.getSiblingDB('{$dbname}');
                        db.dropDatabase();
                        try { db.dropUser('{$dbuser}'); } catch (e) {}
                        db.createUser({
                            user: '{$dbuser}',
                            pwd: '{$dbpass}',
                            roles: [ { role: 'readWrite', db: '{$dbname}' } ]
                        });
                    "
                ];
                $process = new \Symfony\Component\Process\Process($command);
                $process->setTimeout(30);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    throw new \Exception("Error en {$mongoCli} al aprovisionar MongoDB: " . $process->getErrorOutput());
                }
                
                $logs .= "Base de datos MongoDB '{$dbname}' y usuario '{$dbuser}' recreados limpios con éxito.\n";
            } elseif ($driver === 'mysql') {
                $logs .= "Asegurando base de datos MySQL central '{$dbname}'...\n";
                // 1. Recrear DB de forma limpia
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("DROP DATABASE IF EXISTS {$dbname};");
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("CREATE DATABASE {$dbname};");
                
                // 2. Crear usuario si no existe y actualizar contraseña
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "CREATE USER IF NOT EXISTS '{$dbuser}'@'%' IDENTIFIED BY '{$dbpass}';"
                );
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "ALTER USER '{$dbuser}'@'%' IDENTIFIED BY '{$dbpass}';"
                );
                
                // 3. Otorgar privilegios
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "GRANT ALL PRIVILEGES ON {$dbname}.* TO '{$dbuser}'@'%';"
                );
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "FLUSH PRIVILEGES;"
                );
                $logs .= "Base de datos MySQL '{$dbname}' recreada limpia con éxito.\n";
            } else {
                // PostgreSQL
                $logs .= "Asegurando existencia del usuario de base de datos '{$dbuser}' en Postgres...\n";
                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("
                    DO \$\$
                    BEGIN
                        IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$dbuser}') THEN
                            CREATE ROLE {$dbuser} WITH LOGIN PASSWORD '{$dbpass}';
                        END IF;
                    END
                    \$\$;
                ");

                $logs .= "Preparando base de datos Postgres limpia '{$dbname}'...\n";
                try {
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->select("
                        SELECT pg_terminate_backend(pg_stat_activity.pid)
                        FROM pg_stat_activity
                        WHERE pg_stat_activity.datname = '{$dbname}'
                          AND pid <> pg_backend_pid();
                    ");
                } catch (\Throwable $e) {
                    // Ignorar si no había conexiones activas
                }

                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("DROP DATABASE IF EXISTS {$dbname};");
                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("CREATE DATABASE {$dbname} OWNER {$dbuser};");
                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("GRANT ALL PRIVILEGES ON DATABASE {$dbname} TO {$dbuser};");
                $logs .= "Base de datos Postgres '{$dbname}' recreada limpia con éxito.\n";
            }
            
            $logs .= "Aprovisionamiento de base de datos completado exitosamente.\n\n";

        } catch (\Exception $e) {
            $logs .= "Error en aprovisionamiento: " . $e->getMessage() . "\n\n";
            throw new \Exception("Error al aprovisionar la base de datos: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Search and import SQL files into the student's database.
     */
    private function importSqlFiles(Project $project, string $projectPath, &$logs): void
    {
        $logs .= "--- PASO 3.3: Importación de Archivos SQL ---\n";
        
        if ($project->db_driver === 'mongodb') {
            $logs .= "Proyecto NoSQL (MongoDB) detectado. Omitiendo importación de archivos SQL.\n\n";
            return;
        }
        
        if (empty($project->db_name)) {
            $logs .= "No hay base de datos configurada para este proyecto. Omitiendo.\n\n";
            return;
        }

        // Search for .sql files recursively (excluding vendor, node_modules, etc.)
        $sqlFiles = $this->getProjectScanFiles($projectPath, '*.sql');

        if (empty($sqlFiles)) {
            $logs .= "No se encontraron archivos .sql para importar.\n\n";
            return;
        }

        foreach ($sqlFiles as $sqlFile) {
            $relPath = $sqlFile->getRelativePathname();
            // Omitir scripts SQL internos de configuración de Docker o CI de terceros
            if (str_contains($relPath, 'docker/') || str_contains($relPath, '.github/')) {
                continue;
            }

            $logs .= "Detectado archivo SQL: '{$relPath}'. Importando...\n";
            
            if ($project->db_driver === 'mysql') {
                // Import into MySQL
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'mysql:8.0',
                    'sh', '-c',
                    "mysql -h uleam_mysql_students -u '{$project->db_user}' -p'{$project->db_password}' {$project->db_name} < /workspace/{$sqlFile->getRelativePathname()}"
                ];
            } else {
                // Import into PostgreSQL
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'postgres:15-alpine',
                    'sh', '-c',
                    "PGPASSWORD='{$project->db_password}' psql -h uleam_postgres_students -U '{$project->db_user}' -d '{$project->db_name}' -f /workspace/{$sqlFile->getRelativePathname()}"
                ];
            }

            $process = new Process($command);
            $process->setTimeout(90);
            $process->run();

            if ($process->isSuccessful()) {
                $logs .= "Importación exitosa del archivo '{$sqlFile->getFilename()}'.\n";
            } else {
                $logs .= "Error al importar '{$sqlFile->getFilename()}': " . $process->getErrorOutput() . "\n";
            }
        }
        $logs .= "\n";

        // Generar snapshot inicial tras importar los respaldos SQL legítimos
        $this->generateInitialDatabaseSnapshot($project, $projectPath);
    }

    /**
     * Run framework-specific database migrations (Laravel, Django, Sequelize) automatically.
     */
    private function runFrameworkMigrations(Project $project, string $projectPath, &$logs): void
    {
        $dbHost = $project->db_driver === 'mysql' ? 'uleam_mysql_students' : 'uleam_postgres_students';
        $dbPort = $project->db_driver === 'mysql' ? '3306' : '5432';

        // 1. Laravel (PHP)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/artisan')) {
            $logs .= "--- PASO 3.6: Framework Laravel Detectado: Ejecutando Migraciones ---\n";

            // Soporte automatizado para Bagisto E-Commerce
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/packages/Webkul')) {
                $envManager = $projectPath . '/packages/Webkul/Installer/src/Helpers/EnvironmentManager.php';
                if (\Illuminate\Support\Facades\File::exists($envManager)) {
                    $emContent = \Illuminate\Support\Facades\File::get($envManager);
                    $emContent = str_replace(': string|bool', ': string|bool|null', $emContent);
                    \Illuminate\Support\Facades\File::put($envManager, $emContent);
                }

                $templateSnapshot = storage_path('app/templates/bagisto_initial.sql');
                $snapshotFile = $projectPath . '/.initial_db_snapshot.sql';

                if (\Illuminate\Support\Facades\File::exists($templateSnapshot)) {
                    $logs .= "Proyecto Bagisto E-Commerce detectado. Aprovisionando base de datos completa con catálogo de demostración desde plantilla precargada...\n";
                    $importCmd = new Process([
                        'docker', 'exec', '-i', 'uleam_mysql_students',
                        'mysql', '-u', 'root', '-puleam_secure_pass', $project->db_name
                    ]);
                    $importCmd->setInput(\Illuminate\Support\Facades\File::get($templateSnapshot));
                    $importCmd->run();

                    if ($importCmd->isSuccessful()) {
                        $logs .= "Base de datos de comercio electrónico y catálogo de productos importados instantáneamente (3 segundos).\n";
                        @copy($templateSnapshot, $snapshotFile);

                        // Sincronizar las imágenes de catálogo de muestra si existen en plantilla
                        $templateStorage = storage_path('app/templates/bagisto_public_storage');
                        if (\Illuminate\Support\Facades\File::exists($templateStorage)) {
                            \Illuminate\Support\Facades\File::makeDirectory($projectPath . '/storage/app/public', 0777, true, true);
                            $copyProcess = new Process(['cp', '-rn', $templateStorage . '/.', $projectPath . '/storage/app/public/']);
                            $copyProcess->run();
                            $logs .= "Imágenes de muestra de catálogo y categorías sincronizadas exitosamente.\n";
                        }

                        // Permitir incrustación en iframe (Showcase Modal de ULEAM Academic)
                        $bagistoSecureHeaders = $projectPath . '/packages/Webkul/Core/src/Http/Middleware/SecureHeaders.php';
                        if (\Illuminate\Support\Facades\File::exists($bagistoSecureHeaders)) {
                            $shContent = \Illuminate\Support\Facades\File::get($bagistoSecureHeaders);
                            $shContent = str_replace(
                                "\$response->headers->set('X-Frame-Options', 'DENY');",
                                "\$response->headers->remove('X-Frame-Options');\n        \$response->headers->set('Content-Security-Policy', 'frame-ancestors *');",
                                $shContent
                            );
                            \Illuminate\Support\Facades\File::put($bagistoSecureHeaders, $shContent);
                        }

                        // Polyfill de localStorage para prevenir errores de navegador en iframes de diferente origen
                        $bagistoLayout = $projectPath . '/packages/Webkul/Shop/src/Resources/views/components/layouts/index.blade.php';
                        if (\Illuminate\Support\Facades\File::exists($bagistoLayout)) {
                            $layoutContent = \Illuminate\Support\Facades\File::get($bagistoLayout);
                            if (!str_contains($layoutContent, '__uleam_storage_test__')) {
                                $poly = "<script>(function(){try{var t='__uleam_storage_test__';window.localStorage.setItem(t,'1');window.localStorage.removeItem(t);}catch(e){var s={};try{Object.defineProperty(window,'localStorage',{value:{getItem:function(k){return s.hasOwnProperty(k)?s[k]:null;},setItem:function(k,v){s[k]=String(v);},removeItem:function(k){delete s[k];},clear:function(){s={};},key:function(i){return Object.keys(s)[i]||null;},get length(){return Object.keys(s).length;}},configurable:true,enumerable:true,writable:true});}catch(err){}}})();</script>\n";
                                $layoutContent = str_replace('<head>', "<head>\n        " . $poly, $layoutContent);
                                \Illuminate\Support\Facades\File::put($bagistoLayout, $layoutContent);
                            }
                        }

                        // Fallback para imágenes lazy cargadas dentro de un iframe
                        $bagistoLazy = $projectPath . '/packages/Webkul/Shop/src/Resources/views/components/media/images/lazy.blade.php';
                        if (\Illuminate\Support\Facades\File::exists($bagistoLazy)) {
                            $lazyContent = \Illuminate\Support\Facades\File::get($bagistoLazy);
                            if (!str_contains($lazyContent, 'Fallback de seguridad para iframes')) {
                                $lazyPatch = "setTimeout(() => { let l = document.getElementById('image-' + self.$.uid); if (l && (!l.src || l.src === window.location.href) && l.dataset.src) { l.src = l.dataset.src; } }, 300);";
                                $lazyContent = str_replace("lazyImageObserver.observe(document.getElementById('image-shimmer-' + this.$.uid));", "let shim = document.getElementById('image-shimmer-' + this.$.uid); if (shim) lazyImageObserver.observe(shim);\n                // Fallback de seguridad para iframes\n                " . $lazyPatch, $lazyContent);
                                \Illuminate\Support\Facades\File::put($bagistoLazy, $lazyContent);
                            }
                        }
                    } else {
                        $logs .= "Aviso al importar plantilla SQL: " . $importCmd->getErrorOutput() . "\nEjecutando instalador estándar...\n";
                        $command = [
                            'docker', 'run', '--rm',
                            '--network', 'uleam_academic_network',
                            '-v', "{$projectPath}:/app",
                            '-w', '/app',
                            '-e', "DB_CONNECTION={$project->db_driver}",
                            '-e', "DB_HOST={$dbHost}",
                            '-e', "DB_PORT={$dbPort}",
                            '-e', "DB_DATABASE={$project->db_name}",
                            '-e', "DB_USERNAME={$project->db_user}",
                            '-e', "DB_PASSWORD={$project->db_password}",
                            'webdevops/php:8.4',
                            'php', 'artisan', 'bagisto:install', '-n', '--demo-samples'
                        ];
                        $this->executeMigrationCommand($command, $logs);
                    }
                } else {
                    $logs .= "Proyecto Bagisto E-Commerce detectado. Ejecutando instalador y sembrador de tienda...\n";
                    $command = [
                        'docker', 'run', '--rm',
                        '--network', 'uleam_academic_network',
                        '-v', "{$projectPath}:/app",
                        '-w', '/app',
                        '-e', "DB_CONNECTION={$project->db_driver}",
                        '-e', "DB_HOST={$dbHost}",
                        '-e', "DB_PORT={$dbPort}",
                        '-e', "DB_DATABASE={$project->db_name}",
                        '-e', "DB_USERNAME={$project->db_user}",
                        '-e', "DB_PASSWORD={$project->db_password}",
                        'webdevops/php:8.4',
                        'php', 'artisan', 'bagisto:install', '-n', '--demo-samples'
                    ];
                    $this->executeMigrationCommand($command, $logs);

                    // Generar snapshot inicial para futuros despliegues y reseteos
                    if (!\Illuminate\Support\Facades\File::exists($snapshotFile)) {
                        $dumpCmd = new Process([
                            'docker', 'exec', 'uleam_mysql_students',
                            'mysqldump', '--no-tablespaces', '-u', 'root', '-puleam_secure_pass', $project->db_name
                        ]);
                        $dumpCmd->run();
                        if ($dumpCmd->isSuccessful() && strlen($dumpCmd->getOutput()) > 500) {
                            \Illuminate\Support\Facades\File::put($snapshotFile, $dumpCmd->getOutput());
                            \Illuminate\Support\Facades\File::put($templateSnapshot, $dumpCmd->getOutput());
                        }
                    }
                }

                if (empty($project->demo_instructions)) {
                    $project->demo_instructions = "Acceso al Panel de Administración (http://{$project->subdomain}.localhost/admin):\nUsuario: admin@example.com\nClave: admin123\n\nLa tienda de comercio electrónico cuenta con categorías y productos de muestra precargados.";
                    $project->save();
                }

                return;
            }

            $command = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                'webdevops/php:8.4',
                'php', 'artisan', 'migrate', '--force'
            ];
            $this->executeMigrationCommand($command, $logs);

            // Si existen seeders de base de datos, ejecutarlos automáticamente para poblar catálogo/datos de prueba
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/database/seeders/DatabaseSeeder.php')) {
                $logs .= "Seeder de base de datos detectado (DatabaseSeeder.php). Poblando catálogo/datos iniciales...\n";
                $seedCommand = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/app",
                    '-w', '/app',
                    '-e', "DB_CONNECTION={$project->db_driver}",
                    '-e', "DB_HOST={$dbHost}",
                    '-e', "DB_PORT={$dbPort}",
                    '-e', "DB_DATABASE={$project->db_name}",
                    '-e', "DB_USERNAME={$project->db_user}",
                    '-e', "DB_PASSWORD={$project->db_password}",
                    'webdevops/php:8.4',
                    'php', 'artisan', 'db:seed', '--force'
                ];
                $this->executeMigrationCommand($seedCommand, $logs);
            }

            // Auto-completar instalación para proyectos con asistentes web (Zero-Click para evaluadores)
            // 1. Archivos bandera estándar de instalación en Laravel / CMS
            foreach (['database_created', 'installed', '.installed', 'setup_completed'] as $flag) {
                @touch($projectPath . '/storage/app/' . $flag);
                @touch($projectPath . '/storage/' . $flag);
            }

            // 2. Si el proyecto tiene tabla o modelo Setting, marcar flags de completado automáticamente
            $postInstallTinker = <<<'PHP'
try {
    if (class_exists('Crater\Models\Setting')) {
        \Crater\Models\Setting::updateOrCreate(['option' => 'profile_complete'], ['value' => 'COMPLETED']);
        \Crater\Models\Setting::updateOrCreate(['option' => 'version'], ['value' => '6.0.6']);
    }
    if (class_exists('Crater\Models\Company') && class_exists('Crater\Models\User')) {
        $c = \Crater\Models\Company::first();
        if (!$c) {
            $c = \Crater\Models\Company::create(['name' => 'Demo Company', 'slug' => 'demo-company']);
        }
        $u = \Crater\Models\User::first();
        if ($u && $c) {
            $c->owner_id = $u->id;
            if (class_exists('Vinkla\Hashids\Facades\Hashids')) {
                try {
                    $c->unique_hash = \Vinkla\Hashids\Facades\Hashids::connection(\Crater\Models\Company::class)->encode($c->id);
                } catch (\Throwable $e) {}
            }
            $c->save();
            try { $c->setupDefaultData(); } catch (\Throwable $e) {}
            if (!$u->companies()->where('companies.id', $c->id)->exists()) {
                $u->companies()->attach($c->id);
            }
            if (class_exists('Silber\Bouncer\BouncerFacade')) {
                try {
                    \Silber\Bouncer\BouncerFacade::scope()->to($c->id);
                    $u->assign('super admin');
                    \Silber\Bouncer\BouncerFacade::allow('super admin')->everything();
                } catch (\Throwable $e) {}
            }
        }
    }

    // Auto-detección y provisión universal de usuario administrador para pruebas
    if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
        $firstUser = \Illuminate\Support\Facades\DB::table('users')->first();
        if ($firstUser) {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $firstUser->id)->update([
                'password' => \Illuminate\Support\Facades\Hash::make('password')
            ]);
            echo "AUTH_USER:" . $firstUser->email . PHP_EOL;
        } else {
            $cols = \Illuminate\Support\Facades\Schema::getColumnListing('users');
            $data = [
                'email' => 'admin@example.com',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
            ];
            if (in_array('name', $cols)) $data['name'] = 'Admin Evaluador';
            if (in_array('role', $cols)) $data['role'] = 'admin';
            if (in_array('created_at', $cols)) $data['created_at'] = now();
            if (in_array('updated_at', $cols)) $data['updated_at'] = now();
            \Illuminate\Support\Facades\DB::table('users')->insert($data);
            echo "AUTH_USER:admin@example.com" . PHP_EOL;
        }
    }
} catch (\Throwable $e) {}
PHP;
            $tinkerProcess = new Process([
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                'webdevops/php:8.4',
                'php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', 'artisan', 'tinker', '--execute=' . $postInstallTinker
            ]);
            $tinkerProcess->run();
            $tinkerOut = $tinkerProcess->getOutput();

            if (preg_match('/AUTH_USER:([^\r\n]+)/', $tinkerOut, $authMatches)) {
                $authEmail = trim($authMatches[1]);
                if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                    $prefix = "Acceso predeterminado para pruebas:\nUsuario: {$authEmail}\nClave: password";
                    $project->demo_instructions = empty($project->demo_instructions)
                        ? "{$prefix}\n\nEl sistema incluye catálogo y datos de muestra listos para evaluar."
                        : "{$prefix}\n\n" . $project->demo_instructions;
                    $project->save();
                    $logs .= "Credenciales predeterminadas generadas e integradas en las instrucciones: {$authEmail} / password\n";
                }
            }

            // Generar snapshot inicial de la base de datos para la función de reseteo del evaluador
            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 2. Django (Python)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')) {
            $logs .= "--- PASO 3.6: Framework Django Detectado: Ejecutando Migraciones ---\n";
            
            // Inyectar variables de entorno para la base de datos (Django suele leerlas)
            $command = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                // Si la app lee DATABASE_URL
                '-e', "DATABASE_URL=" . ($project->db_driver === 'mysql' ? 'mysql' : 'postgres') . "://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}",
                'python:3.12-alpine',
                'sh', '-c', '.venv/bin/python manage.py migrate'
            ];
            $this->executeMigrationCommand($command, $logs);
            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 3. Node.js + Sequelize
        $packageJsonPath = $projectPath . '/package.json';
        $hasSequelize = false;
        if (\Illuminate\Support\Facades\File::exists($packageJsonPath)) {
            $packageJson = json_decode(\Illuminate\Support\Facades\File::get($packageJsonPath), true);
            $deps = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
            if (isset($deps['sequelize']) || \Illuminate\Support\Facades\File::exists($projectPath . '/.sequelizerc')) {
                $hasSequelize = true;
            }
        }

        if ($hasSequelize) {
            $logs .= "--- PASO 3.6: ORM Sequelize Detectado: Ejecutando Migraciones ---\n";
            
            $command = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                '-e', "DATABASE_URL=" . ($project->db_driver === 'mysql' ? 'mysql' : 'postgres') . "://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}",
                'node:18-alpine',
                'sh', '-c', 'npx sequelize-cli db:migrate || npx sequelize db:migrate'
            ];
            $this->executeMigrationCommand($command, $logs);
            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }
    }

    /**
     * Ejecutar comando de migración dentro del contenedor temporal de Docker.
     */
    private function executeMigrationCommand(array $command, &$logs, int $timeout = 300): void
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $logs .= "Aviso: Se alcanzó el límite de tiempo ({$timeout}s) durante las migraciones del framework.\n";
            return;
        }

        $output = $process->getOutput() . $process->getErrorOutput();
        $logs .= $output . "\n";

        if ($process->isSuccessful()) {
            $logs .= "Migraciones ejecutadas exitosamente.\n\n";
        } else {
            $logs .= "Advertencia/Fallo al ejecutar las migraciones del framework.\n\n";
        }
    }

    /**
     * Detect if a SQL file has MySQL syntax.
     */
    private function isMysqlSyntax(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return false;
        }

        $content = fread($handle, 20480);
        fclose($handle);

        if ($content === false) {
            return false;
        }

        if (Str::contains($content, '`')) {
            return true;
        }
        if (stripos($content, 'ENGINE=') !== false) {
            return true;
        }
        if (stripos($content, 'DEFAULT CHARSET=') !== false) {
            return true;
        }
        if (stripos($content, 'character_set_client') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Detect which database driver to use (pgsql or mysql).
     */
    private function detectDbDriver(Project $project, string $projectPath): string
    {
        // 1. Check package.json for Node.js (priority for MongoDB/MySQL)
        $packageJsonPath = $projectPath . '/package.json';
        if (\Illuminate\Support\Facades\File::exists($packageJsonPath)) {
            $packageJson = json_decode(\Illuminate\Support\Facades\File::get($packageJsonPath), true);
            $deps = array_merge(
                $packageJson['dependencies'] ?? [],
                $packageJson['devDependencies'] ?? []
            );
            if (isset($deps['mongoose']) || isset($deps['mongodb'])) {
                return 'mongodb';
            }
            if (isset($deps['mysql']) || isset($deps['mysql2'])) {
                return 'mysql';
            }
        }

        // 2. Check requirements.txt for Python
        $requirementsPath = $projectPath . '/requirements.txt';
        if (\Illuminate\Support\Facades\File::exists($requirementsPath)) {
            $reqs = \Illuminate\Support\Facades\File::get($requirementsPath);
            if (stripos($reqs, 'pymongo') !== false || stripos($reqs, 'mongoengine') !== false) {
                return 'mongodb';
            }
        }

        // 3. Check .env, .env.example, .env.dist if exists in project
        $envFiles = ['.env', '.env.example', '.env.dist', '.env.local'];
        foreach ($envFiles as $eFile) {
            $ePath = $projectPath . '/' . $eFile;
            if (\Illuminate\Support\Facades\File::exists($ePath)) {
                $envContent = \Illuminate\Support\Facades\File::get($ePath);
                if (stripos($envContent, 'DB_CONNECTION=mongodb') !== false || stripos($envContent, 'MONGODB_URI') !== false || stripos($envContent, 'MONGO_URL') !== false) {
                    return 'mongodb';
                }
                if (stripos($envContent, 'DB_CONNECTION=mysql') !== false || stripos($envContent, 'DB_DRIVER=mysql') !== false) {
                    return 'mysql';
                }
                if (stripos($envContent, 'DB_CONNECTION=pgsql') !== false || stripos($envContent, 'DB_DRIVER=pgsql') !== false) {
                    return 'pgsql';
                }
            }
        }

        // 4. Check config/database.php in PHP / Laravel
        $configDb = $projectPath . '/config/database.php';
        if (\Illuminate\Support\Facades\File::exists($configDb)) {
            $configContent = \Illuminate\Support\Facades\File::get($configDb);
            if (str_contains($configContent, "'default' => env('DB_CONNECTION', 'mysql')") || str_contains($configContent, "'default' => 'mysql'")) {
                return 'mysql';
            }
            if (str_contains($configContent, "'default' => env('DB_CONNECTION', 'pgsql')") || str_contains($configContent, "'default' => 'pgsql'")) {
                return 'pgsql';
            }
        }

        // 5. Check .sql files (excluding vendor, node_modules, etc.)
        $sqlFiles = $this->getProjectScanFiles($projectPath, '*.sql');
        foreach ($sqlFiles as $file) {
            if ($this->isMysqlSyntax($file->getRealPath())) {
                return 'mysql';
            }
        }

        // 6. Proyectos PHP / Laravel convencionales usan MySQL por defecto
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/artisan') || \Illuminate\Support\Facades\File::exists($projectPath . '/composer.json')) {
            return 'mysql';
        }

        return 'pgsql';
    }

    /**
     * Get project files safely excluding heavy directories (vendor, node_modules, .git, etc.)
     * to prevent PHP memory exhaustion.
     */
    private function getProjectScanFiles(string $path, ?string $namePattern = null): array
    {
        if (!is_dir($path)) {
            return [];
        }

        try {
            $finder = new \Symfony\Component\Finder\Finder();
            $finder->files()
                ->in($path)
                ->ignoreDotFiles(true)
                ->ignoreVCS(true)
                ->exclude(['vendor', 'node_modules', '.git', '.venv', 'storage', 'dist', '.next', 'build', 'tests', 'test', 'cache']);
            
            if ($namePattern) {
                $finder->name($namePattern);
            }
            
            return iterator_to_array($finder, false);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Sincronizar de manera universal y automática las credenciales de base de datos
     * y URL de aplicación en todos los archivos .env del proyecto.
     */
    private function syncProjectEnvironmentVariables(Project $project, string $projectPath, string &$logs): void
    {
        if (empty($project->db_name)) {
            return;
        }

        $logs .= "--- PASO 2.5: Sincronizando Variables de Entorno de Base de Datos (.env) ---\n";

        $dbHost = $project->db_driver === 'mysql' ? 'uleam_mysql_students' : ($project->db_driver === 'mongodb' ? 'uleam_mongodb_students' : 'uleam_postgres_students');
        $dbPort = $project->db_driver === 'mysql' ? '3306' : ($project->db_driver === 'mongodb' ? '27017' : '5432');
        $appUrl = "http://{$project->subdomain}.localhost";
        $dbPrefix = $project->db_driver === 'mysql' ? 'mysql' : ($project->db_driver === 'mongodb' ? 'mongodb' : 'postgresql');
        $dbUrl = "{$dbPrefix}://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}";
        if ($project->db_driver === 'mongodb') {
            $dbUrl .= "?authSource=admin";
        }

        $commonEnvVars = [
            'APP_URL' => $appUrl,
            'DB_CONNECTION' => $project->db_driver ?: 'mysql',
            'DB_HOST' => $dbHost,
            'DB_PORT' => $dbPort,
            'DB_DATABASE' => $project->db_name,
            'DB_USERNAME' => $project->db_user,
            'DB_PASSWORD' => $project->db_password,
            'DATABASE_URL' => $dbUrl,
            'MONGODB_URI' => $dbUrl,
            'MONGO_URI' => $dbUrl,
            'DB_URI' => $dbUrl,
            'PGHOST' => $dbHost,
            'PGPORT' => $dbPort,
            'PGDATABASE' => $project->db_name,
            'PGUSER' => $project->db_user,
            'PGPASSWORD' => $project->db_password,
            'APP_DEBUG' => 'false',
            'DEBUGBAR_ENABLED' => 'false',
            'SESSION_DRIVER' => 'file',
            'CACHE_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'log',
            'FILESYSTEM_DISK' => 'public',
            'SANCTUM_STATEFUL_DOMAINS' => "{$project->subdomain}.localhost,localhost,127.0.0.1",
            'SESSION_DOMAIN' => '',
        ];

        // Buscar posibles ubicaciones de .env (.env en raíz, en backend, api, server)
        $potentialDirs = [
            $projectPath,
            $projectPath . '/backend',
            $projectPath . '/api',
            $projectPath . '/server',
        ];

        foreach ($potentialDirs as $dir) {
            if (!\Illuminate\Support\Facades\File::isDirectory($dir)) {
                continue;
            }

            $envPath = $dir . '/.env';
            // Si no existe .env, buscar plantillas de ejemplo (.env.example, .env.sample, etc.)
            if (!\Illuminate\Support\Facades\File::exists($envPath)) {
                foreach (['/.env.example', '/.env.sample', '/.env.local', '/.env.dist'] as $sample) {
                    if (\Illuminate\Support\Facades\File::exists($dir . $sample)) {
                        \Illuminate\Support\Facades\File::copy($dir . $sample, $envPath);
                        $logs .= "Creado '{$dir}/.env' a partir de plantilla '{$sample}'.\n";
                        break;
                    }
                }
            }

            if (!\Illuminate\Support\Facades\File::exists($envPath)) {
                \Illuminate\Support\Facades\File::put($envPath, "");
            }

            $content = \Illuminate\Support\Facades\File::get($envPath);

            // Reemplazar cualquier clave existente o agregarla si no existe en el archivo
            foreach ($commonEnvVars as $k => $v) {
                if (preg_match("/^{$k}=.*/m", $content)) {
                    $content = preg_replace("/^{$k}=.*/m", "{$k}={$v}", $content);
                } else {
                    $content .= "\n{$k}={$v}";
                }
            }

            \Illuminate\Support\Facades\File::put($envPath, $content);
            $rel = str_replace($projectPath, '', $envPath);
            $logs .= "Variables de conexión a base de datos inyectadas automáticamente en: {$rel}\n";
        }
        $logs .= "\n";
    }

    /**
     * Generar un snapshot SQL inicial para permitir que el evaluador pueda
     * restablecer la base de datos de cualquier proyecto a su estado de fábrica en segundos.
     */
    private function generateInitialDatabaseSnapshot(Project $project, string $projectPath): void
    {
        $snapshotFile = $projectPath . '/.initial_db_snapshot.sql';
        if (\Illuminate\Support\Facades\File::exists($snapshotFile)) {
            return;
        }

        if (empty($project->db_name)) {
            return;
        }

        if ($project->db_driver === 'mysql') {
            $dumpCmd = new Process([
                'docker', 'exec', 'uleam_mysql_students',
                'mysqldump', '--no-tablespaces', '-u', 'root', '-puleam_secure_pass', $project->db_name
            ]);
            $dumpCmd->run();
            $output = $dumpCmd->getOutput();
            if ($dumpCmd->isSuccessful() && (str_contains($output, 'CREATE TABLE') || strlen($output) > 500)) {
                \Illuminate\Support\Facades\File::put($snapshotFile, $output);
            }
        } elseif ($project->db_driver === 'pgsql') {
            $dumpCmd = new Process([
                'docker', 'exec', 'uleam_postgres_students',
                'pg_dump', '-U', 'postgres', '-d', $project->db_name
            ]);
            $dumpCmd->run();
            $output = $dumpCmd->getOutput();
            if ($dumpCmd->isSuccessful() && (str_contains($output, 'CREATE TABLE') || strlen($output) > 500)) {
                \Illuminate\Support\Facades\File::put($snapshotFile, $output);
            }
        }
    }
}
