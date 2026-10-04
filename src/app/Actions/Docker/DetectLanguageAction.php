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

        // 3. Detect Ruby (Prioritized over Node.js because Rails projects often have package.json for Vite/JS/Tailwind)
        if (File::exists($projectPath . '/Gemfile') || 
            File::exists($projectPath . '/config.ru') || 
            File::exists($projectPath . '/Rakefile') || 
            File::exists($projectPath . '/.ruby-version')
        ) {
            return 'ruby';
        }

        // 4. Detect Node.js
        if (File::exists($projectPath . '/package.json')) {
            return 'nodejs';
        }

        // 5. Detect Java (Maven or Gradle)
        if (File::exists($projectPath . '/pom.xml') ||
            File::exists($projectPath . '/build.gradle') ||
            File::exists($projectPath . '/build.gradle.kts') ||
            File::exists($projectPath . '/gradlew')
        ) {
            return 'java';
        }

        // 6. Detect .NET (ASP.NET Core / C#)
        $rootFiles = File::files($projectPath);
        foreach ($rootFiles as $file) {
            $ext = $file->getExtension();
            if ($ext === 'csproj' || $ext === 'sln' || $ext === 'fsproj') {
                return 'dotnet';
            }
        }
        // 7. Detect Go
        if (File::exists($projectPath . '/go.mod') || File::exists($projectPath . '/main.go')) {
            return 'go';
        }

        // 8. Inspect Dockerfile base image if present
        $dockerfilePath = File::exists($projectPath . '/Dockerfile') ? $projectPath . '/Dockerfile' : (File::exists($projectPath . '/dockerfile') ? $projectPath . '/dockerfile' : null);
        if ($dockerfilePath) {
            $dockerContent = strtolower(@file_get_contents($dockerfilePath) ?: '');
            if (str_contains($dockerContent, 'ruby') || str_contains($dockerContent, 'rails')) return 'ruby';
            if (str_contains($dockerContent, 'node:') || str_contains($dockerContent, 'node-') || str_contains($dockerContent, 'node ')) return 'nodejs';
            if (str_contains($dockerContent, 'python:') || str_contains($dockerContent, 'python-') || str_contains($dockerContent, 'python ')) return 'python';
            if (str_contains($dockerContent, 'php:') || str_contains($dockerContent, 'php-') || str_contains($dockerContent, 'php ')) return 'php';
            if (str_contains($dockerContent, 'openjdk') || str_contains($dockerContent, 'java:') || str_contains($dockerContent, 'maven') || str_contains($dockerContent, 'gradle')) return 'java';
            if (str_contains($dockerContent, 'dotnet') || str_contains($dockerContent, 'aspnet')) return 'dotnet';
            if (str_contains($dockerContent, 'golang') || str_contains($dockerContent, 'go:')) return 'go';
        }


        // 9. Last resort: scan file extensions in root
        foreach ($rootFiles as $file) {
            $extension = $file->getExtension();
            if ($extension === 'rb') {
                return 'ruby';
            }
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
            if ($extension === 'go') {
                return 'go';
            }
            if ($extension === 'js' || $extension === 'ts' || $extension === 'jsx' || $extension === 'tsx' || $extension === 'html') {
                return 'nodejs';
            }
        }

        // 10. Scan immediate subdirectories for multi-folder frontend projects (e.g., web/index.html, script.js)
        try {
            $allFiles = File::allFiles($projectPath);
            foreach ($allFiles as $file) {
                $path = $file->getRelativePathname();
                if (str_starts_with($path, 'node_modules') || str_starts_with($path, 'vendor') || str_starts_with($path, '.git')) {
                    continue;
                }
                $ext = $file->getExtension();
                if (in_array($ext, ['js', 'ts', 'jsx', 'tsx', 'html'])) {
                    return 'nodejs';
                }
                if ($ext === 'rb') return 'ruby';
                if ($ext === 'php') return 'php';
                if ($ext === 'py') return 'python';
                if ($ext === 'java') return 'java';
                if ($ext === 'cs') return 'dotnet';
                if ($ext === 'go') return 'go';
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }
}
