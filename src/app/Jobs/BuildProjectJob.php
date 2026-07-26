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
    public $timeout = 600; // 10 minutes timeout matching the thesis proposal limit

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
        $domain = env('APP_DOMAIN', 'uleam-academic.software');

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
            }

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
            'requirements.txt', 'Pipfile', 'manage.py'
        ];
        foreach ($strongMarkers as $marker) {
            if (\Illuminate\Support\Facades\File::exists($dir . '/' . $marker)) {
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
        $files = \Illuminate\Support\Facades\File::files($dir);
        foreach ($files as $file) {
            $name = $file->getFilename();
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['php', 'py'])) {
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
        // Verificar si existen archivos .sql recursivamente
        $files = \Illuminate\Support\Facades\File::allFiles($projectPath);
        $hasSql = false;
        foreach ($files as $file) {
            if (\Illuminate\Support\Str::endsWith(\Illuminate\Support\Str::lower($file->getFilename()), '.sql')) {
                $path = $file->getRelativePathname();
                if (!\Illuminate\Support\Str::contains($path, ['node_modules', '.git', '.venv', 'vendor'])) {
                    $hasSql = true;
                    break;
                }
            }
        }

        // Verificar si tiene migraciones de Laravel, Django o Sequelize
        $hasMigrations = false;
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/artisan') || 
            \Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')) {
            $hasMigrations = true;
        }
        
        $packageJsonPath = $projectPath . '/package.json';
        if (\Illuminate\Support\Facades\File::exists($packageJsonPath)) {
            $packageJson = json_decode(\Illuminate\Support\Facades\File::get($packageJsonPath), true);
            $deps = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
            if (isset($deps['sequelize']) || \Illuminate\Support\Facades\File::exists($projectPath . '/.sequelizerc')) {
                $hasMigrations = true;
            }
        }

        // Comprobar si requiere base de datos leyendo dependencias
        $requiresDb = false;
        if (\Illuminate\Support\Facades\File::exists($packageJsonPath)) {
            $packageJson = json_decode(\Illuminate\Support\Facades\File::get($packageJsonPath), true);
            $deps = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
            if (isset($deps['pg']) || isset($deps['mysql']) || isset($deps['mysql2']) || isset($deps['sequelize']) || isset($deps['mongoose']) || isset($deps['mongodb'])) {
                $requiresDb = true;
            }
        }
        $requirementsPath = $projectPath . '/requirements.txt';
        if (\Illuminate\Support\Facades\File::exists($requirementsPath)) {
            $reqs = \Illuminate\Support\Facades\File::get($requirementsPath);
            if (stripos($reqs, 'psycopg2') !== false || stripos($reqs, 'mysqlclient') !== false || stripos($reqs, 'sqlalchemy') !== false || stripos($reqs, 'django') !== false || stripos($reqs, 'pymongo') !== false || stripos($reqs, 'mongoengine') !== false) {
                $requiresDb = true;
            }
        }
        if ($project->language === 'php') {
            $phpFiles = \Illuminate\Support\Facades\File::allFiles($projectPath);
            foreach ($phpFiles as $file) {
                if (\Illuminate\Support\Str::endsWith(\Illuminate\Support\Str::lower($file->getFilename()), '.php')) {
                    $content = @file_get_contents($file->getRealPath());
                    if ($content && (stripos($content, 'new PDO') !== false || stripos($content, 'mysqli_connect') !== false || stripos($content, 'pg_connect') !== false || stripos($content, 'DB_CONNECTION') !== false)) {
                        $requiresDb = true;
                        break;
                    }
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
                $command = [
                    'docker', 'exec', 'uleam_mongodb_students', 'mongosh',
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
                    throw new \Exception("Error en mongosh al aprovisionar: " . $process->getErrorOutput());
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
                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("
                    SELECT pg_terminate_backend(pg_stat_activity.pid)
                    FROM pg_stat_activity
                    WHERE pg_stat_activity.datname = '{$dbname}'
                      AND pid <> pg_backend_pid();
                ");

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

        // Search for .sql files recursively
        $files = \Illuminate\Support\Facades\File::allFiles($projectPath);
        $sqlFiles = [];
        foreach ($files as $file) {
            if (Str::endsWith(Str::lower($file->getFilename()), '.sql')) {
                $path = $file->getRelativePathname();
                if (!Str::contains($path, ['node_modules', '.git', '.venv', 'vendor'])) {
                    $sqlFiles[] = $file;
                }
            }
        }

        if (empty($sqlFiles)) {
            $logs .= "No se encontraron archivos .sql para importar.\n\n";
            return;
        }

        foreach ($sqlFiles as $sqlFile) {
            $logs .= "Detectado archivo SQL: '{$sqlFile->getRelativePathname()}'. Importando...\n";
            
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
                'python:3.11-alpine',
                'sh', '-c', '.venv/bin/python manage.py migrate'
            ];
            $this->executeMigrationCommand($command, $logs);
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
            return;
        }
    }

    /**
     * Ejecutar comando de migración dentro del contenedor temporal de Docker.
     */
    private function executeMigrationCommand(array $command, &$logs): void
    {
        $process = new Process($command);
        $process->setTimeout(90);
        $process->run();

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

        // 3. Check .env if exists in project
        $envPath = $projectPath . '/.env';
        if (\Illuminate\Support\Facades\File::exists($envPath)) {
            $envContent = \Illuminate\Support\Facades\File::get($envPath);
            if (stripos($envContent, 'DB_CONNECTION=mongodb') !== false || stripos($envContent, 'MONGODB_URI') !== false || stripos($envContent, 'MONGO_URL') !== false) {
                return 'mongodb';
            }
            if (stripos($envContent, 'DB_CONNECTION=mysql') !== false) {
                return 'mysql';
            }
        }

        // 4. Check .sql files
        $files = \Illuminate\Support\Facades\File::allFiles($projectPath);
        foreach ($files as $file) {
            if (Str::endsWith(Str::lower($file->getFilename()), '.sql')) {
                $path = $file->getRelativePathname();
                if (!Str::contains($path, ['node_modules', '.git', '.venv', 'vendor'])) {
                    if ($this->isMysqlSyntax($file->getRealPath())) {
                        return 'mysql';
                    }
                }
            }
        }

        return 'pgsql';
    }
}
