<?php

namespace App\Actions\Docker;

use App\Models\Project;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\File;
use Exception;

class BuildProjectAction
{
    /**
     * Build the project by installing dependencies inside a temporary Docker container.
     *
     * @param Project $project
     * @param string $projectPath
     * @return array{success: bool, output: string}
     */
    public function execute(Project $project, string $projectPath): array
    {
        if (!$project->language) {
            return [
                'success' => false,
                'output' => "Compilación fallida: Lenguaje no detectado o no establecido para el proyecto."
            ];
        }

        $uid = $this->getUid();
        $gid = $this->getGid();

        // Prepare commands based on language
        switch ($project->language) {
            case 'nodejs':
                return $this->buildNodeJs($project, $projectPath, $uid, $gid);
            case 'php':
                return $this->buildPhp($project, $projectPath, $uid, $gid);
            case 'python':
                return $this->buildPython($projectPath, $uid, $gid);
            case 'java':
                return $this->buildJava($projectPath, $uid, $gid);
            case 'dotnet':
                return $this->buildDotnet($projectPath, $uid, $gid);
            case 'dockerfile':
                return $this->buildDockerfile($project, $projectPath);
            default:
                return [
                    'success' => false,
                    'output' => "Lenguaje no soportado: " . $project->language
                ];
        }
    }

    /**
     * Build Node.js application (npm install && npm run build)
     */
    private function buildNodeJs(Project $project, string $path, string $uid, string $gid): array
    {
        $output = "";

        // Auto-instalar el driver de BD si el proyecto no lo tiene en dependencias
        $packageJsonPath = $path . '/package.json';
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            $deps = array_merge(
                $packageJson['dependencies'] ?? [],
                $packageJson['devDependencies'] ?? []
            );

            if ($project->db_driver === 'mysql') {
                if (!isset($deps['mysql']) && !isset($deps['mysql2'])) {
                    $output .= "Detectado proyecto Node.js sin driver de MySQL. Instalando mysql2 automáticamente...\n";
                    $dbCommand = [
                        'docker', 'run', '--rm',
                        '-u', "$uid:$gid",
                        '-v', "$path:/app",
                        '-w', '/app',
                        'node:18-alpine',
                        'npm', 'install', 'mysql2', '--no-audit', '--no-fund', '--save'
                    ];
                    $dbResult = $this->runCommand($dbCommand);
                    $output .= $dbResult['output'] . "\n";
                }
            } else { // pgsql
                if (!isset($deps['pg'])) {
                    $output .= "Detectado proyecto Node.js sin driver de PostgreSQL. Instalando pg automáticamente...\n";
                    $dbCommand = [
                        'docker', 'run', '--rm',
                        '-u', "$uid:$gid",
                        '-v', "$path:/app",
                        '-w', '/app',
                        'node:18-alpine',
                        'npm', 'install', 'pg', '--no-audit', '--no-fund', '--save'
                    ];
                    $dbResult = $this->runCommand($dbCommand);
                    $output .= $dbResult['output'] . "\n";
                }
            }

            // Auto-instalar paquetes comunes requeridos en el código pero no declarados en package.json
            $commonPackages = ['jsonwebtoken', 'bcrypt', 'cors', 'express', 'dotenv', 'multer', 'nodemailer'];
            $missingPackages = [];
            foreach ($commonPackages as $pkg) {
                if (!isset($deps[$pkg])) {
                    $missingPackages[] = $pkg;
                }
            }

            if (!empty($missingPackages)) {
                try {
                    $finder = new \Symfony\Component\Finder\Finder();
                    $finder->files()
                        ->in($path)
                        ->name('*.js')
                        ->name('*.ts')
                        ->name('*.jsx')
                        ->name('*.tsx')
                        ->ignoreDotFiles(true)
                        ->ignoreVCS(true)
                        ->exclude(['node_modules', 'dist', 'vendor', '.git', 'build', '.next', 'cache']);

                    foreach ($missingPackages as $pkg) {
                        $found = false;
                        foreach ($finder as $file) {
                            $content = @file_get_contents($file->getRealPath());
                            if ($content && (
                                preg_match('/require\([\'"]' . preg_quote($pkg, '/') . '[\'"]\)/', $content) ||
                                preg_match('/import\s+.*?\s+from\s+[\'"]' . preg_quote($pkg, '/') . '[\'"]/', $content) ||
                                preg_match('/import\s+[\'"]' . preg_quote($pkg, '/') . '[\'"]/', $content)
                            )) {
                                $found = true;
                                break;
                            }
                        }
                        if ($found) {
                            $output .= "Detectado uso de '{$pkg}' pero no está declarado en package.json. Instalando automáticamente...\n";
                            $installPkgCmd = [
                                'docker', 'run', '--rm',
                                '-u', "$uid:$gid",
                                '-v', "$path:/app",
                                '-w', '/app',
                                'node:18-alpine',
                                'npm', 'install', $pkg, '--no-audit', '--no-fund', '--save'
                            ];
                            $pkgResult = $this->runCommand($installPkgCmd);
                            $output .= $pkgResult['output'] . "\n";
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore search errors
                }
            }
        }

        // 1. Detectar e instalar dependencias en subdirectorios comunes de monorepos (api, client, frontend, backend, etc.)
        $subDirs = ['api', 'client', 'frontend', 'backend', 'server', 'web', 'app'];
        foreach ($subDirs as $subDir) {
            $subPackageJson = $path . '/' . $subDir . '/package.json';
            if (File::exists($subPackageJson)) {
                $output .= "Detectado subproyecto Node.js en '{$subDir}'. Instalando dependencias...\n";
                $subInstallCmd = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-v', "$path:/app",
                    '-w', "/app/{$subDir}",
                    'node:20-alpine',
                    'npm', 'install', '--no-audit', '--no-fund'
                ];
                $subResult = $this->runCommand($subInstallCmd);
                $output .= $subResult['output'] . "\n";
            }
        }

        // 2. Install root dependencies
        $installCommand = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
            '-v', "$path:/app",
            '-w', '/app',
            'node:20-alpine',
            'npm', 'install', '--no-audit', '--no-fund'
        ];

        $output .= "Ejecutando npm install raíz...\n";
        $result = $this->runCommand($installCommand);
        $output .= $result['output'];

        if (!$result['success']) {
            return ['success' => false, 'output' => $output];
        }

        // Neutralizar URLs de API externas hardcodeadas en Webpack para permitir consumo del backend local
        $webpackConfigs = $this->getProjectScanFiles($path, 'webpack.config*.js');
        foreach ($webpackConfigs as $wFile) {
            $wContent = @file_get_contents($wFile->getRealPath());
            if ($wContent && str_contains($wContent, 'https://jira-api.ivorreic.com')) {
                $wContent = str_replace('https://jira-api.ivorreic.com', '', $wContent);
                @file_put_contents($wFile->getRealPath(), $wContent);
            }
        }

        // Neutralizar fallbacks de localhost en clientes API frontend
        $apiJsFiles = $this->getProjectScanFiles($path, 'api.js');
        foreach ($apiJsFiles as $aFile) {
            $aContent = @file_get_contents($aFile->getRealPath());
            if ($aContent && str_contains($aContent, "'http://localhost:3000'")) {
                $aContent = str_replace("'http://localhost:3000'", "''", $aContent);
                @file_put_contents($aFile->getRealPath(), $aContent);
            }
        }

        // 3. Check if build script exists and run npm run build con binarios en PATH
        $packageJsonPath = $path . '/package.json';
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            if (isset($packageJson['scripts']['build'])) {
                $output .= "\nEjecutando npm run build...\n";
                $pathExports = "export PATH=\$PATH:/app/node_modules/.bin:/app/api/node_modules/.bin:/app/client/node_modules/.bin:/app/frontend/node_modules/.bin:/app/backend/node_modules/.bin:/app/server/node_modules/.bin && export NODE_OPTIONS=--openssl-legacy-provider";
                $buildCommand = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-v', "$path:/app",
                    '-w', '/app',
                    'node:20-alpine',
                    'sh', '-c',
                    "{$pathExports} && npm run build"
                ];
                $buildResult = $this->runCommand($buildCommand);
                $output .= $buildResult['output'];
                if (!$buildResult['success']) {
                    return ['success' => false, 'output' => $output];
                }
            }
        }

        // Si es un monorepo con client/server.js y api/, asegurar que client/server.js haga proxy a las rutas de API
        $clientServerJs = $path . '/client/server.js';
        if (File::exists($clientServerJs) && File::exists($path . '/api')) {
            $serverJsContent = File::get($clientServerJs);
            if (!str_contains($serverJsContent, 'proxyReq')) {
                $proxyCode = <<<'JS'
const http = require('http');
const apiRoutes = ['/currentUser', '/issues', '/comments', '/authentication', '/api'];
app.use((req, res, next) => {
    const isHtmlNavigation = req.method === 'GET' && req.headers.accept && req.headers.accept.includes('text/html');
    const isApiMatch = (apiRoutes.some(route => req.path.startsWith(route)) || req.path === '/project') && !isHtmlNavigation;

    if (isApiMatch) {
        const proxyReq = http.request({
            host: '127.0.0.1',
            port: 5000,
            path: req.url,
            method: req.method,
            headers: req.headers,
        }, proxyRes => {
            res.writeHead(proxyRes.statusCode, proxyRes.headers);
            proxyRes.pipe(res, { end: true });
        });
        proxyReq.on('error', err => {
            console.error('API Proxy Error:', err);
            res.status(502).json({ error: 'Backend unavailable' });
        });
        req.pipe(proxyReq, { end: true });
    } else {
        next();
    }
});
JS;
                $serverJsContent = preg_replace('/app\.use\(express\.static/', $proxyCode . "\napp.use(express.static", $serverJsContent);
                File::put($clientServerJs, $serverJsContent);
            }
        }

        // Parche de compatibilidad para TypeORM 0.2 con PostgreSQL 12-16 (reemplazo de pg_constraint.consrc)
        $pqrFiles = $this->getProjectScanFiles($path, 'PostgresQueryRunner.js');
        foreach ($pqrFiles as $pqr) {
            $content = @file_get_contents($pqr->getRealPath());
            if ($content && str_contains($content, 'consrc')) {
                $content = str_replace('CASE \\"cnst\\".\\"contype\\" WHEN \'x\' THEN pg_get_constraintdef(\\"cnst\\".\\"oid\\", true) ELSE \\"cnst\\".\\"consrc\\" END', 'pg_get_constraintdef(\\"cnst\\".\\"oid\\", true)', $content);
                $content = str_replace('CASE "cnst"."contype" WHEN \'x\' THEN pg_get_constraintdef("cnst"."oid", true) ELSE "cnst"."consrc" END', 'pg_get_constraintdef("cnst"."oid", true)', $content);
                $content = str_replace('"cnst"."consrc"', 'pg_get_constraintdef("cnst"."oid", true)', $content);
                @file_put_contents($pqr->getRealPath(), $content);
            }
        }

        // Parche de compatibilidad para module-alias en Node 20
        $moduleAliasFiles = $this->getProjectScanFiles($path, 'register.js');
        foreach ($moduleAliasFiles as $maFile) {
            if (str_contains($maFile->getRealPath(), 'module-alias')) {
                @file_put_contents($maFile->getRealPath(), '// noop module-alias for node 20 compatibility');
            }
        }

        // Asegurar que el driver de PostgreSQL en Node sea compatible con SCRAM-SHA-256 (pg@8)
        if ($project->db_driver === 'pgsql') {
            $pgSubDirs = array_merge(['.'], $subDirs);
            foreach ($pgSubDirs as $sd) {
                $pkgFile = ($sd === '.') ? $path . '/package.json' : $path . '/' . $sd . '/package.json';
                if (File::exists($pkgFile)) {
                    $pkgData = json_decode(File::get($pkgFile), true);
                    if (isset($pkgData['dependencies']['pg']) || isset($pkgData['devDependencies']['pg'])) {
                        $workDir = ($sd === '.') ? '/app' : "/app/{$sd}";
                        $pgUpgradeCmd = [
                            'docker', 'run', '--rm',
                            '-u', "$uid:$gid",
                            '-v', "$path:/app",
                            '-w', $workDir,
                            'node:20-alpine',
                            'npm', 'install', 'pg@^8.11.0', '--no-audit', '--no-fund', '--save'
                        ];
                        $this->runCommand($pgUpgradeCmd);
                    }
                }
            }
        }

        return ['success' => true, 'output' => $output];
    }

    /**
     * Construir aplicación PHP (composer install)
     */
    private function buildPhp(Project $project, string $path, string $uid, string $gid): array
    {
        // Si no existe composer.json, omitimos la instalación de dependencias
        if (!File::exists($path . '/composer.json')) {
            return [
                'success' => true,
                'output' => "No se encontró composer.json. Omitiendo la instalación de dependencias de Composer.\n"
            ];
        }

        $composerCacheDir = storage_path('app/composer-cache');
        if (!File::exists($composerCacheDir)) {
            File::makeDirectory($composerCacheDir, 0777, true, true);
        }

        $command = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
            '-v', "$composerCacheDir:/tmp/cache",
            '-e', 'COMPOSER_CACHE_DIR=/tmp/cache',
            '-w', '/app',
            'composer:latest',
            'composer', 'install', '--optimize-autoloader', '--no-interaction', '--ignore-platform-reqs'
        ];

        $output = "Ejecutando composer install...\n";
        $result = $this->runCommand($command);
        $output .= $result['output'];

        if (!$result['success']) {
            return [
                'success' => false,
                'output' => $output
            ];
        }

        // Si es Laravel, asegurar la existencia de un APP_KEY en el .env
        if (File::exists($path . '/artisan')) {
            $envPath = $path . '/.env';
            if (!File::exists($envPath) || strlen(trim(File::get($envPath))) < 50) {
                if (File::exists($path . '/.env.example')) {
                    File::copy($path . '/.env.example', $envPath);
                } elseif (!File::exists($envPath)) {
                    File::put($envPath, "");
                }
            }

            // Sincronizar credenciales de BD y APP_URL en el .env si el proyecto tiene base de datos asignada
            if (!empty($project->db_name)) {
                $dbHost = $project->db_driver === 'mysql' ? 'uleam_mysql_students' : 'uleam_postgres_students';
                $dbPort = $project->db_driver === 'mysql' ? '3306' : '5432';
                $appUrl = "http://{$project->subdomain}.localhost";

                $envVars = [
                    'APP_URL' => $appUrl,
                    'DB_CONNECTION' => $project->db_driver ?: 'mysql',
                    'DB_HOST' => $dbHost,
                    'DB_PORT' => $dbPort,
                    'DB_DATABASE' => $project->db_name,
                    'DB_USERNAME' => $project->db_user,
                    'DB_PASSWORD' => $project->db_password,
                ];

                $currentEnv = File::get($envPath);
                foreach ($envVars as $varKey => $varVal) {
                    if (preg_match("/^{$varKey}=/m", $currentEnv)) {
                        $currentEnv = preg_replace("/^{$varKey}=.*/m", "{$varKey}={$varVal}", $currentEnv);
                    } else {
                        $currentEnv .= "\n{$varKey}={$varVal}";
                    }
                }
                File::put($envPath, $currentEnv);
            }
            $envContent = File::get($envPath);
            if (!str_contains($envContent, 'APP_KEY=base64:')) {
                if (!str_contains($envContent, 'APP_KEY=')) {
                    File::append($envPath, "\nAPP_KEY=\n");
                }
                $output .= "\nDetectado Laravel sin APP_KEY. Generando llave de aplicación...\n";
                $keyCommand = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-v', "$path:/app",
                    '-w', '/app',
                    'webdevops/php:8.4',
                    'php', 'artisan', 'key:generate'
                ];
                $keyResult = $this->runCommand($keyCommand);
                $output .= $keyResult['output'] . "\n";
            }
        }

        // Si el proyecto PHP tiene package.json, compilar los assets de frontend (Vite/Mix/Webpack/esbuild)
        $packageJsonPath = $path . '/package.json';
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            $scripts = $packageJson['scripts'] ?? [];
            $buildScript = isset($scripts['build']) ? 'build' : (isset($scripts['production']) ? 'production' : (isset($scripts['prod']) ? 'prod' : null));

            // Verificar si ya existen assets precompilados en public/
            $hasPrecompiledAssets = File::exists($path . '/public/css') 
                || File::exists($path . '/public/js') 
                || File::exists($path . '/public/build') 
                || File::exists($path . '/public/akaunting-js')
                || File::exists($path . '/public/mix-manifest.json');

            if ($buildScript && !$hasPrecompiledAssets) {
                $output .= "\nDetectado package.json en proyecto PHP sin assets precompilados. Instalando dependencias de frontend...\n";
                $npmInstallCmd = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-v', "$path:/app",
                    '-w', '/app',
                    'node:20-alpine',
                    'npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts'
                ];
                $npmResult = $this->runCommand($npmInstallCmd);
                $output .= $npmResult['output'] . "\n";

                $output .= "Compilando assets de frontend (npm run {$buildScript})...\n";
                $npmBuildCmd = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-v', "$path:/app",
                    '-w', '/app',
                    'node:20-alpine',
                    'sh', '-c', "export PATH=\$PATH:/app/node_modules/.bin && npm run {$buildScript}"
                ];
                $npmBuildResult = $this->runCommand($npmBuildCmd);
                $output .= $npmBuildResult['output'] . "\n";
            } elseif ($hasPrecompiledAssets) {
                $output .= "\nAssets precompilados detectados en public/. Omitiendo compilación pesada de Node.js para acelerar el despliegue PHP.\n";
            }
        }

        // Compatibilidad con proyectos PHP que invocan asset('public/...') teniendo Document Root en public/
        if (File::exists($path . '/public') && !File::exists($path . '/public/public')) {
            @symlink('.', $path . '/public/public');
        }

        return [
            'success' => true,
            'output' => $output
        ];
    }

    /**
     * Build Python application (pip install in a venv)
     */
    private function buildPython(string $path, string $uid, string $gid): array
    {
        $hasRequirements = File::exists($path . '/requirements.txt');

        if (!$hasRequirements) {
            return [
                'success' => true,
                'output' => "No se encontró requirements.txt. Omitiendo la instalación de dependencias de pip."
            ];
        }

        // Run python to create venv and install dependencies
        $command = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
            '-w', '/app',
            'python:3.11-alpine',
            'sh', '-c', 'python -m venv .venv && .venv/bin/pip install --no-cache-dir -r requirements.txt'
        ];

        $output = "Creando entorno virtual e instalando requerimientos de Python...\n";
        $result = $this->runCommand($command);
        $output .= $result['output'];

        return $result;
    }

    /**
     * Execute a shell command and return its output.
     */
    private function runCommand(array $command, int $timeout = 600): array
    {
        $process = new Process($command);
        $process->setTimeout($timeout); // 10 minutes timeout for builds matching BuildProjectJob

        try {
            $process->mustRun();
            return [
                'success' => true,
                'output' => $process->getOutput() . "\n" . $process->getErrorOutput()
            ];
        } catch (ProcessFailedException $e) {
            return [
                'success' => false,
                'output' => "El comando falló: " . $e->getMessage() . "\n" . $process->getErrorOutput()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'output' => "Excepción: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get system UID of the current user.
     */
    private function getUid(): string
    {
        if (function_exists('posix_getuid')) {
            return (string) posix_getuid();
        }
        $process = new Process(['id', '-u']);
        $process->run();
        return trim($process->getOutput()) ?: '1000';
    }

    /**
     * Get system GID of the current user.
     */
    private function getGid(): string
    {
        if (function_exists('posix_getgid')) {
            return (string) posix_getgid();
        }
        $process = new Process(['id', '-g']);
        $process->run();
        return trim($process->getOutput()) ?: '1000';
    }

    /**
     * Build Java application (Maven or Gradle → Fat JAR)
     */
    private function buildJava(string $path, string $uid, string $gid): array
    {
        $output = "";
        $isMaven  = File::exists($path . '/pom.xml');
        $isGradle = File::exists($path . '/build.gradle') || File::exists($path . '/build.gradle.kts');

        if (!$isMaven && !$isGradle) {
            return ['success' => false, 'output' => "No se encontró pom.xml ni build.gradle. No se puede compilar el proyecto Java."];
        }

        if ($isMaven) {
            $output .= "Detectado proyecto Maven. Ejecutando mvn package...\n";
            $command = [
                'docker', 'run', '--rm',
                '-v', "$path:/app",
                '-w', '/app',
                'maven:3.9-eclipse-temurin-17-alpine',
                'mvn', '-q', 'package', '-DskipTests'
            ];
        } else {
            $output .= "Detectado proyecto Gradle. Ejecutando gradle build...\n";
            $wrapper = File::exists($path . '/gradlew') ? './gradlew' : 'gradle';
            $command = [
                'docker', 'run', '--rm',
                '-v', "$path:/app",
                '-w', '/app',
                'gradle:8.5-jdk17-alpine',
                'sh', '-c', "$wrapper build -x test"
            ];
        }

        $result = $this->runCommand($command);
        $output .= $result['output'];

        // Fix file permissions on host for created build artifacts (target/ / build/)
        $this->runCommand(['chown', '-R', "$uid:$gid", $path]);

        if (!$result['success']) {
            return ['success' => false, 'output' => $output];
        }

        $output .= "\nCompilación Java exitosa.\n";
        return ['success' => true, 'output' => $output];
    }

    /**
     * Build .NET application (ASP.NET Core → dotnet publish)
     */
    private function buildDotnet(string $path, string $uid, string $gid): array
    {
        $output = "Detectado proyecto .NET/C#. Ejecutando dotnet publish...\n";

        // Find the .csproj file
        $csprojFiles = glob($path . '/*.csproj');
        if (empty($csprojFiles)) {
            // Buscar en subdirectorios nivel 1
            $csprojFiles = glob($path . '/*/*.csproj');
        }

        $publishCommand = 'dotnet publish -c Release -o /app/publish --nologo -v q';

        $command = [
            'docker', 'run', '--rm',
            '-v', "$path:/app",
            '-w', '/app',
            'mcr.microsoft.com/dotnet/sdk:8.0-alpine',
            'sh', '-c', $publishCommand
        ];

        $result = $this->runCommand($command);
        $output .= $result['output'];

        // Fix file permissions on host for published artifacts
        $this->runCommand(['chown', '-R', "$uid:$gid", $path]);

        if (!$result['success']) {
            return ['success' => false, 'output' => $output];
        }

        $output .= "\nPublicación .NET exitosa. Artefactos en /app/publish.\n";
        return ['success' => true, 'output' => $output];
    }

    /**
     * Build using the project's own Dockerfile (universal language support)
     */
    private function buildDockerfile(Project $project, string $path): array
    {
        $imageName = 'project-' . $project->id . '-img';
        $output = "Detectado Dockerfile personalizado. Construyendo imagen {$imageName}...\n";

        $command = [
            'env', 'TMPDIR=/var/tmp',
            'docker', 'build',
            '-t', $imageName,
            $path
        ];

        $result = $this->runCommand($command);
        $output .= $result['output'];

        if (!$result['success']) {
            return ['success' => false, 'output' => $output];
        }

        $output .= "\nImagen Docker construida exitosamente: {$imageName}\n";
        return ['success' => true, 'output' => $output];
    }

    /**
     * Buscar archivos dentro de un proyecto para parches y configuraciones automáticas
     */
    private function getProjectScanFiles(string $path, string $pattern): array
    {
        $result = [];
        try {
            $finder = new \Symfony\Component\Finder\Finder();
            $finder->files()
                ->in($path)
                ->name($pattern)
                ->ignoreDotFiles(true)
                ->ignoreVCS(true)
                ->exclude(['.git', '.venv', 'vendor', 'cache', '.next']);
            
            foreach ($finder as $file) {
                $result[] = $file;
            }
        } catch (\Exception $e) {
            $result = [];
        }
        return $result;
    }
}
