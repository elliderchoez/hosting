<?php

namespace App\Actions\Docker;

use Illuminate\Support\Facades\File;

class DetectLanguageAction
{
    /**
     * Detect the language of a project by analyzing files in its folder.
     *
     * @param string $projectPath
     * @return string|null 'nodejs', 'php', 'python', 'java', 'dotnet', 'dockerfile', or null if undetected
     */
    public function execute(string $projectPath): ?string
    {
        if (!File::isDirectory($projectPath)) {
            return null;
        }

        // 0. Detect Dockerfile (Universal support for projects that provide their own custom Docker container)
        $dockerfilePath = File::exists($projectPath . '/Dockerfile') ? $projectPath . '/Dockerfile' : (File::exists($projectPath . '/dockerfile') ? $projectPath . '/dockerfile' : null);
        if ($dockerfilePath) {
            $dockerContent = @file_get_contents($dockerfilePath) ?: '';
            // Si es un proyecto Laravel (tiene artisan) y su Dockerfile es solo un contenedor auxiliar FPM o está incompleto, priorizar PHP nativo
            $isAuxiliaryPhpFpm = File::exists($projectPath . '/artisan') && (str_contains($dockerContent, '-fpm') || !str_contains($dockerContent, 'COPY') || str_contains($dockerContent, 'RUN docker-php-ext-'));
            if (!$isAuxiliaryPhpFpm) {
                return 'dockerfile';
            }
        }

        // 1. Detect PHP (Prioritized because Laravel contains package.json for Vite/JS compilation)
        if (File::exists($projectPath . '/composer.json') || 
            File::exists($projectPath . '/artisan') ||
            File::exists($projectPath . '/index.php')
        ) {
            return 'php';
        }

        // 2. Detect Python (Prioritized over Node.js when Python frameworks/tools like Django or pyproject.toml exist)
        if (File::exists($projectPath . '/requirements.txt') || 
            File::exists($projectPath . '/main.py') || 
            File::exists($projectPath . '/manage.py') || 
            File::exists($projectPath . '/Pipfile') ||
            File::exists($projectPath . '/pyproject.toml') ||
            File::exists($projectPath . '/setup.cfg')
        ) {
            return 'python';
        }

        // 3. Detect Node.js
        if (File::exists($projectPath . '/package.json')) {
            return 'nodejs';
        }

        // 4. Detect Java (Maven or Gradle)
        if (File::exists($projectPath . '/pom.xml') ||
            File::exists($projectPath . '/build.gradle') ||
            File::exists($projectPath . '/build.gradle.kts') ||
            File::exists($projectPath . '/gradlew')
        ) {
            return 'java';
        }

        // 5. Detect .NET (ASP.NET Core / C#)
        $rootFiles = File::files($projectPath);
        foreach ($rootFiles as $file) {
            $ext = $file->getExtension();
            if ($ext === 'csproj' || $ext === 'sln' || $ext === 'fsproj') {
                return 'dotnet';
            }
        }
        if (File::exists($projectPath . '/Program.cs') || File::exists($projectPath . '/Startup.cs')) {
            return 'dotnet';
        }


        // 7. Last resort: scan file extensions
        foreach ($rootFiles as $file) {
            $extension = $file->getExtension();
            if ($extension === 'php') {
                return 'php';
            }
            if ($extension === 'py') {
                return 'python';
            }
            if ($extension === 'java') {
                return 'java';
            }
            if ($extension === 'cs') {
                return 'dotnet';
            }
            if ($extension === 'js' || $extension === 'ts' || $extension === 'jsx' || $extension === 'tsx') {
                return 'nodejs';
            }
        }

        return null;
    }
}
