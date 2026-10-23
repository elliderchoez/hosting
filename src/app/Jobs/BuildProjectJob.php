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
    public $timeout = 900; // 15 minutes timeout limit for compilation

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
        $domain = env('APP_DOMAIN', 'uleamcode.software');

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
            // cualquier origen *.localhost y *.uleamcode.software automáticamente,
            // eliminando la necesidad de que el estudiante configure FRONTEND_URL.
            $corsConfigPath = $projectPath . '/config/cors.php';
            if (\Illuminate\Support\Facades\File::exists($corsConfigPath)) {
                $corsContent = \Illuminate\Support\Facades\File::get($corsConfigPath);
                $needsPatch = strpos($corsContent, 'localhost') === false
                    || (strpos($corsContent, 'uleamcode') === false && strpos($corsContent, 'uleam-academic') === false);
                if ($needsPatch) {
                    $logs .= "Detectado config/cors.php en proyecto Laravel. Habilitando CORS automático para dominios de la plataforma...\n";
                    // Insertar patrones al inicio del array 'allowed_origins_patterns'
                    $corsContent = preg_replace(
                        "/('allowed_origins_patterns'\s*=>\s*\[)/",
                        "$1\n        '#^https?://.*\\.localhost\$#',\n        '#^https?://.*\\.uleamcode\\.software\$#',\n        '#^https?://.*\\.uleam-academic\\.software\$#',",
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
                    $logs .= "CORS habilitado automáticamente para *.localhost y *.uleamcode.software.\n";
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

            // Auto-generar manage.py si es un proyecto Django (o app Django empaquetada) que no lo incluye
            $isDjango = ($project->framework === 'django') 
                || \Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')
                || \Illuminate\Support\Facades\File::exists($projectPath . '/test_settings.py')
                || !empty(glob($projectPath . '/*settings*.py'));

            if ($isDjango && !\Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')) {
                $djangoSettingsModule = $this->detectDjangoSettingsModule($projectPath) ?: (\Illuminate\Support\Facades\File::exists($projectPath . '/test_settings.py') ? 'test_settings' : 'settings');
                $managePyContent = "#!/usr/bin/env python\nimport os\nimport sys\n\ndef main():\n    os.environ.setdefault('DJANGO_SETTINGS_MODULE', '{$djangoSettingsModule}')\n    try:\n        from django.core.management import execute_from_command_line\n    except ImportError as exc:\n        raise ImportError(\"Couldn't import Django.\") from exc\n    execute_from_command_line(sys.argv)\n\nif __name__ == '__main__':\n    main()\n";
                \Illuminate\Support\Facades\File::put($projectPath . '/manage.py', $managePyContent);
                @chmod($projectPath . '/manage.py', 0755);
                $logs .= "Archivo 'manage.py' generado automáticamente para el proyecto Django (configuración: {$djangoSettingsModule}).\n";
            }

            // Auto-parchear base_urls.py si existe para compatibilidad con Django 3/4 y habilitar admin
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/base_urls.py')) {
                $buContent = \Illuminate\Support\Facades\File::get($projectPath . '/base_urls.py');
                if (str_contains($buContent, "django.get_version()[0] == '2'")) {
                    $buContent = str_replace(
                        "if django.get_version()[0] == '2':\n    from django.urls import include, path\nelse:\n    from django.conf.urls import include, url as path",
                        "try:\n    from django.urls import include, path\nexcept ImportError:\n    from django.conf.urls import include, url as path",
                        $buContent
                    );
                }
                if (!str_contains($buContent, "admin.site.urls")) {
                    $buContent = "from django.contrib import admin\nfrom django.views.generic import RedirectView\n" . $buContent;
                    $buContent = str_replace(
                        "urlpatterns = [",
                        "urlpatterns = [\n    path('admin/', admin.site.urls),\n    path('', RedirectView.as_view(url='/invoices/', permanent=False)),",
                        $buContent
                    );
                }
                \Illuminate\Support\Facades\File::put($projectPath . '/base_urls.py', $buContent);
            }

            // Auto-parchear base.html de faktura si existe para corregir CDN obsoleto de Bootstrap
            $fakturaBaseHtml = $projectPath . '/faktura/templates/faktura/base.html';
            if (\Illuminate\Support\Facades\File::exists($fakturaBaseHtml)) {
                $baseHtml = \Illuminate\Support\Facades\File::get($fakturaBaseHtml);
                if (str_contains($baseHtml, 'stackpath.bootstrapcdn.com')) {
                    $baseHtml = str_replace(
                        '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/css/bootstrap.min.css" integrity="sha384-GJzZqFGwb1QTTN6wy59ffF1BuGJpLSa9DkKMp0DgiMDm4iYMj70gZWKYbI706tWS" crossorigin="anonymous">',
                        '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">',
                        $baseHtml
                    );
                    $baseHtml = str_replace(
                        '<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>',
                        '<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script><script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>',
                        $baseHtml
                    );
                    $baseHtml = str_replace(
                        '<body',
                        '<body class="bg-light"',
                        $baseHtml
                    );
                    \Illuminate\Support\Facades\File::put($fakturaBaseHtml, $baseHtml);
                }
            }

            // Parche de compatibilidad para weasyprint en entornos Docker slim
            $pdfCandidates = [
                $projectPath . '/invoices/pdf.py',
                $projectPath . '/backend/invoices/pdf.py',
            ];
            foreach ($pdfCandidates as $pdfFile) {
                if (\Illuminate\Support\Facades\File::exists($pdfFile)) {
                    $pdfContent = \Illuminate\Support\Facades\File::get($pdfFile);
                    if (str_contains($pdfContent, 'except ImportError:')) {
                        $pdfContent = str_replace('except ImportError:', 'except (ImportError, OSError):', $pdfContent);
                        \Illuminate\Support\Facades\File::put($pdfFile, $pdfContent);
                    }
                }
            }

            // Parche de compatibilidad para Django-CRM: Redirección de / a /admin/ y exención en rls_context
            $crmUrls = [
                $projectPath . '/crm/urls.py',
                $projectPath . '/backend/crm/urls.py',
            ];
            foreach ($crmUrls as $urlsFile) {
                if (\Illuminate\Support\Facades\File::exists($urlsFile)) {
                    $urlsContent = \Illuminate\Support\Facades\File::get($urlsFile);
                    if (!str_contains($urlsContent, 'url="/admin/"')) {
                        $urlsContent = str_replace('from django.views.generic import TemplateView', "from django.views.generic import RedirectView, TemplateView", $urlsContent);
                        $urlsContent = str_replace('urlpatterns = [', "urlpatterns = [\n    path(\"\", RedirectView.as_view(url=\"/admin/\", permanent=False)),", $urlsContent);
                        \Illuminate\Support\Facades\File::put($urlsFile, $urlsContent);
                    }
                }
            }

            $rlsFiles = [
                $projectPath . '/common/middleware/rls_context.py',
                $projectPath . '/backend/common/middleware/rls_context.py',
            ];
            foreach ($rlsFiles as $rlsFile) {
                if (\Illuminate\Support\Facades\File::exists($rlsFile)) {
                    $rlsContent = \Illuminate\Support\Facades\File::get($rlsFile);
                    if (!str_contains($rlsContent, '"/",')) {
                        $rlsContent = str_replace("EXEMPT_EXACT_PATHS = [\n", "EXEMPT_EXACT_PATHS = [\n        \"/\",\n", $rlsContent);
                        \Illuminate\Support\Facades\File::put($rlsFile, $rlsContent);
                    }
                }
            }

            // Auto-parchear settings.py de proyectos Django para permitir dominios de la plataforma (ALLOWED_HOSTS, CSRF y frames)
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')) {
                $djangoSettingsModule = $this->detectDjangoSettingsModule($projectPath);
                if ($djangoSettingsModule) {
                    $envPath = $projectPath . '/.env';
                    if (\Illuminate\Support\Facades\File::exists($envPath)) {
                        $envContent = \Illuminate\Support\Facades\File::get($envPath);
                        if (!str_contains($envContent, 'DJANGO_SETTINGS_MODULE=')) {
                            \Illuminate\Support\Facades\File::append($envPath, "\nDJANGO_SETTINGS_MODULE={$djangoSettingsModule}\n");
                        }
                    }
                }

                $managePyPath = $projectPath . '/manage.py';
                $pymysqlShim = "\ntry:\n" .
                    "    import pymysql\n" .
                    "    pymysql.version_info = (2, 2, 2, 'final', 0)\n" .
                    "    pymysql.install_as_MySQLdb()\n" .
                    "except Exception:\n" .
                    "    pass\n" .
                    "try:\n" .
                    "    from django.db.backends.base.base import BaseDatabaseWrapper\n" .
                    "    BaseDatabaseWrapper.check_database_version_supported = lambda self: None\n" .
                    "except Exception:\n" .
                    "    pass\n";

                if (\Illuminate\Support\Facades\File::exists($managePyPath)) {
                    $managePyContent = \Illuminate\Support\Facades\File::get($managePyPath);
                    if (!str_contains($managePyContent, 'pymysql.install_as_MySQLdb()')) {
                        \Illuminate\Support\Facades\File::put($managePyPath, $pymysqlShim . $managePyContent);
                    }
                }

                $wsgiFiles = array_merge(
                    glob($projectPath . '/*/wsgi.py') ?: [],
                    glob($projectPath . '/*/*/wsgi.py') ?: [],
                    glob($projectPath . '/wsgi.py') ?: []
                );
                foreach ($wsgiFiles as $wsgiFile) {
                    $wsgiContent = \Illuminate\Support\Facades\File::get($wsgiFile);
                    if (!str_contains($wsgiContent, 'pymysql.install_as_MySQLdb()')) {
                        \Illuminate\Support\Facades\File::put($wsgiFile, $pymysqlShim . $wsgiContent);
                    }
                }

                $djangoSettingsFiles = array_merge(
                    glob($projectPath . '/*/settings.py') ?: [],
                    glob($projectPath . '/*/*/settings.py') ?: [],
                    glob($projectPath . '/*/settings/*.py') ?: [],
                    glob($projectPath . '/settings.py') ?: [],
                    glob($projectPath . '/*settings*.py') ?: []
                );

                $patchPayload = "\n\n# --- Configuracion Automatica ULEAM Academic ---\n" .
                    "import os\n" .
                    "ALLOWED_HOSTS = ['*']\n" .
                    "CSRF_TRUSTED_ORIGINS = ['http://*.localhost', 'http://127.0.0.1', 'https://*.uleamcode.software', 'http://*.uleamcode.software']\n" .
                    "X_FRAME_OPTIONS = 'ALLOWALL'\n" .
                    "if isinstance(locals().get('SECRET_KEY'), (list, tuple)) or not locals().get('SECRET_KEY') or locals().get('SECRET_KEY') == '':\n" .
                    "    SECRET_KEY = os.environ.get('SECRET_KEY') or 'uleam_secret_key_academic_platform_77483'\n" .
                    "if 'AUTHENTICATION_BACKENDS' in locals():\n" .
                    "    AUTHENTICATION_BACKENDS = [b for b in AUTHENTICATION_BACKENDS if 'axes' not in b.lower()]\n" .
                    "    if not AUTHENTICATION_BACKENDS:\n" .
                    "        AUTHENTICATION_BACKENDS = ['django.contrib.auth.backends.ModelBackend']\n" .
                    "else:\n" .
                    "    AUTHENTICATION_BACKENDS = ['django.contrib.auth.backends.ModelBackend']\n" .
                    "if 'MIDDLEWARE' in locals():\n" .
                    "    MIDDLEWARE = [m for m in MIDDLEWARE if 'axes' not in m.lower()]\n" .
                    "    if 'whitenoise.middleware.WhiteNoiseMiddleware' not in MIDDLEWARE:\n" .
                    "        _sec_idx = -1\n" .
                    "        for _i, _m in enumerate(MIDDLEWARE):\n" .
                    "            if 'SecurityMiddleware' in _m:\n" .
                    "                _sec_idx = _i\n" .
                    "                break\n" .
                    "        if _sec_idx != -1:\n" .
                    "            MIDDLEWARE.insert(_sec_idx + 1, 'whitenoise.middleware.WhiteNoiseMiddleware')\n" .
                    "        else:\n" .
                    "            MIDDLEWARE.insert(0, 'whitenoise.middleware.WhiteNoiseMiddleware')\n" .
                    "AXES_ENABLED = False\n" .
                    "WHITENOISE_MANIFEST_STRICT = False\n" .
                    "try:\n" .
                    "    import pymysql\n" .
                    "    pymysql.version_info = (2, 2, 2, 'final', 0)\n" .
                    "    pymysql.install_as_MySQLdb()\n" .
                    "except Exception:\n" .
                    "    pass\n" .
                    "try:\n" .
                    "    from django.db.backends.base.base import BaseDatabaseWrapper\n" .
                    "    BaseDatabaseWrapper.check_database_version_supported = lambda self: None\n" .
                    "except Exception:\n" .
                    "    pass\n" .
                    "if os.environ.get('DB_HOST') or os.environ.get('DATABASE_URL'):\n" .
                    "    _driver = os.environ.get('DB_CONNECTION', 'mysql')\n" .
                    "    _engine = 'django.db.backends.mysql' if _driver == 'mysql' else 'django.db.backends.postgresql'\n" .
                    "    DATABASES = {\n" .
                    "        'default': {\n" .
                    "            'ENGINE': _engine,\n" .
                    "            'NAME': os.environ.get('DB_DATABASE') or os.environ.get('DB_NAME') or 'uleam_db',\n" .
                    "            'USER': os.environ.get('DB_USERNAME') or os.environ.get('DB_USER') or 'root',\n" .
                    "            'PASSWORD': os.environ.get('DB_PASSWORD') or os.environ.get('DB_PASS') or '',\n" .
                    "            'HOST': os.environ.get('DB_HOST') or 'localhost',\n" .
                    "            'PORT': str(os.environ.get('DB_PORT') or ('3306' if _driver == 'mysql' else '5432')),\n" .
                    "        }\n" .
                    "    }\n" .
                    "try:\n" .
                    "    if 'STATIC_ROOT' not in locals() or not locals().get('STATIC_ROOT'):\n" .
                    "        STATIC_ROOT = os.path.join(str(BASE_DIR), 'static') if 'BASE_DIR' in locals() else '/app/static'\n" .
                    "except Exception:\n" .
                    "    STATIC_ROOT = '/app/static'\n" .
                    "try:\n" .
                    "    if 'STORAGES' in locals() and 'staticfiles' in STORAGES:\n" .
                    "        STORAGES['staticfiles']['BACKEND'] = 'whitenoise.storage.CompressedStaticFilesStorage'\n" .
                    "    STATICFILES_STORAGE = 'whitenoise.storage.CompressedStaticFilesStorage'\n" .
                    "except Exception:\n" .
                    "    pass\n" .
                    "if 'STATIC_URL' not in locals() or not locals().get('STATIC_URL'):\n" .
                    "    STATIC_URL = '/static/'\n" .
                    "if 'SITE_ID' not in locals() or not locals().get('SITE_ID'):\n" .
                    "    SITE_ID = 1\n" .
                    "if 'INSTALLED_APPS' in locals():\n" .
                    "    if isinstance(INSTALLED_APPS, tuple):\n" .
                    "        INSTALLED_APPS = list(INSTALLED_APPS)\n" .
                    "    if 'django.contrib.staticfiles' not in INSTALLED_APPS:\n" .
                    "        INSTALLED_APPS.append('django.contrib.staticfiles')\n" .
                    "if 'LOGIN_REDIRECT_URL' not in locals() or not locals().get('LOGIN_REDIRECT_URL'):\n" .
                    "    LOGIN_REDIRECT_URL = '/invoices/'\n" .
                    "if 'LOGIN_URL' not in locals() or not locals().get('LOGIN_URL'):\n" .
                    "    LOGIN_URL = '/admin/login/'\n" .
                    "if 'DEFAULT_AUTO_FIELD' not in locals() or not locals().get('DEFAULT_AUTO_FIELD'):\n" .
                    "    DEFAULT_AUTO_FIELD = 'django.db.models.BigAutoField'\n";

                $patchedCount = 0;
                foreach ($djangoSettingsFiles as $settingsFile) {
                    if (str_contains($settingsFile, '/.venv/')) continue;
                    $content = @file_get_contents($settingsFile);
                    if ($content && !str_contains($content, 'Configuracion Automatica ULEAM Academic')) {
                        @file_put_contents($settingsFile, $content . $patchPayload);
                        $patchedCount++;
                    }
                }
                if ($patchedCount > 0) {
                    $logs .= "Configuración de Django optimizada (ALLOWED_HOSTS, CSRF y visualizador web habilitados en {$patchedCount} archivo/s de settings).\n";
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

            // Soporte predeterminado para OnlineBookStore (Java Servlets + MySQL)
            if (str_contains(strtolower($project->name), 'onlinebookstore') || str_contains(strtolower($project->github_repo_url), 'onlinebookstore') || \Illuminate\Support\Facades\File::exists($projectPath . '/WebContent/login.html')) {
                $project->demo_instructions = "Acceso predeterminado para pruebas (OnlineBookStore):\n"
                    . "1. Usuario Cliente:\n"
                    . "   Usuario: shashi\n"
                    . "   Clave: shashi\n\n"
                    . "2. Usuario Administrador:\n"
                    . "   Usuario: Admin\n"
                    . "   Clave: Admin\n\n"
                    . "Librería online en Java Servlets con catálogo de libros, gestión de inventario y pedidos.";
                $project->save();
            }

            // Soporte predeterminado para Pharmacy Management App (Spring Boot + MySQL)
            if (str_contains(strtolower($project->name), 'pharmacy') || str_contains(strtolower($project->github_repo_url), 'pharmacy-management') || \Illuminate\Support\Facades\File::exists($projectPath . '/HeThongBanThuoc_DB.sql')) {
                $project->demo_instructions = "API Backend Spring Boot (Gestión de Farmacia y Medicamentos):\n"
                    . "Rutas públicas disponibles para prueba:\n"
                    . "- Catálogo de Productos/Medicamentos: http://{$project->subdomain}.localhost/api/sanpham/all\n"
                    . "- Medicamentos más vendidos: http://{$project->subdomain}.localhost/api/sanpham/best-sellers\n"
                    . "- Banners de farmacia: http://{$project->subdomain}.localhost/api/banner/all\n"
                    . "- Autenticación / Registro de usuarios: POST /api/auth/register y POST /api/auth/login\n\n"
                    . "La base de datos MySQL incluye catálogo precargado de productos, categorías y promociones.";
                $project->save();
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

            // Auto-inyección universal de usuario administrador si el proyecto no tiene .sql o la tabla de usuarios está vacía
            if (!empty($project->db_name)) {
                $this->ensureDefaultAdminUserInDatabase($project, $projectPath, $logs);
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

            // Monorepos: Si existen múltiples componentes de proyecto (ej: frontend y backend, client y api/server):
            // Si el backend es Django (Python) y se despliega como servicio individual (sin suite fullstack),
            // seleccionar el backend Django para que el servidor API y panel de administración funcionen directamente.
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/backend/manage.py') && 
                !\Illuminate\Support\Facades\File::exists($projectPath . '/manage.py') && 
                empty($project->root_dir) && 
                !$project->is_backend_service && 
                !$project->backend_project_id
            ) {
                $bestPath = $projectPath . '/backend';
                $relativeBestPath = 'backend';
                $log = "Monorepo con backend Django detectado. Seleccionando subdirectorio de backend: 'backend'\n";
            } else {
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
        
        // Soporte nativo para SQLite
        if ($driver === 'sqlite' || $driver === 'sqlite3') {
            $project->db_driver = 'sqlite';
            $project->db_name = null;
            $project->db_user = null;
            $project->db_password = null;
            $project->save();

            $logs .= "Configurando base de datos local SQLite para el proyecto...\n";
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/storage', 0777);
            @chmod($projectPath . '/storage', 0777);
            \Illuminate\Support\Facades\File::ensureDirectoryExists($projectPath . '/db', 0777);
            @chmod($projectPath . '/db', 0777);
            $logs .= "Base de datos SQLite local lista para el proyecto.\n";
            return;
        }

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
            if ($driver === 'mongodb') {
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

        // Extraer esquemas y semillas SQL documentadas en archivos markdown (*database*.md, *dummy*.md, etc.)
        $mdFiles = $this->getProjectScanFiles($projectPath, '*.md');
        foreach ($mdFiles as $mdFile) {
            $mdName = strtolower($mdFile->getFilename());
            if (str_contains($mdName, 'database') || str_contains($mdName, 'dummy') || str_contains($mdName, 'schema')) {
                $mdContent = @file_get_contents($mdFile->getRealPath());
                if ($mdContent && (stripos($mdContent, 'create table') !== false || stripos($mdContent, 'insert into') !== false)) {
                    $sqlLines = [];
                    foreach (explode("\n", $mdContent) as $line) {
                        $trimmed = trim($line);
                        if (empty($trimmed) || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '```') || str_starts_with($trimmed, '-')) {
                            continue;
                        }
                        $sqlLines[] = $line;
                    }
                    if (!empty($sqlLines)) {
                        $extractedName = '_' . pathinfo($mdFile->getFilename(), PATHINFO_FILENAME) . '_extracted.sql';
                        \Illuminate\Support\Facades\File::put($projectPath . '/' . $extractedName, implode("\n", $sqlLines));
                    }
                }
            }
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

            $rawSql = @file_get_contents($sqlFile->getRealPath());
            if ($rawSql === false) {
                continue;
            }

            // Sanitizar sentencias problemáticas que intentan cambiar de base de datos o desconectarse
            $sanitizedSql = preg_replace('/^\s*CREATE\s+DATABASE\s+[^;]+;/mi', '-- [SANITIZED CREATE DATABASE]', $rawSql);
            $sanitizedSql = preg_replace('/^\s*USE\s+[`"\'\w\d_]+;/mi', '-- [SANITIZED USE DATABASE]', $sanitizedSql);
            $sanitizedSql = preg_replace('/^\s*\\\\(c|connect)\s+.*$/m', '-- [SANITIZED CONNECT]', $sanitizedSql);
            $sanitizedSql = preg_replace('/^\s*SELECT\s+.*?;/mi', '-- [SANITIZED SELECT]', $sanitizedSql);

            if ($project->db_driver === 'mysql') {
                $sanitizedSql = "SET FOREIGN_KEY_CHECKS = 0;\n" . $sanitizedSql . "\nSET FOREIGN_KEY_CHECKS = 1;\n";
            }

            $tempSanitizedName = '.temp_import_' . md5($relPath) . '.sql';
            \Illuminate\Support\Facades\File::put($projectPath . '/' . $tempSanitizedName, $sanitizedSql);
            
            if ($project->db_driver === 'mysql') {
                // Import into MySQL
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'mysql:8.0',
                    'sh', '-c',
                    "mysql --force -h uleam_mysql_students -u '{$project->db_user}' -p'{$project->db_password}' {$project->db_name} < /workspace/{$tempSanitizedName}"
                ];
            } else {
                // Import into PostgreSQL
                $command = [
                    'docker', 'run', '--rm',
                    '--network', 'uleam_academic_network',
                    '-v', "{$projectPath}:/workspace:ro",
                    'postgres:15-alpine',
                    'sh', '-c',
                    "PGPASSWORD='{$project->db_password}' psql -h uleam_postgres_students -U '{$project->db_user}' -d '{$project->db_name}' -f /workspace/{$tempSanitizedName}"
                ];
            }

            $process = new Process($command);
            $process->setTimeout(90);
            $process->run();

            @unlink($projectPath . '/' . $tempSanitizedName);

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
        $baseDomain = env('APP_DOMAIN', 'uleamcode.software');
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
from django.contrib.auth import get_user_model
User = get_user_model()
try:
    try:
        from django.core.management import call_command
        call_command('create_default_admin')
    except Exception:
        pass
    for u in User.objects.all():
        u.set_password('password')
        u.is_active = True
        u.is_staff = True
        u.is_superuser = True
        u.save()
    username_field = getattr(User, 'USERNAME_FIELD', 'username')
    if username_field == 'email':
        admin_user = User.objects.filter(email='admin@localhost').first() or User.objects.filter(email='admin@example.com').first() or User.objects.filter(is_superuser=True).first()
        if not admin_user:
            try:
                admin_user = User.objects.create_superuser(email='admin@localhost', password='password')
            except Exception:
                try:
                    admin_user = User.objects.create_superuser('admin@localhost', 'password')
                except Exception:
                    pass
        if admin_user:
            admin_user.set_password('password')
            admin_user.save()
            print(f'AUTH_USER:{admin_user.email}')
        else:
            print('AUTH_USER:admin@localhost')
    else:
        if not User.objects.filter(username='admin').exists():
            User.objects.create_superuser('admin', 'admin@example.com', 'password')
        admin_user = User.objects.filter(username='admin').first()
        if admin_user:
            admin_user.set_password('password')
            admin_user.save()
        print('AUTH_USER:admin')
    if admin_user:
        if not admin_user.email:
            admin_user.email = 'admin@gmail.com'
        if hasattr(admin_user, 'user_type') and str(admin_user.user_type) != '1':
            admin_user.user_type = '1'
        admin_user.save()
    try:
        from django.apps import apps
        if apps.is_installed('employee'):
            EmpModel = apps.get_model('employee', 'Employee')
            if admin_user and not EmpModel.objects.filter(employee_user_id=admin_user).exists():
                EmpModel.objects.create(
                    employee_user_id=admin_user,
                    badge_id='EMP001',
                    employee_first_name='Admin',
                    employee_last_name='User',
                    email='admin@example.com',
                    is_active=True
                )
    except Exception:
        pass
    try:
        from django.apps import apps
        if apps.is_installed('faktura'):
            from faktura.models import Invoice, Item
            import datetime
            if admin_user and not Invoice.objects.exists():
                inv1 = Invoice.objects.create(
                    author=admin_user,
                    seller='Acme Corporation S.A.',
                    seller_details='RUC: 1790012345001\nAv. Principal 123, Manta, Ecuador\nEmail: billing@acme.com',
                    buyer='Universidad Laica Eloy Alfaro de Manabi (ULEAM)',
                    buyer_details='RUC: 1360000840001\nCircunvalacion Via San Mateo\nManta, Manabi',
                    status=Invoice.FINAL,
                    type=Invoice.INVOICE,
                    includes_vat=True,
                    vat=15.0,
                    currency='USD',
                    date_of_issue=datetime.date.today(),
                    due_date=datetime.date.today() + datetime.timedelta(days=15),
                    note='Factura por servicios de desarrollo y plataforma academica'
                )
                Item.objects.create(invoice=inv1, title='Licencia de Plataforma Cloud Sandbox', price=250.0, quantity=1)
                Item.objects.create(invoice=inv1, title='Soporte Tecnico Especializado 24/7', price=75.0, quantity=2)
    except Exception:
        pass
    try:
        from django.apps import apps
        if apps.is_installed('axes'):
            from axes.utils import reset
            reset()
    except Exception:
        pass
except Exception as e:
    print('ADMIN SETUP ERROR:', e)
PY;
            $djangoSettingsModule = $this->detectDjangoSettingsModule($projectPath);
            $djangoSettingsEnv = $djangoSettingsModule ? ['-e', "DJANGO_SETTINGS_MODULE={$djangoSettingsModule}"] : [];
            $settingsFlag = $djangoSettingsModule ? " --settings={$djangoSettingsModule}" : "";

            \Illuminate\Support\Facades\File::put($projectPath . '/.uleam_setup_admin.py', $djangoScript);

            $isSqlite = ($project->db_driver === 'sqlite' || $project->db_driver === 'sqlite3');
            $dbEnv = $isSqlite ? [] : [
                '-e', "DB_CONNECTION={$project->db_driver}",
                '-e', "DB_HOST={$dbHost}",
                '-e', "DB_PORT={$dbPort}",
                '-e', "DB_DATABASE={$project->db_name}",
                '-e', "DB_USERNAME={$project->db_user}",
                '-e', "DB_PASSWORD={$project->db_password}",
                '-e', "DBHOST={$dbHost}",
                '-e', "DBPORT={$dbPort}",
                '-e', "DBNAME={$project->db_name}",
                '-e', "DBUSER={$project->db_user}",
                '-e', "DBPASSWORD={$project->db_password}",
                '-e', "ENV_TYPE=dev",
                '-e', "DEBUG=True",
                '-e', "DATABASE_URL=" . ($project->db_driver === 'mysql' ? 'mysql' : 'postgres') . "://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}",
            ];

            $command = array_merge([
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "SECRET_KEY=uleam_secret_key_" . md5($project->id),
            ], $dbEnv, $djangoSettingsEnv, [
                'python:3.12-slim',
                'sh', '-c', ".venv/bin/python manage.py migrate{$settingsFlag} || true; .venv/bin/python manage.py collectstatic --noinput{$settingsFlag} || true; .venv/bin/python manage.py shell{$settingsFlag} < .uleam_setup_admin.py || true; rm -f .uleam_setup_admin.py"
            ]);
            $this->executeMigrationCommand($command, $logs);
            @\Illuminate\Support\Facades\File::delete($projectPath . '/.uleam_setup_admin.py');

            if (preg_match('/AUTH_USER:([^\r\n]+)/', $logs, $authMatches)) {
                $authEmail = trim($authMatches[1]);
                $isEmail = str_contains($authEmail, '@');
                $userLabel = $isEmail ? 'Correo' : 'Nombre de usuario';
                $project->demo_instructions = "Acceso predeterminado para pruebas (Django Admin / Login):\n{$userLabel}: {$authEmail}\nClave: password (o admin)\n\nEl sistema incluye base de datos y superusuario listos para evaluar.";
                $project->save();
            } elseif (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                $project->demo_instructions = "Acceso predeterminado para pruebas (Django Admin / Login):\nUsuario / Email: admin@localhost (o admin)\nClave: password (o admin)\n\nEl sistema incluye base de datos y superusuario listos para evaluar.";
                $project->save();
            }

            $this->generateInitialDatabaseSnapshot($project, $projectPath);
            return;
        }

        // 2.1 Flask (Python / Flask-Migrate)
        if (\Illuminate\Support\Facades\File::isDirectory($projectPath . '/migrations') && 
            (\Illuminate\Support\Facades\File::exists($projectPath . '/.flaskenv') || 
             \Illuminate\Support\Facades\File::exists($projectPath . '/microblog.py') || 
             $project->framework === 'flask')
        ) {
            $logs .= "--- PASO 3.6: Framework Flask con Flask-Migrate Detectado: Ejecutando Migraciones (flask db upgrade) ---\n";
            $flaskApp = \Illuminate\Support\Facades\File::exists($projectPath . '/microblog.py') ? 'microblog.py' : 'app.py';
            $dbDriverName = ($project->db_driver === 'mysql' ? 'mysql' : 'postgresql');
            $dbUrl = "{$dbDriverName}://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}";
            $flaskMigrateCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "FLASK_APP={$flaskApp}",
                '-e', "DATABASE_URL={$dbUrl}",
                '-e', "SECRET_KEY=uleam_secret_key_" . md5($project->id),
                'python:3.12-slim',
                'sh', '-c', '.venv/bin/flask db upgrade || true'
            ];
            $this->executeMigrationCommand($flaskMigrateCmd, $logs);

            // Auto-crear usuario evaluador en microblog si aplica
            $userSetupScript = <<<'PY'
try:
    from app import create_app, db
    from app.models import User
    app = create_app()
    with app.app_context():
        u = db.session.scalar(db.select(User).filter_by(username='admin'))
        if not u:
            u = User(username='admin', email='admin@example.com')
            u.set_password('password')
            db.session.add(u)
            db.session.commit()
            print('AUTH_USER:admin')
        else:
            u.set_password('password')
            db.session.commit()
            print('AUTH_USER:admin')
except Exception as e:
    print('USER SETUP NOTICE:', e)
PY;
            \Illuminate\Support\Facades\File::put($projectPath . '/.uleam_setup_flask.py', $userSetupScript);
            $userCmd = [
                'docker', 'run', '--rm',
                '--network', 'uleam_academic_network',
                '-v', "{$projectPath}:/app",
                '-w', '/app',
                '-e', "FLASK_APP={$flaskApp}",
                '-e', "DATABASE_URL={$dbUrl}",
                '-e', "SECRET_KEY=uleam_secret_key_" . md5($project->id),
                'python:3.12-slim',
                'sh', '-c', '.venv/bin/python .uleam_setup_flask.py || true; rm -f .uleam_setup_flask.py'
            ];
            $this->executeMigrationCommand($userCmd, $logs);
            @\Illuminate\Support\Facades\File::delete($projectPath . '/.uleam_setup_flask.py');

            if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                $project->demo_instructions = "Acceso predeterminado para pruebas (Microblog Flask):\nUsuario: admin\nClave: password\n\nEl sistema incluye base de datos PostgreSQL, migraciones y usuario de prueba listos para evaluar.";
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
    private function executeMigrationCommand(array $command, &$logs, int $timeout = 850): void
    {
        $tempContainerName = null;
        if (isset($command[0]) && $command[0] === 'docker' && isset($command[1]) && $command[1] === 'run') {
            $tempContainerName = 'mig_' . uniqid();
            array_splice($command, 2, 0, ['--name', $tempContainerName]);
        }

        $process = new Process($command);
        $process->setTimeout($timeout);
        
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            if ($tempContainerName) {
                (new Process(['docker', 'rm', '-f', $tempContainerName]))->run();
            }
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
        if (stripos($content, 'AUTO_INCREMENT') !== false) {
            return true;
        }
        if (stripos($content, 'CREATE DATABASE if not exists') !== false || stripos($content, 'CREATE DATABASE IF NOT EXISTS') !== false) {
            return true;
        }
        if (preg_match('/^\s*USE\s+[`"\'\w\d_]+;/mi', $content)) {
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

        // 4c. Check Django settings.py
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/manage.py') || \Illuminate\Support\Facades\File::exists($projectPath . '/backend/manage.py')) {
            $settingsFiles = array_merge(
                glob($projectPath . '/*/settings.py') ?: [],
                glob($projectPath . '/*/*/settings.py') ?: [],
                glob($projectPath . '/settings.py') ?: [],
                glob($projectPath . '/backend/*/settings.py') ?: [],
                glob($projectPath . '/backend/*/*/settings.py') ?: []
            );
            foreach ($settingsFiles as $sf) {
                $sContent = @file_get_contents($sf);
                if ($sContent) {
                    if (str_contains($sContent, 'django.db.backends.mysql')) {
                        return 'mysql';
                    }
                    if (str_contains($sContent, 'django.db.backends.postgresql') || str_contains($sContent, 'django_postgrespool')) {
                        return 'pgsql';
                    }
                    if (str_contains($sContent, 'django.db.backends.sqlite3')) {
                        return 'sqlite';
                    }
                }
            }
        }

        // 4d. Check Java application.properties / application.yml
        $javaConfigFiles = $this->getProjectScanFiles($projectPath, 'application.*');
        foreach ($javaConfigFiles as $jFile) {
            $jContent = @file_get_contents($jFile->getRealPath());
            if ($jContent) {
                if (stripos($jContent, 'mysql') !== false) {
                    return 'mysql';
                }
                if (stripos($jContent, 'postgresql') !== false || stripos($jContent, 'postgres') !== false) {
                    return 'pgsql';
                }
                if (stripos($jContent, 'mongodb') !== false) {
                    return 'mongodb';
                }
                if (stripos($jContent, 'sqlite') !== false) {
                    return 'sqlite';
                }
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
        $baseDomain = env('APP_DOMAIN', 'uleamcode.software');
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
            'DBHOST' => $dbHost,
            'DBPORT' => $dbPort,
            'DBNAME' => $project->db_name,
            'DBUSER' => $project->db_user,
            'DBPASSWORD' => $project->db_password,
            'ENV_TYPE' => 'dev',
            'DEBUG' => 'True',
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
            'APP_TRUSTED_HOSTS' => "{$projectHost},localhost,127.0.0.1,{$project->subdomain}.localhost,{$project->subdomain}.uleamcode.software",
            'ALLOWED_HOSTS' => "*",
            'CSRF_TRUSTED_ORIGINS' => "https://{$project->subdomain}.{$baseDomain},http://{$project->subdomain}.{$baseDomain},http://{$project->subdomain}.localhost,http://localhost:5000,http://127.0.0.1:5000",
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

        // Detección de archivo de inicio para aplicaciones Flask / Python
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/microblog.py')) {
            $commonEnvVars['FLASK_APP'] = 'microblog.py';
        } elseif (\Illuminate\Support\Facades\File::exists($projectPath . '/app.py')) {
            $commonEnvVars['FLASK_APP'] = 'app.py';
        } elseif (\Illuminate\Support\Facades\File::exists($projectPath . '/wsgi.py')) {
            $commonEnvVars['FLASK_APP'] = 'wsgi.py';
        }

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
            $commonEnvVars['SUPPORT_EMAIL'] = 'support@uleamcode.software';
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

        // Sincronizar archivos de configuración de bases de datos para proyectos Java
        if ($project->language === 'java') {
            $javaPropFiles = $this->getProjectScanFiles($projectPath, 'application.*');
            foreach ($javaPropFiles as $jFile) {
                $jPath = $jFile->getRealPath();
                $jContent = @file_get_contents($jPath);
                if (!$jContent) continue;

                $relJPath = ltrim(str_replace($projectPath, '', $jPath), '/');
                $jdbcDriver = $project->db_driver === 'mysql' ? 'com.mysql.cj.jdbc.Driver' : 'org.postgresql.Driver';
                $jdbcScheme = ($project->db_driver === 'pgsql') ? 'postgresql' : $project->db_driver;
                $jdbcParams = ($project->db_driver === 'mysql') ? '?useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=UTC' : '';
                $jdbcUrl = "jdbc:{$jdbcScheme}://{$dbHost}:{$dbPort}/{$project->db_name}{$jdbcParams}";

                if (str_ends_with($jPath, '.properties')) {
                    // Soporte para apps Java personalizadas (OnlineBookStore, etc.)
                    $jContent = preg_replace('/^db\.driver\s*=.*/m', "db.driver={$jdbcDriver}", $jContent);
                    $jContent = preg_replace('/^db\.host\s*=.*/m', "db.host=jdbc:{$jdbcScheme}://{$dbHost}", $jContent);
                    $jContent = preg_replace('/^db\.port\s*=.*/m', "db.port={$dbPort}", $jContent);
                    $jContent = preg_replace('/^db\.name\s*=.*/m', "db.name={$project->db_name}", $jContent);
                    $jContent = preg_replace('/^db\.username\s*=.*/m', "db.username={$project->db_user}", $jContent);
                    $jContent = preg_replace('/^db\.password\s*=.*/m', "db.password={$project->db_password}", $jContent);

                    // Soporte estándar Spring Boot
                    $jContent = preg_replace('/^spring\.datasource\.url\s*=.*/m', "spring.datasource.url={$jdbcUrl}", $jContent);
                    $jContent = preg_replace('/^spring\.datasource\.username\s*=.*/m', "spring.datasource.username={$project->db_user}", $jContent);
                    $jContent = preg_replace('/^spring\.datasource\.password\s*=.*/m', "spring.datasource.password={$project->db_password}", $jContent);
                    if (preg_match('/^spring\.datasource\.(driver-class-name|driverClassName)\s*=.*/m', $jContent)) {
                        $jContent = preg_replace('/^spring\.datasource\.(driver-class-name|driverClassName)\s*=.*/m', "spring.datasource.driver-class-name={$jdbcDriver}", $jContent);
                    } else {
                        $jContent .= "\nspring.datasource.driver-class-name={$jdbcDriver}\n";
                    }

                    // Normalizar puerto de Spring Boot para que enlace siempre con Traefik en el puerto 8080
                    if (preg_match('/^server\.port\s*=.*/m', $jContent)) {
                        $jContent = preg_replace('/^server\.port\s*=.*/m', 'server.port=${PORT:8080}', $jContent);
                    } else {
                        $jContent .= "\nserver.port=\${PORT:8080}\n";
                    }

                    \Illuminate\Support\Facades\File::put($jPath, $jContent);
                    $logs .= "Configuración de base de datos Java sincronizada en: {$relJPath}\n";
                }
            }
            $logs .= "\n";
        }
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
     * Auto-inyectar usuario administrador por defecto si la base de datos no tiene usuarios
     * o si el proyecto no incluyó archivos .sql con datos de usuario predeterminados.
     */
    private function ensureDefaultAdminUserInDatabase(Project $project, string $projectPath, &$logs): void
    {
        if (empty($project->db_name)) {
            return;
        }

        $driver = $project->db_driver;
        if (!in_array($driver, ['mysql', 'pgsql', 'postgres'])) {
            return;
        }

        $dbname = $project->db_name;
        $dbConn = ($driver === 'mysql') ? 'students_mysql' : 'students_postgres';

        try {
            // Para aplicaciones con ORMs (Spring Boot Hibernate, Prisma, etc.) que generan sus tablas al iniciar,
            // esperar a que el servidor inicialice sus tablas (hasta 25 intentos x 2s = 50 segundos).
            $tables = [];
            $matchedTable = null;
            $maxAttempts = 25;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($driver === 'mysql') {
                    $rawTables = \Illuminate\Support\Facades\DB::connection($dbConn)->select("SHOW TABLES IN `{$dbname}`;");
                    $tables = array_map(function ($row) {
                        return array_values((array) $row)[0];
                    }, $rawTables);
                } else {
                    $rawTables = \Illuminate\Support\Facades\DB::connection($dbConn)->select(
                        "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_catalog = '{$dbname}';"
                    );
                    $tables = array_map(function ($row) {
                        return $row->table_name;
                    }, $rawTables);
                }

                if (!empty($tables)) {
                    $candidateTables = [
                        'users', 'user', 'usuarios', 'usuario', 'admins', 'admin',
                        'administrators', 'administrator', 'administradores',
                        'managers', 'manager', 'inventory_manager', 'inventory_managers',
                        'tbl_users', 'tbl_user', 'app_users', 'accounts', 'members'
                    ];
                    foreach ($candidateTables as $candidate) {
                        foreach ($tables as $table) {
                            if (strcasecmp($table, $candidate) === 0) {
                                $matchedTable = $table;
                                break 2;
                            }
                        }
                    }
                    if ($matchedTable) {
                        break;
                    }
                }
                sleep(2);
            }

            if (!$matchedTable) {
                return;
            }

            // Verificar si la tabla de usuarios ya cuenta con registros
            if ($driver === 'mysql') {
                $countResult = \Illuminate\Support\Facades\DB::connection($dbConn)->select("SELECT COUNT(*) AS total FROM `{$dbname}`.`{$matchedTable}`;");
            } else {
                $countResult = \Illuminate\Support\Facades\DB::connection($dbConn)->select("SELECT COUNT(*) AS total FROM \"{$matchedTable}\";");
            }

            $userCount = $countResult[0]->total ?? 0;

            if ($userCount > 0) {
                // Ya cuenta con usuarios (por ejemplo, importados desde .sql o seeds del proyecto)
                return;
            }

            // La tabla existe pero está VACÍA (caso típico de proyectos sin .sql o con solo DDL de Hibernate/Prisma)
            $logs .= "--- Auto-Aprovisionamiento: Tabla de usuarios '{$matchedTable}' detectada sin registros. Inyectando usuario administrador... ---\n";

            // Obtener columnas de la tabla
            $cols = [];
            $colDetails = [];
            if ($driver === 'mysql') {
                $colRows = \Illuminate\Support\Facades\DB::connection($dbConn)->select("SHOW COLUMNS FROM `{$dbname}`.`{$matchedTable}`;");
                foreach ($colRows as $cRow) {
                    $colName = $cRow->Field;
                    $cols[] = strtolower($colName);
                    $colDetails[strtolower($colName)] = [
                        'name' => $colName,
                        'type' => strtolower($cRow->Type),
                        'null' => $cRow->Null,
                        'key' => $cRow->Key,
                        'extra' => strtolower($cRow->Extra),
                    ];
                }
            } else {
                $colRows = \Illuminate\Support\Facades\DB::connection($dbConn)->select(
                    "SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '{$matchedTable}';"
                );
                foreach ($colRows as $cRow) {
                    $colName = $cRow->column_name;
                    $cols[] = strtolower($colName);
                    $colDetails[strtolower($colName)] = [
                        'name' => $colName,
                        'type' => strtolower($cRow->data_type),
                        'null' => $cRow->is_nullable,
                        'key' => '',
                        'extra' => strtolower($cRow->column_default ?? ''),
                    ];
                }
            }

            // Detectar si el código del proyecto utiliza BCrypt o comparación de texto plano
            $usesBcrypt = ($project->language === 'java' || $project->language === 'ruby');
            $passCol = null;
            foreach (['password', 'passwd', 'pass', 'clave', 'pwd', 'user_password'] as $pCol) {
                if (in_array($pCol, $cols)) {
                    $passCol = $pCol;
                    break;
                }
            }
            if ($passCol && isset($colDetails[$passCol])) {
                $pType = $colDetails[$passCol]['type'];
                if (preg_match('/(?:varchar|char)\((\d+)\)/', $pType, $lenMatch)) {
                    if ((int)$lenMatch[1] >= 60) {
                        $usesBcrypt = true;
                    }
                }
            }
            if (!$usesBcrypt) {
                $codeFiles = $this->getProjectScanFiles($projectPath, '*.*');
                foreach ($codeFiles as $cFile) {
                    $cPath = $cFile->getRealPath();
                    if (is_file($cPath) && filesize($cPath) < 500000) {
                        $cContent = @file_get_contents($cPath);
                        if ($cContent && (
                            stripos($cContent, 'BCryptPasswordEncoder') !== false ||
                            stripos($cContent, 'bcrypt') !== false ||
                            stripos($cContent, 'password_hash') !== false
                        )) {
                            $usesBcrypt = true;
                            break;
                        }
                    }
                }
            }

            $makeHash = function ($p) use ($usesBcrypt, $project) {
                if (!$usesBcrypt) return $p;
                $h = password_hash($p, PASSWORD_BCRYPT);
                return ($project->language === 'java' && str_starts_with($h, '$2y$')) ? '$2a$' . substr($h, 4) : $h;
            };

            $passwordVal = $makeHash('password');

            $insertData = [];

            // Email
            foreach (['email', 'mail', 'correo', 'user_email'] as $eCol) {
                if (in_array($eCol, $cols)) {
                    $insertData[$colDetails[$eCol]['name']] = 'admin@example.com';
                    break;
                }
            }

            // Username
            foreach (['username', 'user_name', 'usuario', 'login', 'name'] as $uCol) {
                if (in_array($uCol, $cols)) {
                    $insertData[$colDetails[$uCol]['name']] = 'Admin';
                    break;
                }
            }

            // First / Last Name
            if (in_array('first_name', $cols)) $insertData[$colDetails['first_name']['name']] = 'Admin';
            if (in_array('last_name', $cols)) $insertData[$colDetails['last_name']['name']] = 'Evaluador';
            if (in_array('full_name', $cols)) $insertData[$colDetails['full_name']['name']] = 'Admin Evaluador';

            // Password
            foreach (['password', 'passwd', 'pass', 'clave', 'pwd', 'user_password'] as $pCol) {
                if (in_array($pCol, $cols)) {
                    $insertData[$colDetails[$pCol]['name']] = $passwordVal;
                    break;
                }
            }

            // Role
            foreach (['role', 'roles', 'rol', 'user_role', 'role_name'] as $rCol) {
                if (in_array($rCol, $cols)) {
                    $rType = $colDetails[$rCol]['type'];
                    $insertData[$colDetails[$rCol]['name']] = (str_contains($rType, 'int')) ? 1 : 'ADMIN';
                    break;
                }
            }
            if (in_array('role_id', $cols)) $insertData[$colDetails['role_id']['name']] = 1;

            // Flags de activación / admin
            if (in_array('is_admin', $cols)) $insertData[$colDetails['is_admin']['name']] = 1;
            if (in_array('admin', $cols)) $insertData[$colDetails['admin']['name']] = 1;
            if (in_array('is_superadmin', $cols)) $insertData[$colDetails['is_superadmin']['name']] = 1;
            if (in_array('is_active', $cols)) $insertData[$colDetails['is_active']['name']] = 1;
            if (in_array('active', $cols)) $insertData[$colDetails['active']['name']] = 1;
            if (in_array('enabled', $cols)) $insertData[$colDetails['enabled']['name']] = 1;
            if (in_array('activated', $cols)) $insertData[$colDetails['activated']['name']] = 1;

            if (in_array('status', $cols)) {
                $sType = $colDetails['status']['type'];
                $insertData[$colDetails['status']['name']] = (str_contains($sType, 'int')) ? 1 : 'active';
            }

            // Fechas
            if (in_array('created_at', $cols)) $insertData[$colDetails['created_at']['name']] = now();
            if (in_array('updated_at', $cols)) $insertData[$colDetails['updated_at']['name']] = now();
            if (in_array('email_verified_at', $cols)) $insertData[$colDetails['email_verified_at']['name']] = now();

            // ID si es UUID / no auto-incrementable
            if (in_array('id', $cols)) {
                $idCol = $colDetails['id'];
                if (!str_contains($idCol['extra'], 'auto_increment')) {
                    if (str_contains($idCol['type'], 'char') || str_contains($idCol['type'], 'uuid') || str_contains($idCol['type'], 'text')) {
                        $insertData[$idCol['name']] = (string) \Illuminate\Support\Str::uuid();
                    }
                }
            }

            // Si hay columnas NOT NULL obligatorias sin valor por defecto que no hemos llenado:
            foreach ($colDetails as $cKey => $cMeta) {
                if ($cMeta['null'] === 'NO' && !isset($insertData[$cMeta['name']]) && !str_contains($cMeta['extra'], 'auto_increment')) {
                    if (str_contains($cMeta['type'], 'int')) {
                        $insertData[$cMeta['name']] = 0;
                    } elseif (str_contains($cMeta['type'], 'date') || str_contains($cMeta['type'], 'time')) {
                        $insertData[$cMeta['name']] = now();
                    } else {
                        $insertData[$cMeta['name']] = 'admin';
                    }
                }
            }

            // Cuentas administradoras a sembrar para máxima compatibilidad con evaluadores
            $accountsToSeed = [
                ['email' => 'admin@example.com', 'username' => 'Admin', 'pass' => $passwordVal],
                ['email' => 'admin@localhost', 'username' => 'Admin', 'pass' => $makeHash('admin')],
                ['email' => 'admin@admin.com', 'username' => 'Admin', 'pass' => $makeHash('admin123')],
            ];

            $seededCount = 0;
            foreach ($accountsToSeed as $acc) {
                try {
                    $rowInsert = $insertData;
                    foreach (['email', 'mail', 'correo', 'user_email'] as $eCol) {
                        if (isset($rowInsert[$colDetails[$eCol]['name'] ?? ''])) {
                            $rowInsert[$colDetails[$eCol]['name']] = $acc['email'];
                        }
                    }
                    foreach (['username', 'user_name', 'usuario', 'login'] as $uCol) {
                        if (isset($rowInsert[$colDetails[$uCol]['name'] ?? ''])) {
                            $rowInsert[$colDetails[$uCol]['name']] = $acc['username'];
                        }
                    }
                    foreach (['password', 'passwd', 'pass', 'clave', 'pwd', 'user_password'] as $pCol) {
                        if (isset($rowInsert[$colDetails[$pCol]['name'] ?? ''])) {
                            $rowInsert[$colDetails[$pCol]['name']] = $acc['pass'];
                        }
                    }
                    if (in_array('id', $cols)) {
                        $idCol = $colDetails['id'];
                        if (!str_contains($idCol['extra'], 'auto_increment') && (str_contains($idCol['type'], 'char') || str_contains($idCol['type'], 'uuid') || str_contains($idCol['type'], 'text'))) {
                            $rowInsert[$idCol['name']] = (string) \Illuminate\Support\Str::uuid();
                        }
                    }

                    if ($driver === 'mysql') {
                        $colNames = array_map(function ($k) { return "`{$k}`"; }, array_keys($rowInsert));
                        $placeholders = array_fill(0, count($rowInsert), '?');
                        $sql = "INSERT INTO `{$dbname}`.`{$matchedTable}` (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $placeholders) . ");";
                        \Illuminate\Support\Facades\DB::connection($dbConn)->insert($sql, array_values($rowInsert));
                    } else {
                        $colNames = array_map(function ($k) { return "\"{$k}\""; }, array_keys($rowInsert));
                        $placeholders = array_fill(0, count($rowInsert), '?');
                        $sql = "INSERT INTO \"{$matchedTable}\" (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $placeholders) . ");";
                        \Illuminate\Support\Facades\DB::connection($dbConn)->insert($sql, array_values($rowInsert));
                    }
                    $seededCount++;
                } catch (\Throwable $th) {
                    // Ignorar si falla alguna cuenta secundaria por restricciones de unicidad
                }
            }

            if ($seededCount > 0) {
                $logs .= "Usuario administrador inyectado exitosamente en tabla '{$matchedTable}': admin@example.com / password\n";

                // Actualizar demo_instructions para la guía del evaluador
                if (empty($project->demo_instructions) || !str_contains($project->demo_instructions, 'Clave:')) {
                    $prefix = "Acceso predeterminado para pruebas:\nUsuario / Email: admin@example.com\nClave: password";
                    $project->demo_instructions = empty($project->demo_instructions)
                        ? "{$prefix}\n\nUsuario administrador inyectado automáticamente para facilitar la evaluación."
                        : "{$prefix}\n\n" . $project->demo_instructions;
                    $project->save();
                }
            }

        } catch (\Throwable $th) {
            $logs .= "Aviso: No se pudo auto-inyectar usuario administrador: " . $th->getMessage() . "\n";
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
            $deployment->build_log .= "\n\n[ERROR CRITICO]: El proceso de compilación ha fallado o superó el tiempo máximo límite permitido (15 minutos).\nDetalle: {$errorMsg}\n";
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
            || \Illuminate\Support\Facades\File::exists($projectPath . '/site.php')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/app.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/main.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/wsgi.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/asgi.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/server.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/run.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/config.ru')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/server.js')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/app.js')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/index.js');

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
        $isOtherBackendProject = \Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/requirements.txt')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/pyproject.toml')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/Pipfile')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/composer.json')
            || \Illuminate\Support\Facades\File::exists($projectPath . '/Gemfile');

        if (!$isLibraryOrCoreEngine && !$hasWebEntrypoint && !$isOtherBackendProject && \Illuminate\Support\Facades\File::exists($packageJsonPath)) {
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

    private function detectDjangoSettingsModule(string $projectPath): ?string
    {
        // 1. Si manage.py define DJANGO_SETTINGS_MODULE por defecto
        if (\Illuminate\Support\Facades\File::exists($projectPath . '/manage.py')) {
            $manageContent = \Illuminate\Support\Facades\File::get($projectPath . '/manage.py');
            if (preg_match('/["\']DJANGO_SETTINGS_MODULE["\']\s*,\s*["\']([^"\']+)["\']/', $manageContent, $m)) {
                return $m[1];
            }
        }

        // 2. Si ya está definido en .env o .env.example
        foreach (['.env', '.env.example', 'example.env'] as $envFile) {
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/' . $envFile)) {
                $envContent = \Illuminate\Support\Facades\File::get($projectPath . '/' . $envFile);
                if (preg_match('/^DJANGO_SETTINGS_MODULE\s*=\s*(.+)$/m', $envContent, $m)) {
                    $val = trim($m[1], " \t\n\r\0\x0B\"'");
                    if (!empty($val)) return $val;
                }
            }
        }

        // 3. Buscar en Procfile o Procfile.sh (común en babybuddy u otras apps)
        foreach (['Procfile', 'Procfile.sh'] as $pf) {
            if (\Illuminate\Support\Facades\File::exists($projectPath . '/' . $pf)) {
                $pfContent = \Illuminate\Support\Facades\File::get($projectPath . '/' . $pf);
                if (preg_match('/DJANGO_SETTINGS_MODULE=([a-zA-Z0-9_\.]+)/', $pfContent, $m)) {
                    return $m[1];
                }
                if (preg_match('/gunicorn\s+([a-zA-Z0-9_]+)\.wsgi/i', $pfContent, $m)) {
                    $pkg = $m[1];
                    if (\Illuminate\Support\Facades\File::exists($projectPath . "/{$pkg}/settings/production.py")) return "{$pkg}.settings.production";
                    if (\Illuminate\Support\Facades\File::exists($projectPath . "/{$pkg}/settings/base.py")) return "{$pkg}.settings.base";
                    if (\Illuminate\Support\Facades\File::exists($projectPath . "/{$pkg}/settings/development.py")) return "{$pkg}.settings.development";
                    if (\Illuminate\Support\Facades\File::exists($projectPath . "/{$pkg}/settings.py")) return "{$pkg}.settings";
                }
            }
        }

        // 4. Buscar candidatos en el árbol de directorios
        $candidateFiles = array_merge(
            glob($projectPath . '/*/settings/production.py') ?: [],
            glob($projectPath . '/*/settings/base.py') ?: [],
            glob($projectPath . '/*/settings/development.py') ?: [],
            glob($projectPath . '/*/settings/local.py') ?: [],
            glob($projectPath . '/*/settings/dev.py') ?: [],
            glob($projectPath . '/*/settings.py') ?: [],
            glob($projectPath . '/*/*/settings.py') ?: [],
            glob($projectPath . '/*settings*.py') ?: []
        );

        // 4. Buscar candidatos en el árbol de directorios que realmente sean módulos de settings de Django
        foreach ($candidateFiles as $file) {
            if (str_contains($file, '/.venv/')) continue;
            $fc = @file_get_contents($file) ?: '';
            if (str_contains($fc, 'INSTALLED_APPS') || str_contains($fc, 'ROOT_URLCONF') || str_contains($fc, 'DATABASES') || str_contains($fc, 'from .base import') || str_contains($fc, 'from .common import')) {
                $rel = str_replace($projectPath . '/', '', $file);
                $rel = preg_replace('/\.py$/', '', $rel);
                return str_replace('/', '.', $rel);
            }
        }

        // Fallback si ninguno contiene las claves explícitas
        foreach ($candidateFiles as $file) {
            if (str_contains($file, '/.venv/')) continue;
            $rel = str_replace($projectPath . '/', '', $file);
            $rel = preg_replace('/\.py$/', '', $rel);
            return str_replace('/', '.', $rel);
        }

        return null;
    }
}


