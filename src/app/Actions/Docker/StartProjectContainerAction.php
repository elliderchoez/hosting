<?php

namespace App\Actions\Docker;

use App\Models\Project;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\File;
use Exception;

class StartProjectContainerAction
{
    /**
     * Start the Docker container for a student project.
     *
     * @param Project $project
     * @param string $projectPath
     * @param string $domain The base domain (e.g. uleam-academic.software)
     * @return array{success: bool, container_id: string|null, output: string}
     */
    public function execute(Project $project, string $projectPath, string $domain): array
    {
        $containerName = $this->getContainerName($project);
        $output = "Iniciando servidor de la aplicación...\n";

        try {
            // 1. Ensure Docker Network exists
            $this->ensureDockerNetworkExists('uleam_academic_network');

            // 2. Stop and remove existing container if running
            $this->stopAndRemoveContainer($containerName);

            // 3. Define language settings
            $settings = $this->getLanguageSettings($project->language, $projectPath, $project);
            if (!$settings) {
                return [
                    'success' => false,
                    'container_id' => null,
                    'output' => "Lenguaje no soportado o no detectado para el proyecto."
                ];
            }

            // 4. Read runtime from env (runsc for production gVisor, runc for local development)
            $runtime = env('DOCKER_RUNTIME', 'runc');

            $workDir = '/app';
            if ($project->language === 'dockerfile') {
                $workDir = $this->detectDockerfileWorkdir($projectPath);
            }

            // 5. Construct Docker Run Command
            $command = [
                'docker', 'run', '-d',
                '--name', $containerName,
                '--runtime', $runtime,
                '--network', 'uleam_academic_network',
                '--memory', ($project->language === 'ruby' ? '1024m' : '256m'),
                '--cpus', ($project->language === 'ruby' ? '1.0' : '0.5'),
                '--pids-limit', ($project->language === 'ruby' ? '200' : '50'),
                '-v', "$projectPath:$workDir", // Montar en su WORKDIR como lectura y escritura
            ];

            // Si el WORKDIR del contenedor no es /app, montar también en /app para compatibilidad
            if ($workDir !== '/app') {
                $command[] = '-v';
                $command[] = "$projectPath:/app";
            }

            // Si el contenedor fue construido con Dockerfile,
            // preservar el directorio de node_modules de la imagen para que el montaje host no lo oculte
            if ($project->language === 'dockerfile') {
                $command[] = '-v';
                $command[] = "$workDir/node_modules";
                if ($workDir !== '/app') {
                    $command[] = '-v';
                    $command[] = '/app/node_modules';
                }
            }

            $command[] = '-w';
            $command[] = $workDir;

            $command[] = '--label';
            $command[] = 'traefik.enable=true';

            if (config('app.env') === 'production') {
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.rule=Host(`{$project->subdomain}.{$domain}`)";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.entrypoints=websecure";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.tls.certresolver=myresolver";
            } else {
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.rule=Host(`{$project->subdomain}.{$domain}`)";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.entrypoints=web";
            }

            // Router local (HTTP / web) para pruebas locales en localhost
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.rule=Host(`{$project->subdomain}.localhost`)";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.entrypoints=web";

            // ── CORS & Iframe Embedding Universal ─────────────────────────────────────────
            // Inyectar encabezados CORS y permitir visualización en iframe para el evaluador
            $corsName = "{$project->id}-cors";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolalloworiginlist=*";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolallowmethods=GET,OPTIONS,PUT,PATCH,POST,DELETE";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolallowheaders=Content-Type,Authorization,X-Requested-With,Accept,Origin,X-CSRF-Token";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolmaxage=100";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.addvaryheader=true";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.customresponseheaders.X-Frame-Options=";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.customresponseheaders.Content-Security-Policy=frame-ancestors *";
            // Aplicar el middleware a ambos routers (producción y local)
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}.middlewares={$corsName}";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.middlewares={$corsName}";
            // ───────────────────────────────────────────────────────────────────────────

            // Asociación al servicio balanceador de carga
            $serviceName = "{$project->id}-service";
            $command[] = '--label';
            $command[] = "traefik.http.services.{$serviceName}.loadbalancer.server.port={$settings['port']}";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}.service={$serviceName}";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.service={$serviceName}";

            // Add environment variables if needed
            $command[] = '-e';
            $command[] = 'PORT=' . $settings['port'];

            if (!empty($project->db_name)) {
                $dbDriver = $project->db_driver ?: 'pgsql';
                
                if ($dbDriver === 'mongodb') {
                    $dbHost = 'uleam_mongodb_students';
                    $dbPort = '27017';
                    $mongoUri = "mongodb://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}?authSource={$project->db_name}";
                    
                    $command[] = '-e';
                    $command[] = 'DB_CONNECTION=mongodb';
                    $command[] = '-e';
                    $command[] = 'DB_HOST=' . $dbHost;
                    $command[] = '-e';
                    $command[] = 'DB_PORT=' . $dbPort;
                    $command[] = '-e';
                    $command[] = 'DB_DATABASE=' . $project->db_name;
                    $command[] = '-e';
                    $command[] = 'DB_USERNAME=' . $project->db_user;
                    $command[] = '-e';
                    $command[] = 'DB_PASSWORD=' . $project->db_password;
                    $command[] = '-e';
                    $command[] = 'DB_USER=' . $project->db_user;
                    $command[] = '-e';
                    $command[] = 'DB_NAME=' . $project->db_name;
                    $command[] = '-e';
                    $command[] = 'DB_PASS=' . $project->db_password;
                    $command[] = '-e';
                    $command[] = 'DATABASE_URL=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'MONGODB_URI=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'MONGO_URL=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'MONGODB_URL=' . $mongoUri;
                } else {
                    $dbHost = $dbDriver === 'mysql' ? 'uleam_mysql_students' : ($project->language === 'ruby' ? 'uleam-postgres-students' : 'uleam_postgres_students');
                    $dbPort = $dbDriver === 'mysql' ? '3306' : '5432';
                    $dbUrlScheme = $dbDriver === 'mysql' ? 'mysql' : 'postgres';

                    $command[] = '-e';
                    $command[] = 'DB_CONNECTION=' . $dbDriver;
                    $command[] = '-e';
                    $command[] = 'DB_HOST=' . $dbHost;
                    $command[] = '-e';
                    $command[] = 'DB_PORT=' . $dbPort;
                    $command[] = '-e';
                    $command[] = 'DB_DATABASE=' . $project->db_name;
                    $command[] = '-e';
                    $command[] = 'DB_USERNAME=' . $project->db_user;
                    $command[] = '-e';
                    $command[] = 'DB_PASSWORD=' . $project->db_password;
                    $command[] = '-e';
                    $command[] = 'DB_USER=' . $project->db_user;
                    $command[] = '-e';
                    $command[] = 'DB_NAME=' . $project->db_name;
                    $command[] = '-e';
                    $command[] = 'DB_PASS=' . $project->db_password;
                    $command[] = '-e';
                    $command[] = 'DATABASE_URL=' . $dbUrlScheme . '://' . $project->db_user . ':' . $project->db_password . '@' . $dbHost . ':' . $dbPort . '/' . $project->db_name;
                    if ($dbDriver === 'pgsql' || $dbDriver === 'postgres') {
                        $command[] = '-e';
                        $command[] = 'POSTGRES_HOST=' . $dbHost;
                        $command[] = '-e';
                        $command[] = 'POSTGRES_PORT=' . $dbPort;
                        $command[] = '-e';
                        $command[] = 'POSTGRES_DATABASE=' . $project->db_name;
                        $command[] = '-e';
                        $command[] = 'POSTGRES_USERNAME=' . $project->db_user;
                        $command[] = '-e';
                        $command[] = 'POSTGRES_DB=' . $project->db_name;
                        $command[] = '-e';
                        $command[] = 'POSTGRES_USER=' . $project->db_user;
                        $command[] = '-e';
                        $command[] = 'POSTGRES_PASSWORD=' . $project->db_password;
                        $command[] = '-e';
                        $command[] = 'PGHOST=' . $dbHost;
                        $command[] = '-e';
                        $command[] = 'PGPORT=' . $dbPort;
                        $command[] = '-e';
                        $command[] = 'PGDATABASE=' . $project->db_name;
                        $command[] = '-e';
                        $command[] = 'PGUSER=' . $project->db_user;
                        $command[] = '-e';
                        $command[] = 'PGPASSWORD=' . $project->db_password;
                    }
                }
            }

            // Inyectar servicio de Redis universitario para colas y WebSockets
            $redisHost = ($project->language === 'ruby') ? 'uleam-redis-students' : 'uleam_redis_students';
            $command[] = '-e';
            $command[] = "REDIS_URL=redis://{$redisHost}:6379";
            $command[] = '-e';
            $command[] = "REDIS_HOST={$redisHost}";
            $command[] = '-e';
            $command[] = 'REDIS_PORT=6379';

            // Para proyectos Node.js asegurar compatibilidad con OpenSSL 3.0, Webpack y secretos comunes
            if ($project->language === 'nodejs') {
                $command[] = '-e';
                $command[] = 'NODE_OPTIONS=--openssl-legacy-provider';
                $command[] = '-e';
                $command[] = 'JWT_SECRET=uleam_secret_jwt_token_' . md5($project->id);
                $command[] = '-e';
                $command[] = 'SECRET_KEY=uleam_secret_key_' . md5($project->id);
                $command[] = '-e';
                $command[] = 'SESSION_SECRET=uleam_session_secret_' . md5($project->id);
            }

            if ($project->language === 'php') {
                $command[] = '-e';
                $command[] = 'PHP_CLI_SERVER_WORKERS=4';
            }

            if ($project->language === 'ruby') {
                $command[] = '-e';
                $command[] = 'RAILS_ENV=production';
                $command[] = '-e';
                $command[] = 'RACK_ENV=production';
                $command[] = '-e';
                $command[] = 'RAILS_SERVE_STATIC_FILES=true';
                $command[] = '-e';
                $command[] = 'RAILS_LOG_TO_STDOUT=true';
                $command[] = '-e';
                $command[] = 'RAILS_FORCE_SSL=false';
                $command[] = '-e';
                $command[] = 'RAILS_ASSUME_SSL=false';
                $command[] = '-e';
                $command[] = 'DISABLE_SSL=true';
                $command[] = '-e';
                $command[] = 'SECRET_KEY_BASE=uleam_rails_secret_key_base_' . md5($project->id);
                $command[] = '-e';
                $command[] = 'PORT=3000';
                $command[] = '-e';
                $command[] = 'BUNDLE_PATH=vendor/bundle';
            }

            // Variables de entorno para optimización de memoria (256MB) y compatibilidad Python/Django/Node
            $command[] = '-e';
            $command[] = 'WEB_CONCURRENCY=1';
            $command[] = '-e';
            $command[] = 'ALLOWED_HOSTS=*';
            $command[] = '-e';
            $command[] = 'SECRET_KEY=uleam_secret_key_' . md5($project->id);

            // Append base image
            $command[] = $settings['image'];

            // Append start command
            foreach ($settings['command'] as $arg) {
                $command[] = $arg;
            }

            $process = new Process($command);
            $process->setTimeout(60);
            $process->mustRun();

            $containerId = trim($process->getOutput());

            // Liveness Probe: Verificar durante 4 segundos que el contenedor se mantenga en ejecucion
            $isAlive = true;
            $exitCode = null;

            for ($i = 0; $i < 4; $i++) {
                sleep(1);
                $inspect = new Process(['docker', 'inspect', '--format', '{{.State.Status}} {{.State.ExitCode}}', $containerName]);
                $inspect->run();
                $stateOutput = trim($inspect->getOutput());
                $parts = explode(' ', $stateOutput);
                $status = $parts[0] ?? '';
                $exitCode = $parts[1] ?? '0';

                if ($status === 'exited' || $status === 'dead' || ($exitCode !== '0' && $exitCode !== '')) {
                    $isAlive = false;
                    break;
                }
            }

            if (!$isAlive) {
                $logsProcess = new Process(['docker', 'logs', '--tail', '100', $containerName]);
                $logsProcess->run();
                $crashLogs = trim($logsProcess->getOutput() . "\n" . $logsProcess->getErrorOutput());

                // Limpiar el contenedor fallido
                (new Process(['docker', 'rm', '-f', $containerName]))->run();

                $output .= $this->formatFriendlyCrashDiagnosis($crashLogs, $project, (string)$exitCode);

                return [
                    'success' => false,
                    'container_id' => null,
                    'output' => $output
                ];
            }

            $projectUrl = "https://{$project->subdomain}.{$domain}";
            $output .= "Servidor web verificado y activo (En ejecución).\n\n";
            $output .= "=======================================================\n";
            $output .= "PROYECTO DESPLEGADO CON EXITO\n";
            $output .= "Tu aplicacion se encuentra en ejecucion y lista para ser evaluada.\n";
            $output .= "URL: {$projectUrl}\n";
            $output .= "=======================================================\n";

            return [
                'success' => true,
                'container_id' => $containerId,
                'output' => $output
            ];

        } catch (ProcessFailedException $e) {
            return [
                'success' => false,
                'container_id' => null,
                'output' => $output . "Fallo al iniciar el contenedor: " . $e->getMessage() . "\n" . $e->getProcess()->getErrorOutput()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'container_id' => null,
                'output' => $output . "Error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get unique container name.
     */
    private function getContainerName(Project $project): string
    {
        return "project-{$project->id}";
    }

    /**
     * Ensure the shared Docker network exists.
     */
    private function ensureDockerNetworkExists(string $networkName): void
    {
        $check = new Process(['docker', 'network', 'inspect', $networkName]);
        $check->run();

        if (!$check->isSuccessful()) {
            $create = new Process(['docker', 'network', 'create', $networkName]);
            $create->run();
        }
    }

    /**
     * Detener y eliminar el contenedor si ya existe de forma inmediata.
     */
    private function stopAndRemoveContainer(string $name): void
    {
        // Forzar eliminación inmediata
        $rm = new Process(['docker', 'rm', '-f', $name]);
        $rm->run();
    }

    /**
     * Get container image, port, and start command based on language.
     */
    private function getLanguageSettings(?string $language, string $projectPath, ?Project $project = null): ?array
    {
        switch ($language) {
            case 'nodejs':
                $command = ['node', 'index.js'];
                // Monorepos con client y api integrados
                if (File::exists($projectPath . '/client/server.js') && File::exists($projectPath . '/api')) {
                    $runnerContent = <<<'JS'
const { spawn } = require('child_process');

console.log('[Runner] Iniciando backend API en puerto 5000...');
const api = spawn('node', ['build/index.js'], {
    cwd: '/app/api',
    env: { ...process.env, PORT: '5000', NODE_PATH: '/app/api/build:/app/api/node_modules' },
    stdio: 'inherit'
});

console.log('[Runner] Esperando 3 segundos para que la API esté lista...');
setTimeout(() => {
    console.log('[Runner] Iniciando servidor frontend en puerto 3000...');
    const client = spawn('node', ['client/server.js'], {
        cwd: '/app',
        env: { ...process.env, PORT: '3000' },
        stdio: 'inherit'
    });

    client.on('exit', code => process.exit(code));
    api.on('exit', code => process.exit(code));
}, 3000);
JS;
                    File::put($projectPath . '/_monorepo_runner.js', $runnerContent);
                    $command = ['node', '_monorepo_runner.js'];
                } elseif (File::exists($projectPath . '/server.js')) {
                    $command = ['node', 'server.js'];
                } elseif (File::exists($projectPath . '/app.js')) {
                    $command = ['node', 'app.js'];
                } elseif (File::exists($projectPath . '/client/server.js')) {
                    $command = ['node', 'client/server.js'];
                } elseif (File::exists($projectPath . '/backend/server.js')) {
                    $command = ['node', 'backend/server.js'];
                } else {
                    // Detección universal de SPAs estáticas (Create React App, Vite, Vue, Angular, etc.)
                    $staticDirs = ['build', 'dist', 'out', 'public', 'client/build', 'frontend/dist', 'frontend/build'];
                    $servedStaticDir = null;
                    if (!File::exists($projectPath . '/server.js') && !File::exists($projectPath . '/app.js') && !File::exists($projectPath . '/api')) {
                        foreach ($staticDirs as $sDir) {
                            if (File::exists($projectPath . '/' . $sDir . '/index.html')) {
                                $servedStaticDir = $sDir;
                                break;
                            }
                        }
                    }

                    if ($servedStaticDir) {
                        // Servimos con serve en el puerto 3000 (consume < 25MB RAM y soporta SPA fallback)
                        if ($project && !empty($project->subdomain)) {
                            $subdomainLink = $projectPath . '/' . $servedStaticDir . '/' . trim($project->subdomain, '/');
                            if (!file_exists($subdomainLink) && !is_link($subdomainLink)) {
                                @symlink('.', $subdomainLink);
                            }
                        }
                        $command = ['npx', '-y', 'serve', '-s', $servedStaticDir, '-l', '3000'];
                    } elseif (File::exists($projectPath . '/package.json')) {
                        $packageJson = json_decode(File::get($projectPath . '/package.json'), true);
                        if (isset($packageJson['scripts']['start:production']) && !str_contains($packageJson['scripts']['start:production'], 'pm2')) {
                            $command = ['npm', 'run', 'start:production'];
                        } elseif (isset($packageJson['scripts']['start']) && !str_contains($packageJson['scripts']['start'], 'pm2')) {
                            $command = ['npm', 'start'];
                        } elseif (isset($packageJson['scripts']['preview'])) {
                            $command = ['npx', 'vite', 'preview', '--host', '0.0.0.0', '--port', '3000'];
                        } elseif (isset($packageJson['scripts']['dev'])) {
                            $command = ['npx', 'vite', '--host', '0.0.0.0', '--port', '3000'];
                        }
                    }
                }

                return [
                    'image' => 'node:20-alpine',
                    'port' => 3000,
                    'command' => $command
                ];

            case 'php':
                // Built-in PHP server con supresion de avisos deprecated y soporte de router para Laravel/frameworks
                $command = ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-S', '0.0.0.0:80'];
                if (File::exists($projectPath . '/server.php')) {
                    // Laravel incluye server.php en la raíz como emulador de mod_rewrite para el servidor integrado con document root public
                    $command = ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-S', '0.0.0.0:80', '-t', 'public', 'server.php'];
                } elseif (File::exists($projectPath . '/public/index.php')) {
                    $routerPath = $projectPath . '/_php_router.php';
                    if (!File::exists($routerPath)) {
                        $routerContent = <<<'PHP'
<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    return false;
}
require_once __DIR__ . '/public/index.php';
PHP;
                        File::put($routerPath, $routerContent);
                    }
                    $command = ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-S', '0.0.0.0:80', '-t', 'public', '_php_router.php'];
                }
                return [
                    'image' => 'webdevops/php:8.4',
                    'port' => 80,
                    'command' => $command
                ];

            case 'ruby':
                @unlink($projectPath . '/tmp/pids/server.pid');
                $railsBin = File::exists($projectPath . '/bin/rails') ? 'bin/rails' : 'rails';
                $command = ['sh', '-c', "rm -f tmp/pids/server.pid && exec bundle exec {$railsBin} server -b 0.0.0.0 -p 3000 -e production"];
                if (!File::exists($projectPath . '/bin/rails') && !File::exists($projectPath . '/config/environment.rb')) {
                    if (File::exists($projectPath . '/config.ru')) {
                        $command = ['bundle', 'exec', 'rackup', '-o', '0.0.0.0', '-p', '3000', '-E', 'production'];
                    } elseif (File::exists($projectPath . '/app.rb')) {
                        $command = ['ruby', 'app.rb', '-o', '0.0.0.0', '-p', '3000'];
                    }
                }
                $rubyImage = 'uleam_ruby:3.3';
                if (File::exists($projectPath . '/.ruby-version') && str_starts_with(trim(File::get($projectPath . '/.ruby-version')), '4')) {
                    $rubyImage = 'uleam_ruby:4.0';
                } elseif (File::exists($projectPath . '/.ruby-version') && str_starts_with(trim(File::get($projectPath . '/.ruby-version')), '3.4')) {
                    $rubyImage = 'uleam_ruby:3.4';
                } elseif (File::exists($projectPath . '/.ruby-version') && str_starts_with(trim(File::get($projectPath . '/.ruby-version')), '2.')) {
                    $rubyImage = 'uleam_ruby:2.7';
                } elseif (File::exists($projectPath . '/Gemfile') && preg_match("/ruby\s+['\"]4/", File::get($projectPath . '/Gemfile'))) {
                    $rubyImage = 'uleam_ruby:4.0';
                } elseif (File::exists($projectPath . '/Gemfile') && preg_match("/ruby\s+['\"]3\.4/", File::get($projectPath . '/Gemfile'))) {
                    $rubyImage = 'uleam_ruby:3.4';
                } elseif (File::exists($projectPath . '/Gemfile') && preg_match("/ruby\s+['\"]2\./", File::get($projectPath . '/Gemfile'))) {
                    $rubyImage = 'uleam_ruby:2.7';
                }
                return [
                    'image' => $rubyImage,
                    'port' => 3000,
                    'command' => $command
                ];

            case 'python':
                // Check if main.py, app.py or manage.py exists
                $command = ['python', 'main.py'];
                if (File::exists($projectPath . '/app.py')) {
                    $command = ['python', 'app.py'];
                } elseif (File::exists($projectPath . '/manage.py')) {
                    $command = ['python', 'manage.py', 'runserver', '0.0.0.0:5000'];
                }

                // If virtual env exists, run from it
                if (File::isDirectory($projectPath . '/.venv')) {
                    $command[0] = './.venv/bin/' . $command[0];
                }

                return [
                    'image' => 'python:3.12-alpine',
                    'port' => 5000,
                    'command' => $command
                ];

            case 'java':
                // Determine JAR location (Maven: target/, Gradle: build/libs/)
                $jarDir = File::exists($projectPath . '/pom.xml') ? 'target' : 'build/libs';
                return [
                    'image'   => 'eclipse-temurin:17-jre-alpine',
                    'port'    => 8080,
                    'command' => ['sh', '-c', "java -jar /app/{$jarDir}/*.jar"]
                ];

            case 'dotnet':
                // Run the pre-published binary from /app/publish/
                return [
                    'image'   => 'mcr.microsoft.com/dotnet/aspnet:8.0-alpine',
                    'port'    => 80,
                    'command' => ['sh', '-c', 'dotnet /app/publish/*.dll']
                ];

            case 'dockerfile':
                // Use the image built by buildDockerfile().
                // basename($projectPath) = 'project-{UUID}', so image = 'project-{UUID}-img'
                // which matches what buildDockerfile() creates: 'project-' . $project->id . '-img'
                $port = $this->detectDockerfilePort($projectPath);
                $cmd = $this->detectDockerfileCommand($projectPath);
                return [
                    'image'   => basename($projectPath) . '-img',
                    'port'    => $port,
                    'command' => $cmd
                ];

            default:
                return null;
        }
    }

    /**
     * Detect the EXPOSE port from a project's Dockerfile.
     * Falls back to 8080 if not found.
     */
    private function detectDockerfilePort(string $projectPath): int
    {
        $dockerfilePath = File::exists($projectPath . '/Dockerfile')
            ? $projectPath . '/Dockerfile'
            : $projectPath . '/dockerfile';

        if (!File::exists($dockerfilePath)) {
            return 8080;
        }

        $content = File::get($dockerfilePath);
        if (preg_match('/^EXPOSE\s+(\d+)/mi', $content, $matches)) {
            return (int) $matches[1];
        }

        return 8080;
    }

    /**
     * Detect the WORKDIR from a project's Dockerfile.
     * Falls back to /app if not found.
     */
    private function detectDockerfileWorkdir(string $projectPath): string
    {
        $dockerfilePath = File::exists($projectPath . '/Dockerfile')
            ? $projectPath . '/Dockerfile'
            : $projectPath . '/dockerfile';

        if (!File::exists($dockerfilePath)) {
            return '/app';
        }

        $content = File::get($dockerfilePath);
        if (preg_match('/^WORKDIR\s+(\S+)/mi', $content, $matches)) {
            return trim($matches[1]);
        }

        return '/app';
    }

    /**
     * Detect and adapt the CMD from a project's Dockerfile.
     * Reduces multi-worker servers (like uvicorn/gunicorn --workers=2+) to 1 worker for the 256MB sandbox limit.
     */
    private function detectDockerfileCommand(string $projectPath): array
    {
        $dockerfilePath = File::exists($projectPath . '/Dockerfile')
            ? $projectPath . '/Dockerfile'
            : $projectPath . '/dockerfile';

        if (!File::exists($dockerfilePath)) {
            return [];
        }

        $content = File::get($dockerfilePath);
        if (preg_match('/CMD\s*(\[[^\]]+\])/i', $content, $matches)) {
            $cmdArray = json_decode($matches[1], true);
            if (is_array($cmdArray)) {
                // Adaptar flags de workers pesados para sandbox de 256MB
                return array_map(function($arg) {
                    return preg_replace('/--workers=\d+/', '--workers=1', $arg);
                }, $cmdArray);
            }
        }
        return [];
    }

    /**
     * Analiza los logs de colapso y genera un diagnóstico comprensible y pedagógico para el estudiante.
     */
    private function formatFriendlyCrashDiagnosis(string $crashLogs, Project $project, string $exitCode): string
    {
        $logLower = strtolower($crashLogs);

        // Extraer la ultima linea significativa de error para casos no catalogados
        $lastErrorLine = '';
        if (!empty($crashLogs)) {
            $lines = array_filter(array_map('trim', explode("\n", $crashLogs)));
            $reversed = array_reverse($lines);
            foreach ($reversed as $l) {
                if (strlen($l) > 6 && !str_starts_with($l, 'from ') && !str_starts_with($l, 'at ') && !str_starts_with($l, '#')) {
                    $lastErrorLine = $l;
                    break;
                }
            }
            if (empty($lastErrorLine) && !empty($reversed)) {
                $lastErrorLine = $reversed[0];
            }
        }

        $titulo = "Error Inesperado en la Aplicacion";
        $quePaso = "Tu servidor se inicio pero se cerro repentinamente al intentar ejecutarse.";
        if (!empty($lastErrorLine)) {
            $quePaso .= "\nDetalle reportado por el servidor: \"{$lastErrorLine}\"";
        }
        $solucion = "- Revisa el mensaje anterior para corregir el archivo o variable correspondiente.\n- Prueba ejecutar tu proyecto localmente para validar que el servidor inicie correctamente y luego haz un nuevo push.";

        // Caso 1: Software no soportado o que requiere binarios de sistema ajenos al hosting web (Leptonica, libvips, poppler, tesseract, etc.)
        if (str_contains($logLower, 'libleptonica') || str_contains($logLower, 'leptonica') || 
            str_contains($logLower, 'cannot open shared object file') || str_contains($logLower, 'shared object file') ||
            str_contains($logLower, 'could not open library') || str_contains($logLower, 'libvips') ||
            str_contains($logLower, 'libgomp') || str_contains($logLower, 'tesseract')) {
            $titulo = "Tipo de Proyecto No Soportado (Arquitectura Incompatible)";
            $quePaso = "Este repositorio no es una aplicacion web estandar, sino un software especializado (appliance) que exige herramientas binarias de C/C++ de bajo nivel. La plataforma esta diseñada exclusivamente para el despliegue de aplicaciones y sitios web academicos.";
            $solucion = "- Despliega unicamente proyectos de desarrollo web academico (Laravel, Node.js, Django, React, Rails, etc.) basados en librerias estandar del lenguaje.\n- El software empresarial de terceros o herramientas con dependencias nativas de bajo nivel del sistema operativo no son compatibles con este entorno de hosting.";
        }
        // Caso 2: Error de conexión a la Base de Datos
        elseif (str_contains($logLower, 'connection refused') || str_contains($logLower, 'connectionbad') || 
                str_contains($logLower, 'econnrefused') || str_contains($logLower, 'password authentication failed') ||
                str_contains($logLower, 'could not connect to server') || str_contains($logLower, 'access denied for user')) {
            $titulo = "Error de Conexión con la Base de Datos";
            $quePaso = "El servidor intentó conectarse a la base de datos pero la conexión fue rechazada o las credenciales no son válidas.";
            $solucion = "- Verifica que tu base de datos esté encendida en la plataforma.\n- Revisa las variables de entorno en la configuración para confirmar que DB_HOST, DB_PORT, DB_USERNAME y DB_PASSWORD sean los asignados por el sistema.";
        }
        // Caso 3: Módulos o dependencias faltantes
        elseif (str_contains($logLower, 'cannot find module') || str_contains($logLower, 'modulenotfounderror') || 
                str_contains($logLower, 'no module named') || str_contains($logLower, 'cannot load such file') ||
                str_contains($logLower, 'loaderror') || str_contains($logLower, 'class not found')) {
            $titulo = "Dependencia o Módulo No Instalado";
            $quePaso = "El código intenta importar un paquete o módulo que no se encuentra instalado en las dependencias.";
            $solucion = "- Declara todas tus librerías en tu archivo de configuración (package.json, requirements.txt, Gemfile o composer.json).\n- Asegúrate de hacer 'git commit' y 'git push' de dicho archivo en tu repositorio.";
        }
        // Caso 4: Falta el comando de inicio o script start
        elseif (str_contains($logLower, 'missing script: "start"') || str_contains($logLower, 'command not found') || $exitCode === '127') {
            $titulo = "Archivo o Comando de Inicio No Encontrado";
            $quePaso = "La plataforma intentó iniciar tu proyecto pero no encontró el script de inicio principal.";
            $solucion = "- En Node.js: Asegúrate de tener la clave \"start\" dentro de \"scripts\" en tu package.json (ej: \"start\": \"node index.js\").\n- En Python o PHP: Asegúrate de que el archivo principal (app.py, main.py, index.php) esté en la raíz de tu proyecto.";
        }
        // Caso 5: Error de Sintaxis o Excepción no controlada en el código
        elseif (str_contains($logLower, 'syntaxerror') || str_contains($logLower, 'parse error') || str_contains($logLower, 'syntax error') ||
                str_contains($logLower, 'indentationerror')) {
            $titulo = "Error de Sintaxis en el Código";
            $quePaso = "El código de tu aplicación contiene un error de sintaxis que impide al intérprete ejecutar el proyecto.";
            $solucion = "- Corrige el error de sintaxis en tu computadora localmente y haz un nuevo push a GitHub.";
        }
        // Caso 6: Conflicto de puerto
        elseif (str_contains($logLower, 'eaddrinuse') || str_contains($logLower, 'address already in use')) {
            $titulo = "Conflicto de Puerto de Red";
            $quePaso = "La aplicación intentó abrir un puerto que ya estaba en uso o no disponible.";
            $solucion = "- Configura tu servidor para escuchar en la variable de entorno PORT (ej: process.env.PORT || 3000).";
        }

        $headerText = str_contains($titulo, 'No Soportado') ? $titulo : "ERROR AL INICIAR EL SERVIDOR: {$titulo}";

        $out = "\n=======================================================\n";
        $out .= "{$headerText}\n";
        $out .= "=======================================================\n\n";
        $out .= "QUE PASO:\n";
        $out .= "{$quePaso}\n\n";
        $out .= "COMO SOLUCIONARLO:\n";
        $out .= "{$solucion}\n";
        $out .= "=======================================================\n";

        return $out;
    }
}
