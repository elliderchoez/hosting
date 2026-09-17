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
        $npmCacheDir = $this->getCacheDir('npm');

        // Auto-instalar el driver de BD si el proyecto no lo tiene en dependencias
        $packageJsonPath = $path . '/package.json';
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            $deps = array_merge(
                $packageJson['dependencies'] ?? [],
                $packageJson['devDependencies'] ?? []
            );

            $subDirs = ['api', 'client', 'frontend', 'backend', 'server', 'web', 'app'];

            if ($project->db_driver === 'mysql') {
                if (!isset($deps['mysql']) && !isset($deps['mysql2'])) {
                    $output .= "Detectado proyecto Node.js sin driver de MySQL. Instalando mysql2 automáticamente...\n";
                    $dbCommand = [
                        'docker', 'run', '--rm',
                        '-u', "$uid:$gid",
                        '-v', "$path:/app",
                        '-v', "$npmCacheDir:/tmp/npm-cache",
                        '-e', 'npm_config_cache=/tmp/npm-cache',
                        '-w', '/app',
                        'node:18-alpine',
                        'npm', 'install', 'mysql2', '--no-audit', '--no-fund', '--save', '--prefer-offline', '--legacy-peer-deps'
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
                        '-v', "$npmCacheDir:/tmp/npm-cache",
                        '-e', 'npm_config_cache=/tmp/npm-cache',
                        '-w', '/app',
                        'node:18-alpine',
                        'npm', 'install', 'pg', '--no-audit', '--no-fund', '--save', '--prefer-offline', '--legacy-peer-deps'
                    ];
                    $dbResult = $this->runCommand($dbCommand);
                    $output .= $dbResult['output'] . "\n";
                }
            }

            // Auto-instalar paquetes comunes requeridos en el código pero no declarados en package.json
            // Nota: Se excluyen las subcarpetas de monorepos (api, client, etc.) para no duplicar en la raíz
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
                        ->exclude(array_merge(['node_modules', 'dist', 'vendor', '.git', 'build', '.next', 'cache'], $subDirs));

                    $packagesToInstall = [];
                    foreach ($missingPackages as $pkg) {
                        foreach ($finder as $file) {
                            $content = @file_get_contents($file->getRealPath());
                            if ($content && (
                                preg_match('/require\([\'"]' . preg_quote($pkg, '/') . '[\'"]\)/', $content) ||
                                preg_match('/import\s+.*?\s+from\s+[\'"]' . preg_quote($pkg, '/') . '[\'"]/', $content) ||
                                preg_match('/import\s+[\'"]' . preg_quote($pkg, '/') . '[\'"]/', $content)
                            )) {
                                $packagesToInstall[] = $pkg;
                                break;
                            }
                        }
                    }

                    if (!empty($packagesToInstall)) {
                        $output .= "Detectado uso de paquetes no declarados en raíz: " . implode(', ', $packagesToInstall) . ". Instalando automáticamente...\n";
                        $installPkgCmd = array_merge(
                            [
                                'docker', 'run', '--rm',
                                '-u', "$uid:$gid",
                                '-v', "$path:/app",
                                '-v', "$npmCacheDir:/tmp/npm-cache",
                                '-e', 'npm_config_cache=/tmp/npm-cache',
                                '-w', '/app',
                                'node:18-alpine',
                                'npm', 'install'
                            ],
                            $packagesToInstall,
                            ['--no-audit', '--no-fund', '--save', '--prefer-offline', '--legacy-peer-deps']
                        );
                        $pkgResult = $this->runCommand($installPkgCmd);
                        $output .= $pkgResult['output'] . "\n";
                    }
                } catch (\Exception $e) {
                    // Ignore search errors
                }
            }
        } else {
            $subDirs = ['api', 'client', 'frontend', 'backend', 'server', 'web', 'app'];
        }

        // 1. Detectar e instalar dependencias en subdirectorios comunes de monorepos (api, client, frontend, backend, etc.)
        foreach ($subDirs as $subDir) {
            $subPackageJson = $path . '/' . $subDir . '/package.json';
            if (File::exists($subPackageJson)) {
                $output .= "Detectado subproyecto Node.js en '{$subDir}'. Instalando dependencias...\n";
                $subInstallCmd = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-v', "$path:/app",
                    '-v', "$npmCacheDir:/tmp/npm-cache",
                    '-e', 'npm_config_cache=/tmp/npm-cache',
                    '-w', "/app/{$subDir}",
                    'node:20-alpine',
                    'npm', 'install', '--no-audit', '--no-fund', '--prefer-offline', '--legacy-peer-deps'
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
            '-v', "$npmCacheDir:/tmp/npm-cache",
            '-e', 'npm_config_cache=/tmp/npm-cache',
            '-w', '/app',
            'node:20-alpine',
            'npm', 'install', '--no-audit', '--no-fund', '--prefer-offline', '--legacy-peer-deps'
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

        // 3. Pre-procesamiento universal de SPAs (Create React App, Vite, Vue, Angular, etc.)
        // Detectar y normalizar rutas base de GitHub Pages u otros hostings con subcarpetas
        $detectedSubpaths = [];
        if (!empty($project->subdomain)) {
            $detectedSubpaths[] = trim($project->subdomain, '/');
        }
        if (!empty($project->repository_url)) {
            $repoPath = parse_url($project->repository_url, PHP_URL_PATH);
            if ($repoPath) {
                $repoName = basename(preg_replace('/\.git$/', '', $repoPath));
                if (!empty($repoName)) {
                    $detectedSubpaths[] = trim($repoName, '/');
                }
            }
        }

        // Buscar todos los package.json (raíz, client, frontend, etc.)
        $packageJsonFiles = $this->getProjectScanFiles($path, 'package.json');
        foreach ($packageJsonFiles as $pjFile) {
            $pjPath = $pjFile->getRealPath();
            $pjData = json_decode(@file_get_contents($pjPath), true);
            if (is_array($pjData) && isset($pjData['homepage'])) {
                $rawHomepage = $pjData['homepage'];
                if (is_string($rawHomepage) && $rawHomepage !== '.' && $rawHomepage !== '/') {
                    $parsedPath = parse_url($rawHomepage, PHP_URL_PATH);
                    $slug = trim($parsedPath ?: $rawHomepage, '/');
                    if (!empty($slug)) {
                        $detectedSubpaths[] = $slug;
                    }
                }
                // Forzar siempre homepage relativa '.' para que los assets carguen en cualquier dominio o subdominio
                $pjData['homepage'] = '.';
                @file_put_contents($pjPath, json_encode($pjData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        // Buscar configs de Vite (vite.config.*) y neutralizar base subpath
        $viteConfigs = $this->getProjectScanFiles($path, 'vite.config.*');
        foreach ($viteConfigs as $vFile) {
            $vContent = @file_get_contents($vFile->getRealPath());
            if ($vContent && preg_match('/base\s*:\s*[\'"]\/([^\'"]+)\/[\'"]/', $vContent, $m)) {
                $detectedSubpaths[] = trim($m[1], '/');
                $vContent = preg_replace('/base\s*:\s*[\'"][^\'"]+[\'"]/', "base: '/'", $vContent);
                @file_put_contents($vFile->getRealPath(), $vContent);
            }
        }

        // Buscar configs de Vue (vue.config.js) y neutralizar publicPath subpath
        $vueConfigs = $this->getProjectScanFiles($path, 'vue.config.*');
        foreach ($vueConfigs as $vFile) {
            $vContent = @file_get_contents($vFile->getRealPath());
            if ($vContent && preg_match('/publicPath\s*:\s*[\'"]\/([^\'"]+)\/[\'"]/', $vContent, $m)) {
                $detectedSubpaths[] = trim($m[1], '/');
                $vContent = preg_replace('/publicPath\s*:\s*[\'"][^\'"]+[\'"]/', "publicPath: '/'", $vContent);
                @file_put_contents($vFile->getRealPath(), $vContent);
            }
        }

        $detectedSubpaths = array_values(array_unique(array_filter($detectedSubpaths)));

        // 4. Compilación del proyecto frontend (si define script build)
        $packageJsonPath = $path . '/package.json';
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            if (isset($packageJson['scripts']['build'])) {
                $output .= "\nEjecutando npm run build...\n";
                $pathExports = "export PATH=\$PATH:/app/node_modules/.bin:/app/api/node_modules/.bin:/app/client/node_modules/.bin:/app/frontend/node_modules/.bin:/app/backend/node_modules/.bin:/app/server/node_modules/.bin && export NODE_OPTIONS=--openssl-legacy-provider && export PUBLIC_URL=. && export CI=false";
                $buildCommand = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-e', 'PUBLIC_URL=.',
                    '-e', 'CI=false',
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

                // 5. Post-procesamiento universal de carpetas de distribución (build, dist, out, public)
                $this->sanitizeCompiledOutputDirectories($path, $detectedSubpaths);
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
        $pqrPatterns = [
            $path . '/*/node_modules/typeorm/*/postgres/PostgresQueryRunner.js',
            $path . '/*/node_modules/typeorm/*/*/postgres/PostgresQueryRunner.js',
            $path . '/node_modules/typeorm/*/postgres/PostgresQueryRunner.js',
            $path . '/node_modules/typeorm/*/*/postgres/PostgresQueryRunner.js',
        ];
        $typeormFiles = [];
        foreach ($pqrPatterns as $pattern) {
            $typeormFiles = array_merge($typeormFiles, glob($pattern) ?: []);
        }
        foreach (array_unique($typeormFiles) as $pqrPath) {
            if (File::exists($pqrPath)) {
                $content = @file_get_contents($pqrPath);
                if ($content && str_contains($content, 'consrc')) {
                    $content = str_replace('CASE \\"cnst\\".\\"contype\\" WHEN \'x\' THEN pg_get_constraintdef(\\"cnst\\".\\"oid\\", true) ELSE \\"cnst\\".\\"consrc\\" END', 'pg_get_constraintdef(\\"cnst\\".\\"oid\\", true)', $content);
                    $content = str_replace('CASE "cnst"."contype" WHEN \'x\' THEN pg_get_constraintdef("cnst"."oid", true) ELSE "cnst"."consrc" END', 'pg_get_constraintdef("cnst"."oid", true)', $content);
                    $content = str_replace('"cnst"."consrc"', 'pg_get_constraintdef("cnst"."oid", true)', $content);
                    $content = str_replace('\"cnst\".\"consrc\"', 'pg_get_constraintdef(\"cnst\".\"oid\", true)', $content);
                    @file_put_contents($pqrPath, $content);
                }
            }
        }

        // Parche de compatibilidad para module-alias en Node 20
        $moduleAliasCandidates = [
            $path . '/node_modules/module-alias/register.js',
            $path . '/api/node_modules/module-alias/register.js',
            $path . '/backend/node_modules/module-alias/register.js',
            $path . '/server/node_modules/module-alias/register.js',
        ];
        foreach ($moduleAliasCandidates as $maPath) {
            if (File::exists($maPath)) {
                @file_put_contents($maPath, '// noop module-alias for node 20 compatibility');
            }
        }

        // Parche para proyectos con avatares o enlaces a i.ibb.co (ej: Jira Clone)
        $this->patchJiraCloneAvatars($path);

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
                            '-v', "$npmCacheDir:/tmp/npm-cache",
                            '-e', 'npm_config_cache=/tmp/npm-cache',
                            '-w', $workDir,
                            'node:20-alpine',
                            'npm', 'install', 'pg@^8.11.0', '--no-audit', '--no-fund', '--save', '--prefer-offline', '--legacy-peer-deps'
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

        $composerCacheDir = $this->getCacheDir('composer');

        $command = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
            '-v', "$composerCacheDir:/tmp/cache",
            '-e', 'COMPOSER_CACHE_DIR=/tmp/cache',
            '-w', '/app',
            'composer:latest',
            'composer', 'install', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--ignore-platform-reqs', '--no-scripts'
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

        // Auto-parchear paquetes heredados en vendor para compatibilidad con PHP 8.2+
        $this->patchLegacyPhpPackages($path);

        // Si es Laravel, ejecutar package:discover en el entorno de ejecución objetivo y asegurar APP_KEY
        if (File::exists($path . '/artisan')) {
            $discoverCommand = [
                'docker', 'run', '--rm',
                '-u', "$uid:$gid",
                '-v', "$path:/app",
                '-w', '/app',
                'webdevops/php:8.4',
                'php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', 'artisan', 'package:discover'
            ];
            $discoverResult = $this->runCommand($discoverCommand);
            $output .= $discoverResult['output'] . "\n";

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
            if (!str_contains($envContent, 'APP_KEY=base64:') || str_contains($envContent, 'APP_KEY=SomeRandomString')) {
                if (preg_match('/^APP_KEY=.*/m', $envContent)) {
                    $envContent = preg_replace('/^APP_KEY=.*/m', 'APP_KEY=', $envContent);
                    File::put($envPath, $envContent);
                } elseif (!str_contains($envContent, 'APP_KEY=')) {
                    File::append($envPath, "\nAPP_KEY=\n");
                }
                $output .= "\nDetectado Laravel sin APP_KEY válido. Generando llave de aplicación...\n";
                $keyCommand = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-v', "$path:/app",
                    '-w', '/app',
                    'webdevops/php:8.4',
                    'php', 'artisan', 'key:generate', '--force'
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
                $npmCacheDir = $this->getCacheDir('npm');
                $npmInstallCmd = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-v', "$path:/app",
                    '-v', "$npmCacheDir:/tmp/npm-cache",
                    '-e', 'npm_config_cache=/tmp/npm-cache',
                    '-w', '/app',
                    'node:20-alpine',
                    'npm', 'install', '--no-audit', '--no-fund', '--ignore-scripts', '--prefer-offline', '--legacy-peer-deps'
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
        $hasPyproject = File::exists($path . '/pyproject.toml');
        $hasPipfile = File::exists($path . '/Pipfile');

        if (!$hasRequirements && !$hasPyproject && !$hasPipfile) {
            return [
                'success' => true,
                'output' => "No se encontró requirements.txt ni pyproject.toml. Omitiendo la instalación de dependencias de pip."
            ];
        }

        $pipCacheDir = $this->getCacheDir('pip');

        $installCmd = 'python -m venv .venv';
        if ($hasRequirements) {
            $installCmd .= ' && .venv/bin/pip install --prefer-binary -r requirements.txt';
        } elseif ($hasPyproject) {
            $installCmd .= ' && .venv/bin/pip install --prefer-binary .';
        } elseif ($hasPipfile) {
            $installCmd .= ' && .venv/bin/pip install pipenv && .venv/bin/pipenv install --system';
        }

        // Run python to create venv and install dependencies with persistent pip cache
        $command = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
            '-v', "$pipCacheDir:/tmp/pip-cache",
            '-e', 'PIP_CACHE_DIR=/tmp/pip-cache',
            '-w', '/app',
            'python:3.12-alpine',
            'sh', '-c', $installCmd
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
     * Asegura la existencia y permisos de directorios de caché compartidos entre compilaciones.
     */
    private function getCacheDir(string $subDir): string
    {
        $cachePath = storage_path("app/caches/{$subDir}");
        if (!File::exists($cachePath)) {
            File::makeDirectory($cachePath, 0777, true, true);
        }
        @chmod($cachePath, 0777);
        return $cachePath;
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

        $m2CacheDir = $this->getCacheDir('m2');
        $gradleCacheDir = $this->getCacheDir('gradle');

        if ($isMaven) {
            $output .= "Detectado proyecto Maven. Ejecutando mvn package...\n";
            $command = [
                'docker', 'run', '--rm',
                '-v', "$path:/app",
                '-v', "$m2CacheDir:/tmp/.m2/repository",
                '-w', '/app',
                'maven:3.9-eclipse-temurin-17-alpine',
                'mvn', '-q', 'package', '-DskipTests', '-Dmaven.repo.local=/tmp/.m2/repository'
            ];
        } else {
            $output .= "Detectado proyecto Gradle. Ejecutando gradle build...\n";
            $wrapper = File::exists($path . '/gradlew') ? './gradlew' : 'gradle';
            $command = [
                'docker', 'run', '--rm',
                '-v', "$path:/app",
                '-v', "$gradleCacheDir:/tmp/.gradle",
                '-e', 'GRADLE_USER_HOME=/tmp/.gradle',
                '-w', '/app',
                'gradle:8.5-jdk17-alpine',
                'sh', '-c', "$wrapper build -x test --no-daemon"
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

        $nugetCacheDir = $this->getCacheDir('nuget');
        $publishCommand = 'dotnet publish -c Release -o /app/publish --nologo -v q';

        $command = [
            'docker', 'run', '--rm',
            '-v', "$path:/app",
            '-v', "$nugetCacheDir:/tmp/nuget-cache",
            '-e', 'NUGET_PACKAGES=/tmp/nuget-cache',
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

        $uid = $this->getUid();
        $gid = $this->getGid();

        // 1. Si el proyecto tiene composer.json pero no se instaló vendor, correr composer install para que quede en el build y en el host
        if (File::exists($path . '/composer.json') && !File::exists($path . '/vendor')) {
            $output .= "Instalando dependencias de Composer antes de compilar Dockerfile...\n";
            $composerCacheDir = $this->getCacheDir('composer');
            $compCmd = [
                'docker', 'run', '--rm',
                '-u', "$uid:$gid",
                '-v', "$path:/app",
                '-v', "$composerCacheDir:/tmp/cache",
                '-e', 'COMPOSER_CACHE_DIR=/tmp/cache',
                '-w', '/app',
                'composer:latest',
                'composer', 'install', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--ignore-platform-reqs'
            ];
            $compRes = $this->runCommand($compCmd);
            $output .= $compRes['output'] . "\n";
        }

        // 2. Si es Laravel (artisan), sincronizar .env, APP_KEY y ejecutar migraciones/seeders
        if (File::exists($path . '/artisan')) {
            $envPath = $path . '/.env';
            if (!File::exists($envPath) || strlen(trim(File::get($envPath))) < 50) {
                if (File::exists($path . '/.env.example')) {
                    File::copy($path . '/.env.example', $envPath);
                } elseif (!File::exists($envPath)) {
                    File::put($envPath, "");
                }
            }

            if (!empty($project->db_name)) {
                $dbHost = $project->db_driver === 'mysql' ? 'uleam_mysql_students' : 'uleam_postgres_students';
                $dbPort = $project->db_driver === 'mysql' ? '3306' : '5432';
                $appUrl = "http://{$project->subdomain}.localhost";

                $envVars = [
                    'APP_URL' => $appUrl,
                    'DB_CONNECTION' => $project->db_driver ?: 'pgsql',
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

            // Ejecutar migraciones automáticas y dump de snapshot inicial
            $migCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "$path:/app",
                '-w', '/app',
                'webdevops/php:8.4',
                'sh', '-c', 'php artisan migrate --force && php artisan db:seed --force'
            ];
            $migRes = $this->runCommand($migCmd);
            $output .= $migRes['output'] . "\n";

            $snapshotFile = $path . '/.initial_db_snapshot.sql';
            if (!File::exists($snapshotFile)) {
                if ($project->db_driver === 'mysql') {
                    $snapCmd = ['docker', 'exec', 'uleam_mysql_students', 'mysqldump', '-u', 'root', "-p{$project->db_password}", $project->db_name];
                } else {
                    $snapCmd = ['docker', 'exec', 'uleam_postgres_students', 'pg_dump', '-U', 'postgres', $project->db_name];
                }
                $snapProcess = new Process($snapCmd);
                $snapProcess->run();
                if ($snapProcess->isSuccessful() && strlen($snapProcess->getOutput()) > 50) {
                    File::put($snapshotFile, $snapProcess->getOutput());
                }
            }
        }

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
                ->exclude(['.git', '.venv', 'vendor', 'cache', '.next', 'node_modules']);
            
            foreach ($finder as $file) {
                $result[] = $file;
            }
        } catch (\Exception $e) {
            $result = [];
        }
        return $result;
    }

    /**
     * Sanitiza carpetas compiladas (build, dist, etc.) para que las SPAs carguen sin errores de rutas.
     */
    private function sanitizeCompiledOutputDirectories(string $path, array $detectedSubpaths): void
    {
        $candidateDirs = ['build', 'dist', 'out', 'public', 'client/build', 'frontend/dist', 'frontend/build'];
        $reservedDirNames = ['static', 'assets', 'media', 'css', 'js', 'fonts', 'images', 'img', 'favicon.ico'];

        foreach ($candidateDirs as $candidate) {
            $dirPath = $path . '/' . $candidate;
            if (!File::isDirectory($dirPath)) {
                continue;
            }

            // A. Sanitizar todos los archivos .html dentro del build
            try {
                $htmlFiles = File::allFiles($dirPath);
                foreach ($htmlFiles as $file) {
                    if ($file->getExtension() !== 'html') {
                        continue;
                    }

                    $filePath = $file->getRealPath();
                    $content = @file_get_contents($filePath);
                    if (!$content) {
                        continue;
                    }

                    $originalContent = $content;

                    // 1. Normalizar <base href="...">
                    $content = preg_replace('/<base\s+href=[\'"][^\'"]*[\'"]\s*\/?>/i', '<base href="/">', $content);

                    // 2. Normalizar rutas con subcarpetas conocidas (ej: /calculator/ -> /)
                    foreach ($detectedSubpaths as $subpath) {
                        if (empty($subpath) || in_array($subpath, $reservedDirNames, true)) {
                            continue;
                        }
                        $content = str_replace("/{$subpath}/", '/', $content);
                        $content = str_replace("l.p=\"/{$subpath}/\"", 'l.p="/"', $content);
                        $content = str_replace("__webpack_require__.p=\"/{$subpath}/\"", '__webpack_require__.p="/"', $content);
                    }

                    // 3. Regex universal para limpiar prefijos desconocidos hacia carpetas comunes de assets
                    // Ej: href="/algo/static/..." -> href="/static/..." o src="/algo/assets/..." -> src="/assets/..."
                    $content = preg_replace(
                        '/(href|src)=(["\'])\/(?:(?!(?:static|assets|media|css|js)\/)[^"\'\/]+)\/(static|assets|media|css|js)\//i',
                        '$1=$2/$3/',
                        $content
                    );

                    if ($content !== $originalContent) {
                        @file_put_contents($filePath, $content);
                    }
                }
            } catch (\Exception $e) {
                // Continuar si hay error de permisos o archivos
            }

            // B. Crear symlinks resilientes en el directorio compilado para absorber cualquier ruta residual
            foreach ($detectedSubpaths as $subpath) {
                if (empty($subpath) || in_array($subpath, $reservedDirNames, true)) {
                    continue;
                }

                $symlinkTarget = $dirPath . '/' . $subpath;
                if (!file_exists($symlinkTarget) && !is_link($symlinkTarget)) {
                    @symlink('.', $symlinkTarget);
                }
            }
        }
    }

    /**
     * Parchea automáticamente paquetes de terceros heredados en vendor para compatibilidad con PHP 8.2+.
     */
    private function patchLegacyPhpPackages(string $path): void
    {
        // 1. Parchear Carbon setLastErrors para PHP 8.2+
        $carbonCreator = $path . '/vendor/nesbot/carbon/src/Carbon/Traits/Creator.php';
        if (File::exists($carbonCreator)) {
            $content = @file_get_contents($carbonCreator);
            if ($content) {
                $content = str_replace(
                    'self::setLastErrors(parent::getLastErrors());',
                    'self::setLastErrors(parent::getLastErrors() ?: []);',
                    $content
                );
                $content = str_replace(
                    'private static function setLastErrors(array $lastErrors)',
                    'private static function setLastErrors($lastErrors = [])',
                    $content
                );
                $content = str_replace(
                    'static::$lastErrors = $lastErrors;',
                    'static::$lastErrors = is_array($lastErrors) ? $lastErrors : [];',
                    $content
                );
                @file_put_contents($carbonCreator, $content);
            }
        }

        // 2. Parchear Ignition string interpolation para PHP 8.2+
        $ignitionSolution = $path . '/vendor/facade/ignition/src/SolutionProviders/MergeConflictSolutionProvider.php';
        if (File::exists($ignitionSolution)) {
            $content = @file_get_contents($ignitionSolution);
            if ($content && str_contains($content, '${directory}')) {
                @file_put_contents($ignitionSolution, str_replace('${directory}', '{$directory}', $content));
            }
        }

        // 3. Parchear migraciones heredadas con llamadas inseguras a relaciones vacías
        $migrationFiles = $this->getProjectScanFiles($path . '/database/migrations', '*.php');
        foreach ($migrationFiles as $mFile) {
            $mContent = @file_get_contents($mFile->getRealPath());
            if ($mContent && str_contains($mContent, '$user->companies()->first()->id')) {
                $mContent = str_replace(
                    '$user->companies()->first()->id',
                    'optional($user->companies()->first())->id',
                    $mContent
                );
                @file_put_contents($mFile->getRealPath(), $mContent);
            }
        }

        // 4. Parchear seeders para evitar Duplicate entry en re-ejecuciones
        $usersSeeder = $path . '/database/seeders/UsersTableSeeder.php';
        if (File::exists($usersSeeder)) {
            $sContent = @file_get_contents($usersSeeder);
            if ($sContent && str_contains($sContent, 'User::create([')) {
                $sContent = str_replace('User::create([', 'User::firstOrCreate([\'email\' => \'admin@craterapp.com\'], [', $sContent);
                @file_put_contents($usersSeeder, $sContent);
            }
        }
    }

    /**
     * Parchear URLs de avatares rotos/bloqueados por adblockers o CDN externo (ej: i.ibb.co en Jira Clone).
     * Copia imágenes locales confiables a las carpetas públicas/build y reemplaza las URLs externas.
     */
    private function patchJiraCloneAvatars(string $path): void
    {
        $defaultAvatarsDir = storage_path('app/default_assets/jira_avatars');
        if (!File::exists($defaultAvatarsDir)) {
            return;
        }

        $replacements = [
            'https://i.ibb.co/7JM1P2r/picke-rick.jpg' => '/avatars/pickle-rick.jpg',
            'https://i.ibb.co/6n0hLML/baby-yoda.jpg' => '/avatars/baby-yoda.jpg',
            'https://i.ibb.co/6RJ5hq6/gaben.jpg' => '/avatars/gaben.jpg',
        ];

        // Copiar avatares a carpetas públicas si existen
        $targetDirs = [
            $path . '/client/build/avatars',
            $path . '/client/public/avatars',
            $path . '/build/avatars',
            $path . '/public/avatars',
            $path . '/dist/avatars',
        ];

        foreach ($targetDirs as $targetDir) {
            $parent = dirname($targetDir);
            if (File::exists($parent)) {
                File::ensureDirectoryExists($targetDir);
                File::copyDirectory($defaultAvatarsDir, $targetDir);
            }
        }

        // Reemplazar referencias a i.ibb.co en archivos de código (.ts, .js, .sql, .json, .jsx, etc.)
        $codeDirs = [$path . '/api/src', $path . '/api/build', $path . '/client/src', $path . '/src'];
        foreach ($codeDirs as $cDir) {
            if (File::exists($cDir)) {
                $files = File::allFiles($cDir);
                foreach ($files as $file) {
                    $ext = $file->getExtension();
                    if (in_array($ext, ['ts', 'js', 'jsx', 'tsx', 'sql', 'json'])) {
                        $filePath = $file->getRealPath();
                        $content = @file_get_contents($filePath);
                        if ($content && str_contains($content, 'i.ibb.co')) {
                            $updated = str_replace(array_keys($replacements), array_values($replacements), $content);
                            @file_put_contents($filePath, $updated);
                        }
                    }
                }
            }
        }
    }
}

