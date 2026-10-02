<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ResetStudentDatabases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:reset-databases {--project= : ID del proyecto específico a restablecer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restablece las bases de datos de los estudiantes a su estado de fábrica importando los respaldos SQL originales.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $projectId = $this->option('project');

        if ($projectId) {
            $projects = Project::where('id', $projectId)->get();
            if ($projects->isEmpty()) {
                $this->error("No se encontró el proyecto con ID: {$projectId}");
                return 1;
            }
        } else {
            $projects = Project::whereNotNull('db_name')->get();
        }

        if ($projects->isEmpty()) {
            $this->info("No hay proyectos con bases de datos que restablecer.");
            return 0;
        }

        $this->info("Iniciando el restablecimiento de bases de datos de estudiantes...");

        foreach ($projects as $project) {
            $this->info("Restableciendo base de datos para el proyecto: {$project->name} (Driver: " . strtoupper($project->db_driver) . ")");
            
            $dbname = $project->db_name;
            $dbuser = $project->db_user;
            $dbpass = $project->db_password;
            $driver = $project->db_driver ?: 'pgsql';
            $projectPath = storage_path("app/projects/project-{$project->id}");

            if (!File::exists($projectPath)) {
                $this->warn("La carpeta del proyecto no existe físicamente en el almacenamiento. Omitiendo.");
                continue;
            }

            $resetLock = $projectPath . '/.resetting';
            File::put($resetLock, (string)time());

            try {
                // 1. Recrear Base de Datos Limpia
                // 1. Recrear Base de Datos Limpia
                if ($driver === 'mongodb') {
                    $this->info("Limpiando colecciones de MongoDB central para '{$dbname}'...");
                    $checkCli = new Process(['docker', 'exec', 'uleam_mongodb_students', 'which', 'mongosh']);
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
                    $process = new Process($command);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        throw new \Exception("Error reseteando MongoDB con {$mongoCli}: " . $process->getErrorOutput());
                    }
                    $this->info("Base de datos MongoDB '{$dbname}' recreada limpia.");

                    // Si el contenedor está activo, reiniciarlo rápidamente para que vuelva a sembrar el registro inicial
                    $containerName = "project-{$project->id}";
                    $checkRunning = new Process(['docker', 'inspect', '-f', '{{.State.Running}}', $containerName]);
                    $checkRunning->run();
                    if (trim($checkRunning->getOutput()) === 'true') {
                        $restartProc = new Process(['docker', 'restart', '-t', '1', $containerName]);
                        $restartProc->run();
                    }
                } elseif ($driver === 'mysql') {
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("DROP DATABASE IF EXISTS {$dbname};");
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("CREATE DATABASE {$dbname};");
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("CREATE USER IF NOT EXISTS '{$dbuser}'@'%' IDENTIFIED BY '{$dbpass}';");
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("ALTER USER '{$dbuser}'@'%' IDENTIFIED BY '{$dbpass}';");
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("GRANT ALL PRIVILEGES ON {$dbname}.* TO '{$dbuser}'@'%';");
                    \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("FLUSH PRIVILEGES;");
                    $this->info("Base de datos '{$dbname}' recreada limpia.");
                } else {
                    // Postgres
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("
                        DO \$\$
                        BEGIN
                            IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$dbuser}') THEN
                                CREATE ROLE {$dbuser} WITH LOGIN PASSWORD '{$dbpass}';
                            END IF;
                        END
                        \$\$;
                    ");
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("
                        SELECT pg_terminate_backend(pg_stat_activity.pid)
                        FROM pg_stat_activity
                        WHERE pg_stat_activity.datname = '{$dbname}'
                          AND pid <> pg_backend_pid();
                    ");
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("DROP DATABASE IF EXISTS {$dbname};");
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("CREATE DATABASE {$dbname} OWNER {$dbuser};");
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("GRANT ALL PRIVILEGES ON DATABASE {$dbname} TO {$dbuser};");
                    $this->info("Base de datos '{$dbname}' recreada limpia.");
                }

                // Soporte para aplicaciones con base de datos SQLite embebida (Grocy, etc.)
                $sqliteSnapshot = $projectPath . '/.initial_db_snapshot.sqlite';
                $sqliteDb = $projectPath . '/data/grocy.db';
                $isSqliteProject = File::exists($sqliteSnapshot) || File::exists($sqliteDb) || File::exists($projectPath . '/config-dist.php');
                if (File::exists($sqliteSnapshot) && File::exists($sqliteDb)) {
                    $this->info("Restaurando snapshot inicial de SQLite para {$project->name}...");
                    @copy($sqliteSnapshot, $sqliteDb);
                    @chmod($sqliteDb, 0777);
                    $this->info("Base de datos SQLite restaurada limpiamente desde snapshot inicial.");
                }

                if ($driver !== 'mongodb' && !$isSqliteProject) {
                    // 2. Si es un proyecto con framework (Laravel/Django), ejecutar sus migraciones y seeders
                    if (File::exists($projectPath . '/artisan') || File::exists($projectPath . '/manage.py') || File::exists($projectPath . '/package.json')) {
                        $this->runFrameworkMigrations($project, $projectPath);
                    }

                    // 3. Buscar archivos .sql de respaldo legítimos e importarlos (si no fueron gestionados por framework)
                    if (!File::exists($projectPath . '/artisan')) {
                        $sqlFiles = [];
                        try {
                            $finder = new \Symfony\Component\Finder\Finder();
                            $finder->files()
                                ->in($projectPath)
                                ->name('*.sql')
                                ->ignoreDotFiles(true)
                                ->ignoreVCS(true)
                                ->exclude(['node_modules', 'vendor', '.git', '.venv', 'dist', 'build', 'cache', 'scripts', 'sail']);
                            foreach ($finder as $file) {
                                $sqlFiles[] = $file;
                            }
                        } catch (\Exception $e) {
                            $sqlFiles = [];
                        }

                        if (!empty($sqlFiles)) {
                            foreach ($sqlFiles as $sqlFile) {
                                $this->info("Importando respaldo original: {$sqlFile->getFilename()}...");
                                
                                if ($driver === 'mysql') {
                                    $command = [
                                        'docker', 'run', '--rm',
                                        '--network', 'uleam_academic_network',
                                        '-v', "{$projectPath}:/workspace:ro",
                                        'mysql:8.0',
                                        'sh', '-c',
                                        "mysql -h uleam_mysql_students -u '{$dbuser}' -p'{$dbpass}' {$dbname} < /workspace/{$sqlFile->getRelativePathname()}"
                                    ];
                                } else {
                                    $command = [
                                        'docker', 'run', '--rm',
                                        '--network', 'uleam_academic_network',
                                        '-v', "{$projectPath}:/workspace:ro",
                                        'postgres:15-alpine',
                                        'sh', '-c',
                                        "PGPASSWORD='{$dbpass}' psql -h uleam_postgres_students -U '{$dbuser}' -d '{$dbname}' -f /workspace/{$sqlFile->getRelativePathname()}"
                                    ];
                                }

                                $process = new Process($command);
                                $process->setTimeout(90);
                                $process->run();

                                if ($process->isSuccessful()) {
                                    $this->info("Importación del archivo '{$sqlFile->getFilename()}' exitosa.");
                                } else {
                                    $this->error("Fallo al importar '{$sqlFile->getFilename()}': " . $process->getErrorOutput());
                                }
                            }
                        }
                    }
                }

                // Si el contenedor está activo, reiniciarlo para que reconecte a la base de datos limpia y regenere esquemas/conexiones
                $containerName = "project-{$project->id}";
                $checkRunning = new Process(['docker', 'inspect', '-f', '{{.State.Running}}', $containerName]);
                $checkRunning->run();
                if (trim($checkRunning->getOutput()) === 'true') {
                    $restartProc = new Process(['docker', 'restart', '-t', '2', $containerName]);
                    $restartProc->run();
                    $this->info("Contenedor '{$containerName}' reiniciado tras restablecer la base de datos.");
                    // Mantener el bloqueo activo brevemente para permitir que los servicios internos (como APIs secundarias o TypeORM) inicien
                    sleep(2);
                }

            } catch (\Exception $e) {
                $this->error("Error al restablecer proyecto {$project->name}: " . $e->getMessage());
            } finally {
                if (isset($resetLock)) {
                    @unlink($resetLock);
                }
            }
        }

        $this->info("Restablecimiento de bases de datos completado.");
        return 0;
    }

    /**
     * Run framework-specific database migrations (Laravel, Django, Sequelize) automatically on reset.
     */
    private function runFrameworkMigrations(Project $project, string $projectPath): void
    {
        $driver = $project->db_driver ?: 'pgsql';
        $dbHost = $driver === 'mysql' ? 'uleam_mysql_students' : 'uleam_postgres_students';
        $dbPort = $driver === 'mysql' ? '3306' : '5432';
        $dbname = $project->db_name;
        $dbuser = $project->db_user;
        $dbpass = $project->db_password;

        $containerName = "project-{$project->id}";
        $check = new Process(['docker', 'inspect', '-f', '{{.State.Running}}', $containerName]);
        $check->run();
        $isRunning = trim($check->getOutput()) === 'true';

        // 0. Si existe un snapshot inicial válido (.initial_db_snapshot.sql con tablas), restaurarlo directamente en segundos
        $snapshotFile = $projectPath . '/.initial_db_snapshot.sql';
        if (File::exists($snapshotFile) && str_contains(File::get($snapshotFile), 'CREATE TABLE')) {
            $this->info("Snapshot inicial detectado. Restaurando réplica exacta en segundos...");
            if ($driver === 'mysql') {
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'mysql:8.0',
                    'sh', '-c',
                    "mysql -h uleam_mysql_students -u '{$dbuser}' -p'{$dbpass}' {$dbname} < /workspace/.initial_db_snapshot.sql"
                ];
            } else {
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'postgres:15-alpine',
                    'sh', '-c',
                    "PGPASSWORD='{$dbpass}' psql -h uleam_postgres_students -U '{$dbuser}' -d '{$dbname}' -f /workspace/.initial_db_snapshot.sql"
                ];
            }
            $proc = new Process($command);
            $proc->setTimeout(60);
            $proc->run();
            if ($proc->isSuccessful()) {
                $this->info("Snapshot inicial restaurado con éxito.");
                return;
            } else {
                $this->warn("Aviso al restaurar snapshot: " . $proc->getErrorOutput() . " - Reintentando con migraciones estándar.");
            }
        }

        // 1. Laravel (PHP)
        if (File::exists($projectPath . '/artisan')) {
            if ($isRunning) {
                $this->info("Contenedor activo. Ejecutando migraciones y seeders vía exec...");
                $command = [
                    'docker', 'exec', $containerName,
                    'sh', '-c', 'php artisan migrate --force && php artisan db:seed --force'
                ];
            } else {
                $this->info("Contenedor inactivo. Ejecutando mediante contenedor temporal...");
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/app",
                    '-w', '/app',
                    '-e', "DB_CONNECTION={$driver}",
                    '-e', "DB_HOST={$dbHost}",
                    '-e', "DB_PORT={$dbPort}",
                    '-e', "DB_DATABASE={$dbname}",
                    '-e', "DB_USERNAME={$dbuser}",
                    '-e', "DB_PASSWORD={$dbpass}",
                    'webdevops/php:8.4',
                    'sh', '-c', 'php artisan migrate --force && php artisan db:seed --force'
                ];
            }
            $this->executeMigrationCommand($command);

            // Generar snapshot inicial para futuros reseteos ultrarrápidos
            if (!File::exists($snapshotFile)) {
                $this->info("Generando snapshot inicial para acelerar futuros reseteos...");
                if ($driver === 'mysql') {
                    $dumpCmd = new Process([
                        'docker', 'exec', 'uleam_mysql_students',
                        'mysqldump', '-u', $dbuser, "-p{$dbpass}", $dbname
                    ]);
                } else {
                    $dumpCmd = new Process([
                        'docker', 'exec', '-e', "PGPASSWORD={$dbpass}", 'uleam_postgres_students',
                        'pg_dump', '-U', $dbuser, '-d', $dbname
                    ]);
                }
                $dumpCmd->run();
                if ($dumpCmd->isSuccessful() && strlen($dumpCmd->getOutput()) > 500) {
                    File::put($snapshotFile, $dumpCmd->getOutput());
                    $this->info("Snapshot guardado correctamente.");
                }
            }
            return;
        }

        // 2. Django (Python)
        if (File::exists($projectPath . '/manage.py')) {
            if ($isRunning) {
                $this->info("Contenedor activo. Ejecutando migraciones vía exec...");
                $command = [
                    'docker', 'exec', $containerName,
                    'sh', '-c', '.venv/bin/python manage.py migrate'
                ];
            } else {
                $this->info("Contenedor inactivo. Ejecutando mediante contenedor temporal...");
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/app",
                    '-w', '/app',
                    '-e', "DB_CONNECTION={$driver}",
                    '-e', "DB_HOST={$dbHost}",
                    '-e', "DB_PORT={$dbPort}",
                    '-e', "DB_DATABASE={$dbname}",
                    '-e', "DB_USERNAME={$dbuser}",
                    '-e', "DB_PASSWORD={$dbpass}",
                    '-e', "DATABASE_URL=" . ($driver === 'mysql' ? 'mysql' : 'postgres') . "://{$dbuser}:{$dbpass}@{$dbHost}:{$dbPort}/{$dbname}",
                    'python:3.11-alpine',
                    'sh', '-c', '.venv/bin/python manage.py migrate'
                ];
            }
            $this->executeMigrationCommand($command);
            return;
        }

        // 3. Node.js + Sequelize
        $packageJsonPath = $projectPath . '/package.json';
        $hasSequelize = false;
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            $deps = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
            if (isset($deps['sequelize']) || File::exists($projectPath . '/.sequelizerc')) {
                $hasSequelize = true;
            }
        }

        if ($hasSequelize) {
            if ($isRunning) {
                $this->info("Contenedor activo. Ejecutando migraciones vía exec...");
                $command = [
                    'docker', 'exec', $containerName,
                    'sh', '-c', 'npx sequelize-cli db:migrate || npx sequelize db:migrate'
                ];
            } else {
                $this->info("Contenedor inactivo. Ejecutando mediante contenedor temporal...");
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/app",
                    '-w', '/app',
                    '-e', "DB_CONNECTION={$driver}",
                    '-e', "DB_HOST={$dbHost}",
                    '-e', "DB_PORT={$dbPort}",
                    '-e', "DB_DATABASE={$dbname}",
                    '-e', "DB_USERNAME={$dbuser}",
                    '-e', "DB_PASSWORD={$dbpass}",
                    '-e', "DATABASE_URL=" . ($driver === 'mysql' ? 'mysql' : 'postgres') . "://{$dbuser}:{$dbpass}@{$dbHost}:{$dbPort}/{$dbname}",
                    'node:18-alpine',
                    'sh', '-c', 'npx sequelize-cli db:migrate || npx sequelize db:migrate'
                ];
            }
            $this->executeMigrationCommand($command);
            return;
        }
    }

    /**
     * Ejecutar comando de migración dentro del contenedor temporal de Docker.
     */
    private function executeMigrationCommand(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(90);
        $process->run();

        if ($process->isSuccessful()) {
            $this->info("Migraciones ejecutadas exitosamente.");
        } else {
            $this->warn("Advertencia/Fallo en migraciones: " . $process->getErrorOutput());
        }
    }
}
