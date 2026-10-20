<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\Project;
use App\Actions\Github\CloneRepositoryAction;
use App\Actions\Docker\DetectLanguageAction;
use App\Actions\Docker\BuildProjectAction;
use App\Actions\Docker\StartProjectContainerAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Exception;

class BuildProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600; // 10 minutes timeout limit for compilation

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    protected Deployment $deployment;

    /**
     * Create a new job instance.
     */
    public function __construct(Deployment $deployment)
    {
        $this->deployment = $deployment;
    }

    /**
     * Execute the job.
     */
    public function handle(
        CloneRepositoryAction $cloneAction,
        DetectLanguageAction $detectLanguageAction,
        BuildProjectAction $buildAction,
        StartProjectContainerAction $startAction
    ): void {
        $deployment = $this->deployment;
        $project = $deployment->project;

        $deployment->status = 'building';
        $deployment->save();

        $project->status = 'building';
        $project->save();

        $startTime = microtime(true);
        $logs = "=== INICIO DE COMPILACIÓN ===\n";
        $logs .= "Fecha: " . now()->toDateTimeString() . "\n";
        $logs .= "Repositorio: {$project->github_repo_url} (Rama: {$project->branch})\n\n";

        // Paths
        $projectPath = storage_path("app/projects/project-{$project->id}");
        $domain = env('APP_DOMAIN', 'nexus-academic.software');

        // Detener cualquier contenedor previo para liberar locks de archivos montados
        $containerName = "project-{$project->id}";
        (new Process(['docker', 'rm', '-f', $containerName]))->run();

        try {
            // Paso 1: Clonar el repositorio de Git o verificar archivos subidos localmente
            if ($project->github_repo_url === 'Subido localmente') {
                $logs .= "--- PASO 1: Subida de Carpeta Local ---\n";
                $logs .= "Proyecto subido de forma local. Omitiendo clonación desde GitHub.\n\n";
            } else {
                $logs .= "--- PASO 1: Clonando repositorio de GitHub ---\n";
                $cloneResult = $cloneAction->execute($project, $projectPath);
                $logs .= $cloneResult['output'] . "\n";

                if (!$cloneResult['success']) {
                    $this->failBuild($project, $deployment, $logs, $startTime);
                    return;
                }
            }

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 1.5: Optimizar y aplanar estructuras de proyecto anidadas
            $logs .= "--- PASO 1.5: Optimizando Estructura del Proyecto ---\n";
            $flattenLog = $this->flattenDirectoryStructure($project, $projectPath);
            $logs .= $flattenLog . "\n\n";

            // Paso 1.6: Validación de Compatibilidad del Proyecto
            $compatibilityError = $this->checkProjectArchitectureCompatibility($projectPath);
            if ($compatibilityError) {
                $logs .= "--- PASO 1.6: Validación de Compatibilidad del Proyecto ---\n";
                $logs .= "[ERROR DE COMPATIBILIDAD]: {$compatibilityError}\n\n";
                $this->failBuild($project, $deployment, $logs, $startTime, $projectPath);
                return;
            }

            // Verificación de Cuota Máxima de Almacenamiento (Máximo 1GB)
            $projectSizeBytes = $this->calculateDirectorySize($projectPath);
            $maxAllowedBytes = 1024 * 1024 * 1024; // 1GB
            if ($projectSizeBytes > $maxAllowedBytes) {
                $sizeMb = round($projectSizeBytes / (1024 * 1024), 2);
                $logs .= "[ERROR] El tamaño del proyecto ({$sizeMb} MB) excede la cuota máxima permitida de disco (1024 MB / 1 GB).\n";
                $this->failBuild($project, $deployment, $logs, $startTime);
                return;
            }

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 2: Detectar Lenguaje/Entorno y Tecnologías
            $logs .= "--- PASO 2: Detectando Lenguaje/Entorno y Tecnologías ---\n";
            $language = $detectLanguageAction->execute($projectPath);
            if (!$language) {
                $logs .= "Advertencia: No se detectaron archivos de lenguajes específicos. Configurando servidor estático por defecto (PHP).\n";
                $language = 'php';
            }
            $displayLanguage = match(strtolower((string) $language)) {
                'nodejs' => 'JavaScript / TypeScript (Entorno: Node.js)',
                'php'    => 'PHP',
                'python' => 'Python',
                'ruby'   => 'Ruby (Entorno: Ruby on Rails)',
                'java'   => 'Java',
                'dotnet' => 'C# (.NET)',
                default  => ucfirst((string) $language),
            };
            $logs .= "Lenguaje detectado: $displayLanguage\n";
            $project->language = $language;

            // Detectar framework o librería específica
            $detectFrameworkAction = app(\App\Actions\Docker\DetectFrameworkAction::class);
            $framework = $detectFrameworkAction->execute($projectPath, $language);
            $project->framework = $framework;
            if ($framework) {
                $logs .= "Framework/Stack detectado: $framework\n\n";
            } else {
                $logs .= "Framework: Ninguno detectado (Lenguaje puro / Vanilla)\n\n";
            }
            $project->save();

            $deployment->build_log = $logs;
            // Auto-configurar archivos de configuración de muestra en Rails (database.yml, credentials, etc.)
            if (!\Illuminate\Support\Facades\File::exists($projectPath . '/config/database.yml')) {
                if (\Illuminate\Support\Facades\File::exists($projectPath . '/config/database.yml.sample')) {
                    @copy($projectPath . '/config/database.yml.sample', $projectPath . '/config/database.yml');
                } elseif (\Illuminate\Support\Facades\File::exists($projectPath . '/config/database.yml.example')) {
                    @copy($projectPath . '/config/database.yml.example', $projectPath . '/config/database.yml');
                } elseif (\Illuminate\Support\Facades\File::exists($projectPath . '/config/database.example.yml')) {
                    @copy($projectPath . '/config/database.example.yml', $projectPath . '/config/database.yml');
                }
            }
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/config/database.yml')) {
                $dbContent = \Illuminate\Support\Facades\File::get($projectPath . '/config/database.yml');
                if (str_contains($dbContent, 'host: localhost') && !str_contains($dbContent, 'ENV[')) {
                    $dbContent = str_replace('host: localhost', 'host: <%= ENV["DB_HOST"] || ENV["POSTGRES_HOST"] || "localhost" %>', $dbContent);
                    $dbContent = str_replace('database: redmine', 'database: <%= ENV["DB_DATABASE"] || ENV["POSTGRES_DATABASE"] || "redmine" %>', $dbContent);
                    $dbContent = str_replace('username: root', 'username: <%= ENV["DB_USERNAME"] || ENV["POSTGRES_USERNAME"] || "root" %>', $dbContent);
                    $dbContent = str_replace('password: ""', 'password: <%= ENV["DB_PASSWORD"] || ENV["POSTGRES_PASSWORD"] || "" %>', $dbContent);
                    $dbContent = str_replace("password: ''", 'password: <%= ENV["DB_PASSWORD"] || ENV["POSTGRES_PASSWORD"] || "" %>', $dbContent);
                    \Illuminate\Support\Facades\File::put($projectPath . '/config/database.yml', $dbContent);
                }
            }
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/config/initializers') && !\Illuminate\Support\Facades\File::exists($projectPath . '/config/initializers/secret_token.rb')) {
                $secretKey = 'uleam_rails_secret_key_base_' . md5($project->id);
                $tokenRb = "RedmineApp::Application.config.secret_key_base = '{$secretKey}' if defined?(RedmineApp)\n" .
                           "Rails.application.config.secret_key_base = '{$secretKey}' if defined?(Rails.application.config.secret_key_base)\n";
                \Illuminate\Support\Facades\File::put($projectPath . '/config/initializers/secret_token.rb', $tokenRb);
            }
            if (!\Illuminate\Support\Facades\File::exists($projectPath . '/config/credentials.yml.enc')) {
                if (\Illuminate\Support\Facades\File::exists($projectPath . '/config/credentials.yml.enc.sample')) {
                    @copy($projectPath . '/config/credentials.yml.enc.sample', $projectPath . '/config/credentials.yml.enc');
                }
            }
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/storage', 0777);
            @chmod($projectPath . '/storage', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/log', 0777);
            @chmod($projectPath . '/log', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/tmp/pids', 0777);
            @chmod($projectPath . '/tmp/pids', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/db', 0777);
            @chmod($projectPath . '/db', 0777);

            // Deshabilitar Rack::Attack para evitar 429 Throttling al navegar y evaluar proyectos en Docker
            $rackAttackFile = $projectPath . '/config/initializers/rack_attack.rb';
            if (\Illuminate\Support\Facades\File::exists($rackAttackFile)) {
                $rackContent = \Illuminate\Support\Facades\File::get($rackAttackFile);
                if (!str_contains($rackContent, 'Rack::Attack.enabled = false')) {
                    \Illuminate\Support\Facades\File::put($rackAttackFile, "Rack::Attack.enabled = false\nRack::Attack.safelist('allow_all') { true }\n" . $rackContent);
                }
            }

            // Aprovisionar base de datos
            $this->provisionDatabase($project, $projectPath, $logs);

            // Sincronizar automáticamente credenciales de base de datos en archivos .env
            $this->syncProjectEnvironmentVariables($project, $projectPath, $logs);

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 3: Compilar / Instalar Dependencias
            $logs .= "--- PASO 3: Instalando y Compilando Dependencias ---\n";
            if (!empty($project->env_vars)) {
                $logs .= "Inyectando variables de entorno personalizadas...\n";
                $envFile = $projectPath . '/.env';
                if (\Illuminate\Support\Facades\File::exists($envFile)) {
                    \Illuminate\Support\Facades\File::append($envFile, "\n\n# Variables personalizadas configuradas en ULEAM Academic\n" . trim($project->env_vars) . "\n");
                } else {
                    \Illuminate\Support\Facades\File::put($envFile, trim($project->env_vars) . "\n");
                }
            }

            // Inyectar configuración de allowedHosts para proyectos Vite
            foreach (['/vite.config.js', '/vite.config.ts'] as $file) {
                $configPath = $projectPath . $file;
                if (\Illuminate\Support\Facades\File::exists($configPath)) {
                    $content = \Illuminate\Support\Facades\File::get($configPath);
                    if (strpos($content, 'allowedHosts') === false) {
                        $logs .= "Optimizando configuración de Vite (allowedHosts) para permitir acceso externo...\n";
                        $content = str_replace(
                            'defineConfig({',
                            "defineConfig({\n  preview: { allowedHosts: true },",
                            $content
                        );
                        \Illuminate\Support\Facades\File::put($configPath, $content);
                    }
                }
            }

            // Auto-parchear config/cors.php de proyectos Laravel para aceptar
            // cualquier origen *.localhost y *.nexus-academic.software automáticamente,
            // eliminando la necesidad de que el estudiante configure FRONTEND_URL.
            $corsConfigPath = $projectPath . '/config/cors.php';
            if (\Illuminate\Support\Facades\File::exists($corsConfigPath)) {
                $corsContent = \Illuminate\Support\Facades\File::get($corsConfigPath);
                $needsPatch = strpos($corsContent, 'localhost') === false
                    || (strpos($corsContent, 'nexus-academic') === false && strpos($corsContent, 'uleam-academic') === false);
                if ($needsPatch) {
                    $logs .= "Detectado config/cors.php en proyecto Laravel. Habilitando CORS automático para dominios de la plataforma...\n";
                    // Insertar patrones al inicio del array 'allowed_origins_patterns'
                    $corsContent = preg_replace(
                        "/('allowed_origins_patterns'\s*=>\s*\[)/",
                        "$1\n        '#^https?://.*\\.localhost\$#',\n        '#^https?://.*\\.nexus-academic\\.software\$#',\n        '#^https?://.*\\.uleam-academic\\.software\$#',",
                        $corsContent
                    );
                    // También agregar wildcard al array 'allowed_origins' si usa env()
                    if (strpos($corsContent, "env('FRONTEND_URL'") !== false) {
                        $corsContent = preg_replace(
                            "/('allowed_origins'\s*=>\s*\[)/",
                            "$1\n        'http://localhost',\n        'http://localhost:5173',",
                            $corsContent
                        );
                    }
                    \Illuminate\Support\Facades\File::put($corsConfigPath, $corsContent);
                    $logs .= "CORS habilitado automáticamente para *.localhost y *.nexus-academic.software.\n";
                }
            }

            // Deshabilitar verificación forzada de correo electrónico en entornos de sandbox para evaluación fluida
            $fortifyConfigPath = $projectPath . '/config/fortify.php';
            if (\Illuminate\Support\Facades\File::exists($fortifyConfigPath)) {
                $fortifyContent = \Illuminate\Support\Facades\File::get($fortifyConfigPath);
                if (str_contains($fortifyContent, 'Features::emailVerification()')) {
                    $fortifyContent = str_replace('Features::emailVerification()', '// Features::emailVerification()', $fortifyContent);
                    \Illuminate\Support\Facades\File::put($fortifyConfigPath, $fortifyContent);
                    $logs .= "Verificación estricta de correo electrónico desactivada automáticamente para permitir pruebas inmediatas.\n";
                }
            }

            $buildResult = $buildAction->execute($project, $projectPath);
            $logs .= $buildResult['output'] . "\n";

            if (!$buildResult['success']) {
                $this->failBuild($project, $deployment, $logs, $startTime);
                return;
            }

            $deployment->build_log = $logs;
            $deployment->save();

            // Importar archivos SQL y correr migraciones si es necesario
            $this->importSqlFiles($project, $projectPath, $logs);
            $this->runFrameworkMigrations($project, $projectPath, $logs);

            // Asegurar symlink de almacenamiento público (storage:link) para proyectos Laravel/PHP
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/storage/app/public') || \Illuminate\Support\Facades\File::exists($projectPath . '/artisan')) {
                \Illuminate\Support\Facades\File::makeDirectory($projectPath . '/public', 0777, true, true);
                \Illuminate\Support\Facades\File::makeDirectory($projectPath . '/storage/app/public', 0777, true, true);
                if (!is_link($projectPath . '/public/storage') && !is_dir($projectPath . '/public/storage')) {
                    @symlink('../storage/app/public', $projectPath . '/public/storage');
                    $logs .= "Enlace simbólico de almacenamiento público (storage:link) creado exitosamente.\n";
                }
            }

            // Asegurar permisos de escritura para proyectos PHP/Laravel y aplicaciones basadas en data/ (Grocy)
            if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/storage')) {
                @chmod($projectPath . '/storage', 0777);
                $chmodCmd = new Process(['chmod', '-R', '777', $projectPath . '/storage']);
                $chmodCmd->run();
            }
            if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/bootstrap/cache')) {
                @chmod($projectPath . '/bootstrap/cache', 0777);
                $chmodCmd2 = new Process(['chmod', '-R', '777', $projectPath . '/bootstrap/cache']);
                $chmodCmd2->run();
            }
            if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/data')) {
                @chmod($projectPath . '/data', 0777);
                $chmodCmd3 = new Process(['chmod', '-R', '777', $projectPath . '/data']);
                $chmodCmd3->run();
            }

            // Soporte predeterminado para Grocy (usuario admin / admin y compatibilidad PHP)
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/app.php') && \Illuminate\Support\Facades\File::exists($projectPath . '/config-dist.php')) {
                $prereqFile = $projectPath . '/helpers/PrerequisiteChecker.php';
                if (\Illuminate\Support\Facades\File::exists($prereqFile)) {
                    $prereqContent = \Illuminate\Support\Facades\File::get($prereqFile);
                    if (str_contains($prereqContent, "'8.5.0'")) {
                        $prereqContent = str_replace("'8.5.0'", "'8.4.0'", $prereqContent);
                        \Illuminate\Support\Facades\File::put($prereqFile, $prereqContent);
                    }
                }
                $authMiddleware = $projectPath . '/middleware/Auth/BaseAuthMiddleware.php';
                if (\Illuminate\Support\Facades\File::exists($authMiddleware)) {
                    $authContent = \Illuminate\Support\Facades\File::get($authMiddleware);
                    if (str_contains($authContent, "'samesite' => 'Lax'")) {
                        $authContent = str_replace("'samesite' => 'Lax'", "'samesite' => 'None', 'secure' => true", $authContent);
                        \Illuminate\Support\Facades\File::put($authMiddleware, $authContent);
                    }
                }
                if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/public/packages')) {
                    @chmod($projectPath . '/public/packages', 0777);
                    $chmodCmdPackages = new Process(['chmod', '-R', '777', $projectPath . '/public/packages']);
                    $chmodCmdPackages->run();
                }
                if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                    $project->demo_instructions = "Acceso predeterminado para pruebas (Grocy):\nUsuario: admin\nClave: admin\n\nEl sistema incluye base de datos SQLite y gestión de inventario y hogar listos para evaluar.";
                    $project->save();
                    $logs .= "Credenciales predeterminadas para Grocy integradas en las instrucciones: admin / admin\n";
                }
            }

            // Soporte predeterminado para Flarum (instalación automatizada, permisos y credenciales de evaluador)
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/site.php') && \Illuminate\Support\Facades\File::exists($projectPath . '/flarum')) {
                if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/public/assets')) {
                    @chmod($projectPath . '/public/assets', 0777);
                    (new Process(['chmod', '-R', '777', $projectPath . '/public/assets']))->run();
                }
                @chmod($projectPath, 0777);

                // Si Flarum aún no ha sido instalado (falta config.php), aprovisionarlo automáticamente
                if (!\Illuminate\Support\Facades\File::exists($projectPath . '/config.php')) {
                    $logs .= "Ejecutando instalación automatizada de Flarum (CLI install)...\n";
                    $dbDriver = !empty($project->db_name) ? ($project->db_driver ?: 'mysql') : 'sqlite';
                    $dbHost = $dbDriver === 'mysql' ? 'uleam_mysql_students' : 'uleam_postgres_students';
                    $dbPort = $dbDriver === 'mysql' ? 3306 : 5432;
                    $dbDatabase = !empty($project->db_name) ? $project->db_name : '/app/storage/flarum.sqlite';
                    $dbUsername = !empty($project->db_name) ? $project->db_user : '';
                    $dbPassword = !empty($project->db_name) ? $project->db_password : '';

                    $installConfig = [
                        'debug' => false,
                        'baseUrl' => "http://{$project->subdomain}.localhost",
                        'databaseConfiguration' => [
                            'driver' => $dbDriver,
                            'host' => $dbHost,
                            'port' => $dbPort,
                            'database' => $dbDatabase,
                            'username' => $dbUsername,
                            'password' => $dbPassword,
                            'prefix' => '',
                        ],
                        'adminUser' => [
                            'username' => 'admin',
                            'password' => 'password',
                            'email' => 'admin@uleam.edu.ec',
                        ],
                        'settings' => [
                            'forum_title' => $project->name ?: 'Flarum Forum',
                        ],
                    ];

                    \Illuminate\Support\Facades\File::put($projectPath . '/install.json', json_encode($installConfig, JSON_PRETTY_PRINT));

                    $uid = trim(shell_exec('id -u') ?? '1000');
                    $gid = trim(shell_exec('id -g') ?? '1000');

                    $installProcess = new Process([
                        'docker', 'run', '--rm',
                        '--network', 'uleam_academic_network',
                        '-u', "{$uid}:{$gid}",
                        '-v', "{$projectPath}:/app",
                        '-w', '/app',
                        'webdevops/php:8.4',
                        'php', 'flarum', 'install', '--file', 'install.json'
                    ]);
                    $installProcess->setTimeout(180);
                    $installProcess->run();
                    @unlink($projectPath . '/install.json');

                    // Garantizar permisos 777 universales vía contenedor Docker como root
                    (new Process(['docker', 'run', '--rm', '-v', "{$projectPath}:/app", 'alpine', 'chmod', '-R', '777', '/app']))->run();

                    $cfgFile = $projectPath . '/config.php';
                    if (\Illuminate\Support\Facades\File::exists($cfgFile)) {
                        $dynamicUrlCode = "'url' => (isset(\$_SERVER['HTTP_HOST']) ? ('http' . ((isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on') || (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 's' : '') . '://' . \$_SERVER['HTTP_HOST']) : 'http://{$project->subdomain}.localhost')";
                        try {
                            $cfgContent = \Illuminate\Support\Facades\File::get($cfgFile);
                            $cfgContent = preg_replace("/'url'\s*=>\s*['\"][^'\"]+['\"]/", $dynamicUrlCode, $cfgContent);
                            \Illuminate\Support\Facades\File::put($cfgFile, $cfgContent);
                        } catch (\Throwable $cfgErr) {
                            (new Process([
                                'docker', 'run', '--rm',
                                '-v', "{$projectPath}:/app",
                                'alpine', 'sh', '-c',
                                "sed -i \"s|'url' => '.*'|{$dynamicUrlCode}|g\" /app/config.php && chmod 777 /app/config.php"
                            ]))->run();
                        }
                    }

                    $logs .= $installProcess->isSuccessful() 
                        ? "Flarum instalado y configurado exitosamente con base de datos.\n"
                        : "Aviso en instalación Flarum: " . $installProcess->getErrorOutput() . "\n";
                }

                $project->demo_instructions = "Acceso predeterminado para pruebas (Flarum Forum):\nUsuario: admin\nClave: password\n\nForo de discusión Flarum 2.x instalado y configurado con base de datos, etiquetas y usuario administrador listo para evaluar.";
                $project->save();
            }

            // Soporte predeterminado para Amazona (MERN E-Commerce)
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/backend/server.js') && \Illuminate\Support\Facades\File::exists($projectPath . '/backend/data.js')) {
                if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'admin@example.com')) {
                    $project->demo_instructions = "Acceso predeterminado para pruebas (Amazona E-Commerce):\nUsuario: admin@example.com\nClave: 1234\n\nTienda completa MERN (MongoDB + Express + React + Node.js) con catálogo de productos, carrito de compras y panel de administración.";
                    $project->save();
                    $logs .= "Credenciales predeterminadas para Amazona integradas en las instrucciones: admin@example.com / 1234\n";
                }
            }

            // Soporte predeterminado para IDURAR ERP CRM (MERN)
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/backend/src/server.js') || \Illuminate\Support\Facades\File::exists($projectPath . '/src/server.js')) {
                if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'admin@admin.com')) {
                    $project->demo_instructions = "Acceso predeterminado para pruebas (IDURAR ERP CRM):\nUsuario: admin@admin.com\nClave: admin123\n\nSistema ERP / CRM completo (Node.js, Express, React, MongoDB) con gestión de clientes, facturas, cotizaciones y pagos.";
                    $project->save();
                    $logs .= "Credenciales predeterminadas para IDURAR integradas en las instrucciones: admin@admin.com / admin123\n";
                }
            }

            // Soporte predeterminado para Firefly III (Finanzas Personales)
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/resources/assets/v3/package.json') || \Illuminate\Support\Facades\File::exists($projectPath . '/config/firefly.php')) {
                if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'admin@example.com')) {
                    $project->demo_instructions = "Acceso predeterminado para pruebas (Firefly III - Finanzas Personales):\nUsuario: admin@example.com\nClave: password\n\nSistema integral de gestión financiera y contabilidad listo para evaluar.";
                    $project->save();
                    $logs .= "Credenciales predeterminadas para Firefly III integradas en las instrucciones: admin@example.com / password\n";
                }
            }

            // Soporte predeterminado para EverShop E-Commerce
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/packages/evershop')) {
                $adminUrl = (config('app.env') === 'production')
                    ? "https://{$project->subdomain}.{$domain}/admin"
                    : "http://{$project->subdomain}.localhost/admin";

                $project->demo_instructions = "Acceso predeterminado para pruebas (EverShop E-Commerce):\n"
                    . "Panel de Administración: {$adminUrl}\n"
                    . "Usuario Administrador: admin@example.com\n"
                    . "Clave: password\n\n"
                    . "Tienda de Clientes (Storefront): El catálogo incluye productos, categorías y blog de muestra. Para iniciar sesión como cliente, puedes registrarte en 'Create an account'.";
                $project->save();
                $logs .= "Credenciales predeterminadas para EverShop integradas: admin@example.com / password en {$adminUrl}\n";
            }

            $deployment->build_log = $logs;
            $deployment->save();

            // Paso 4: Levantar contenedor Docker en Sandbox seguro (gVisor)
            $logs .= "--- PASO 4: Iniciando Contenedor Sandbox de Docker (gVisor) ---\n";
            $startResult = $startAction->execute($project, $projectPath, $domain);
            $logs .= $startResult['output'] . "\n";

            if (!$startResult['success']) {
                $this->failBuild($project, $deployment, $logs, $startTime);
                return;
            }

            // ¡Éxito!
            $endTime = microtime(true);
            $duration = (int)($endTime - $startTime);

            $project->status = 'running';
            $project->container_id = $startResult['container_id'];
            $project->last_visited_at = now();
            $project->save();

            // Soporte predeterminado para EverShop E-Commerce: crear admin y sembrar catálogo demo una vez que las tablas ya existen
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/packages/evershop')) {
                try {
                    $containerId = $startResult['container_id'];
                    $execProcess = new \Symfony\Component\Process\Process([
                        'docker', 'exec', $containerId,
                        'sh', '-c',
                        'node ./packages/evershop/dist/bin/evershop.js user:create --name "Admin" --email "admin@example.com" --password "password" || true; node ./packages/evershop/dist/bin/evershop.js seed --all || true'
                    ]);
                    $execProcess->setTimeout(180);
                    $execProcess->run();
                    $logs .= "Datos de demostración y usuario administrador creados automáticamente para EverShop.\n";
                } catch (\Throwable $th) {
                    // Ignorar si falla el seed secundario
                }
            }

            // Generar snapshot inicial universal para cualquier aplicación (Node, monorepos, TypeORM, Prisma, etc.)
            if (!empty($project->db_name)) {
                $this->generateInitialDatabaseSnapshot($project, $projectPath);
            }

            $deployment->status = 'success';
            $deployment->build_log = $this->sanitizeLogs($logs, $projectPath) . "\n=== COMPILACIÓN EXITOSA ===";
            $deployment->duration_seconds = $duration;
            $deployment->save();

        } catch (Exception $e) {
            if ($e->getCode() === 422) {
                $logs .= "\nError de Validación:\n{$e->getMessage()}\n";
            } else {
                $logs .= "\nExcepción Crítica: " . $e->getMessage() . "\n" . $e->getTraceAsString();
            }
            $this->failBuild($project, $deployment, $logs, $startTime, $projectPath);
        }
    }

    /**
     * Marcar compilación como fallida y actualizar estado.
     */
    private function failBuild(Project $project, Deployment $deployment, string $logs, float $startTime, string $projectPath = ''): void
    {
        $endTime = microtime(true);
        $duration = (int)($endTime - $startTime);

        $project->status = 'failed';
        $project->save();

        $cleanLogs = $projectPath ? $this->sanitizeLogs($logs, $projectPath) : $this->sanitizeLogs($logs);
        $deployment->status = 'failed';
        $deployment->build_log = $cleanLogs . "\n=== COMPILACIÓN FALLIDA ===";
        $deployment->duration_seconds = $duration;
        $deployment->save();
    }

    /**
     * Sanitizar los logs para ocultar rutas internas del servidor host (/home/usuario/...)
     * y mantener una presentación profesional y limpia.
     */
    private function sanitizeLogs(string $logs, string $projectPath = ''): string
    {
        if ($projectPath) {
            $logs = str_replace($projectPath . '/', '', $logs);
            $logs = str_replace($projectPath, '.', $logs);
        }
        $logs = preg_replace('/\/home\/[^\/]+\/[^\/\s\'"]+\/src\/storage\/app\/projects\/[^\/\s\'"]+/', '.', $logs);

        // Eliminar emojis y caracteres gráficos no estándar de todos los logs
        return preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F018}-\x{1F0F5}\x{1F200}-\x{1F270}\x{FE00}-\x{FE0F}\x{1F000}-\x{1F02F}\x{1F0A0}-\x{1F0FF}]/u', '', $logs);
    }

    /**
     * Automatically scan and flatten the project directory structure if the actual code
     * is located inside a nested folder rather than the root.
     */
    private function flattenDirectoryStructure(Project $project, string $projectPath): string
    {
        if (!\Illuminate\Support\Facades\File::isDirectory($projectPath)) {
            return "Optimización de estructura omitida: No es un directorio válido.";
        }

        $bestPath = $projectPath;
        $relativeBestPath = '';

        if (!empty($project->root_dir)) {
            $rootDirClean = trim($project->root_dir, '/\\');
            $targetPath = $projectPath . '/' . $rootDirClean;

            if (\Illuminate\Support\Facades\File::isDirectory($targetPath)) {
                $bestPath = $targetPath;
                $relativeBestPath = $rootDirClean;
                $log = "Subdirectorio de compilación configurado manualmente: '{$relativeBestPath}'\n";
            } else {
                $log = "Advertencia: El subdirectorio configurado '{$rootDirClean}' no existe. Usando escaneo automático...\n";
            }
        }

        if ($bestPath === $projectPath) {
            // Si la raíz ya cuenta con un manifiesto principal de proyecto (puntaje >= 10),
            // no es necesario buscar carpetas anidadas ni aplanar la estructura.
            $rootScore = $this->calculateProjectScore($projectPath);
            if ($rootScore >= 10) {
                return "Estructura del proyecto verificada (Archivos en la raíz).";
            }

            // Monorepos: Si existen múltiples componentes de proyecto (ej: frontend y backend, client y api/server),
            // preservar la estructura completa del monorepo y no aplanar.
            $monorepoDirs = ['backend', 'frontend', 'client', 'api', 'server', 'web', 'app'];
            $matchingComponents = 0;
            foreach ($monorepoDirs as $mDir) {
                if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/' . $mDir) && 
                    $this->calculateProjectScore($projectPath . '/' . $mDir) >= 5) {
                    $matchingComponents++;
                }
            }
            if ($matchingComponents >= 2) {
                return "Estructura de monorepo detectada ({$matchingComponents} componentes: frontend/backend/client/api). Preservando estructura integral.";
            }

            // Find the best project directory recursively (up to depth 3)
            $bestResult = $this->findBestProjectDir($projectPath, $projectPath, 3, 0);
            $bestPath = $bestResult['path'];
            $bestScore = $bestResult['score'];

            if ($bestScore < 5) {
                return "Estructura estándar de proyecto detectada.";
            }

            // If the best path is already the root directory, no action needed
            if (realpath($bestPath) === realpath($projectPath)) {
                return "Estructura del proyecto verificada (Archivos en la raíz).";
            }

            $relativeBestPath = str_replace($projectPath . '/', '', $bestPath);
            $log = "Directorio de proyecto anidado detectado: '{$relativeBestPath}'\n";
        }

        $log .= "Optimizando estructura: Moviendo archivos desde '{$relativeBestPath}' hacia la raíz...\n";

        try {
            $tempPath = storage_path("app/projects/temp_flatten_" . uniqid());
            \Illuminate\Support\Facades\File::makeDirectory($tempPath, 0755, true, true);

            // Move everything from bestPath to tempPath
            $nestedDirs = \Illuminate\Support\Facades\File::directories($bestPath);
            $nestedFiles = \Illuminate\Support\Facades\File::files($bestPath);

            foreach ($nestedDirs as $nestedDir) {
                \Illuminate\Support\Facades\File::moveDirectory($nestedDir, $tempPath . '/' . basename($nestedDir), true);
            }
            foreach ($nestedFiles as $nestedFile) {
                \Illuminate\Support\Facades\File::move($nestedFile, $tempPath . '/' . basename($nestedFile));
            }

            // Clean up the original project root (except .git and *.sql files)
            $allRootDirs = \Illuminate\Support\Facades\File::directories($projectPath);
            $allRootFiles = \Illuminate\Support\Facades\File::files($projectPath);

            foreach ($allRootDirs as $rootDir) {
                if (basename($rootDir) !== '.git') {
                    \Illuminate\Support\Facades\File::deleteDirectory($rootDir);
                }
            }
            foreach ($allRootFiles as $rootFile) {
                $ext = strtolower(pathinfo($rootFile, PATHINFO_EXTENSION));
                if ($ext !== 'sql') {
                    \Illuminate\Support\Facades\File::delete($rootFile);
                }
            }

            // Move everything from tempPath back to projectPath
            $tempDirs = \Illuminate\Support\Facades\File::directories($tempPath);
            $tempFiles = \Illuminate\Support\Facades\File::files($tempPath);

            foreach ($tempDirs as $tempDir) {
                \Illuminate\Support\Facades\File::moveDirectory($tempDir, $projectPath . '/' . basename($tempDir), true);
            }
            foreach ($tempFiles as $tempFile) {
                \Illuminate\Support\Facades\File::move($tempFile, $projectPath . '/' . basename($tempFile));
            }

            // Delete the temp directory
            \Illuminate\Support\Facades\File::deleteDirectory($tempPath);

            $log .= "Archivos reorganizados con éxito en la raíz del proyecto.";
        } catch (\Exception $e) {
            $log .= "Fallo al promover archivos de la carpeta anidada: " . $e->getMessage();
        }

        return $log;
    }

    /**
     * Recursively scan directories to find the folder that contains project configuration/code files.
     */
    private function findBestProjectDir(string $basePath, string $currentPath, int $maxDepth, int $currentDepth): array
    {
        $bestPath = $currentPath;
        $bestScore = $this->calculateProjectScore($currentPath);

        if ($currentDepth >= $maxDepth) {
            return ['path' => $bestPath, 'score' => $bestScore];
        }

        $dirs = \Illuminate\Support\Facades\File::directories($currentPath);
        foreach ($dirs as $dir) {
            $dirName = basename($dir);
            // Skip hidden directories and dependency/output folders
            if (str_starts_with($dirName, '.') || in_array(strtolower($dirName), ['node_modules', 'vendor', 'storage', 'bootstrap', 'tests', 'dist', 'build', '.venv', 'venv', 'env'])) {
                continue;
            }

            $result = $this->findBestProjectDir($basePath, $dir, $maxDepth, $currentDepth + 1);
            if ($result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestPath = $result['path'];
            }
        }

        return ['path' => $bestPath, 'score' => $bestScore];
    }

    /**
     * Score a directory based on the presence of typical programming framework/language files.
     */
    private function calculateProjectScore(string $dir): int
    {
        if (!\Illuminate\Support\Facades\File::isDirectory($dir)) {
            return 0;
        }

        // Strong markers (framework definitions / lock files) -> Score 10
        $strongMarkers = [
            'composer.json', 'artisan',
            'package.json',
            'Gemfile', 'Gemfile.lock', 'config.ru', 'Rakefile',
            'requirements.txt', 'Pipfile', 'manage.py', 'pyproject.toml',
            'pom.xml', 'build.gradle', 'build.gradle.kts', 'gradlew',
            'Program.cs', 'Startup.cs',
            'go.mod', 'Cargo.toml',
            'Dockerfile', 'dockerfile'
        ];
        foreach ($strongMarkers as $marker) {
            if (\Illuminate\Support\Facades\File::exists($dir . '/' . $marker)) {
                return 10;
            }
        }

        // Check for .csproj or .sln files in directory -> Score 10
        $dirFiles = \Illuminate\Support\Facades\File::files($dir);
        foreach ($dirFiles as $file) {
            $ext = strtolower($file->getExtension());
            if ($ext === 'csproj' || $ext === 'sln' || $ext === 'fsproj') {
                return 10;
            }
        }

        // Medium markers (entry points / typical index files) -> Score 5
        $mediumMarkers = [
            'index.php',
            'server.js', 'index.js', 'app.js',
            'main.py', 'app.py'
        ];
        foreach ($mediumMarkers as $marker) {
            if (\Illuminate\Support\Facades\File::exists($dir . '/' . $marker)) {
                return 5;
            }
        }

        // Weak markers (raw source code files) -> Score 1
        foreach ($dirFiles as $file) {
            $name = $file->getFilename();
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['php', 'py', 'java', 'cs', 'rb', 'go', 'rs'])) {
                return 1;
            }
            if (in_array($ext, ['js', 'ts', 'jsx', 'tsx'])) {
                // Skip common JS build files to avoid false positives
                if (!preg_match('/(config|webpack|babel|gulpfile|postcss|tailwind)\.js$/i', $name)) {
                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * Provision a database for the project (MySQL or PostgreSQL).
     */
    private function provisionDatabase(Project $project, string $projectPath, &$logs): void
    {
        $logs .= "--- PASO 2.5: Aprovisionamiento de Base de Datos ---\n";
        
        // 1. Detect dialect (mysql vs pgsql)
        $driver = $this->detectDbDriver($project, $projectPath);
        $project->db_driver = $driver;
        $logs .= "Motor de base de datos detectado/seleccionado: " . strtoupper($driver) . "\n";

        // --- VALIDACIÓN DE BASE DE DATOS REQUERIDA (TESIS) ---
        // 1. Verificar si existen archivos .sql recursivamente (excluyendo vendor y node_modules)
        $sqlFiles = $this->getProjectScanFiles($projectPath, '*.sql');
        $hasSql = !empty($sqlFiles);

        // 2. Verificar si tiene migraciones o auto-sincronización (Laravel, Django, TypeORM, Prisma, Sequelize, Knex, Drizzle, Mongoose, Grocy/SQLite, CodeIgniter, etc.)
        $hasMigrations = false;
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/artisan') || 
            \Illuminate\Support\Facades\File::exists($projectPath . '/manage.py') ||
            \Illuminate\Support\Facades\File::isDirectory($projectPath . '/migrations') ||
            \Illuminate\Support\Facades\File::isDirectory($projectPath . '/database/migrations') ||
            \Illuminate\Support\Facades\File::isDirectory($projectPath . '/application/migrations') ||
            \Illuminate\Support\Facades\File::isDirectory($projectPath . '/app/Database/Migrations') ||
            \Illuminate\Support\Facades\File::isDirectory($projectPath . '/src/Migrations') ||
            \Illuminate\Support\Facades\File::isDirectory($projectPath . '/db/migrate') ||
            \Illuminate\Support\Facades\File::isDirectory($projectPath . '/db/cache_migrate') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/Gemfile') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/config-dist.php') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/config-sample.php') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/config.sample.php') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/wp-config-sample.php')) {
            $hasMigrations = true;
        }

        // Buscar en todos los package.json (raíz y subcarpetas como api, client, backend, server)
        $pkgFiles = $this->getProjectScanFiles($projectPath, 'package.json');
        $requiresDb = false;

        foreach ($pkgFiles as $pkgFile) {
            $packageJson = json_decode(@file_get_contents($pkgFile->getRealPath()), true);
            if ($packageJson) {
                $deps = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
                
                // Si usa drivers de BD
                if (isset($deps['pg']) || isset($deps['mysql']) || isset($deps['mysql2']) || isset($deps['mongoose']) || isset($deps['mongodb']) || isset($deps['sequelize']) || isset($deps['typeorm']) || isset($deps['prisma']) || isset($deps['@prisma/client']) || isset($deps['knex']) || isset($deps['drizzle-orm'])) {
                    $requiresDb = true;
                }

                // Si usa ORMs con auto-sincronización o migraciones
                if (
                    isset($deps['typeorm']) || 
                    isset($deps['prisma']) || 
                    isset($deps['@prisma/client']) || 
                    isset($deps['sequelize']) || 
                    isset($deps['knex']) || 
                    isset($deps['drizzle-orm']) || 
                    isset($deps['mongoose']) || 
                    isset($deps['mongodb']) || 
                    isset($deps['mikro-orm']) || 
                    isset($deps['@mikro-orm/core'])
                ) {
                    $hasMigrations = true;
                }
            }
        }

        // Archivos de configuración de ORMs comunes
        if (
            \Illuminate\Support\Facades\File::exists($projectPath . '/.sequelizerc') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/ormconfig.json') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/ormconfig.js') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/prisma/schema.prisma') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/knexfile.js') ||
            \Illuminate\Support\Facades\File::exists($projectPath . '/drizzle.config.ts')
        ) {
            $hasMigrations = true;
        }

        $requirementsPath = $projectPath . '/requirements.txt';
        if (\Illuminate\Support\Facades\File::exists($requirementsPath)) {
            $reqs = \Illuminate\Support\Facades\File::get($requirementsPath);
            if (stripos($reqs, 'psycopg2') !== false || stripos($reqs, 'mysqlclient') !== false || stripos($reqs, 'sqlalchemy') !== false || stripos($reqs, 'django') !== false || stripos($reqs, 'pymongo') !== false || stripos($reqs, 'mongoengine') !== false || stripos($reqs, 'tortoise') !== false || stripos($reqs, 'peewee') !== false) {
                $requiresDb = true;
            }
        }
        if ($project->language === 'php') {
            $phpFiles = $this->getProjectScanFiles($projectPath, '*.php');
            foreach ($phpFiles as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content && (stripos($content, 'new PDO') !== false || stripos($content, 'mysqli_connect') !== false || stripos($content, 'pg_connect') !== false || stripos($content, 'DB_CONNECTION') !== false)) {
                    $requiresDb = true;
                    break;
                }
            }
        }

        if ($requiresDb && $driver !== 'mongodb' && !$hasSql && !$hasMigrations) {
            $errorMsg = "No se pudo desplegar el proyecto porque falta el archivo de base de datos SQL o archivos de migraciones. Por favor, revisa el proyecto y vuélvelo a subir.";
            throw new \Exception($errorMsg, 422);
        }
        // ----------------------------------------------------
        
        // 2. Generate credentials if not present
        if (empty($project->db_name)) {
            $uuidClean = str_replace('-', '_', $project->id);
            $project->db_name = "db_{$uuidClean}";
            // MySQL limits usernames to 32 characters. We use a shorter prefix and a truncated UUID (26 characters total)
            $project->db_user = "u_" . substr($uuidClean, 0, 24);
            $project->db_password = \Illuminate\Support\Str::random(24);
            $logs .= "Configurando credenciales dedicadas para el proyecto.\n";
        } else {
            $logs .= "Conectando a base de datos existente del proyecto.\n";
        }
        $project->save();

        $dbname = $project->db_name;
        $dbuser = $project->db_user;
        $dbpass = $project->db_password;

        try {
            if ($driver === 'sqlite' || $driver === 'sqlite3') {
                $logs .= "Configurando base de datos local SQLite para el proyecto...\n";
                \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/storage', 0777);
                @chmod($projectPath . '/storage', 0777);
                \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/db', 0777);
                @chmod($projectPath . '/db', 0777);
                $logs .= "Base de datos SQLite local lista para el proyecto.\n";
            } elseif ($driver === 'mongodb') {
                $logs .= "Configurando base de datos aislada en MongoDB...\n";
                
                // Detectar binario disponible (mongo en v4.4/v5 o mongosh en v6+)
                $checkCli = new \Symfony\Component\Process\Process(['docker', 'exec', 'uleam_mongodb_students', 'which', 'mongosh']);
                $checkCli->run();
                $mongoCli = $checkCli->isSuccessful() ? 'mongosh' : 'mongo';

                $command = [
                    'docker', 'exec', 'uleam_mongodb_students', $mongoCli,
                    '-u', 'root', '-p', 'uleam_mongo_pass',
                    '--authenticationDatabase', 'admin',
                    '--eval', "
                        db = db.getSiblingDB('{$dbname}');
                        db.dropDatabase();
                        try { db.dropUser('{$dbuser}'); } catch (e) {}
                        db.createUser({
                            user: '{$dbuser}',
                            pwd: '{$dbpass}',
                            roles: [ { role: 'readWrite', db: '{$dbname}' } ]
                        });
                    "
                ];
                $process = new \Symfony\Component\Process\Process($command);
                $process->setTimeout(30);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    throw new \Exception("Error en {$mongoCli} al aprovisionar MongoDB: " . $process->getErrorOutput());
                }
                
                $logs .= "Base de datos MongoDB lista para el proyecto.\n";
            } elseif ($driver === 'mysql') {
                $logs .= "Configurando base de datos aislada en MySQL...\n";
                // 1. Recrear DB de forma limpia
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("DROP DATABASE IF EXISTS {$dbname};");
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement("CREATE DATABASE {$dbname};");
                
                // 2. Crear usuario si no existe y actualizar contraseña
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "CREATE USER IF NOT EXISTS '{$dbuser}'@'%' IDENTIFIED BY '{$dbpass}';"
                );
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "ALTER USER '{$dbuser}'@'%' IDENTIFIED BY '{$dbpass}';"
                );
                
                // 3. Otorgar privilegios
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "GRANT ALL PRIVILEGES ON {$dbname}.* TO '{$dbuser}'@'%';"
                );
                \Illuminate\Support\Facades\DB::connection('students_mysql')->statement(
                    "FLUSH PRIVILEGES;"
                );
                $logs .= "Base de datos MySQL lista para el proyecto.\n";
            } else {
                // PostgreSQL
                $logs .= "Configurando permisos y acceso en PostgreSQL...\n";
                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("
                    DO \$\$
                    BEGIN
                        IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$dbuser}') THEN
                            CREATE ROLE {$dbuser} WITH LOGIN SUPERUSER PASSWORD '{$dbpass}';
                        ELSE
                            ALTER ROLE {$dbuser} WITH SUPERUSER PASSWORD '{$dbpass}';
                        END IF;
                    END
                    \$\$;
                ");

                try {
                    \Illuminate\Support\Facades\DB::connection('students_postgres')->select("
                        SELECT pg_terminate_backend(pg_stat_activity.pid)
                        FROM pg_stat_activity
                        WHERE pg_stat_activity.datname = '{$dbname}'
                          AND pid <> pg_backend_pid();
                    ");
                } catch (\Throwable $e) {
                    // Ignorar si no había conexiones activas
                }

                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("DROP DATABASE IF EXISTS {$dbname};");
                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("CREATE DATABASE {$dbname} OWNER {$dbuser};");
                \Illuminate\Support\Facades\DB::connection('students_postgres')->statement("GRANT ALL PRIVILEGES ON DATABASE {$dbname} TO {$dbuser};");
                $logs .= "Base de datos PostgreSQL lista para el proyecto.\n";
            }
            
            $logs .= "Aprovisionamiento de base de datos completado exitosamente.\n\n";

        } catch (\Exception $e) {
            $logs .= "Error en aprovisionamiento: " . $e->getMessage() . "\n\n";
            throw new \Exception("Error al aprovisionar la base de datos: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Search and import SQL files into the student's database.
     */
    private function importSqlFiles(Project $project, string $projectPath, &$logs): void
    {
        $logs .= "--- PASO 3.3: Importación de Archivos SQL ---\n";
        
        if ($project->db_driver === 'mongodb') {
            $logs .= "Proyecto NoSQL (MongoDB) detectado. Omitiendo importación de archivos SQL.\n\n";
            return;
        }

        // Proyectos basados en SQLite (Grocy, etc.) gestionan sus propias migraciones internamente
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/config-dist.php') || \Illuminate\Support\Facades\File::exists($projectPath . '/data/grocy.db') || str_contains(strtolower($project->name), 'grocy')) {
            $logs .= "Proyecto con base de datos SQLite detectado (Grocy). Las migraciones internas se gestionan automáticamente por la aplicación. Omitiendo importación en base de datos externa.\n\n";
            return;
        }
        
        if (empty($project->db_name)) {
            $logs .= "No hay base de datos configurada para este proyecto. Omitiendo.\n\n";
            return;
        }

        // Proyectos EverShop gestionan sus migraciones y esquemas de base de datos en su arranque
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/packages/evershop') || \Illuminate\Support\Facades\File::exists($projectPath . '/packages/loot-core')) {
            $logs .= "Proyecto con gestión interna de esquemas y migraciones detectado. Omitiendo importación manual de archivos SQL.\n\n";
            return;
        }

        // Search for .sql files recursively (excluding vendor, node_modules, etc.)
        $sqlFiles = $this->getProjectScanFiles($projectPath, '*.sql');

        if (empty($sqlFiles)) {
            $logs .= "No se encontraron archivos .sql para importar.\n\n";
            return;
        }

        foreach ($sqlFiles as $sqlFile) {
            $relPath = $sqlFile->getRelativePathname();
            $lowerRelPath = strtolower($relPath);
            // Omitir scripts SQL internos de configuración de Docker, CI o testing/unit-tests
            if (
                str_contains($lowerRelPath, 'docker/') ||
                str_contains($lowerRelPath, '.github/') ||
                str_contains($lowerRelPath, 'testing') ||
                str_contains($lowerRelPath, 'test/') ||
                str_contains($lowerRelPath, 'tests/') ||
                str_contains($lowerRelPath, 'spec/') ||
                str_contains($lowerRelPath, 'mock') ||
                str_contains($lowerRelPath, 'migration') ||
                str_contains($lowerRelPath, 'migrations/') ||
                str_contains($lowerRelPath, 'sqlscripts/') ||
                str_contains($lowerRelPath, 'upgrade') ||
                str_contains($lowerRelPath, 'rollback') ||
                str_contains($lowerRelPath, 'drop')
            ) {
                continue;
            }

            $logs .= "Detectado archivo SQL: '{$relPath}'. Importando...\n";
            
            if ($project->db_driver === 'mysql') {
                // Import into MySQL
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'mysql:8.0',
                    'sh', '-c',
                    "mysql -h uleam_mysql_students -u '{$project->db_user}' -p'{$project->db_password}' {$project->db_name} < /workspace/{$sqlFile->getRelativePathname()}"
                ];
            } else {
                // Import into PostgreSQL
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'postgres:15-alpine',
                    'sh', '-c',
                    "PGPASSWORD='{$project->db_password}' psql -h uleam_postgres_students -U '{$project->db_user}' -d '{$project->db_name}' -f /workspace/{$sqlFile->getRelativePathname()}"
                ];
            }

            $process = new Process($command);
            $process->setTimeout(90);
            $process->run();

            if ($process->isSuccessful()) {
                $logs .= "Importación exitosa del archivo '{$sqlFile->getFilename()}'.\n";
            } else {
                $logs .= "Error al importar '{$sqlFile->getFilename()}': " . $process->getErrorOutput() . "\n";
            }
        }
        $logs .= "\n";

        // Generar snapshot inicial tras importar los respaldos SQL legítimos
        $this->generateInitialDatabaseSnapshot($project, $projectPath);
    }

    /**
     * Run framework-specific database migrations (Laravel, Django, Sequelize) automatically.
     */
    private function runFrameworkMigrations(Project $project, string $projectPath, &$logs): void
    {
        $baseDomain = env('APP_DOMAIN', 'nexus-academic.software');
        $dbHost = $project->db_driver === 'mysql' ? 'uleam_mysql_students' : 'uleam_postgres_students';
        $dbPort = $project->db_driver === 'mysql' ? '3306' : '5432';

        // 1. Laravel (PHP)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/artisan')) {
            $logs .= "--- PASO 3.6: Framework Laravel Detectado: Ejecutando Migraciones ---\n";

            // Soporte automatizado para Bagisto E-Commerce
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/packages/Webkul')) {
                $envManager = $projectPath . '/packages/Webkul/Installer/src/Helpers/EnvironmentManager.php';
                if (\Illuminate\Support\Facades\File::exists($envManager)) {
                    $emContent = \Illuminate\Support\Facades\File::get($envManager);
                    $emContent = str_replace(': string|bool', ': string|bool|null', $emContent);
                    \Illuminate\Support\Facades\File::put($envManager, $emContent);
                }

                $templateSnapshot = storage_path('app/templates/bagisto_initial.sql');
                $snapshotFile = $projectPath . '/.initial_db_snapshot.sql';

                if (\Illuminate\Support\Facades\File::exists($templateSnapshot)) {
                    $logs .= "Proyecto Bagisto E-Commerce detectado. Aprovisionando base de datos completa con catálogo de demostración desde plantilla precargada...\n";
                    $importCmd = new Process([
                        'docker', 'exec', '-i', 'uleam_mysql_students',
                        'mysql', '-u', 'root', '-puleam_secure_pass', $project->db_name
                    ]);
                    $importCmd->setInput(\Illuminate\Support\Facades\File::get($templateSnapshot));
                    $importCmd->run();

                    if ($importCmd->isSuccessful()) {
                        $logs .= "Base de datos de comercio electrónico y catálogo de productos importados instantáneamente (3 segundos).\n";
                        @copy($templateSnapshot, $snapshotFile);

                        // Sincronizar las imágenes de catálogo de muestra si existen en plantilla
                        $templateStorage = storage_path('app/templates/bagisto_public_storage');
                        if (\Illuminate\Support\Facades\File::exists($templateStorage)) {
                            \Illuminate\Support\Facades\File::makeDirectory($projectPath . '/storage/app/public', 0777, true, true);
                            $copyProcess = new Process(['cp', '-rn', $templateStorage . '/.', $projectPath . '/storage/app/public/']);
                            $copyProcess->run();
                            $logs .= "Imágenes de muestra de catálogo y categorías sincronizadas exitosamente.\n";
                        }

                        // Permitir incrustación en iframe (Showcase Modal de ULEAM Academic)
                        $bagistoSecureHeaders = $projectPath . '/packages/Webkul/Core/src/Http/Middleware/SecureHeaders.php';
                        if (\Illuminate\Support\Facades\File::exists($bagistoSecureHeaders)) {
                            $shContent = \Illuminate\Support\Facades\File::get($bagistoSecureHeaders);
                            $shContent = str_replace(
                                "\$response->headers->set('X-Frame-Options', 'DENY');",
                                "\$response->headers->remove('X-Frame-Options');\n        \$response->headers->set('Content-Security-Policy', 'frame-ancestors *');",
                                $shContent
                            );
                            \Illuminate\Support\Facades\File::put($bagistoSecureHeaders, $shContent);
                        }

                        // Polyfill de localStorage para prevenir errores de navegador en iframes de diferente origen
                        $bagistoLayout = $projectPath . '/packages/Webkul/Shop/src/Resources/views/components/layouts/index.blade.php';
                        if (\Illuminate\Support\Facades\File::exists($bagistoLayout)) {
                            $layoutContent = \Illuminate\Support\Facades\File::get($bagistoLayout);
                            if (!str_contains($layoutContent, '__uleam_storage_test__')) {
                                $poly = "<script>(function(){try{var t='__uleam_storage_test__';window.localStorage.setItem(t,'1');window.localStorage.removeItem(t);}catch(e){var s={};try{Object.defineProperty(window,'localStorage',{value:{getItem:function(k){return s.hasOwnProperty(k)?s[k]:null;},setItem:function(k,v){s[k]=String(v);},removeItem:function(k){delete s[k];},clear:function(){s={};},key:function(i){return Object.keys(s)[i]||null;},get length(){return Object.keys(s).length;}},configurable:true,enumerable:true,writable:true});}catch(err){}}})();</script>\n";
                                $layoutContent = str_replace('<head>', "<head>\n        " . $poly, $layoutContent);
                                \Illuminate\Support\Facades\File::put($bagistoLayout, $layoutContent);
                            }
                        }

                        // Fallback para imágenes lazy cargadas dentro de un iframe
                        $bagistoLazy = $projectPath . '/packages/Webkul/Shop/src/Resources/views/components/media/images/lazy.blade.php';
                        if (\Illuminate\Support\Facades\File::exists($bagistoLazy)) {
                            $lazyContent = \Illuminate\Support\Facades\File::get($bagistoLazy);
                            if (!str_contains($lazyContent, 'Fallback de seguridad para iframes')) {
                                $lazyPatch = "setTimeout(() => { let l = document.getElementById('image-' + self.$.uid); if (l && (!l.src || l.src === window.location.href) && l.dataset.src) { l.src = l.dataset.src; } }, 300);";
                                $lazyContent = str_replace("lazyImageObserver.observe(document.getElementById('image-shimmer-' + this.$.uid));", "let shim = document.getElementById('image-shimmer-' + this.$.uid); if (shim) lazyImageObserver.observe(shim);\n                // Fallback de seguridad para iframes\n                " . $lazyPatch, $lazyContent);
                                \Illuminate\Support\Facades\File::put($bagistoLazy, $lazyContent);
                            }
                        }
                    } else {
                        $logs .= "Aviso al importar plantilla SQL: " . $importCmd->getErrorOutput() . "\nEjecutando instalador estándar...\n";
                        $command = [
                            'docker', 'run', '--rm',
                            '--network', 'uleam_academic_network',
                            '-v', "{$projectPath}:/app",
                            '-w', '/app',
                            '-e', "DB_CONNECTION={$project->db_driver}",
                            '-e', "DB_HOST={$dbHost}",
                            '-e', "DB_PORT={$dbPort}",
                            '-e', "DB_DATABASE={$project->db_name}",
                            '-e', "DB_USERNAME={$project->db_user}",
                            '-e', "DB_PASSWORD={$project->db_password}",
                            'webdevops/php:8.4',
                            'php', 'artisan', 'bagisto:install', '-n', '--demo-samples'
                        ];
                        $this->executeMigrationCommand($command, $logs);
                    }
                } else {
                    $logs .= "Proyecto Bagisto E-Commerce detectado. Ejecutando instalador y sembrador de tienda...\n";
                    $command = [
                        'docker', 'run', '--rm',
                        '--network', 'uleam_academic_network',
                        '-v', "{$projectPath}:/app",
                        '-w', '/app',
                        '-e', "DB_CONNECTION={$project->db_driver}",
                        '-e', "DB_HOST={$dbHost}",
                        '-e', "DB_PORT={$dbPort}",
                        '-e', "DB_DATABASE={$project->db_name}",
                        '-e', "DB_USERNAME={$project->db_user}",
                        '-e', "DB_PASSWORD={$project->db_password}",
                        'webdevops/php:8.4',
                        'php', 'artisan', 'bagisto:install', '-n', '--demo-samples'
                    ];
                    $this->executeMigrationCommand($command, $logs);

                    // Generar snapshot inicial para futuros despliegues y reseteos
                    if (!\Illuminate\Support\Facades\File::exists($snapshotFile)) {
                        $dumpCmd = new Process([
                            'docker', 'exec', 'uleam_mysql_students',
                            'mysqldump', '--no-tablespaces', '-u', 'root', '-puleam_secure_pass', $project->db_name
                        ]);
                        $dumpCmd->run();
                        if ($dumpCmd->isSuccessful() && strlen($dumpCmd->getOutput()) > 500) {
                            \Illuminate\Support\Facades\File::put($snapshotFile, $dumpCmd->getOutput());
                            \Illuminate\Support\Facades\File::put($templateSnapshot, $dumpCmd->getOutput());
                        }
                    }
                }

                if (empty($project->demo_instructions)) {
                    $project->demo_instructions = "Acceso al Panel de Administración (http://{$project->subdomain}.localhost/admin):\nUsuario: admin@example.com\nClave: admin123\n\nLa tienda de comercio electrónico cuenta con categorías y productos de muestra precargados.";
                    $project->save();
                }

                return;
            }

            // Soporte automatizado para FreeScout Help Desk
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/app/Mailbox.php')) {
                if (empty($project->demo_instructions)) {
                    $project->demo_instructions = "Acceso al Panel de FreeScout Help Desk:\nUsuario: admin@example.com\nClave: password\n\nEl sistema cuenta con un buzón de soporte configurado con tickets de prueba para responder, reasignar o crear nuevas consultas desde el menú superior 'Manage'.";
                    $project->save();
                }
            }

            $command = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                'webdevops/php:8.4',
                'php', 'artisan', 'migrate', '--force'
            ];
            $this->executeMigrationCommand($command, $logs);

            // Si existen seeders de base de datos, ejecutarlos automáticamente para poblar catálogo/datos de prueba
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/database/seeders/DatabaseSeeder.php')) {
                $logs .= "Seeder de base de datos detectado (DatabaseSeeder.php). Poblando catálogo/datos iniciales...\n";
                $seedCommand = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/app",
                    '-w', '/app',
                    '-e', "DB_CONNECTION={$project->db_driver}",
                    '-e', "DB_HOST={$dbHost}",
                    '-e', "DB_PORT={$dbPort}",
                    '-e', "DB_DATABASE={$project->db_name}",
                    '-e', "DB_USERNAME={$project->db_user}",
                    '-e', "DB_PASSWORD={$project->db_password}",
                    'webdevops/php:8.4',
                    'php', 'artisan', 'db:seed', '--force'
                ];
                $this->executeMigrationCommand($seedCommand, $logs);
            }

            // Soporte para Filament Shield (generación de roles y permisos para paneles administrativos)
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/database/seeders/ShieldSeeder.php')) {
                $shieldCmd = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/app",
                    '-w', '/app',
                    '-e', "DB_CONNECTION={$project->db_driver}",
                    '-e', "DB_HOST={$dbHost}",
                    '-e', "DB_PORT={$dbPort}",
                    '-e', "DB_DATABASE={$project->db_name}",
                    '-e', "DB_USERNAME={$project->db_user}",
                    '-e', "DB_PASSWORD={$project->db_password}",
                    'webdevops/php:8.4',
                    'php', 'artisan', 'db:seed', '--class=Database\\Seeders\\ShieldSeeder', '--force'
                ];
                $this->executeMigrationCommand($shieldCmd, $logs);
            }

            // Auto-completar instalación para proyectos con asistentes web (Zero-Click para evaluadores)
            // 1. Archivos bandera estándar de instalación en Laravel / CMS / Web Wizards
            foreach (['database_created', 'installed', '.installed', 'setup_completed', 'install.lock', 'installed.lock', 'installed.txt'] as $flag) {
                @touch($projectPath . '/storage/app/' . $flag);
                @touch($projectPath . '/storage/' . $flag);
                @touch($projectPath . '/' . $flag);
            }

            // 2. Motor de Auto-Inicialización Universal para frameworks y asistentes web
            $postInstallTinker = <<<'PHP'
try {
    // A. Crater Invoicing
    if (class_exists('Crater\Models\Setting')) {
        \Crater\Models\Setting::updateOrCreate(['option' => 'profile_complete'], ['value' => 'COMPLETED']);
        \Crater\Models\Setting::updateOrCreate(['option' => 'version'], ['value' => '6.0.6']);
    }
    if (class_exists('Crater\Models\Company') && class_exists('Crater\Models\User')) {
        $c = \Crater\Models\Company::first();
        if (!$c) {
            $c = \Crater\Models\Company::create(['name' => 'Demo Company', 'slug' => 'demo-company']);
        }
        $u = \Crater\Models\User::first();
        if ($u && $c) {
            $c->owner_id = $u->id;
            if (class_exists('Vinkla\Hashids\Facades\Hashids')) {
                try {
                    $c->unique_hash = \Vinkla\Hashids\Facades\Hashids::connection(\Crater\Models\Company::class)->encode($c->id);
                } catch (\Throwable $e) {}
            }
            $c->save();
            try { $c->setupDefaultData(); } catch (\Throwable $e) {}
            if (!$u->companies()->where('companies.id', $c->id)->exists()) {
                $u->companies()->attach($c->id);
            }
            if (class_exists('Silber\Bouncer\BouncerFacade')) {
                try {
                    \Silber\Bouncer\BouncerFacade::scope()->to($c->id);
                    $u->assign('super admin');
                    \Silber\Bouncer\BouncerFacade::allow('super admin')->everything();
                } catch (\Throwable $e) {}
            }
        }
    }

    // B. Invoice Ninja & Sistemas Multi-Tenant con Cuentas
    if (\Illuminate\Support\Facades\Schema::hasTable('accounts')) {
        $accCount = \Illuminate\Support\Facades\DB::table('accounts')->count();
        if ($accCount === 0) {
            try {
                \Illuminate\Support\Facades\Artisan::call('ninja:create-account', [
                    '--email' => 'admin@example.com',
                    '--password' => 'password',
                ]);
            } catch (\Throwable $e) {}
        }
        // Usar la interfaz Flutter Web precompilada del repositorio oficial
        try {
            \Illuminate\Support\Facades\DB::table('accounts')->update(['set_react_as_default_ap' => 0]);
        } catch (\Throwable $e) {}
    }

    // B2. Firefly III Auto-Setup & Upgrades
    if (class_exists('FireflyIII\User') || class_exists('FireflyIII\Support\Facades\AppConfiguration')) {
        try {
            \Illuminate\Support\Facades\Artisan::call('firefly-iii:laravel-passport-keys');
        } catch (\Throwable $e) {}
        try {
            \Illuminate\Support\Facades\Artisan::call('firefly-iii:upgrade-database', ['--force' => true]);
        } catch (\Throwable $e) {}
        try {
            $cfg = (int) config('firefly.build_time');
            \Illuminate\Support\Facades\DB::table('configuration')->updateOrInsert(['name' => 'ff3_build_time'], ['data' => (string)$cfg, 'updated_at' => now(), 'created_at' => now()]);
            if (class_exists('FireflyIII\Support\Facades\AppConfiguration')) {
                \FireflyIII\Support\Facades\AppConfiguration::set('ff3_build_time', $cfg);
            }
        } catch (\Throwable $e) {}

        try {
            $ffUser = \FireflyIII\User::where('email', 'admin@example.com')->first();
            if (!$ffUser) {
                $ffUser = \FireflyIII\User::create([
                    'email' => 'admin@example.com',
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                    'blocked' => 0
                ]);
            }
            if (class_exists('FireflyIII\Models\UserGroup') && class_exists('FireflyIII\Models\UserRole')) {
                $group = \FireflyIII\Models\UserGroup::firstOrCreate(['title' => $ffUser->email]);
                $role = \FireflyIII\Models\UserRole::where('title', \FireflyIII\Enums\UserRoleEnum::OWNER->value)->first();
                if ($role && class_exists('FireflyIII\Models\GroupMembership')) {
                    \FireflyIII\Models\GroupMembership::firstOrCreate([
                        'user_id' => $ffUser->id,
                        'user_group_id' => $group->id,
                        'user_role_id' => $role->id
                    ]);
                }
                $ffUser->user_group_id = $group->id;
                $ffUser->save();

                // Pre-crear cuenta bancaria de prueba para ingresar directo al dashboard
                try {
                    $currRepo = app(\FireflyIII\Repositories\Currency\CurrencyRepositoryInterface::class);
                    $accRepo = app(\FireflyIII\Repositories\Account\AccountRepositoryInterface::class);
                    $currRepo->setUser($ffUser);
                    $accRepo->setUser($ffUser);
                    $currency = $currRepo->findByCode('USD') ?: $currRepo->first();
                    if ($currency) {
                        $currRepo->enable($currency);
                        $currRepo->makePrimary($currency);
                        $accRepo->store([
                            'name' => 'Cuenta Principal (Demo)',
                            'account_type_name' => 'asset',
                            'account_role' => 'defaultAsset',
                            'active' => true,
                            'virtual_balance' => 0,
                            'opening_balance' => '1500',
                            'opening_balance_date' => now(),
                            'currency_id' => $currency->id
                        ]);
                        $accounts = $accRepo->getAccountsByType([\FireflyIII\Enums\AccountTypeEnum::ASSET->value])->pluck('id')->toArray();
                        \FireflyIII\Support\Facades\Preferences::set('frontpageAccounts', $accounts);
                        \FireflyIII\Support\Facades\Preferences::mark();
                    }
                } catch (\Throwable $e) {}
            }
            if (class_exists('FireflyIII\Models\Role')) {
                $ownerRole = \FireflyIII\Models\Role::where('name', 'owner')->first();
                if ($ownerRole && !$ffUser->roles()->where('roles.id', $ownerRole->id)->exists()) {
                    $ffUser->roles()->attach($ownerRole->id);
                }
            }
            echo "AUTH_USER:admin@example.com\n";
        } catch (\Throwable $e) {}
    }

    // B3. Monica CRM Auto-Setup
    if (class_exists('App\Domains\Settings\CreateAccount\Services\CreateAccount')) {
        try {
            $monicaUser = \Illuminate\Support\Facades\DB::table('users')->where('email', 'admin@example.com')->first();
            if (!$monicaUser) {
                app(\App\Domains\Settings\CreateAccount\Services\CreateAccount::class)->execute([
                    'first_name' => 'Admin',
                    'last_name' => 'Evaluador',
                    'email' => 'admin@example.com',
                    'password' => 'password',
                ]);
            }
            echo "AUTH_USER:admin@example.com\n";
        } catch (\Throwable $e) {}
    }

    // B4. FreeScout Help Desk Auto-Setup
    if (\Illuminate\Support\Facades\Schema::hasTable('mailboxes') && \Illuminate\Support\Facades\Schema::hasTable('users')) {
        try {
            $fsUser = \App\User::where('email', 'admin@example.com')->first();
            if (!$fsUser) {
                $fsUser = new \App\User();
                $fsUser->first_name = 'Admin';
                $fsUser->last_name = 'Docente';
                $fsUser->email = 'admin@example.com';
                $fsUser->password = \Illuminate\Support\Facades\Hash::make('password');
            }
            $fsUser->role = \App\User::ROLE_ADMIN;
            $fsUser->status = \App\User::STATUS_ACTIVE;
            $fsUser->invite_state = \App\User::INVITE_STATE_ACTIVATED;
            $fsUser->save();
            \Illuminate\Support\Facades\DB::table('users')->where('email', 'admin@example.com')->update([
                'role' => \App\User::ROLE_ADMIN,
                'status' => \App\User::STATUS_ACTIVE,
                'invite_state' => \App\User::INVITE_STATE_ACTIVATED,
            ]);

            // Aprovisionar buzón de soporte inicial si aún no existe
            if (\App\Mailbox::count() === 0) {
                $mb = new \App\Mailbox();
                $mb->name = 'Atención y Soporte';
                $mb->email = 'soporte@uleam.edu.ec';
                $mb->in_protocol = 1;
                $mb->in_server = 'localhost';
                $mb->in_port = 993;
                $mb->in_username = 'soporte';
                $mb->in_password = 'demo';
                $mb->out_method = \App\Mailbox::OUT_METHOD_PHP_MAIL;
                $mb->save();
                $mb->users()->sync([$fsUser->id]);
                $mb->syncPersonalFolders([$fsUser->id]);

                // Crear tickets de prueba para que tanto "Unassigned" como "Mine" tengan conversaciones
                try {
                    $customer = \App\Customer::create('juan.perez@estudiante.uleam.edu.ec', [
                        'first_name' => 'Juan',
                        'last_name' => 'Perez'
                    ]);
                    // Ticket 1: Asignado al Admin (Carpeta Mine)
                    $convData1 = [
                        'type' => \App\Conversation::TYPE_EMAIL,
                        'subject' => 'Consulta sobre matrícula y horario académico',
                        'mailbox_id' => $mb->id,
                        'source_type' => 1,
                        'user_id' => $fsUser->id,
                    ];
                    $threads1 = [
                        [
                            'type' => \App\Thread::TYPE_CUSTOMER,
                            'body' => 'Estimados, tengo una consulta respecto a la inscripción de materias para el presente periodo académico. ¿Podrían indicarme los pasos?',
                            'customer_id' => $customer->id,
                        ]
                    ];
                    \App\Conversation::create($convData1, $threads1, $customer);

                    // Ticket 2: Sin asignar (Carpeta Unassigned - vista por defecto al entrar al buzón)
                    $customer2 = \App\Customer::create('maria.alava@estudiante.uleam.edu.ec', [
                        'first_name' => 'Maria',
                        'last_name' => 'Alava'
                    ]);
                    $convData2 = [
                        'type' => \App\Conversation::TYPE_EMAIL,
                        'subject' => 'Consulta de horarios y aula de clases',
                        'mailbox_id' => $mb->id,
                        'source_type' => 1,
                        'user_id' => null,
                    ];
                    $threads2 = [
                        [
                            'type' => \App\Thread::TYPE_CUSTOMER,
                            'body' => 'Buenas tardes, quisiera consultar en qué aula se dictará la clase de Sistemas Distribuidos el día de mañana.',
                            'customer_id' => $customer2->id,
                        ]
                    ];
                    \App\Conversation::create($convData2, $threads2, $customer2);

                    \Illuminate\Support\Facades\Artisan::call('freescout:update-folder-counters');
                    \Illuminate\Support\Facades\Artisan::call('freescout:clear-cache');
                } catch (\Throwable $te) {}
            }
            echo "AUTH_USER:admin@example.com\n";
        } catch (\Throwable $e) {}
    }

    // C. Tablas de Configuración y Asistentes Web Generales (settings, configs, options)
    foreach (['settings', 'configs', 'options', 'app_settings', 'system_settings'] as $table) {
        if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
            $cols = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            $keyCol = in_array('key', $cols) ? 'key' : (in_array('option', $cols) ? 'option' : (in_array('name', $cols) ? 'name' : null));
            $valCol = in_array('value', $cols) ? 'value' : (in_array('val', $cols) ? 'val' : null);
            if ($keyCol && $valCol) {
                $setupFlags = [
                    'installed' => '1',
                    'setup_completed' => '1',
                    'is_installed' => '1',
                    'app_installed' => '1',
                    'first_run' => '0',
                    'wizard_completed' => '1',
                ];
                foreach ($setupFlags as $k => $v) {
                    if (\Illuminate\Support\Facades\DB::table($table)->where($keyCol, $k)->exists()) {
                        \Illuminate\Support\Facades\DB::table($table)->where($keyCol, $k)->update([$valCol => $v]);
                    }
                }
            }
            // Sistemas con tabla settings de una sola fila (Snipe-IT, inventarios, etc.)
            if (in_array('site_name', $cols)) {
                $sRecord = \Illuminate\Support\Facades\DB::table($table)->first();
                if ($sRecord) {
                    \Illuminate\Support\Facades\DB::table($table)->where('id', $sRecord->id)->update([
                        'site_name' => 'Demo Asset Management'
                    ]);
                } else {
                    $settingData = ['site_name' => 'Demo Asset Management'];
                    if (in_array('created_at', $cols)) $settingData['created_at'] = now();
                    if (in_array('updated_at', $cols)) $settingData['updated_at'] = now();
                    \Illuminate\Support\Facades\DB::table($table)->insert($settingData);
                }
            }
        }
    }

    // D. Auto-detección y provisión universal de usuario administrador para pruebas
    $targetTables = ['users', 'admins', 'administrators', 'administradores'];
    foreach ($targetTables as $userTable) {
        if (\Illuminate\Support\Facades\Schema::hasTable($userTable)) {
            // Si es FreeScout (tabla mailboxes), saltar Section D porque ya fue aprovisionado con rol ROLE_ADMIN en B4
            if (\Illuminate\Support\Facades\Schema::hasTable('mailboxes')) {
                break;
            }

            $cols = \Illuminate\Support\Facades\Schema::getColumnListing($userTable);
            $firstUser = \Illuminate\Support\Facades\DB::table($userTable)->first();
            $updateData = [];
            if (in_array('password', $cols)) $updateData['password'] = \Illuminate\Support\Facades\Hash::make('password');
            if (in_array('is_admin', $cols)) $updateData['is_admin'] = 1;
            if (in_array('admin', $cols)) $updateData['admin'] = 1;
            if (in_array('is_superadmin', $cols)) $updateData['is_superadmin'] = 1;
            if (in_array('is_account_administrator', $cols)) $updateData['is_account_administrator'] = 1;
            if (in_array('is_instance_administrator', $cols)) $updateData['is_instance_administrator'] = 1;
            if (in_array('role', $cols)) {
                try {
                    $rType = \Illuminate\Support\Facades\Schema::getColumnType($userTable, 'role');
                    $updateData['role'] = in_array($rType, ['integer', 'smallint', 'tinyint', 'bigint', 'boolean']) ? 1 : 'admin';
                } catch (\Throwable $e) {
                    $updateData['role'] = 1;
                }
            }
            if (in_array('role_id', $cols)) $updateData['role_id'] = 1;
            if (in_array('status', $cols)) {
                try {
                    $sType = \Illuminate\Support\Facades\Schema::getColumnType($userTable, 'status');
                    $updateData['status'] = in_array($sType, ['integer', 'smallint', 'tinyint', 'bigint', 'boolean']) ? 1 : 'active';
                } catch (\Throwable $e) {
                    $updateData['status'] = 1;
                }
            }
            if (in_array('activated', $cols)) $updateData['activated'] = 1;
            if (in_array('username', $cols)) $updateData['username'] = 'admin';
            if (in_array('permissions', $cols)) $updateData['permissions'] = '{"superuser":"1","admin":"1"}';
            if (in_array('email_verified_at', $cols)) $updateData['email_verified_at'] = now();

            if ($firstUser) {
                \Illuminate\Support\Facades\DB::table($userTable)->where('id', $firstUser->id)->update($updateData);
                $identifier = in_array('username', $cols) ? 'admin' : ($firstUser->email ?? 'admin@example.com');
                echo "AUTH_USER:" . $identifier . PHP_EOL;
            } else {
                $insertData = array_merge($updateData, [
                    'email' => 'admin@example.com',
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                ]);
                if (in_array('name', $cols)) $insertData['name'] = 'Admin Evaluador';
                if (in_array('first_name', $cols)) $insertData['first_name'] = 'Admin';
                if (in_array('last_name', $cols)) $insertData['last_name'] = 'Evaluador';
                if (in_array('created_at', $cols)) $insertData['created_at'] = now();
                if (in_array('updated_at', $cols)) $insertData['updated_at'] = now();

                // Soporte para tablas con UUIDs en clave primaria
                if (in_array('id', $cols)) {
                    try {
                        $colType = \Illuminate\Support\Facades\Schema::getColumnType($userTable, 'id');
                        if (in_array($colType, ['string', 'guid', 'char'])) {
                            $insertData['id'] = (string) \Illuminate\Support\Str::uuid();
                        }
                    } catch (\Throwable $e) {}
                }

                // Soporte para sistemas con tabla accounts (Multi-tenant)
                if (in_array('account_id', $cols) && \Illuminate\Support\Facades\Schema::hasTable('accounts')) {
                    try {
                        $firstAcc = \Illuminate\Support\Facades\DB::table('accounts')->first();
                        if (!$firstAcc) {
                            $accId = (string) \Illuminate\Support\Str::uuid();
                            $accData = [];
                            $accCols = \Illuminate\Support\Facades\Schema::getColumnListing('accounts');
                            if (in_array('id', $accCols)) $accData['id'] = $accId;
                            if (in_array('name', $accCols)) $accData['name'] = 'Admin Account';
                            if (in_array('created_at', $accCols)) $accData['created_at'] = now();
                            if (in_array('updated_at', $accCols)) $accData['updated_at'] = now();
                            \Illuminate\Support\Facades\DB::table('accounts')->insert($accData);
                            $insertData['account_id'] = $accId;
                        } else {
                            $insertData['account_id'] = $firstAcc->id;
                        }
                    } catch (\Throwable $e) {}
                }

                \Illuminate\Support\Facades\DB::table($userTable)->insert($insertData);
                echo "AUTH_USER:admin@example.com" . PHP_EOL;
            }

            // Asignación de Roles en Spatie Permission / Bouncer si están disponibles
            if (\Illuminate\Support\Facades\Schema::hasTable('roles') && \Illuminate\Support\Facades\Schema::hasTable('model_has_roles')) {
                try {
                    $adminRole = \Illuminate\Support\Facades\DB::table('roles')
                        ->where('name', 'like', '%admin%')
                        ->orWhere('name', 'Super Admin')
                        ->first();
                    if ($adminRole) {
                        $targetUserId = $firstUser ? $firstUser->id : 1;
                        $userModelClass = config('auth.providers.users.model', 'App\\Models\\User');
                        \Illuminate\Support\Facades\DB::table('model_has_roles')->updateOrInsert([
                            'role_id' => $adminRole->id,
                            'model_id' => $targetUserId,
                            'model_type' => $userModelClass
                        ]);
                    }
                } catch (\Throwable $e) {}
            }
            break;
        }
    }

    // Asegurar que todos los usuarios queden con email verificado para no bloquear evaluación con pantallas de verificación
    if (\Illuminate\Support\Facades\Schema::hasTable('users') && \Illuminate\Support\Facades\Schema::hasColumn('users', 'email_verified_at')) {
        \Illuminate\Support\Facades\DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }
} catch (\Throwable $e) {}
PHP;
            $fullBootstrapScript = "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); " . $postInstallTinker;
            $tinkerProcess = new Process([
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                'webdevops/php:8.4',
                'php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-r', $fullBootstrapScript
            ]);
            $tinkerProcess->run();
            $tinkerOut = $tinkerProcess->getOutput();

            if (preg_match('/AUTH_USER:([^\r\n]+)/', $tinkerOut, $authMatches)) {
                $authEmail = trim($authMatches[1]);
                $isEmail = str_contains($authEmail, '@');
                $userLabel = $isEmail ? 'Correo' : 'Nombre de usuario';
                if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                    $prefix = "Acceso predeterminado para pruebas:\n{$userLabel}: {$authEmail}\nClave: password";
                    $project->demo_instructions = empty($project->demo_instructions)
                        ? "{$prefix}\n\nEl sistema incluye catálogo y datos de muestra listos para evaluar."
                        : "{$prefix}\n\n" . $project->demo_instructions;
                    $project->save();
                    $logs .= "Credenciales predeterminadas generadas e integradas en las instrucciones: {$authEmail} / password\n";
                }
            }

            // Generar snapshot inicial de la base de datos para la función de reseteo del evaluador
            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 2. Django (Python)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')) {
            $logs .= "--- PASO 3.6: Framework Django Detectado: Ejecutando Migraciones y Superusuario ---\n";
            
            // Inyectar variables de entorno para la base de datos y correr migraciones + superusuario
            $djangoScript = <<<'PY'
import os
import django
os.environ.setdefault('DJANGO_SETTINGS_MODULE', os.environ.get('DJANGO_SETTINGS_MODULE', ''))
django.setup()
from django.contrib.auth import get_user_model
User = get_user_model()
try:
    if not User.objects.filter(is_superuser=True).exists():
        User.objects.create_superuser('admin', 'admin@example.com', 'password')
        print('AUTH_USER:admin')
    else:
        u = User.objects.filter(is_superuser=True).first()
        u.set_password('password')
        u.save()
        print(f'AUTH_USER:{u.username}')
except Exception as e:
    pass
PY;
            $command = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                '-e', "DATABASE_URL=" . ($project->db_driver === 'mysql' ? 'mysql' : 'postgres') . "://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}",
                'python:3.12-alpine',
                'sh', '-c', ".venv/bin/python manage.py migrate && .venv/bin/python -c \"{$djangoScript}\" || true"
            ];
            $this->executeMigrationCommand($command, $logs);

            if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                $project->demo_instructions = "Acceso predeterminado para pruebas (Django Admin):\nUsuario: admin\nClave: password\n\nEl sistema incluye base de datos y superusuario listos para evaluar.";
                $project->save();
            }

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 2.5 Ruby on Rails
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/Gemfile') && 
            (\Illuminate\Support\Facades\File::exists($projectPath . '/bin/rails') || \Illuminate\Support\Facades\File::exists($projectPath . '/config/environment.rb'))
        ) {
            $logs .= "--- PASO 3.6: Framework Ruby on Rails Detectado: Ejecutando Migraciones ---\n";
            $dbHost = $project->db_driver === 'mysql' ? 'uleam-mysql-students' : 'uleam-postgres-students';
            $dbUrlScheme = $project->db_driver === 'mysql' ? 'mysql2' : 'postgres';
            $dbUrl = "{$dbUrlScheme}://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}";
            $railsScript = <<<'RB'
begin
  # 1. Update all existing users (from db/seeds.rb or migrations) to have Password123!
  if defined?(User)
    User.find_each do |usr|
      begin
        usr.password = 'Password123!' if usr.respond_to?(:password=)
        usr.password_confirmation = 'Password123!' if usr.respond_to?(:password_confirmation=)
        usr.must_change_passwd = false if usr.respond_to?(:must_change_passwd=)
        usr.status = 1 if usr.respond_to?(:status=)
        usr.is_admin = true if usr.respond_to?(:is_admin=)
        usr.is_moderator = true if usr.respond_to?(:is_moderator=)
        usr.admin = true if usr.respond_to?(:admin=)
        usr.active = true if usr.respond_to?(:active=)
        usr.confirm if usr.respond_to?(:confirm)
        usr.confirmed_at = Time.now if usr.respond_to?(:confirmed_at=)
        usr.save(validate: false) rescue nil
        if usr.respond_to?(:authenticate) && !usr.authenticate('Password123!')
          usr.update_column(:password_digest, BCrypt::Password.create('Password123!')) rescue nil
        end
      rescue => e
      end
    end
  end
rescue => e
end

begin
  # 2. Targeted update for known default seed usernames
  if defined?(User) && User.respond_to?(:find_by)
    ['test', 'admin', 'administrator', 'user', 'demo'].each do |uname|
      begin
        usr = (User.find_by(login: uname) rescue nil) ||
              (User.find_by(username: uname) rescue nil) ||
              (User.find_by(email: "#{uname}@example.com") rescue nil) ||
              (User.respond_to?(:find_by_mail) && User.find_by_mail("#{uname}@example.com") rescue nil)
        if usr
          usr.password = 'Password123!' if usr.respond_to?(:password=)
          usr.password_confirmation = 'Password123!' if usr.respond_to?(:password_confirmation=)
          usr.must_change_passwd = false if usr.respond_to?(:must_change_passwd=)
          usr.status = 1 if usr.respond_to?(:status=)
          usr.is_admin = true if usr.respond_to?(:is_admin=)
          usr.is_moderator = true if usr.respond_to?(:is_moderator=)
          usr.admin = true if usr.respond_to?(:admin=)
          usr.active = true if usr.respond_to?(:active=)
          usr.confirm if usr.respond_to?(:confirm)
          usr.confirmed_at = Time.now if usr.respond_to?(:confirmed_at=)
          usr.save(validate: false) rescue nil
          if usr.respond_to?(:authenticate) && !usr.authenticate('Password123!')
            usr.update_column(:password_digest, BCrypt::Password.create('Password123!')) rescue nil
          end
        end
      rescue => e
      end
    end
  end
rescue => e
end

begin
  # 2.5 Ensure a test user exists if User uses login (Redmine, etc.)
  if defined?(User) && User.respond_to?(:column_names) && User.column_names.include?('login')
    if !User.find_by(login: 'test')
      tu = User.new(login: 'test')
      tu.firstname = 'Test' if tu.respond_to?(:firstname=)
      tu.lastname = 'Evaluador' if tu.respond_to?(:lastname=)
      tu.name = 'Test Evaluador' if tu.respond_to?(:name=)
      tu.mail = 'test@example.com' if tu.respond_to?(:mail=)
      tu.email = 'test@example.com' if tu.respond_to?(:email=)
      tu.admin = true if tu.respond_to?(:admin=)
      tu.password = 'Password123!' if tu.respond_to?(:password=)
      tu.password_confirmation = 'Password123!' if tu.respond_to?(:password_confirmation=)
      tu.must_change_passwd = false if tu.respond_to?(:must_change_passwd=)
      tu.status = 1 if tu.respond_to?(:status=)
      tu.save(validate: false) rescue nil
    end
  end
rescue => e
end

begin
  # 3. If Chatwoot AccountBuilder exists
  if defined?(AccountBuilder)
    AccountBuilder.new(account_name: 'ULEAM Soporte', email: 'admin@example.com', user_full_name: 'Admin Evaluador', user_password: 'Password123!', confirmed: 'true', super_admin: true).perform rescue nil
  elsif defined?(User) && User.respond_to?(:where)
    # If no users exist in database, create admin@example.com
    if User.count == 0
      acc = nil
      if defined?(Account) && Account.respond_to?(:find_or_create_by!)
        acc = Account.find_or_create_by!(username: 'admin') rescue (Account.first rescue nil)
      end
      u = User.where(email: 'admin@example.com').first_or_initialize
      u.account = acc if acc && u.respond_to?(:account=)
      u.agreement = true if u.respond_to?(:agreement=)
      u.username = 'eval_admin' if u.respond_to?(:username=) && (u.respond_to?(:username) && u.username.blank?)
      u.name = 'Admin Evaluador' if u.respond_to?(:name=)
      u.first_name = 'Admin' if u.respond_to?(:first_name=)
      u.last_name = 'Evaluador' if u.respond_to?(:last_name=)
      u.password = 'Password123!' if u.respond_to?(:password=)
      u.password_confirmation = 'Password123!' if u.respond_to?(:password_confirmation=)
      if defined?(UserRole)
        owner_role = UserRole.find_or_create_by!(name: 'Owner') { |r| r.permissions = (UserRole::Flags::ALL rescue 0); r.position = 1000 } rescue (UserRole.first rescue nil)
        u.role = owner_role if owner_role
      elsif u.respond_to?(:role=)
        u.role = :admin
      end
      u.approved = true if u.respond_to?(:approved=)
      u.admin = true if u.respond_to?(:admin=)
      u.is_admin = true if u.respond_to?(:is_admin=)
      u.is_moderator = true if u.respond_to?(:is_moderator=)
      u.karma = 100 if u.respond_to?(:karma=)
      u.active = true if u.respond_to?(:active=)
      u.activated = true if u.respond_to?(:activated=)
      u.activated_at = Time.now if u.respond_to?(:activated_at=)
      u.save(validate: false) rescue (u.save rescue nil)
      
      if u.persisted?
        cols = {}
        cols[:approved] = true if u.respond_to?(:approved)
        cols[:is_admin] = true if u.respond_to?(:is_admin)
        cols[:is_moderator] = true if u.respond_to?(:is_moderator)
        cols[:karma] = 100 if u.respond_to?(:karma)
        cols[:activated] = true if u.respond_to?(:activated)
        cols[:admin] = true if u.respond_to?(:admin)
        cols[:confirmed_at] = Time.now if u.respond_to?(:confirmed_at)
        u.update_columns(cols) rescue nil if cols.any?
      end
    end
  end
rescue => e
end

begin
  # 4. Output the authenticated user identifier for demo_instructions
  if defined?(User)
    active_user = (User.find_by(login: 'test') rescue nil) ||
                  (User.find_by(username: 'test') rescue nil) ||
                  (User.find_by(email: 'test@example.com') rescue nil) ||
                  (User.find_by(login: 'admin') rescue nil) ||
                  (User.find_by(username: 'admin') rescue nil) ||
                  (User.find_by(email: 'admin@example.com') rescue nil) ||
                  (User.first rescue nil)
    if active_user
      ident = nil
      ident ||= active_user.login if active_user.respond_to?(:login) && active_user.login.present?
      ident ||= active_user.username if active_user.respond_to?(:username) && active_user.username.present?
      ident ||= active_user.mail if active_user.respond_to?(:mail) && active_user.mail.present?
      ident ||= active_user.email if active_user.respond_to?(:email) && active_user.email.present?
      puts "AUTH_USER:#{ident}" if ident
    end
  end
rescue => e
end
RB;
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

            $seedScriptPath = $projectPath . '/.uleam_seed.rb';
            \Illuminate\Support\Facades\File::put($seedScriptPath, $railsScript);

            $isSqlite = false;
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/config/database.yml')) {
                $dbYaml = \Illuminate\Support\Facades\File::get($projectPath . '/config/database.yml');
                if (str_contains($dbYaml, 'sqlite3') && !str_contains($dbYaml, 'postgresql') && !str_contains($dbYaml, 'postgres') && !str_contains($dbYaml, 'mysql')) {
                    $isSqlite = true;
                }
            }

            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/log', 0777);
            @chmod($projectPath . '/log', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/storage', 0777);
            @chmod($projectPath . '/storage', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/tmp/pids', 0777);
            @chmod($projectPath . '/tmp/pids', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/db', 0777);
            @chmod($projectPath . '/db', 0777);

            if (\Illuminate\Support\Facades\File::exists($projectPath . '/config/environments/production.rb')) {
                $prodConfig = \Illuminate\Support\Facades\File::get($projectPath . '/config/environments/production.rb');
                if (!str_contains($prodConfig, 'active_storage.service')) {
                    \Illuminate\Support\Facades\File::append($projectPath . '/config/environments/production.rb', "\nRails.application.configure do\n  config.active_storage.service = :local if config.respond_to?(:active_storage)\nend\n");
                }
            }

            $bundleCacheVol = 'uleam_bundle_cache_' . str_replace(['uleam_ruby:', '.'], ['', ''], $rubyImage);
            $command = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-v', "{$bundleCacheVol}:/usr/local/bundle",
                '-w', '/app',
                '-e', 'HOME=/tmp',
                '-e', 'BUNDLE_PATH=vendor/bundle',
                '-e', 'RAILS_ENV=production',
                '-e', 'PIDFILE=tmp/pids/server.pid',
                '-e', 'PUMA_WORKERS=0',
                '-e', 'SECRET_KEY_BASE=uleam_rails_secret_key_base_' . md5($project->id),
            ];

            if (!$isSqlite) {
                $command[] = '-e';
                $command[] = "DATABASE_URL={$dbUrl}";
                $command[] = '-e';
                $command[] = "POSTGRES_HOST={$dbHost}";
                $command[] = '-e';
                $command[] = "POSTGRES_PORT={$dbPort}";
                $command[] = '-e';
                $command[] = "POSTGRES_DATABASE={$project->db_name}";
                $command[] = '-e';
                $command[] = "POSTGRES_USERNAME={$project->db_user}";
                $command[] = '-e';
                $command[] = "POSTGRES_DB={$project->db_name}";
                $command[] = '-e';
                $command[] = "POSTGRES_USER={$project->db_user}";
                $command[] = '-e';
                $command[] = "POSTGRES_PASSWORD={$project->db_password}";
                $command[] = '-e';
                $command[] = "DB_HOST={$dbHost}";
                $command[] = '-e';
                $command[] = "DB_PORT={$dbPort}";
                $command[] = '-e';
                $command[] = "DB_DATABASE={$project->db_name}";
                $command[] = '-e';
                $command[] = "DB_USERNAME={$project->db_user}";
                $command[] = '-e';
                $command[] = "DB_PASSWORD={$project->db_password}";
                $command[] = '-e';
                $command[] = "PGHOST={$dbHost}";
                $command[] = '-e';
                $command[] = "PGPORT={$dbPort}";
                $command[] = '-e';
                $command[] = "PGDATABASE={$project->db_name}";
                $command[] = '-e';
                $command[] = "PGUSER={$project->db_user}";
                $command[] = '-e';
                $command[] = "PGPASSWORD={$project->db_password}";
            }

            $encKey1 = hash('sha256', $project->id . '_active_record_primary');
            $encKey2 = hash('sha256', $project->id . '_active_record_deterministic');
            $encKey3 = hash('sha256', $project->id . '_active_record_salt');
            $otpSecret = hash('sha256', $project->id . '_otp_secret');

            $envPath = $projectPath . '/.env';
            $envAdditions = "\nACTIVE_RECORD_ENCRYPTION_PRIMARY_KEY={$encKey1}\n"
                . "ACTIVE_RECORD_ENCRYPTION_DETERMINISTIC_KEY={$encKey2}\n"
                . "ACTIVE_RECORD_ENCRYPTION_KEY_DERIVATION_SALT={$encKey3}\n"
                . "OTP_SECRET={$otpSecret}\n"
                . "LOCAL_DOMAIN={$project->subdomain}.localhost\n"
                . "SECRET_KEY_BASE=uleam_rails_secret_key_base_" . md5($project->id) . "\n";
            @file_put_contents($envPath, $envAdditions, FILE_APPEND);

            $command[] = '-e';
            $command[] = "ACTIVE_RECORD_ENCRYPTION_PRIMARY_KEY={$encKey1}";
            $command[] = '-e';
            $command[] = "ACTIVE_RECORD_ENCRYPTION_DETERMINISTIC_KEY={$encKey2}";
            $command[] = '-e';
            $command[] = "ACTIVE_RECORD_ENCRYPTION_KEY_DERIVATION_SALT={$encKey3}";
            $command[] = '-e';
            $command[] = "OTP_SECRET={$otpSecret}";
            $command[] = '-e';
            $command[] = "LOCAL_DOMAIN={$project->subdomain}.localhost";

            $command[] = '-e';
            $command[] = 'REDIS_URL=redis://uleam-redis-students:6379';
            $command[] = '-e';
            $command[] = 'REDIS_HOST=uleam-redis-students';
            $command[] = '-e';
            $command[] = 'REDIS_PORT=6379';
            $command[] = $rubyImage;
            $command[] = 'sh';
            $command[] = '-c';
            $command[] = 'bundle exec rails db:migrate RAILS_ENV=production && (bundle exec rake redmine:load_default_data REDMINE_LANG=es RAILS_ENV=production || true) && (bundle exec rails db:seed RAILS_ENV=production || true) && (bundle exec rails runner .uleam_seed.rb || true) && (bundle exec rails assets:precompile RAILS_ENV=production || true)';
            $this->executeMigrationCommand($command, $logs);
            @unlink($seedScriptPath);

            $isRedmine = str_contains(strtolower($project->name), 'redmine') || File::exists($projectPath . '/config/initializers/redmine.rb') || File::exists($projectPath . '/app/models/issue.rb');
            if (preg_match('/AUTH_USER:([^\r\n]+)/', $logs, $authMatches)) {
                $authEmail = trim($authMatches[1]);
                $isEmail = str_contains($authEmail, '@');
                $userLabel = $isEmail ? 'Correo' : ($isRedmine ? 'Identificador' : 'Nombre de usuario');
                $alt = $isEmail ? " (o " . explode('@', $authEmail)[0] . ")" : ($isRedmine ? " (o admin)" : "");
                $project->demo_instructions = "Acceso predeterminado para pruebas (Ruby on Rails):\n{$userLabel}: {$authEmail}{$alt}\nClave: Password123!\n\nEl sistema incluye base de datos, semillas y migraciones listas para evaluar.";
                $project->save();
            } elseif (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                $userLabel = $isRedmine ? 'Identificador' : 'Usuario';
                $userVal = $isRedmine ? 'test (o admin)' : 'test@example.com (o test)';
                $project->demo_instructions = "Acceso predeterminado para pruebas (Ruby on Rails):\n{$userLabel}: {$userVal}\nClave: Password123!\n\nEl sistema incluye base de datos, semillas y migraciones listas para evaluar.";
                $project->save();
            }

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 3. Node.js + Sequelize
        $packageJsonPath = $projectPath . '/package.json';
        $hasSequelize = false;
        if (\Illuminate\Support\Facades\File::exists($packageJsonPath)) {
            $packageJson = json_decode(\Illuminate\Support\Facades\File::get($packageJsonPath), true);
            $deps = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);
            if (isset($deps['sequelize']) || \Illuminate\Support\Facades\File::exists($projectPath . '/.sequelizerc')) {
                $hasSequelize = true;
            }
        }

        if ($hasSequelize) {
            $logs .= "--- PASO 3.6: ORM Sequelize Detectado: Ejecutando Migraciones ---\n";
            
            $command = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                '-e', "DATABASE_URL=" . ($project->db_driver === 'mysql' ? 'mysql' : 'postgres') . "://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}",
                'node:18-alpine',
                'sh', '-c', 'npx sequelize-cli db:migrate || npx sequelize db:migrate'
            ];
            $this->executeMigrationCommand($command, $logs);
            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 3.7. IDURAR ERP CRM (MERN / MongoDB inicialización)
        $idurarSetupCandidates = [
            ['dir' => 'backend', 'file' => 'src/setup/setup.js'],
            ['dir' => '.', 'file' => 'src/setup/setup.js']
        ];
        foreach ($idurarSetupCandidates as $setupCandidate) {
            $checkDir = ($setupCandidate['dir'] === '.') ? $projectPath : $projectPath . '/' . $setupCandidate['dir'];
            if (\Illuminate\Support\Facades\File::exists($checkDir . '/' . $setupCandidate['file'])) {
                $logs .= "--- PASO 3.6: Inicialización de IDURAR ERP CRM (setup inicial de base de datos) ---\n";
                $setupWorkDir = ($setupCandidate['dir'] === '.') ? '/app' : "/app/{$setupCandidate['dir']}";
                $mongoUri = "mongodb://{$project->db_user}:{$project->db_password}@uleam_mongodb_students:27017/{$project->db_name}?authSource={$project->db_name}";
                $setupCommand = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/app",
                    '-w', $setupWorkDir,
                    '-e', "DATABASE={$mongoUri}",
                    '-e', "DATABASE_URL={$mongoUri}",
                    '-e', "MONGODB_URI={$mongoUri}",
                    'node:20-alpine',
                    'node', $setupCandidate['file']
                ];
                $this->executeMigrationCommand($setupCommand, $logs);
                $this->generateInitialDatabaseSnapshot($project, $projectPath);
                return;
            }
        }

        // 3.8. Planka Kanban (Sails.js + Knex / PostgreSQL)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/server/db/init.js')) {
            $logs .= "--- PASO 3.6: Planka Kanban Detectado: Inicializando Base de Datos PostgreSQL (knex migrate & seed) ---\n";
            $plankaInitCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app/server',
                'node:20',
                'node', 'db/init.js'
            ];
            $this->executeMigrationCommand($plankaInitCmd, $logs);

            $project->demo_instructions = "Acceso predeterminado para pruebas (Planka Kanban):\nUsuario: demo@demo.demo\nClave: demo\n\nSistema ágil tipo Trello/Kanban con proyectos, tableros, listas y tarjetas.";
            $project->save();
            $logs .= "Credenciales predeterminadas para Planka integradas en las instrucciones: demo@demo.demo / demo\n";

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 3.9. Rallly (Next.js + Prisma / PostgreSQL)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/packages/database/prisma') || \Illuminate\Support\Facades\File::exists($projectPath . '/prisma.config.ts')) {
            $logs .= "--- PASO 3.6: Monorepo Prisma / Rallly Detectado: Ejecutando Migraciones (pnpm db:deploy) ---\n";
            $ralllyMigrateCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-e', 'HOME=/tmp',
                '-e', 'PNPM_HOME=/tmp/.pnpm',
                '-e', "DATABASE_URL=" . ($project->db_driver === 'mysql' ? 'mysql' : 'postgresql') . "://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}",
                '-w', '/app',
                'node:24-bookworm',
                'sh', '-c', 'mkdir -p /tmp/.bin && (corepack enable --install-directory /tmp/.bin 2>/dev/null || true); export PATH=/tmp/.bin:$PATH; pnpm db:deploy'
            ];
            $this->executeMigrationCommand($ralllyMigrateCmd, $logs);

            $project->demo_instructions = "Acceso para pruebas (Rallly Polls & Meetings):\nPlataforma ágil de encuestas, eventos y coordinación de reuniones.\nPuedes crear encuestas públicas de inmediato o registrarte/iniciar sesión con cualquier correo (ej: admin@example.com).\nEl código de verificación de 6 dígitos se muestra directamente en pantalla y en 'Ver Logs' -> 'Consola del contenedor'.";
            $project->save();
            $logs .= "Instrucciones de demostración configuradas para Rallly.\n";

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 4. CodeIgniter 4 (PHP)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/spark')) {
            $logs .= "--- PASO 3.6: Framework CodeIgniter 4 Detectado: Ejecutando Migraciones (spark migrate) ---\n";
            $ciCommand = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "CI_ENVIRONMENT=development",
                '-e', "ALLOWED_HOSTNAMES={$project->subdomain}.localhost,{$project->subdomain}.{$baseDomain},localhost,127.0.0.1",
                '-e', "MYSQL_HOST={$dbHost}",
                '-e', "MYSQL_HOST_NAME={$dbHost}",
                '-e', "MYSQL_PORT={$dbPort}",
                '-e', "MYSQL_DATABASE={$project->db_name}",
                '-e', "MYSQL_DB_NAME={$project->db_name}",
                '-e', "MYSQL_USER={$project->db_user}",
                '-e', "MYSQL_USERNAME={$project->db_user}",
                '-e', "MYSQL_PASSWORD={$project->db_password}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                'webdevops/php:8.4',
                'php', 'spark', 'migrate'
            ];
            $this->executeMigrationCommand($ciCommand, $logs);

            // Asegurar permisos en directorio writable
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/writable', 0777);
            @chmod($projectPath . '/writable', 0777);
            (new Process(['docker', 'run', '--rm', '-v', "{$projectPath}:/app", 'alpine', 'chmod', '-R', '777', '/app/writable']))->run();

            // Detectar credenciales predeterminadas para OpenSourcePOS
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/app/Config/OSPOS.php') || str_contains(strtolower($project->name), 'opensourcepos') || str_contains(strtolower($project->name), 'ospos')) {
                $project->demo_instructions = "Acceso predeterminado para pruebas (OpenSourcePOS):\nUsuario: admin\nClave: pointofsale\n\nSistema punto de venta (POS) completamente configurado con base de datos e inventario listo para evaluar.";
                $project->save();
            }

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 5. Easy!Appointments (CodeIgniter 3)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/config-sample.php') && \Illuminate\Support\Facades\File::isDirectory($projectPath . '/application/migrations')) {
            $logs .= "--- PASO 3.6: Aplicación Easy!Appointments Detectada: Configurando e Instalando ---\n";

            // Crear config.php si aún no existe
            $cfgFile = $projectPath . '/config.php';
            if (!\Illuminate\Support\Facades\File::exists($cfgFile)) {
                $configSample = \Illuminate\Support\Facades\File::get($projectPath . '/config-sample.php');
                $baseUrl = "http://{$project->subdomain}.localhost";
                $configContent = str_replace(
                    [
                        "const BASE_URL = 'http://localhost';",
                        "const DB_HOST = 'mysql';",
                        "const DB_NAME = 'easyappointments';",
                        "const DB_USERNAME = 'user';",
                        "const DB_PASSWORD = 'password';"
                    ],
                    [
                        "const BASE_URL = '{$baseUrl}';",
                        "const DB_HOST = '{$dbHost}';",
                        "const DB_NAME = '{$project->db_name}';",
                        "const DB_USERNAME = '{$project->db_user}';",
                        "const DB_PASSWORD = '{$project->db_password}';"
                    ],
                    $configSample
                );
                \Illuminate\Support\Facades\File::put($cfgFile, $configContent);
                @chmod($cfgFile, 0777);
            }

            // Permisos en storage, cache, logs
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/storage/logs', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/storage/sessions', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/storage/cache', 0777);
            (new Process(['docker', 'run', '--rm', '-v', "{$projectPath}:/app", 'alpine', 'chmod', '-R', '777', '/app/storage', '/app/config.php']))->run();

            // Ejecutar instalación por consola
            $eaCommand = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                'webdevops/php:8.4',
                'php', 'index.php', 'console', 'install'
            ];
            $this->executeMigrationCommand($eaCommand, $logs);

            $project->demo_instructions = "Acceso predeterminado para pruebas (Easy!Appointments):\nUsuario: administrator\nClave: administrator\n\nSistema integral de gestión de citas y reservas online con calendario interactivo, catálogo de servicios, proveedores y agenda médica/académica lista para evaluar.";
            $project->save();

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 6. Kimai (Symfony Time Tracking)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/bin/console') && \Illuminate\Support\Facades\File::exists($projectPath . '/config/packages/kimai.yaml')) {
            $logs .= "--- PASO 3.6: Aplicación Kimai Detectada: Ejecutando Instalación y Superusuario ---\n";

            $installCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                'webdevops/php:8.4',
                'php', 'bin/console', 'kimai:install', '-n'
            ];
            $this->executeMigrationCommand($installCmd, $logs);

            $adminCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                'webdevops/php:8.4',
                'php', 'bin/console', 'kimai:user:create', '--ignore-existing', 'admin', 'admin@example.com', 'ROLE_SUPER_ADMIN', 'password', '-n'
            ];
            $this->executeMigrationCommand($adminCmd, $logs);

            $assetsCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                'webdevops/php:8.4',
                'php', 'bin/console', 'assets:install', '--symlink', '--relative'
            ];
            $this->executeMigrationCommand($assetsCmd, $logs);

            (new Process(['docker', 'run', '--rm', '-v', "{$projectPath}:/app", 'alpine', 'chmod', '-R', '777', '/app/var']))->run();

            $project->demo_instructions = "Acceso predeterminado para pruebas (Kimai Time Tracking):\nUsuario: admin (o admin@example.com)\nClave: password\n\nSistema integral de control de horarios, proyectos, clientes, hojas de tiempo y facturación listo para evaluar.";
            $project->save();

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }
    }

    /**
     * Ejecutar comando de migración dentro del contenedor temporal de Docker.
     */
    private function executeMigrationCommand(array $command, &$logs, int $timeout = 300): void
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $logs .= "Aviso: Se alcanzó el límite de tiempo ({$timeout}s) durante las migraciones del framework.\n";
            return;
        }

        $output = $process->getOutput() . $process->getErrorOutput();
        $output = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F018}-\x{1F0F5}\x{1F200}-\x{1F270}\x{FE00}-\x{FE0F}\x{1F000}-\x{1F02F}\x{1F0A0}-\x{1F0FF}]/u', '', $output);
        $logs .= $output . "\n";

        if ($process->isSuccessful()) {
            $logs .= "Migraciones ejecutadas exitosamente.\n\n";
        } else {
            $logs .= "Advertencia/Fallo al ejecutar las migraciones del framework.\n\n";
        }
    }

    /**
     * Detect if a SQL file has MySQL syntax.
     */
    private function isMysqlSyntax(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return false;
        }

        $content = fread($handle, 20480);
        fclose($handle);

        if ($content === false) {
            return false;
        }

        if (Str::contains($content, '`')) {
            return true;
        }
        if (stripos($content, 'ENGINE=') !== false) {
            return true;
        }
        if (stripos($content, 'DEFAULT CHARSET=') !== false) {
            return true;
        }
        if (stripos($content, 'character_set_client') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Detect which database driver to use (pgsql or mysql).
     */
    private function detectDbDriver(Project $project, string $projectPath): string
    {
        // 1. Check package.json for Node.js (including monorepos: backend, frontend, api, server, etc.)
        $packageJsonFiles = $this->getProjectScanFiles($projectPath, 'package.json');
        foreach ($packageJsonFiles as $pkgFile) {
            $packageJson = json_decode(@file_get_contents($pkgFile->getRealPath()), true);
            if ($packageJson) {
                $deps = array_merge(
                    $packageJson['dependencies'] ?? [],
                    $packageJson['devDependencies'] ?? []
                );
                if (isset($deps['mongoose']) || isset($deps['mongodb'])) {
                    return 'mongodb';
                }
                if (isset($deps['pg']) || isset($deps['postgres']) || isset($deps['@evershop/postgres-query-builder'])) {
                    return 'pgsql';
                }
                if (isset($packageJson['description']) && stripos($packageJson['description'], 'postgres') !== false) {
                    return 'pgsql';
                }
                if (isset($deps['better-sqlite3']) || isset($deps['sqlite3']) || isset($deps['sqlite']) || \Illuminate\Support\Facades\File::exists($projectPath . '/packages/loot-core')) {
                    return 'sqlite';
                }
                if (isset($deps['mysql']) || isset($deps['mysql2'])) {
                    return 'mysql';
                }
            }
        }

        // 2. Check requirements.txt for Python
        $requirementsPath = $projectPath . '/requirements.txt';
        if (\Illuminate\Support\Facades\File::exists($requirementsPath)) {
            $reqs = \Illuminate\Support\Facades\File::get($requirementsPath);
            if (stripos($reqs, 'pymongo') !== false || stripos($reqs, 'mongoengine') !== false) {
                return 'mongodb';
            }
        }

        // 3. Check .env, .env.example, .env.dist if exists in project (root and subdirectories)
        $envFiles = $this->getProjectScanFiles($projectPath, '.env*');
        foreach ($envFiles as $eFile) {
            $envContent = @file_get_contents($eFile->getRealPath());
            if ($envContent) {
                if (stripos($envContent, 'DB_CONNECTION=mongodb') !== false || stripos($envContent, 'MONGODB_URI') !== false || stripos($envContent, 'MONGO_URL') !== false || (stripos($envContent, 'DATABASE=') !== false && stripos($envContent, 'mongodb') !== false)) {
                    return 'mongodb';
                }
                if (stripos($envContent, 'DB_CONNECTION=mysql') !== false || stripos($envContent, 'DB_DRIVER=mysql') !== false) {
                    return 'mysql';
                }
                if (stripos($envContent, 'DB_CONNECTION=pgsql') !== false || stripos($envContent, 'DB_DRIVER=pgsql') !== false) {
                    return 'pgsql';
                }
            }
        }

        // 4. Check config/database.php in PHP / Laravel
        $configDb = $projectPath . '/config/database.php';
        if (\Illuminate\Support\Facades\File::exists($configDb)) {
            $configContent = \Illuminate\Support\Facades\File::get($configDb);
            if (str_contains($configContent, "'default' => env('DB_CONNECTION', 'mysql')") || str_contains($configContent, "'default' => 'mysql'")) {
                return 'mysql';
            }
            if (str_contains($configContent, "'default' => env('DB_CONNECTION', 'pgsql')") || str_contains($configContent, "'default' => 'pgsql'")) {
                return 'pgsql';
            }
        }

        // 4b. Check config/database.yml in Ruby on Rails
        $dbYmlPath = \Illuminate\Support\Facades\File::exists($projectPath . '/config/database.yml') 
            ? $projectPath . '/config/database.yml' 
            : (\Illuminate\Support\Facades\File::exists($projectPath . '/config/database.yml.example') ? $projectPath . '/config/database.yml.example' : null);
        if ($dbYmlPath && \Illuminate\Support\Facades\File::exists($dbYmlPath)) {
            $ymlContent = \Illuminate\Support\Facades\File::get($dbYmlPath);
            if (str_contains($ymlContent, 'sqlite3') && !str_contains($ymlContent, 'postgresql') && !str_contains($ymlContent, 'postgres') && !str_contains($ymlContent, 'mysql')) {
                return 'sqlite';
            }
            if (str_contains($ymlContent, 'mysql')) {
                return 'mysql';
            }
            if (str_contains($ymlContent, 'postgresql') || str_contains($ymlContent, 'postgres')) {
                return 'pgsql';
            }
        }

        // 5. Check .sql files (excluding vendor, node_modules, etc.)
        $sqlFiles = $this->getProjectScanFiles($projectPath, '*.sql');
        foreach ($sqlFiles as $file) {
            if ($this->isMysqlSyntax($file->getRealPath())) {
                return 'mysql';
            }
        }

        // 6. Proyectos PHP / Laravel convencionales usan MySQL por defecto
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/artisan') || \Illuminate\Support\Facades\File::exists($projectPath . '/composer.json')) {
            return 'mysql';
        }

        return 'pgsql';
    }

    /**
     * Get project files safely excluding heavy directories (vendor, node_modules, .git, etc.)
     * to prevent PHP memory exhaustion.
     */
    private function getProjectScanFiles(string $path, ?string $namePattern = null): array
    {
        if (!is_dir($path)) {
            return [];
        }

        try {
            $finder = new \Symfony\Component\Finder\Finder();
            $finder->files()
                ->in($path)
                ->ignoreDotFiles(true)
                ->ignoreVCS(true)
                ->exclude(['vendor', 'node_modules', '.git', '.venv', 'storage', 'dist', '.next', 'build', 'tests', 'test', 'cache']);
            
            if ($namePattern) {
                $finder->name($namePattern);
            }
            
            return iterator_to_array($finder, false);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Sincronizar de manera universal y automática las credenciales de base de datos
     * y URL de aplicación en todos los archivos .env del proyecto.
     */
    private function syncProjectEnvironmentVariables(Project $project, string $projectPath, string &$logs): void
    {
        if (empty($project->db_name)) {
            return;
        }

        $logs .= "--- PASO 2.5: Sincronizando Variables de Entorno de Base de Datos (.env) ---\n";

        $dbHost = $project->db_driver === 'mysql' ? ($project->language === 'ruby' ? 'uleam-mysql-students' : 'uleam_mysql_students') : ($project->db_driver === 'mongodb' ? 'uleam_mongodb_students' : ($project->language === 'ruby' ? 'uleam-postgres-students' : 'uleam_postgres_students'));
        $dbPort = $project->db_driver === 'mysql' ? '3306' : ($project->db_driver === 'mongodb' ? '27017' : '5432');
        $baseDomain = env('APP_DOMAIN', 'nexus-academic.software');
        $isProd = config('app.env') === 'production';
        $projectHost = $isProd ? "{$project->subdomain}.{$baseDomain}" : "{$project->subdomain}.localhost";
        $appUrl = $isProd ? "https://{$projectHost}" : "http://{$projectHost}";
        $dbPrefix = $project->db_driver === 'mysql' ? ($project->language === 'ruby' ? 'mysql2' : 'mysql') : ($project->db_driver === 'mongodb' ? 'mongodb' : ($project->language === 'ruby' ? 'postgres' : 'postgresql'));
        $dbUrl = "{$dbPrefix}://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}";
        if ($project->db_driver === 'mongodb') {
            $dbUrl .= "?authSource={$project->db_name}";
        }

        $redisHost = ($project->language === 'ruby') ? 'uleam-redis-students' : 'uleam_redis_students';

        $commonEnvVars = [
            'APP_URL' => $appUrl,
            'APP_DOMAIN' => $projectHost,
            'ADMIN_DOMAIN' => $projectHost,
            'DB_CONNECTION' => $project->db_driver ?: 'mysql',
            'DB_HOST' => $dbHost,
            'DB_PORT' => $dbPort,
            'DB_DATABASE' => $project->db_name,
            'DB_NAME' => $project->db_name,
            'DB_USERNAME' => $project->db_user,
            'DB_USER' => $project->db_user,
            'DB_PASSWORD' => $project->db_password,
            'DB_PASS' => $project->db_password,
            'DATABASE_URL' => $dbUrl,
            'DATABASE' => $dbUrl,
            'MONGODB_URI' => $dbUrl,
            'MONGO_URI' => $dbUrl,
            'MONGO_URL' => $dbUrl,
            'DB_URI' => $dbUrl,
            'PGHOST' => $dbHost,
            'PGPORT' => $dbPort,
            'PGDATABASE' => $project->db_name,
            'PGUSER' => $project->db_user,
            'PGPASSWORD' => $project->db_password,
            'POSTGRES_HOST' => $dbHost,
            'POSTGRES_PORT' => $dbPort,
            'POSTGRES_DATABASE' => $project->db_name,
            'POSTGRES_USERNAME' => $project->db_user,
            'POSTGRES_DB' => $project->db_name,
            'POSTGRES_USER' => $project->db_user,
            'POSTGRES_PASSWORD' => $project->db_password,
            'REDIS_URL' => "redis://{$redisHost}:6379",
            'REDIS_HOST' => $redisHost,
            'REDIS_PORT' => '6379',
            'SECRET_KEY_BASE' => 'uleam_rails_secret_key_base_' . md5($project->id),
            'BUNDLE_PATH' => 'vendor/bundle',
            'APP_DEBUG' => 'false',
            'DEBUGBAR_ENABLED' => 'false',
            'SESSION_DRIVER' => 'file',
            'CACHE_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'log',
            'FILESYSTEM_DISK' => 'public',
            'SANCTUM_STATEFUL_DOMAINS' => "{$project->subdomain}.localhost,localhost,127.0.0.1",
            'SESSION_DOMAIN' => '',
            'APP_TRUSTED_HOSTS' => "{$projectHost},localhost,127.0.0.1,{$project->subdomain}.localhost,{$project->subdomain}.nexus-academic.software",
            'APP_X_FRAME_OPTIONS' => 'false',
            'APP_INSTALLED' => 'true',
            'INSTALLED' => 'true',
            'SETUP_COMPLETED' => 'true',
            'PRECONFIGURED_INSTALL' => 'true',
            'INSTALL' => 'false',
            'ALREADY_INSTALLED' => 'true',
            'APP_SECRET' => hash('sha256', $project->id),
            'DEFAULT_URI' => $appUrl,
            // CodeIgniter 4 / OpenSourcePOS
            'CI_ENVIRONMENT' => 'development',
            'ALLOWED_HOSTNAMES' => "{$project->subdomain}.localhost,{$project->subdomain}.{$baseDomain},localhost,127.0.0.1",
            'MYSQL_HOST' => $dbHost,
            'MYSQL_HOST_NAME' => $dbHost,
            'MYSQL_PORT' => $dbPort,
            'MYSQL_DATABASE' => $project->db_name,
            'MYSQL_DB_NAME' => $project->db_name,
            'MYSQL_USER' => $project->db_user,
            'MYSQL_USERNAME' => $project->db_user,
            'MYSQL_PASSWORD' => $project->db_password,
        ];

        // Variables con notación de punto exclusivas de CodeIgniter 4 (evitan romper parsers estrictos como Symfony Dotenv)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/spark') || \Illuminate\Support\Facades\File::exists($projectPath . '/app/Config/App.php')) {
            $commonEnvVars['app.allowedHostnames'] = "{$project->subdomain}.localhost,{$project->subdomain}.{$baseDomain},localhost,127.0.0.1";
            $commonEnvVars['database.default.hostname'] = $dbHost;
            $commonEnvVars['database.default.database'] = $project->db_name;
            $commonEnvVars['database.default.username'] = $project->db_user;
            $commonEnvVars['database.default.password'] = $project->db_password;
            $commonEnvVars['database.default.DBDriver'] = ($project->db_driver === 'mysql' ? 'MySQLi' : 'Postgre');
            $commonEnvVars['database.default.DBPrefix'] = 'ospos_';
            $commonEnvVars['database.default.port'] = $dbPort;
        }

        // Variables para Planka Kanban (Sails.js + Knex)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/server/db/init.js')) {
            $commonEnvVars['BASE_URL'] = $appUrl;
            $commonEnvVars['SECRET_KEY'] = hash('sha256', $project->id);
            $commonEnvVars['TRUST_PROXY'] = 'true';
            $commonEnvVars['DEFAULT_ADMIN_EMAIL'] = 'demo@demo.demo';
            $commonEnvVars['DEFAULT_ADMIN_PASSWORD'] = 'demo';
            $commonEnvVars['DEFAULT_ADMIN_NAME'] = 'Demo Admin';
            $commonEnvVars['DEFAULT_ADMIN_USERNAME'] = 'demo';
        }

        // Variables para Rallly Polls (Next.js + BetterAuth + PostgreSQL)
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/apps/web') || \Illuminate\Support\Facades\File::exists($projectPath . '/packages/database')) {
            $commonEnvVars['SECRET_PASSWORD'] = hash('sha256', $project->id);
            $commonEnvVars['BETTER_AUTH_SECRET'] = hash('sha256', $project->id . 'better_auth');
            $commonEnvVars['NEXT_PUBLIC_BASE_URL'] = $appUrl;
            $commonEnvVars['AUTH_URL'] = $appUrl;
            $commonEnvVars['NEXT_PUBLIC_SELF_HOSTED'] = 'true';
            $commonEnvVars['SUPPORT_EMAIL'] = 'support@nexus-academic.software';
        }

        // Buscar posibles ubicaciones de .env (.env en raíz, en backend, api, server, apps/web, packages/database)
        $potentialDirs = [
            $projectPath,
            $projectPath . '/backend',
            $projectPath . '/api',
            $projectPath . '/server',
            $projectPath . '/apps/web',
            $projectPath . '/packages/database',
        ];

        foreach ($potentialDirs as $dir) {
            if (!\Illuminate\Support\Facades\File::isDirectory($dir)) {
                continue;
            }

            $relDir = ltrim(str_replace($projectPath, '', $dir), '/');
            $relEnvPath = $relDir ? "{$relDir}/.env" : ".env";
            $envPath = $dir . '/.env';

            // Si no existe .env, buscar plantillas de ejemplo (.env.example, .env.sample, etc.)
            if (!\Illuminate\Support\Facades\File::exists($envPath)) {
                foreach (['/.env.example', '/.env.sample', '/.env.local', '/.env.dist'] as $sample) {
                    if (\Illuminate\Support\Facades\File::exists($dir . $sample)) {
                        \Illuminate\Support\Facades\File::copy($dir . $sample, $envPath);
                        $sampleName = basename($sample);
                        $logs .= "Archivo de entorno '{$relEnvPath}' generado a partir de '{$sampleName}'.\n";
                        break;
                    }
                }
            }

            if (!\Illuminate\Support\Facades\File::exists($envPath)) {
                \Illuminate\Support\Facades\File::put($envPath, "");
            }

            $content = \Illuminate\Support\Facades\File::get($envPath);

            // Reemplazar cualquier clave existente o agregarla si no existe en el archivo
            foreach ($commonEnvVars as $k => $v) {
                if (preg_match("/^{$k}=.*/m", $content)) {
                    $content = preg_replace("/^{$k}=.*/m", "{$k}={$v}", $content);
                } else {
                    $content .= "\n{$k}={$v}";
                }
            }

            // Asegurar APP_KEY criptográfica válida para proyectos Laravel / PHP
            if (!str_contains($content, 'APP_KEY=base64:') || str_contains($content, 'APP_KEY=SomeRandomString') || preg_match('/^APP_KEY=\s*$/m', $content)) {
                $genKey = 'base64:' . base64_encode(random_bytes(32));
                if (preg_match('/^APP_KEY=.*/m', $content)) {
                    $content = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$genKey}", $content);
                } else {
                    $content .= "\nAPP_KEY={$genKey}";
                }
            }

            // Neutralizar endpoints locales/falsos de plantillas sample que causan crash de conexión (como KV_REST_API_URL o SMTP dummy en Rallly)
            $content = preg_replace('/^KV_REST_API_URL=http:\/\/(0\.0\.0\.0|localhost).*/m', '# KV_REST_API_URL=', $content);
            $content = preg_replace('/^KV_REST_API_TOKEN=.*/m', '# KV_REST_API_TOKEN=', $content);
            $content = preg_replace('/^S3_ENDPOINT=http:\/\/(0\.0\.0\.0|localhost).*/m', '# S3_ENDPOINT=', $content);
            $content = preg_replace('/^S3_BUCKET_NAME=rallly.*/m', '# S3_BUCKET_NAME=', $content);
            $content = preg_replace('/^SMTP_HOST=(0\.0\.0\.0|localhost).*/m', '# SMTP_HOST=', $content);
            $content = preg_replace('/^SMTP_PORT=1025.*/m', '# SMTP_PORT=', $content);

            \Illuminate\Support\Facades\File::put($envPath, $content);
            $logs .= "Variables de entorno configuradas exitosamente en: {$relEnvPath}\n";
        }
        $logs .= "\n";
    }

    /**
     * Generar un snapshot SQL inicial para permitir que el evaluador pueda
     * restablecer la base de datos de cualquier proyecto a su estado de fábrica en segundos.
     */
    private function generateInitialDatabaseSnapshot(Project $project, string $projectPath): void
    {
        // Soporte para SQLite (Grocy, Rails, etc.)
        $sqliteFiles = array_merge(
            glob($projectPath . '/data/*.db') ?: [],
            glob($projectPath . '/storage/*.sqlite3') ?: [],
            glob($projectPath . '/storage/*.db') ?: [],
            glob($projectPath . '/db/*.sqlite3') ?: [],
            glob($projectPath . '/db/*/*.sqlite3') ?: [],
            glob($projectPath . '/database/*.sqlite*') ?: [],
            glob($projectPath . '/*.sqlite3') ?: [],
            glob($projectPath . '/*.sqlite') ?: []
        );
        foreach ($sqliteFiles as $sFile) {
            $base = basename($sFile);
            $snapshot = dirname($sFile) . '/.' . $base . '.snapshot';
            if (!\Illuminate\Support\Facades\File::exists($snapshot) && @filesize($sFile) > 100) {
                @copy($sFile, $snapshot);
                @chmod($snapshot, 0777);
            }
        }

        $snapshotFile = $projectPath . '/.initial_db_snapshot.sql';
        if (\Illuminate\Support\Facades\File::exists($snapshotFile) && @filesize($snapshotFile) > 2000) {
            return;
        }

        if (empty($project->db_name)) {
            return;
        }

        if ($project->db_driver === 'mysql') {
            $dumpCmd = new Process([
                'docker', 'exec', 'uleam_mysql_students',
                'mysqldump', '--no-tablespaces', '-u', 'root', '-puleam_secure_pass', $project->db_name
            ]);
            $dumpCmd->run();
            $output = $dumpCmd->getOutput();
            if ($dumpCmd->isSuccessful() && (str_contains($output, 'CREATE TABLE') || strlen($output) > 500)) {
                \Illuminate\Support\Facades\File::put($snapshotFile, $output);
            }
        } elseif ($project->db_driver === 'pgsql') {
            $dumpCmd = new Process([
                'docker', 'exec', 'uleam_postgres_students',
                'pg_dump', '-U', 'postgres', '-d', $project->db_name
            ]);
            $dumpCmd->run();
            $output = $dumpCmd->getOutput();
            if ($dumpCmd->isSuccessful() && (str_contains($output, 'CREATE TABLE') || strlen($output) > 500)) {
                \Illuminate\Support\Facades\File::put($snapshotFile, $output);
            }
        }
    }

    /**
     * Calcular el tamaño total de un directorio en bytes.
     */
    private function calculateDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        try {
            $process = new Process(['du', '-sb', $path]);
            $process->run();
            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                $parts = preg_split('/\s+/', $output);
                if (isset($parts[0]) && is_numeric($parts[0])) {
                    return (int) $parts[0];
                }
            }
        } catch (\Throwable $e) {}

        $size = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $size += $file->getSize();
            }
        } catch (\Throwable $e) {}

        return $size;
    }

    /**
     * Manejar fallos críticos del trabajo (incluyendo timeout de la cola de Laravel).
     */
    public function failed(?\Throwable $exception): void
    {
        try {
            $deployment = $this->deployment;
            $project = $deployment->project;

            $errorMsg = $exception ? $exception->getMessage() : 'Tiempo máximo de compilación excedido (Build Timeout).';
            $deployment->status = 'failed';
            $deployment->build_log .= "\n\n[ERROR CRITICO]: El proceso de compilación ha fallado o superó el tiempo máximo límite permitido (10 minutos).\nDetalle: {$errorMsg}\n";
            $deployment->save();

            if ($project) {
                $project->status = 'failed';
                $project->save();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Error handling BuildProjectJob failure: " . $e->getMessage());
        }
    }

    /**
     * Valida que el proyecto sea una aplicación web estándar y no una herramienta de orquestación de servidores/PaaS.
     */
    private function checkProjectArchitectureCompatibility(string $projectPath): ?string
    {
        $detectedCategory = null;
        $detail = null;

        // 1. Detección por dependencia o montaje del socket de Docker del host (/var/run/docker.sock)
        $composeFiles = ['docker-compose.yml', 'docker-compose.yaml', 'docker-compose.prod.yml', 'docker-compose.prod.yaml', 'docker-compose.dev.yml'];
        foreach ($composeFiles as $composeFile) {
            $filePath = $projectPath . '/' . $composeFile;
            if (\Illuminate\Support\Facades\File::exists($filePath)) {
                $composeContent = \Illuminate\Support\Facades\File::get($filePath);
                if (
                    str_contains($composeContent, '/var/run/docker.sock') ||
                    str_contains($composeContent, 'docker.sock')
                ) {
                    $detectedCategory = "Control del daemon Docker del anfitrión (/var/run/docker.sock)";
                    $detail = "El archivo {$composeFile} requiere montar el socket Docker del servidor físico anfitrión";
                    break;
                }
            }
        }

        // 2. Detección de orquestadores PaaS / gestores de infraestructura en composer.json
        $composerPath = $projectPath . '/composer.json';
        if (!$detectedCategory && \Illuminate\Support\Facades\File::exists($composerPath)) {
            $composerJson = @json_decode(\Illuminate\Support\Facades\File::get($composerPath), true);
            if (is_array($composerJson)) {
                $packageName = strtolower($composerJson['name'] ?? '');
                $description = strtolower($composerJson['description'] ?? '');

                if (
                    str_contains($packageName, 'coolify') ||
                    str_contains($packageName, 'dokku') ||
                    str_contains($packageName, 'portainer') ||
                    str_contains($packageName, 'caprover') ||
                    str_contains($description, 'self-hosted heroku') ||
                    str_contains($description, 'self-hosted paas') ||
                    str_contains($description, 'alternative to heroku') ||
                    str_contains($description, 'alternative to netlify') ||
                    str_contains($description, 'server manager')
                ) {
                    $detectedCategory = "Plataforma de orquestación PaaS / Gestor de servidores";
                    $detail = "Las dependencias en composer.json clasifican el proyecto como un gestor de infraestructura autohospedado";
                }
            }
        }

        // 3. Detección en package.json (Portainer, CapRover, Dokku, etc.)
        $packageJsonPath = $projectPath . '/package.json';
        if (!$detectedCategory && \Illuminate\Support\Facades\File::exists($packageJsonPath)) {
            $pkgJson = @json_decode(\Illuminate\Support\Facades\File::get($packageJsonPath), true);
            if (is_array($pkgJson)) {
                $pkgName = strtolower($pkgJson['name'] ?? '');
                $pkgDesc = strtolower($pkgJson['description'] ?? '');
                if (
                    str_contains($pkgName, 'portainer') ||
                    str_contains($pkgName, 'caprover') ||
                    str_contains($pkgName, 'coolify') ||
                    str_contains($pkgName, 'dokku') ||
                    str_contains($pkgDesc, 'container management') ||
                    str_contains($pkgDesc, 'docker management') ||
                    str_contains($pkgDesc, 'paas')
                ) {
                    $detectedCategory = "Herramienta de gestión de contenedores o infraestructura";
                    $detail = "El archivo package.json clasifica el proyecto como un panel de administración de contenedores";
                }
            }
        }

        // 4. Detección por firmas de controladores de despliegue de infraestructura
        if (!$detectedCategory) {
            if (
                \Illuminate\Support\Facades\File::exists($projectPath . '/.coolify-logo') ||
                \Illuminate\Support\Facades\File::exists($projectPath . '/app/Models/InstanceSettings.php') ||
                \Illuminate\Support\Facades\File::exists($projectPath . '/app/Jobs/ApplicationDeploymentJob.php') ||
                \Illuminate\Support\Facades\File::exists($projectPath . '/docker/coolify-helper') ||
                \Illuminate\Support\Facades\File::exists($projectPath . '/Sentinel')
            ) {
                $detectedCategory = "Sistema de aprovisionamiento y despliegue de infraestructura";
                $detail = "Se detectaron módulos internos para orquestación de sub-contenedores y servidores";
            }
        }

        if ($detectedCategory) {
            return "Este proyecto tiene la naturaleza de ser software de orquestación de servidores, gestión de infraestructura o plataforma PaaS/IaaS, por lo que no se puede ejecutar en Uleam Academic Code.\n\nLa plataforma está diseñada exclusivamente para alojar y desplegar aplicaciones web académicas (como páginas web, APIs, sistemas de información y proyectos de desarrollo), por lo que no es compatible con herramientas destinadas a administrar servidores o infraestructura.";
        }

        // 5. Detección de bibliotecas, paquetes de componentes y motores de framework (sin Front Controller ni aplicación web)
        $isLibraryOrCoreEngine = false;
        $libraryReason = null;

        // Verificar si existe algún archivo de inicio web estándar para servir en el navegador
        $hasWebEntrypoint = \Illuminate\Support\Facades\File::exists($projectPath . '/index.php')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/public/index.php')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/web/index.php')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/html/index.php')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/www/index.php')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/index.html')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/public/index.html')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/server.php')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/artisan')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/site.php');

        // A. PHP: paquetes de composer que son librerías o núcleos de framework sin punto de entrada web
        $composerPath = $projectPath . '/composer.json';
        if (\Illuminate\Support\Facades\File::exists($composerPath)) {
            $composerData = @json_decode(\Illuminate\Support\Facades\File::get($composerPath), true);
            if (is_array($composerData)) {
                $pkgType = strtolower($composerData['type'] ?? 'library');
                $pkgName = strtolower($composerData['name'] ?? '');

                $isFrameworkCoreName = preg_match('/^(flarum|laravel|symfony|cakephp|codeigniter4|yii2|slim|laminas|zendframework)\/(framework|core|symfony|bundle|components|base)$/i', $pkgName)
                    || \Illuminate\Support\Facades\File::exists($projectPath . '/flarum-monorepo.json');

                if (!$hasWebEntrypoint && ($isFrameworkCoreName || in_array($pkgType, ['library', 'composer-plugin', 'metapackage']))) {
                    $isLibraryOrCoreEngine = true;
                    $libraryReason = "No se encontró un archivo principal como 'index.php' o 'index.html' en la raíz o en la carpeta pública del proyecto.";
                }
            }
        }

        // B. Node.js: módulos de npm o bibliotecas sin frontend ni servidor web
        $packageJsonPath = $projectPath . '/package.json';
        if (!$isLibraryOrCoreEngine && !$hasWebEntrypoint && \Illuminate\Support\Facades\File::exists($packageJsonPath)) {
            $nodePkg = @json_decode(\Illuminate\Support\Facades\File::get($packageJsonPath), true);
            if (is_array($nodePkg)) {
                $hasScripts = isset($nodePkg['scripts']['start']) || isset($nodePkg['scripts']['dev']) || isset($nodePkg['scripts']['serve']) || isset($nodePkg['scripts']['build']);
                $hasWebHtml = \Illuminate\Support\Facades\File::exists($projectPath . '/index.html') || \Illuminate\Support\Facades\File::exists($projectPath . '/public/index.html');
                $hasServerFile = \Illuminate\Support\Facades\File::exists($projectPath . '/server.js') || \Illuminate\Support\Facades\File::exists($projectPath . '/app.js') || \Illuminate\Support\Facades\File::exists($projectPath . '/index.js') || \Illuminate\Support\Facades\File::exists($projectPath . '/src/server.ts') || \Illuminate\Support\Facades\File::exists($projectPath . '/src/index.ts');
                $deps = array_merge($nodePkg['dependencies'] ?? [], $nodePkg['devDependencies'] ?? []);
                $hasWebFramework = isset($deps['express']) || isset($deps['react']) || isset($deps['vue']) || isset($deps['next']) || isset($deps['nuxt']) || isset($deps['fastify']) || isset($deps['nest']) || isset($deps['@nestjs/core']) || isset($deps['koa']) || isset($deps['vite']);

                if (!$hasScripts && !$hasWebHtml && !$hasServerFile && !$hasWebFramework) {
                    $isLibraryOrCoreEngine = true;
                    $libraryReason = "El proyecto no contiene archivos web (index.html, server.js) ni comandos para iniciar una aplicación.";
                }
            }
        }

        // C. Python: librerías de pip (setup.py) sin punto de entrada web
        if (!$isLibraryOrCoreEngine && !$hasWebEntrypoint && (\Illuminate\Support\Facades\File::exists($projectPath . '/setup.py') || \Illuminate\Support\Facades\File::exists($projectPath . '/pyproject.toml'))) {
            $hasPythonEntry = \Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/app.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/main.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/wsgi.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/asgi.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/server.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/run.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/index.html');

            if (!$hasPythonEntry && !\Illuminate\Support\Facades\File::exists($projectPath . '/requirements.txt')) {
                $pyFiles = glob($projectPath . '/*.py');
                if (empty($pyFiles) || (count($pyFiles) === 1 && basename($pyFiles[0]) === 'setup.py')) {
                    $isLibraryOrCoreEngine = true;
                    $libraryReason = "El proyecto es una librería de código y no incluye un archivo de servidor web como app.py, main.py o manage.py.";
                }
            }
        }

        if ($isLibraryOrCoreEngine) {
            $msg = "El proyecto que intentas subir es una librería o paquete de código, no una aplicación web completa.\n\n"
                . "Detalle: {$libraryReason}\n\n"
                . "¿Por qué se detuvo el despliegue?\n"
                . "Uleam Academic Code está diseñado para ejecutar y evaluar proyectos web completos (sitios web, sistemas o APIs que se puedan abrir en el navegador).\n\n"
                . "¿Cómo solucionarlo?\n"
                . "Asegúrate de subir el proyecto web completo que incluya su archivo principal (por ejemplo, index.php, index.html o la plantilla completa de la aplicación).";
            return $msg;
        }

        return null;
    }
}

