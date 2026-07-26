<?php

namespace App\Actions\Docker;

use Illuminate\Support\Facades\File;

class DetectLanguageAction
{
    /**
     * Detect the language of a project by analyzing files in its folder.
     *
     * @param string $projectPath
     * @return string|null 'nodejs', 'php', 'python', or null if undetected
     */
    public function execute(string $projectPath): ?string
    {
        if (!File::isDirectory($projectPath)) {
            return null;
        }

        // 1. Detect Node.js
        if (File::exists($projectPath . '/package.json')) {
            return 'nodejs';
        }

        // 2. Detect PHP
        if (File::exists($projectPath . '/composer.json') || 
            File::exists($projectPath . '/index.php') || 
            File::exists($projectPath . '/index.html') || 
            File::exists($projectPath . '/artisan')
        ) {
            return 'php';
        }

        // 3. Detect Python
        if (File::exists($projectPath . '/requirements.txt') || 
            File::exists($projectPath . '/main.py') || 
            File::exists($projectPath . '/manage.py') || 
            File::exists($projectPath . '/Pipfile')
        ) {
            return 'python';
        }

        // Default: scan recursively for typical extensions in root
        $files = File::files($projectPath);
        foreach ($files as $file) {
            $extension = $file->getExtension();
            if ($extension === 'php') {
                return 'php';
            }
            if ($extension === 'py') {
                return 'python';
            }
            if ($extension === 'js' || $extension === 'ts' || $extension === 'jsx' || $extension === 'tsx') {
                return 'nodejs';
            }
        }

        return null;
    }
}
