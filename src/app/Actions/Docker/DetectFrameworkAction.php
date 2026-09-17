<?php

namespace App\Actions\Docker;

use Illuminate\Support\Facades\File;

class DetectFrameworkAction
{
    /**
     * Detect the specific framework or library of a project based on its files and dependencies.
     * Returns null if it's pure/vanilla (e.g. pure PHP, pure Python, pure Node.js).
     *
     * @param string $projectPath
     * @param string|null $language
     * @return string|null e.g. 'laravel', 'react', 'vue', 'nextjs', 'django', 'fastapi', 'flask', 'express', 'nestjs', 'angular', 'svelte', 'springboot', 'aspnet', or null
     */
    public function execute(string $projectPath, ?string $language): ?string
    {
        if (!File::isDirectory($projectPath)) {
            return null;
        }

        $lang = strtolower($language ?? '');

        // 1. PHP frameworks & libraries
        if ($lang === 'php') {
            if (File::exists($projectPath . '/artisan') || $this->composerHas($projectPath, 'laravel/framework')) {
                return 'laravel';
            }
            if ($this->composerHas($projectPath, 'symfony/')) {
                return 'symfony';
            }
            if ($this->composerHas($projectPath, 'codeigniter') || File::isDirectory($projectPath . '/system/core')) {
                return 'codeigniter';
            }
            if ($this->composerHas($projectPath, 'cakephp')) {
                return 'cakephp';
            }
            if ($this->composerHas($projectPath, 'slim/slim')) {
                return 'slim';
            }
            // Proyecto en PHP puro / Vanilla PHP
            return null;
        }

        // 2. Node.js frameworks & UI libraries
        if ($lang === 'nodejs') {
            $hasReact = $this->packageHasDeep($projectPath, 'react');
            $hasVue = $this->packageHasDeep($projectPath, 'vue');
            $hasBackend = $this->packageHasDeep($projectPath, 'express') || 
                          $this->packageHasDeep($projectPath, '@nestjs/core') || 
                          $this->packageHasDeep($projectPath, 'fastify') ||
                          File::isDirectory($projectPath . '/api') ||
                          File::isDirectory($projectPath . '/server');

            // Monorepos / Fullstack Applications (e.g. React Frontend + Node/Express Backend like Jira Clone)
            if ($hasReact && $hasBackend) {
                return 'react-node';
            }
            if ($hasVue && $hasBackend) {
                return 'vue-node';
            }

            if ($this->packageHasDeep($projectPath, 'next')) {
                return 'nextjs';
            }
            if ($this->packageHasDeep($projectPath, 'nuxt')) {
                return 'vue';
            }
            if ($hasReact) {
                return 'react';
            }
            if ($hasVue) {
                return 'vue';
            }
            if ($this->packageHasDeep($projectPath, '@angular/core')) {
                return 'angular';
            }
            if ($this->packageHasDeep($projectPath, 'svelte') || $this->packageHasDeep($projectPath, '@sveltejs/kit')) {
                return 'svelte';
            }
            if ($this->packageHasDeep($projectPath, '@nestjs/core')) {
                return 'nestjs';
            }
            if ($this->packageHasDeep($projectPath, 'express')) {
                return 'express';
            }
            if ($this->packageHasDeep($projectPath, 'fastify')) {
                return 'fastify';
            }
            if ($this->packageHasDeep($projectPath, 'astro')) {
                return 'astro';
            }
            // Proyecto en Node.js puro / Vanilla JS
            return null;
        }

        // 3. Python frameworks
        if ($lang === 'python') {
            if (File::exists($projectPath . '/manage.py') || $this->pythonHas($projectPath, 'django')) {
                return 'django';
            }
            if ($this->pythonHas($projectPath, 'fastapi')) {
                return 'fastapi';
            }
            if ($this->pythonHas($projectPath, 'flask')) {
                return 'flask';
            }
            if ($this->pythonHas($projectPath, 'tornado')) {
                return 'tornado';
            }
            // Proyecto en Python puro
            return null;
        }

        // 4. Java frameworks
        if ($lang === 'java') {
            if ($this->fileContains($projectPath . '/pom.xml', 'spring-boot') ||
                $this->fileContains($projectPath . '/build.gradle', 'spring-boot') ||
                $this->fileContains($projectPath . '/build.gradle.kts', 'spring-boot')
            ) {
                return 'springboot';
            }
            // Proyecto en Java puro
            return null;
        }

        // 5. .NET frameworks
        if ($lang === 'dotnet') {
            return 'aspnet';
        }

        return null;
    }

    private function composerHas(string $projectPath, string $package): bool
    {
        $path = $projectPath . '/composer.json';
        if (!File::exists($path)) {
            return false;
        }
        $content = @file_get_contents($path);
        return $content && str_contains(strtolower($content), strtolower($package));
    }

    private function packageHas(string $projectPath, string $package): bool
    {
        $path = $projectPath . '/package.json';
        if (!File::exists($path)) {
            return false;
        }
        $content = @file_get_contents($path);
        if (!$content) {
            return false;
        }
        $json = json_decode($content, true);
        if (!$json) {
            return str_contains($content, "\"$package\"");
        }
        $deps = array_merge($json['dependencies'] ?? [], $json['devDependencies'] ?? []);
        return isset($deps[$package]) || str_contains($content, "\"$package\"");
    }

    private function packageHasDeep(string $projectPath, string $package): bool
    {
        if ($this->packageHas($projectPath, $package)) {
            return true;
        }
        foreach (['client', 'frontend', 'api', 'server', 'web', 'ui', 'app', 'src'] as $dir) {
            $subPath = $projectPath . '/' . $dir;
            if (File::isDirectory($subPath) && $this->packageHas($subPath, $package)) {
                return true;
            }
        }
        return false;
    }

    private function pythonHas(string $projectPath, string $package): bool
    {
        foreach (['/requirements.txt', '/Pipfile', '/pyproject.toml', '/setup.py'] as $file) {
            $path = $projectPath . $file;
            if (File::exists($path)) {
                $content = strtolower(@file_get_contents($path) ?: '');
                if (str_contains($content, strtolower($package))) {
                    return true;
                }
            }
        }
        return false;
    }

    private function fileContains(string $filePath, string $search): bool
    {
        if (!File::exists($filePath)) {
            return false;
        }
        $content = @file_get_contents($filePath);
        return $content && str_contains($content, $search);
    }
}
