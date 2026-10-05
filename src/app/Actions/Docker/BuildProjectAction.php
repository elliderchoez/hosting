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
            case 'ruby':
                return $this->buildRuby($project, $projectPath, $uid, $gid);
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

            $subDirs = ['api', 'client', 'frontend', 'backend', 'server', 'web', 'app'];

            // Escanear subproyectos para detectar si el driver ya está declarado en api/server/backend
            foreach ($subDirs as $sd) {
                $subPkg = $path . '/' . $sd . '/package.json';
                if (File::exists($subPkg)) {
                    $subJson = json_decode(File::get($subPkg), true) ?? [];
                    $deps = array_merge(
                        $deps,
                        $subJson['dependencies'] ?? [],
                        $subJson['devDependencies'] ?? []
                    );
                }
            }

            $this->ensureCacheVolumes();
            if ($project->db_driver === 'mysql') {
                if (!isset($deps['mysql']) && !isset($deps['mysql2'])) {
                    $output .= "Detectado proyecto Node.js sin driver de MySQL. Instalando mysql2 automáticamente...\n";
                    $dbCommand = [
                        'docker', 'run', '--rm',
                        '-u', "$uid:$gid",
                        '-v', "$path:/app",
                        '-v', 'uleam_npm_cache:/tmp/npm-cache',
                        '-e', 'npm_config_cache=/tmp/npm-cache',
                        '-w', '/app',
                        'node:18-alpine',
                        'npm', 'install', 'mysql2', '--no-audit', '--no-fund', '--save', '--prefer-offline'
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
                        '-v', 'uleam_npm_cache:/tmp/npm-cache',
                        '-e', 'npm_config_cache=/tmp/npm-cache',
                        '-w', '/app',
                        'node:18-alpine',
                        'npm', 'install', 'pg', '--no-audit', '--no-fund', '--save', '--prefer-offline'
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
                                '-v', 'uleam_npm_cache:/tmp/npm-cache',
                                '-e', 'npm_config_cache=/tmp/npm-cache',
                                '-w', '/app',
                                'node:18-alpine',
                                'npm', 'install'
                            ],
                            $packagesToInstall,
                            ['--no-audit', '--no-fund', '--save', '--prefer-offline']
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

        // 1. Detectar e instalar dependencias en subdirectorios comunes de monorepos (api, client, frontend, backend, etc.) con aceleración por volumen local
        foreach ($subDirs as $subDir) {
            $subPackageJson = $path . '/' . $subDir . '/package.json';
            if (File::exists($subPackageJson)) {
                $subRes = $this->installNodeDependenciesWithSnapshot($project, $path, $subDir, $uid, $gid);
                $output .= $subRes['output'];
                if (!$subRes['success']) {
                    return ['success' => false, 'output' => $output];
                }
            }
        }

        // 2. Install root dependencies con aceleración por volumen local
        if (File::exists($path . '/package.json')) {
            $rootRes = $this->installNodeDependenciesWithSnapshot($project, $path, null, $uid, $gid);
            $output .= $rootRes['output'];
            if (!$rootRes['success']) {
                return ['success' => false, 'output' => $output];
            }
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
                // Si el script de build hace npm install en subdirectorios, asegurar --legacy-peer-deps para Node 18/20
                if (str_contains($packageJson['scripts']['build'], 'npm install') && !str_contains($packageJson['scripts']['build'], '--legacy-peer-deps')) {
                    $packageJson['scripts']['build'] = str_replace('npm install', 'npm install --legacy-peer-deps', $packageJson['scripts']['build']);
                    File::put($packageJsonPath, json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }

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

        // Auto-seed para aplicaciones MERN como Amazona (con data.js y routers/userRouter.js)
        if (File::exists($path . '/backend/server.js') && File::exists($path . '/backend/data.js')) {
            $serverJsPath = $path . '/backend/server.js';
            $serverJs = File::get($serverJsPath);
            if (!str_contains($serverJs, 'Auto-seed initial users and products')) {
                $seedSnippet = <<<'JS'

// Auto-seed initial users and products for Amazona if collections are empty
mongoose.connection.once('open', async () => {
  try {
    const User = (await import('./models/userModel.js')).default;
    const Product = (await import('./models/productModel.js')).default;
    const data = (await import('./data.js')).default;
    const userCount = await User.countDocuments();
    if (userCount === 0) {
      console.log('[Amazona] Auto-seeding initial users and products...');
      const createdUsers = await User.insertMany(data.users);
      const seller = createdUsers.find(u => u.isSeller) || createdUsers[0];
      if (seller) {
        const products = data.products.map(p => ({ ...p, seller: seller._id }));
        await Product.insertMany(products);
        console.log('[Amazona] Database auto-seeded successfully!');
      }
    }
  } catch (err) {
    console.error('[Amazona] Auto-seed notice:', err.message);
  }
});
JS;
                $serverJs .= $seedSnippet;
                File::put($serverJsPath, $serverJs);
                $output .= "\nAuto-sembrado de catálogo y usuarios administradores configurado para Amazona.\n";
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
                    $pgVer = $pkgData['dependencies']['pg'] ?? $pkgData['devDependencies']['pg'] ?? null;
                    if ($pgVer && !str_contains($pgVer, '8.')) {
                        $workDir = ($sd === '.') ? '/app' : "/app/{$sd}";
                        $pgUpgradeCmd = [
                            'docker', 'run', '--rm',
                            '-u', "$uid:$gid",
                            '-v', "$path:/app",
                            '-v', 'uleam_npm_cache:/tmp/npm-cache',
                            '-e', 'npm_config_cache=/tmp/npm-cache',
                            '-w', $workDir,
                            'node:20-alpine',
                            'npm', 'install', 'pg@^8.11.0', '--no-audit', '--no-fund', '--save', '--prefer-offline'
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

        $this->ensureCacheVolumes();
        $command = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
            '-v', "uleam_composer_cache:/tmp/cache",
            '-e', 'COMPOSER_CACHE_DIR=/tmp/cache',
            '-w', '/app',
            'composer:latest',
            'composer', 'install', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--ignore-platform-reqs', '--no-scripts'
        ];

        $output = "Ejecutando composer install desde caché local...\n";
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
                    'DISABLE_FRAME_HEADER' => 'true',
                    'AUTHENTICATION_GUARD' => 'web',
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
            if (!str_contains($envContent, 'APP_KEY=base64:') || str_contains($envContent, 'APP_KEY=SomeRandomString') || preg_match('/^APP_KEY=\s*$/m', $envContent)) {
                $newAppKey = 'base64:' . base64_encode(random_bytes(32));
                if (preg_match('/^APP_KEY=.*/m', $envContent)) {
                    $envContent = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$newAppKey}", $envContent);
                } else {
                    $envContent .= "\nAPP_KEY={$newAppKey}";
                }
                File::put($envPath, $envContent);
                $output .= "\nGenerada llave criptográfica de aplicación (APP_KEY) para Laravel.\n";
            }
        }

        // Si el proyecto PHP tiene package.json, compilar los assets de frontend (Vite/Mix/Webpack/esbuild)
        $packageJsonPath = $path . '/package.json';
        if (File::exists($packageJsonPath)) {
            $packageJson = json_decode(File::get($packageJsonPath), true);
            $scripts = $packageJson['scripts'] ?? [];
            $buildScript = isset($scripts['build']) ? 'build' : (isset($scripts['production']) ? 'production' : (isset($scripts['prod']) ? 'prod' : null));

            // Detectar workspaces con script de build (ej. Firefly III con resources/assets/v3)
            $workspaceBuild = null;
            if (!$buildScript && isset($packageJson['workspaces']) && is_array($packageJson['workspaces'])) {
                foreach ($packageJson['workspaces'] as $ws) {
                    $wsPkg = $path . '/' . $ws . '/package.json';
                    if (File::exists($wsPkg)) {
                        $wsData = json_decode(File::get($wsPkg), true);
                        if (isset($wsData['scripts']['build'])) {
                            $buildScript = 'build';
                            $workspaceBuild = $wsData['name'] ?? $ws;
                            break;
                        }
                    }
                }
            }

            // Detectar si el proyecto usa Vite (en raíz o en workspaces)
            $isViteProject = File::exists($path . '/vite.config.js') 
                || File::exists($path . '/vite.config.ts') 
                || File::exists($path . '/vite.config.mjs')
                || File::exists($path . '/resources/assets/v3/vite.config.js');

            $hasViteManifest = File::exists($path . '/public/build/manifest.json') 
                || File::exists($path . '/public/build/.vite/manifest.json');

            // Verificar si ya existen assets precompilados en public/
            $hasPrecompiledAssets = File::exists($path . '/public/css') 
                || File::exists($path . '/public/js') 
                || $hasViteManifest 
                || File::exists($path . '/public/packages')
                || File::exists($path . '/public/akaunting-js')
                || (File::exists($path . '/public/mix-manifest.json') && !$isViteProject);

            if ($buildScript && !$hasPrecompiledAssets) {
                $output .= "\nDetectado package.json en proyecto PHP sin assets precompilados. Instalando dependencias de frontend...\n";
                $npmRes = $this->installNodeDependenciesWithSnapshot($project, $path, null, $uid, $gid);
                $output .= $npmRes['output'] . "\n";

                $buildCmdStr = $workspaceBuild 
                    ? "export PATH=\$PATH:/app/node_modules/.bin && npm run build --workspace={$workspaceBuild}" 
                    : "export PATH=\$PATH:/app/node_modules/.bin && npm run {$buildScript}";

                $output .= "Compilando assets de frontend ({$buildCmdStr})...\n";
                $npmBuildCmd = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
                    '-v', "$path:/app",
                    '-w', '/app',
                    'node:20',
                    'sh', '-c', $buildCmdStr
                ];
                $npmBuildResult = $this->runCommand($npmBuildCmd);
                $output .= $npmBuildResult['output'] . "\n";
            } elseif (File::exists($path . '/yarn.lock') && !File::exists($path . '/public/packages')) {
                // Proyectos como Grocy que instalan paquetes web en public/packages mediante Yarn
                $output .= "\nInstalando dependencias web de interfaz con Yarn (Grocy / PHP)...\n";
                $yarnCmd = [
                    'docker', 'run', '--rm',
                    '-u', "$uid:$gid",
                    '-v', "$path:/app",
                    '-w', '/app',
                    'node:20',
                    'yarn', '--production', '--ignore-scripts', '--ignore-engines'
                ];
                $yarnResult = $this->runCommand($yarnCmd);
                $output .= $yarnResult['output'] . "\n";
            } elseif ($hasPrecompiledAssets) {
                $output .= "\nAssets precompilados detectados en public/. Omitiendo compilación pesada de Node.js para acelerar el despliegue PHP.\n";
            }
        }

        // Compatibilidad con proyectos PHP basados en plantillas de configuración (Grocy, etc.)
        if (File::exists($path . '/config-dist.php')) {
            File::makeDirectory($path . '/data', 0777, true, true);
            if (!File::exists($path . '/data/config.php')) {
                File::copy($path . '/config-dist.php', $path . '/data/config.php');
                $output .= "Configuración inicial creada: 'data/config.php' a partir de 'config-dist.php'.\n";
            }
            @chmod($path . '/data', 0777);
            @chmod($path . '/data/config.php', 0666);

            // Ajuste de compatibilidad para Grocy master (requiere PHP 8.5 en helpers/PrerequisiteChecker.php antes de su lanzamiento oficial)
            $prereqFile = $path . '/helpers/PrerequisiteChecker.php';
            if (File::exists($prereqFile)) {
                $prereqContent = File::get($prereqFile);
                if (str_contains($prereqContent, "'8.5.0'")) {
                    $prereqContent = str_replace("'8.5.0'", "'8.4.0'", $prereqContent);
                    File::put($prereqFile, $prereqContent);
                    $output .= "Compatibilidad PHP 8.4 aplicada a PrerequisiteChecker de Grocy.\n";
                }
            }

            // Ajustar SameSite en cookies de sesión de Grocy para compatibilidad con iframes
            $authMiddleware = $path . '/middleware/Auth/BaseAuthMiddleware.php';
            if (File::exists($authMiddleware)) {
                $authContent = File::get($authMiddleware);
                if (str_contains($authContent, "'samesite' => 'Lax'")) {
                    $authContent = str_replace("'samesite' => 'Lax'", "'samesite' => 'None', 'secure' => true", $authContent);
                    File::put($authMiddleware, $authContent);
                }
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

        $this->ensureCacheVolumes();
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
            '-v', "uleam_pip_cache:/tmp/pip-cache",
            '-e', 'PIP_CACHE_DIR=/tmp/pip-cache',
            '-w', '/app',
            'python:3.12-alpine',
            'sh', '-c', $installCmd
        ];

        $output = "Creando entorno virtual e instalando requerimientos de Python desde caché local...\n";
        $result = $this->runCommand($command);
        $output .= $result['output'];

        return $result;
    }

    /**
     * Build Ruby on Rails application (bundle install && asset precompilation)
     */
    private function buildRuby(Project $project, string $path, string $uid, string $gid): array
    {
        $hasGemfile = File::exists($path . '/Gemfile');
        if (!$hasGemfile) {
            return [
                'success' => true,
                'output' => "No se encontró Gemfile. Omitiendo instalación de dependencias de Ruby."
            ];
        }

        $this->ensureCacheVolumes();
        $output = "Instalando dependencias de Ruby / Rails (bundle install)...\n";

        $rubyImage = 'uleam_ruby:3.3';
        if (File::exists($path . '/.ruby-version')) {
            $ver = trim(File::get($path . '/.ruby-version'));
            if (str_starts_with($ver, '4')) {
                $rubyImage = 'uleam_ruby:4.0';
            } elseif (str_starts_with($ver, '3.4')) {
                $rubyImage = 'uleam_ruby:3.4';
            } elseif (str_starts_with($ver, '2.')) {
                $rubyImage = 'uleam_ruby:2.7';
            }
        } elseif (File::exists($path . '/Gemfile')) {
            $gemfileContent = File::get($path . '/Gemfile');
            if (preg_match("/ruby\s+['\"]4/", $gemfileContent)) {
                $rubyImage = 'uleam_ruby:4.0';
            } elseif (preg_match("/ruby\s+['\"]3\.4/", $gemfileContent)) {
                $rubyImage = 'uleam_ruby:3.4';
            } elseif (preg_match("/ruby\s+['\"]2\./", $gemfileContent)) {
                $rubyImage = 'uleam_ruby:2.7';
            }
        }

        // Persistir la versión detectada de Ruby en .ruby-version para que los siguientes pasos (migraciones y arranque) usen exactamente esta misma imagen
        if (!File::exists($path . '/.ruby-version')) {
            File::put($path . '/.ruby-version', str_replace('uleam_ruby:', '', $rubyImage));
        }

        // Adaptar de antemano cualquier restricción rígida de versión de Ruby en Gemfile (ej. ruby '2.7.5' vs 2.7.8)
        $gemfile = $path . '/Gemfile';
        if (File::exists($gemfile)) {
            $content = File::get($gemfile);
            if (preg_match('/^[ \t]*ruby[ \t]+.*$/m', $content)) {
                $content = preg_replace('/^[ \t]*ruby[ \t]+.*$/m', '# [ULEAM_ADAPTED_RUBY_VERSION]', $content);
                File::put($gemfile, $content);
            }
        }

        // Sanear controladores de Rails ante posibles errores de sintaxis comunes (ej: protect from forgery sin guiones bajos)
        $appControllersPath = $path . '/app/controllers';
        if (File::isDirectory($appControllersPath)) {
            $controllerFiles = File::allFiles($appControllersPath);
            foreach ($controllerFiles as $file) {
                if ($file->getExtension() === 'rb') {
                    $cText = File::get($file->getRealPath());
                    if (str_contains($cText, 'protect from forgery')) {
                        File::put($file->getRealPath(), str_replace('protect from forgery', 'protect_from_forgery', $cText));
                    }
                }
            }
        }

        // Auto-configurar archivos de configuración de muestra en Rails (database.yml, credentials, etc.)
        if (!File::exists($path . '/config/database.yml')) {
            if (File::exists($path . '/config/database.yml.sample')) {
                @copy($path . '/config/database.yml.sample', $path . '/config/database.yml');
            } elseif (File::exists($path . '/config/database.yml.example')) {
                @copy($path . '/config/database.yml.example', $path . '/config/database.yml');
            } elseif (File::exists($path . '/config/database.example.yml')) {
                @copy($path . '/config/database.example.yml', $path . '/config/database.yml');
            }
        }
        if (!File::exists($path . '/config/credentials.yml.enc')) {
            if (File::exists($path . '/config/credentials.yml.enc.sample')) {
                @copy($path . '/config/credentials.yml.enc.sample', $path . '/config/credentials.yml.enc');
            }
        }
        File::ensureDirectoryExists($path . '/storage', 0777);
        @chmod($path . '/storage', 0777);
        File::ensureDirectoryExists($path . '/tmp/pids', 0777);
        @chmod($path . '/tmp/pids', 0777);

        // 1. Bundle install con caché persistente aislado por versión de Ruby
        $bundleCacheVol = 'uleam_bundle_cache_' . str_replace(['uleam_ruby:', '.'], ['', ''], $rubyImage);
        $bundleCommand = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-v', "$path:/app",
            '-v', "{$bundleCacheVol}:/usr/local/bundle",
            '-e', 'BUNDLE_SILENCE_ROOT_WARNING=1',
            '-e', 'CFLAGS=-Wno-incompatible-pointer-types -Wno-int-conversion -Wno-error=incompatible-pointer-types',
            '-e', 'CXXFLAGS=-Wno-incompatible-pointer-types -Wno-int-conversion -Wno-error=incompatible-pointer-types',
            '-w', '/app',
            $rubyImage,
            'sh', '-c', 'bundle config set --local build.sqlite3 "--with-cflags=\'-Wno-incompatible-pointer-types -Wno-int-conversion\'" && bundle config set --local build.nio4r "--with-cflags=\'-Wno-incompatible-pointer-types\'" && bundle config set --local path vendor/bundle && bundle install --jobs 4 --retry 3'
        ];

        $bundleResult = $this->runCommand($bundleCommand);
        $output .= $bundleResult['output'] . "\n";
        if (!$bundleResult['success']) {
            return [
                'success' => false,
                'output' => $output
            ];
        }

        // Aplicar parches de compatibilidad en gemas y estructura (devise-secure_password, Rails 7.2 monkey patches)
        $this->patchRubyGemsAndProject($path);

        $secretKey = 'uleam_rails_secret_key_base_' . md5($project->id);

        // 2. Si el proyecto tiene package.json para frontend assets (Vite / Webpack / esbuild / Rollup)
        if (File::exists($path . '/package.json')) {
            $pkgJson = @file_get_contents($path . '/package.json') ?: '';
            $isFrontendBundler = str_contains($pkgJson, 'vite') || 
                                 str_contains($pkgJson, 'webpack') || 
                                 str_contains($pkgJson, 'esbuild') || 
                                 str_contains($pkgJson, 'rollup') ||
                                 str_contains($pkgJson, 'tailwindcss');
            if ($isFrontendBundler) {
                $output .= "Detectado package.json con compilador frontend en proyecto Rails. Instalando dependencias y compilando assets...\n";
                $nodeCmd = 'if [ -f pnpm-lock.yaml ]; then pnpm install --no-frozen-lockfile && (pnpm exec vite build || bundle exec bin/vite build || pnpm build || true); elif [ -f yarn.lock ]; then yarn install && (yarn vite build || bundle exec bin/vite build || yarn build || true); else npm install --prefer-offline --no-audit && (npx vite build || bundle exec bin/vite build || npm run build || true); fi';
                $npmCommand = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-u', "$uid:$gid",
                    '-v', "$path:/app",
                    '-v', 'uleam_npm_cache:/tmp/npm-cache',
                    '-v', "{$bundleCacheVol}:/usr/local/bundle",
                    '-e', 'HOME=/tmp',
                    '-e', 'PNPM_HOME=/tmp/.pnpm',
                    '-e', 'NODE_OPTIONS=--max-old-space-size=4096',
                    '-e', 'npm_config_cache=/tmp/npm-cache',
                    '-e', 'BUNDLE_PATH=vendor/bundle',
                    '-e', 'RAILS_ENV=production',
                    '-e', 'RUN_MIGRATIONS=false',
                    '-e', "SECRET_KEY_BASE={$secretKey}",
                    '-e', 'REDIS_URL=redis://uleam-redis-students:6379',
                    '-e', 'REDIS_HOST=uleam-redis-students',
                    '-w', '/app',
                    $rubyImage,
                    'sh', '-c', $nodeCmd
                ];
                $npmResult = $this->runCommand($npmCommand);
                $output .= $npmResult['output'] . "\n";
            }
        }

        // 3. Precompilar assets de Rails si es un proyecto Rails
        if (File::exists($path . '/bin/rails') || File::exists($path . '/config/environment.rb')) {
            $output .= "Precompilando assets de Rails (assets:precompile)...\n";
            File::ensureDirectoryExists($path . '/log', 0777);
            @chmod($path . '/log', 0777);
            File::ensureDirectoryExists($path . '/storage', 0777);
            @chmod($path . '/storage', 0777);
            File::ensureDirectoryExists($path . '/tmp/pids', 0777);
            @chmod($path . '/tmp/pids', 0777);

            $isPostgres = false;
            if (File::exists($path . '/config/database.yml')) {
                $dbYaml = File::get($path . '/config/database.yml');
                if (str_contains($dbYaml, 'postgresql') || str_contains($dbYaml, 'postgres')) {
                    $isPostgres = true;
                }
            }
            $assetCommand = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-u', "$uid:$gid",
                '-v', "$path:/app",
                '-v', "{$bundleCacheVol}:/usr/local/bundle",
                '-e', 'HOME=/tmp',
                '-e', 'NODE_OPTIONS=--max-old-space-size=4096',
                '-e', 'BUNDLE_PATH=vendor/bundle',
                '-e', 'RAILS_ENV=production',
                '-e', 'NODE_ENV=production',
                '-e', 'RUN_MIGRATIONS=false',
                '-e', 'PIDFILE=tmp/pids/server.pid',
                '-e', 'PUMA_WORKERS=0',
                '-e', "SECRET_KEY_BASE={$secretKey}",
                '-e', 'REDIS_URL=redis://uleam-redis-students:6379',
                '-e', 'REDIS_HOST=uleam-redis-students',
            ];
            if ($isPostgres) {
                $assetCommand[] = '-e';
                $assetCommand[] = 'DATABASE_URL=postgres://dummy:dummy@uleam-postgres-students:5432/dummy';
            }
            $assetCommand[] = '-w';
            $assetCommand[] = '/app';
            $assetCommand[] = $rubyImage;
            $assetCommand[] = 'sh';
            $assetCommand[] = '-c';
            $assetCommand[] = 'bundle exec rails assets:precompile RAILS_ENV=production NODE_ENV=production || true';
            $assetResult = $this->runCommand($assetCommand);
            $output .= $assetResult['output'] . "\n";

            // Crear symlinks en public/ para assets estáticos si la app los enlaza sin hash
            $assetsDir = $path . '/public/assets';
            if (File::isDirectory($assetsDir)) {
                $cssFiles = glob($assetsDir . '/application-*.css');
                if (!empty($cssFiles) && !File::exists($path . '/public/application.css')) {
                    @symlink('assets/' . basename($cssFiles[0]), $path . '/public/application.css');
                }
                $systemCss = glob($assetsDir . '/system-system-*.css');
                if (!empty($systemCss) && !File::exists($path . '/public/system-system.css')) {
                    @symlink('assets/' . basename($systemCss[0]), $path . '/public/system-system.css');
                }
                $jsFiles = glob($assetsDir . '/application-*.js');
                if (!empty($jsFiles) && !File::exists($path . '/public/application.js')) {
                    @symlink('assets/' . basename($jsFiles[0]), $path . '/public/application.js');
                }
            }
        }

        return [
            'success' => true,
            'output' => $output
        ];
    }

    /**
     * Aplica parches automáticos de compatibilidad para gemas y proyectos Ruby / Rails (ej. Chatwoot, Rails 7.2)
     */
    private function patchRubyGemsAndProject(string $path): void
    {
        // 1. Compatibilidad para gemas con naming quirk (ej. devise-secure_password)
        $gemDirs = glob($path . '/vendor/bundle/ruby/*/gems/devise-secure_password-*');
        foreach ($gemDirs as $gDir) {
            $origFile = $gDir . '/lib/devise/secure_password.rb';
            $targetFile = $gDir . '/lib/devise-secure_password.rb';
            if (File::exists($origFile) && !File::exists($targetFile)) {
                @copy($origFile, $targetFile);
            }
        }

        // 2. Monkey patch de SchemaDumper para Rails 7.2
        $schemaDumper = $path . '/config/initializers/monkey_patches/schema_dumper.rb';
        if (File::exists($schemaDumper)) {
            $content = File::get($schemaDumper);
            if (str_contains($content, '< ConnectionAdapters::SchemaDumper')) {
                $content = str_replace('< ConnectionAdapters::SchemaDumper', '', $content);
                File::put($schemaDumper, $content);
            }
        }

        // 3. ActsAsTaggableOn cache migration patch
        $taggableMigrations = glob($path . '/db/migrate/*_add_cached_labels_list.rb');
        foreach ($taggableMigrations as $mFile) {
            if (File::exists($mFile)) {
                $mContent = File::get($mFile);
                if (str_contains($mContent, 'ActsAsTaggableOn::Taggable::Cache.included(Conversation)') && !str_contains($mContent, 'rescue nil')) {
                    $mContent = str_replace(
                        'ActsAsTaggableOn::Taggable::Cache.included(Conversation)',
                        'ActsAsTaggableOn::Taggable::Cache.included(Conversation) rescue nil',
                        $mContent
                    );
                    File::put($mFile, $mContent);
                }
            }
        }

        // 4. Vite config ESM compatibility (.mts) para proyectos Rails con vite_ruby
        $viteTs = $path . '/vite.config.ts';
        $viteMts = $path . '/vite.config.mts';
        if (File::exists($viteTs) && !File::exists($viteMts)) {
            @copy($viteTs, $viteMts);
        }

        // 5. Si el proyecto Rails no tiene definida una ruta raíz (ej. APIs headless como Spree),
        // proveer una ruta root por defecto para evitar pantalla blanca/404 al evaluador
        $routesFile = $path . '/config/routes.rb';
        if (File::exists($routesFile)) {
            $routesContent = File::get($routesFile);
            if (!preg_match('/^\s*root\s+to:/m', $routesContent) && !preg_match('/^\s*root\s+[\'":]/m', $routesContent)) {
                $rootInjection = "\n  # Fallback root landing page para APIs headless (Nexus Academic)\n" .
                    "  root to: proc { [200, { 'Content-Type' => 'text/html; charset=utf-8' }, ['<!DOCTYPE html><html lang=\"es\"><head><meta charset=\"UTF-8\"><title>Backend API Activo</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box;}div{text-align:center;background:#1e293b;padding:2.5rem;border-radius:1rem;border:1px solid #334155;max-width:550px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);}h1{color:#38bdf8;margin-top:0;font-size:1.8rem;}p{color:#94a3b8;line-height:1.6;}a{color:#818cf8;text-decoration:none;font-weight:600;}a:hover{text-decoration:underline;}.badge{display:inline-block;background:#065f46;color:#34d399;padding:4px 12px;border-radius:9999px;font-size:0.85rem;font-weight:bold;margin-bottom:1rem;}</style></head><body><div><span class=\"badge\">● Backend API Activo</span><h1>🚀 Servicio en Ejecución</h1><p>El backend está ejecutándose correctamente en Nexus Academic.</p><p>Este proyecto está configurado como un servicio API headless.</p><p style=\"margin-top:1.5rem;\"><a href=\"/up\">🩺 Ver Healthcheck (/up)</a></p></div></body></html>']] }\nend";
                $routesContent = preg_replace('/end\s*$/', $rootInjection, trim($routesContent));
                File::put($routesFile, $routesContent);
            }
        }

        // 6. Optimización de production.rb y application.rb para entornos educativos / hosting local
        $prodConfig = $path . '/config/environments/production.rb';
        if (File::exists($prodConfig)) {
            $content = File::get($prodConfig);
            // Desactivar force_ssl estricto para evitar loops 301 en HTTP si el proyecto no corre bajo SSL directo
            if (str_contains($content, 'config.force_ssl = true')) {
                $content = str_replace('config.force_ssl = true', 'config.force_ssl = false', $content);
            }
            if (str_contains($content, 'config.assume_ssl = true')) {
                $content = str_replace('config.assume_ssl = true', 'config.assume_ssl = (ENV["RAILS_ASSUME_SSL"] != "false" && ENV["DISABLE_SSL"] != "true")', $content);
            }
            // Fallback de Active Storage a :local si está configurado en :amazon sin credenciales AWS
            if (str_contains($content, 'config.active_storage.service = :amazon')) {
                $content = str_replace('config.active_storage.service = :amazon', 'config.active_storage.service = ENV[\'AWS_BUCKET\'].present? ? :amazon : :local', $content);
            }
            File::put($prodConfig, $content);
        }

        $appConfig = $path . '/config/application.rb';
        if (File::exists($appConfig)) {
            $appContent = File::get($appConfig);
            if (preg_match('/def\s+ssl\?\s+true\s+end/s', $appContent)) {
                $appContent = preg_replace('/def\s+ssl\?\s+true\s+end/s', "def ssl?\n    return false if ENV[\"DISABLE_SSL\"] == \"true\" || ENV[\"RAILS_FORCE_SSL\"] == \"false\"\n    true\n  end", $appContent);
                File::put($appConfig, $appContent);
            }
        }

        // 7. Autocompletar dependencias frontend faltantes en Webpacker (ej. jquery, bootstrap en Rails 6)
        $appPackJs = $path . '/app/javascript/packs/application.js';
        $pkgJsonPath = $path . '/package.json';
        if (File::exists($appPackJs) && File::exists($pkgJsonPath)) {
            $packContent = File::get($appPackJs);
            $pkgJson = json_decode(File::get($pkgJsonPath), true) ?: [];
            $deps = array_merge($pkgJson['dependencies'] ?? [], $pkgJson['devDependencies'] ?? []);
            $modified = false;
            if (str_contains($packContent, 'jquery') && !isset($deps['jquery'])) {
                $pkgJson['dependencies']['jquery'] = '^3.5.1';
                $modified = true;
            }
            if (str_contains($packContent, 'bootstrap') && !isset($deps['bootstrap'])) {
                $pkgJson['dependencies']['bootstrap'] = '^3.4.1';
                $modified = true;
            }
            if ($modified) {
                File::put($pkgJsonPath, json_encode($pkgJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }
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
     * Asegura la existencia y permisos 777 de los volúmenes de caché locales de Docker (NVMe nativo).
     */
    private function ensureCacheVolumes(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $volumes = [
            'uleam_npm_cache', 'uleam_composer_cache', 'uleam_pip_cache', 'uleam_m2_cache', 'uleam_gradle_cache', 'uleam_nuget_cache',
            'uleam_bundle_cache_27', 'uleam_bundle_cache_33', 'uleam_bundle_cache_34', 'uleam_bundle_cache_40'
        ];
        foreach ($volumes as $vol) {
            $this->runCommand(['docker', 'volume', 'create', $vol]);
        }

        $this->runCommand([
            'docker', 'run', '--rm',
            '-v', 'uleam_npm_cache:/npm',
            '-v', 'uleam_composer_cache:/composer',
            '-v', 'uleam_pip_cache:/pip',
            '-v', 'uleam_m2_cache:/m2',
            '-v', 'uleam_gradle_cache:/gradle',
            '-v', 'uleam_nuget_cache:/nuget',
            '-v', 'uleam_bundle_cache_27:/bundle27',
            '-v', 'uleam_bundle_cache_33:/bundle33',
            '-v', 'uleam_bundle_cache_34:/bundle34',
            '-v', 'uleam_bundle_cache_40:/bundle40',
            'alpine', 'chmod', '-R', '777', '/npm', '/composer', '/pip', '/m2', '/gradle', '/nuget', '/bundle27', '/bundle33', '/bundle34', '/bundle40'
        ]);

        $ensured = true;
    }

    /**
     * Instala dependencias Node.js con aceleración por caché local de paquetes (tarballs) en volumen Docker nativo y modernización de Lockfiles.
     */
    private function installNodeDependenciesWithSnapshot(Project $project, string $path, ?string $subDir, string $uid, string $gid): array
    {
        $this->ensureCacheVolumes();
        $targetDir = $subDir ? $path . '/' . $subDir : $path;
        $packageJsonPath = $targetDir . '/package.json';
        if (!File::exists($packageJsonPath)) {
            return ['success' => true, 'output' => ''];
        }

        $dirLabel = $subDir ? "'{$subDir}'" : 'raíz';
        $manifestHash = md5_file($packageJsonPath);
        $targetLock = $targetDir . '/package-lock.json';

        // 1. Inyectar Lockfile modernizado si existe en la caché (evita consultas de metadatos en internet para proyectos viejos)
        $this->injectModernLockfile('node', $manifestHash, $targetLock);

        // Pre-parchear pg si es PostgreSQL para soportar SCRAM-SHA-256 en la primera pasada
        if ($project->db_driver === 'pgsql') {
            try {
                $pkgData = json_decode(File::get($packageJsonPath), true);
                $pgModified = false;
                if (isset($pkgData['dependencies']['pg'])) {
                    $v = preg_replace('/[^0-9.]/', '', $pkgData['dependencies']['pg']);
                    if (version_compare($v, '8.0.0', '<')) {
                        $pkgData['dependencies']['pg'] = '^8.11.0';
                        $pgModified = true;
                    }
                }
                if (isset($pkgData['devDependencies']['pg'])) {
                    $v = preg_replace('/[^0-9.]/', '', $pkgData['devDependencies']['pg']);
                    if (version_compare($v, '8.0.0', '<')) {
                        $pkgData['devDependencies']['pg'] = '^8.11.0';
                        $pgModified = true;
                    }
                }
                if ($pgModified) {
                    File::put($packageJsonPath, json_encode($pkgData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Exception $e) {
                // Ignore parse errors
            }
        }

        $output = "";
        $workDir = $subDir ? "/app/{$subDir}" : '/app';

        // 2. Ejecutar npm install con caché en volumen Docker NVMe ultra-rápido y paquetes locales
        $cmd = [
            'docker', 'run', '--rm',
            '-u', "$uid:$gid",
            '-e', 'NODE_OPTIONS=--openssl-legacy-provider',
            '-v', "$path:/app",
            '-v', 'uleam_npm_cache:/tmp/npm-cache',
            '-e', 'npm_config_cache=/tmp/npm-cache',
            '-w', $workDir,
            'node:20',
            'npm', 'install', '--no-audit', '--no-fund', '--prefer-offline'
        ];

        $output .= "Instalando dependencias en {$dirLabel} desde caché local de paquetes...\n";
        $result = $this->runCommand($cmd);
        $output .= $result['output'] . "\n";

        // Fallback automático para proyectos heredados con conflictos estrictos de peer dependencies
        if (!$result['success'] && str_contains($result['output'], 'ERESOLVE')) {
            $fallbackCmd = array_merge($cmd, ['--legacy-peer-deps']);
            $result = $this->runCommand($fallbackCmd);
            $output .= $result['output'] . "\n";
        }

        if (!$result['success']) {
            return ['success' => false, 'output' => $output];
        }

        // 3. Guardar el lockfile modernizado resultante para futuros proyectos
        $this->saveModernLockfile('node', $manifestHash, $targetLock);

        return ['success' => true, 'output' => $output];
    }

    /**
     * Inyecta un lockfile modernizado desde la caché para evitar que npm consulte metadatos por internet.
     */
    private function injectModernLockfile(string $tech, string $hash, string $targetFile): bool
    {
        $cachedLock = storage_path("app/caches/locks/{$tech}/{$hash}.lock");
        if (File::exists($cachedLock)) {
            @copy($cachedLock, $targetFile);
            return true;
        }
        return false;
    }

    /**
     * Guarda el lockfile modernizado resultante para futuros proyectos.
     */
    private function saveModernLockfile(string $tech, string $hash, string $sourceFile): void
    {
        if (!File::exists($sourceFile)) {
            return;
        }
        $cachedLock = storage_path("app/caches/locks/{$tech}/{$hash}.lock");
        if (File::exists($cachedLock)) {
            return;
        }

        try {
            $content = @file_get_contents($sourceFile);
            if ($content && (str_contains($content, '"lockfileVersion": 2') || str_contains($content, '"lockfileVersion": 3') || str_contains($content, '"content-hash"'))) {
                File::ensureDirectoryExists(dirname($cachedLock), 0777, true);
                @file_put_contents($cachedLock, $content);
                @chmod($cachedLock, 0777);
            }
        } catch (\Exception $e) {
            // Ignore lock save errors
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

        $this->ensureCacheVolumes();

        if ($isMaven) {
            $output .= "Detectado proyecto Maven. Ejecutando mvn package...\n";
            $command = [
                'docker', 'run', '--rm',
                '-v', "$path:/app",
                '-v', "uleam_m2_cache:/tmp/.m2/repository",
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
                '-v', "uleam_gradle_cache:/tmp/.gradle",
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

        $this->ensureCacheVolumes();
        $publishCommand = 'dotnet publish -c Release -o /app/publish --nologo -v q';

        $command = [
            'docker', 'run', '--rm',
            '-v', "$path:/app",
            '-v', "uleam_nuget_cache:/tmp/nuget-cache",
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
            $this->ensureCacheVolumes();
            $compCmd = [
                'docker', 'run', '--rm',
                '-u', "$uid:$gid",
                '-v', "$path:/app",
                '-v', "uleam_composer_cache:/tmp/cache",
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

        // 5. Parchear sintaxis experimental PHP 8.5 en Firefly III para ejecución estable en PHP 8.4
        $ff3Controller = $path . '/app/Http/Controllers/Controller.php';
        if (File::exists($ff3Controller)) {
            $cContent = @file_get_contents($ff3Controller);
            if ($cContent && (str_contains($cContent, '// this breaks when running < PHP 8.5') || str_contains($cContent, '|>'))) {
                $cContent = preg_replace('/(\/\/ this breaks when running < PHP 8.5[\s\S]*?)?\$output\s*=\s*\$input[\s\S]*?strtolower\([^;]*;/', '', $cContent);
                @file_put_contents($ff3Controller, $cContent);
            }
        }
        
        $appDir = $path . '/app';
        if (File::isDirectory($appDir)) {
            $phpFiles = $this->getProjectScanFiles($appDir, '*.php');
            foreach ($phpFiles as $file) {
                $filePath = $file->getRealPath();
                $content = @file_get_contents($filePath);
                if ($content && str_contains($content, '#[Override]')) {
                    // En PHP 8.4 #[Override] solo puede aplicarse a métodos, no a propiedades
                    $patched = preg_replace('/#\[Override\]\s*((?:(?:public|protected|private)\s+)+(?:readonly\s+)?(?:[a-zA-Z0-9_|\\\\?]+\s+)?\$)/m', '$1', $content);
                    if ($patched !== null && $patched !== $content) {
                        @file_put_contents($filePath, $patched);
                    }
                }
            }
        }

        // 6. Generar traducciones JSON para Firefly III / frontends i18next si están ausentes
        $langDir = $path . '/resources/lang';
        $i18nDir = $path . '/public/v3/i18n';
        if (File::isDirectory($langDir)) {
            if (!File::isDirectory($i18nDir) && File::exists($path . '/resources/assets/v3')) {
                @File::makeDirectory($i18nDir, 0755, true);
            }
            if (File::isDirectory($i18nDir)) {
                foreach (File::directories($langDir) as $localeDir) {
                    $locale = basename($localeDir);
                    $data = [];
                    foreach (File::files($localeDir) as $phpFile) {
                        if ($phpFile->getExtension() === 'php') {
                            $key = $phpFile->getBasename('.php');
                            try {
                                $res = (include $phpFile->getRealPath());
                                if (is_array($res)) {
                                    $data[$key] = $res;
                                }
                            } catch (\Throwable $e) {}
                        }
                    }
                    if (!empty($data)) {
                        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        @File::put($i18nDir . '/' . $locale . '.json', $json);
                        @File::put($i18nDir . '/' . str_replace('_', '-', $locale) . '.json', $json);
                    }
                }
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

