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
        $output = "Iniciando contenedor $containerName...\n";

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
                '--memory', '256m',
                '--cpus', '0.5',
                '--pids-limit', '50',
                '-v', "$projectPath:$workDir", // Montar en su WORKDIR como lectura y escritura
            ];

            // Si el WORKDIR del contenedor no es /app, montar también en /app para compatibilidad
            if ($workDir !== '/app') {
                $command[] = '-v';
                $command[] = "$projectPath:/app";
            }

            // Si el contenedor fue construido con Dockerfile o contiene package.json,
            // preservar el directorio de node_modules de la imagen para que el montaje host no lo oculte
            if ($project->language === 'dockerfile' || File::exists($projectPath . '/package.json')) {
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
            $command[] = '--label';
            $command[] = "traefik.http.services.{$project->id}-service.loadbalancer.server.port={$settings['port']}";

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
                } else {
                    $dbHost = $dbDriver === 'mysql' ? 'uleam_mysql_students' : 'uleam_postgres_students';
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
                }
            }

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
            $output .= "Contenedor iniciado exitosamente con ID: $containerId\n";

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
}
