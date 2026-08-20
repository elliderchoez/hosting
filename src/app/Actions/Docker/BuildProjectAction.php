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
                return $this->buildPhp($projectPath, $uid, $gid);
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
            foreach ($commonPackages as $pkg) {
                if (!isset($deps[$pkg])) {
                    $found = false;
                    $files = File::allFiles($path);
                    foreach ($files as $file) {
                        $relativePath = $file->getRelativePathname();
                        if (str_contains($relativePath, 'node_modules') || str_contains($relativePath, 'dist') || str_contains($relativePath, 'vendor') || str_contains($relativePath, '.git')) {
                            continue;
                        }
                        if (in_array($file->getExtension(), ['js', 'ts'])) {
                            $content = File::get($file->getRealPath());
                            if (preg_match('/require\([\'"]' . preg_quote($pkg, '/') . '[\'"]\)/', $content) ||
                                preg_match('/import\s+.*?\s+from\s+[\'"]' . preg_quote($pkg, '/') . '[\'"]/', $content) ||
                                preg_match('/import\s+[\'"]' . preg_quote($pkg, '/') . '[\'"]/', $content)) {
                                $found = true;
                                break;
                            }
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
            }
        }

        // 1. Install dependencies
        $installCommand = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
            '-w', '/app',
            'node:18-alpine',
            'npm', 'install', '--no-audit', '--no-fund'
        ];

        $output .= "Ejecutando npm install...\n";
        $result = $this->runCommand($installCommand);
        $output .= $result['output'];

        if (!$result['success']) {
            return ['success' => false, 'output' => $output];
        }

        // 2. Check if build script exists and run npm run build
        $packageJsonPath = $path . '/package.json';
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            if (isset($packageJson['scripts']['build'])) {
                $output .= "\nEjecutando npm run build...\n";
                $buildCommand = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-v', "$path:/app",
                    '-w', '/app',
                    'node:18-alpine',
                    'npm', 'run', 'build'
                ];
                $buildResult = $this->runCommand($buildCommand);
                $output .= $buildResult['output'];
                if (!$buildResult['success']) {
                    return ['success' => false, 'output' => $output];
                }
            }
        }

        return ['success' => true, 'output' => $output];
    }

    /**
     * Construir aplicación PHP (composer install)
     */
    private function buildPhp(string $path, string $uid, string $gid): array
    {
        // Si no existe composer.json, omitimos la instalación de dependencias
        if (!File::exists($path . '/composer.json')) {
            return [
                'success' => true,
                'output' => "No se encontró composer.json. Omitiendo la instalación de dependencias de Composer.\n"
            ];
        }

        $command = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
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
            if (!File::exists($envPath)) {
                File::put($envPath, "");
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
    private function runCommand(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes timeout for builds

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
}
