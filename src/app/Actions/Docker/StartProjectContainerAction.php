<?php

namespace App\Actions\Docker;

use App\Models\Project;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\File;
use Exception;

class StartProjectContainerAction
{
    /**
     * Start the Docker container for a student project.
     *
     * @param Project $project
     * @param string $projectPath
     * @param string $domain The base domain (e.g. uleam-academic.software)
     * @return array{success: bool, container_id: string|null, output: string}
     */
    public function execute(Project $project, string $projectPath, string $domain): array
    {
        $containerName = $this->getContainerName($project);
        $output = "Iniciando servidor de la aplicación...\n";

        try {
            // 1. Ensure Docker Network exists
            $this->ensureDockerNetworkExists('uleam_academic_network');

            // 2. Stop and remove existing container if running
            $this->stopAndRemoveContainer($containerName);

            // 3. Define language settings
            $settings = $this->getLanguageSettings($project->language, $projectPath, $project);
            if (!$settings) {
                return [
                    'success' => false,
                    'container_id' => null,
                    'output' => "Lenguaje no soportado o no detectado para el proyecto."
                ];
            }

            // 4. Read runtime from env (runsc for production gVisor, runc for local development)
            $runtime = env('DOCKER_RUNTIME', 'runc');

            $workDir = '/app';
            if ($project->language === 'dockerfile') {
                $workDir = $this->detectDockerfileWorkdir($projectPath);
            }

            // Generar archivos estáticos universales para borrado de sesión
            $clearHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Sesion Cerrada</title></head><body>'
                . '<script>'
                . 'try{localStorage.clear();}catch(e){}'
                . 'try{sessionStorage.clear();}catch(e){}'
                . 'try{'
                . '  var cookies = document.cookie.split(";");'
                . '  for (var i = 0; i < cookies.length; i++) {'
                . '    var eq = cookies[i].indexOf("=");'
                . '    var name = eq > -1 ? cookies[i].substr(0, eq).trim() : cookies[i].trim();'
                . '    if (name) {'
                . '      document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/;";'
                . '      document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=" + window.location.hostname + ";";'
                . '      document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=." + window.location.hostname + ";";'
                . '    }'
                . '  }'
                . '}catch(e){}'
                . '</script>'
                . '<h3>Sesion cerrada correctamente.</h3></body></html>';

            foreach (['/public', '/static', '/client/public', '/dist', '/build', ''] as $relPub) {
                $dir = $projectPath . $relPub;
                if (File::isDirectory($dir) || $relPub === '/public') {
                    File::ensureDirectoryExists($dir, 0777);
                    @file_put_contents($dir . '/uleam-clear-session', $clearHtml);
                    @file_put_contents($dir . '/uleam-clear-session.html', $clearHtml);
                }
            }

            $isHeavyApp = $project->language === 'ruby'
                || $project->language === 'java'
                || $project->language === 'dotnet'
                || ($project->language === 'python' && (File::exists($projectPath . '/manage.py') || File::exists($projectPath . '/pyproject.toml') || File::exists($projectPath . '/Pipfile')))
                || File::exists($projectPath . '/packages/evershop')
                || File::exists($projectPath . '/packages/sync-server')
                || File::exists($projectPath . '/server/app.js')
                || File::exists($projectPath . '/apps/web')
                || File::exists($projectPath . '/pnpm-workspace.yaml')
                || File::exists($projectPath . '/turbo.json')
                || File::exists($projectPath . '/lerna.json');

            $memoryLimit = $isHeavyApp ? '1024m' : ($project->language === 'nodejs' ? '768m' : '512m');
            $memorySwap = $isHeavyApp ? '2048m' : '1024m';
            $cpusLimit = $isHeavyApp ? '1.5' : '1.0';
            $pidsLimit = $isHeavyApp ? '300' : '200';

            // 5. Construct Docker Run Command
            $command = [
                'docker', 'run', '-d',
                '--name', $containerName,
                '--runtime', $runtime,
                '--network', 'uleam_academic_network',
                '--memory', $memoryLimit,
                '--memory-swap', $memorySwap,
                '--cpus', $cpusLimit,
                '--pids-limit', $pidsLimit,
                '--storage-opt', 'size=1G',
                '-v', "$projectPath:$workDir", // Montar en su WORKDIR como lectura y escritura
            ];

            // Si el WORKDIR del contenedor no es /app, montar también en /app para compatibilidad
            if ($workDir !== '/app') {
                $command[] = '-v';
                $command[] = "$projectPath:/app";
            }

            // Si el contenedor fue construido con Dockerfile,
            // preservar el directorio de node_modules de la imagen para que el montaje host no lo oculte
            if ($project->language === 'dockerfile') {
                $command[] = '-v';
                $command[] = "$workDir/node_modules";
                if ($workDir !== '/app') {
                    $command[] = '-v';
                    $command[] = '/app/node_modules';
                }
            }

            $command[] = '-w';
            $command[] = $workDir;

            $command[] = '--label';
            $command[] = 'traefik.enable=true';

            if (config('app.env') === 'production') {
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.rule=Host(`{$project->subdomain}.{$domain}`)";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.entrypoints=websecure";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.tls.certresolver=myresolver";

                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}-http.rule=Host(`{$project->subdomain}.{$domain}`)";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}-http.entrypoints=web";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}-http.middlewares={$corsName}";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}-http.service={$serviceName}";
            } else {
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.rule=Host(`{$project->subdomain}.{$domain}`)";
                $command[] = '--label';
                $command[] = "traefik.http.routers.{$project->id}.entrypoints=web";
            }

            // Router local (HTTP / web) para pruebas locales en localhost
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.rule=Host(`{$project->subdomain}.localhost`)";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.entrypoints=web";

            // ── CORS & Iframe Embedding Universal ─────────────────────────────────────────
            // Inyectar encabezados CORS y permitir visualización en iframe para el evaluador
            $corsName = "{$project->id}-cors";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolalloworiginlist=*";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolallowmethods=GET,OPTIONS,PUT,PATCH,POST,DELETE";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolallowheaders=Content-Type,Authorization,X-Requested-With,Accept,Origin,X-CSRF-Token";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.accesscontrolmaxage=100";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.addvaryheader=true";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.customresponseheaders.X-Frame-Options=";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$corsName}.headers.customresponseheaders.Content-Security-Policy=frame-ancestors *";
            // Aplicar el middleware a ambos routers (producción y local)
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}.middlewares={$corsName}";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.middlewares={$corsName}";

            // Asociación al servicio balanceador de carga
            $serviceName = "{$project->id}-service";
            $command[] = '--label';
            $command[] = "traefik.http.services.{$serviceName}.loadbalancer.server.port={$settings['port']}";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}.service={$serviceName}";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-local.service={$serviceName}";

            // Endpoint universal de cierre de sesión (/uleam-clear-session) para limpiar cookies del navegador sin apagar el contenedor
            $clearName = "{$project->id}-clear-session";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$clearName}.headers.customresponseheaders.Clear-Site-Data=\"cookies\", \"storage\"";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$clearName}.headers.customresponseheaders.Cache-Control=no-store, no-cache, must-revalidate";
            $command[] = '--label';
            $command[] = "traefik.http.middlewares.{$clearName}.headers.customresponseheaders.Content-Type=text/html; charset=utf-8";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-clear.rule=(Host(`{$project->subdomain}.{$domain}`) || Host(`{$project->subdomain}.localhost`)) && PathPrefix(`/uleam-clear-session`)";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-clear.priority=2000";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-clear.entrypoints=web";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-clear.middlewares={$clearName},{$corsName}";
            $command[] = '--label';
            $command[] = "traefik.http.routers.{$project->id}-clear.service={$serviceName}";
            // ───────────────────────────────────────────────────────────────────────────

            // Add environment variables if needed
            $isProduction = config('app.env') === 'production';
            $primaryUrl = $isProduction ? "https://{$project->subdomain}.{$domain}" : "http://{$project->subdomain}.localhost";

            $command[] = '-e';
            $command[] = 'PORT=' . $settings['port'];
            $command[] = '-e';
            $command[] = 'PUERTO=' . $settings['port'];
            $command[] = '-e';
            $command[] = 'HOST=0.0.0.0';
            $command[] = '-e';
            $command[] = 'APP_URL=' . $primaryUrl;
            $command[] = '-e';
            $command[] = 'PUBLIC_URL=' . $primaryUrl;
            $command[] = '-e';
            $command[] = 'BASE_URL=' . $primaryUrl;
            $command[] = '-e';
            $command[] = 'NEXT_PUBLIC_BASE_URL=' . $primaryUrl;
            $command[] = '-e';
            $command[] = 'AUTH_URL=' . $primaryUrl;
            $command[] = '-e';
            $command[] = 'NEXT_PUBLIC_SELF_HOSTED=true';
            $command[] = '-e';
            $command[] = 'SECRET_PASSWORD=' . hash('sha256', $project->id);
            $command[] = '-e';
            $command[] = 'BETTER_AUTH_SECRET=' . hash('sha256', $project->id . 'better_auth');
            $command[] = '-e';
            $command[] = 'SUPPORT_EMAIL=support@uleamcode.software';
            $command[] = '-e';
            $command[] = 'HOSTNAME=0.0.0.0';
            $command[] = '-e';
            $command[] = 'NEXTAUTH_URL=' . $primaryUrl;
            $command[] = '-e';
            $command[] = 'EVERSHOP_HOME_URL=' . $primaryUrl;
            $command[] = '-e';
            $command[] = 'SUPPRESS_NO_CONFIG_WARNING=1';

            if ($project->language === 'dotnet') {
                $command[] = '-e';
                $command[] = 'ASPNETCORE_HTTP_PORTS=' . $settings['port'];
                $command[] = '-e';
                $command[] = 'ASPNETCORE_URLS=http://+:' . $settings['port'];
            }

            // Vinculación automática en suites Fullstack (Frontend <-> Backend)
            if ($project->backend_project_id) {
                $backend = \App\Models\Project::find($project->backend_project_id);
                if ($backend) {
                    $backendUrl = $isProduction ? "https://{$backend->subdomain}.{$domain}" : "http://{$backend->subdomain}.localhost";
                    $backendInternalUrl = "http://project-{$backend->id}:5000";
                    $command[] = '-e';
                    $command[] = 'API_URL=' . $backendUrl;
                    $command[] = '-e';
                    $command[] = 'BACKEND_URL=' . $backendUrl;
                    $command[] = '-e';
                    $command[] = 'PUBLIC_DJANGO_API_URL=' . $backendInternalUrl;
                    $command[] = '-e';
                    $command[] = 'VITE_API_URL=' . $backendUrl;
                    $command[] = '-e';
                    $command[] = 'VITE_BACKEND_URL=' . $backendUrl;
                    $command[] = '-e';
                    $command[] = 'NEXT_PUBLIC_API_URL=' . $backendUrl;
                    $command[] = '-e';
                    $command[] = 'REACT_APP_API_URL=' . $backendUrl;
                }
            } elseif ($project->is_backend_service) {
                $frontend = \App\Models\Project::where('backend_project_id', $project->id)->first();
                if ($frontend) {
                    $frontendUrl = $isProduction ? "https://{$frontend->subdomain}.{$domain}" : "http://{$frontend->subdomain}.localhost";
                    $command[] = '-e';
                    $command[] = 'FRONTEND_URL=' . $frontendUrl;
                    $command[] = '-e';
                    $command[] = 'APP_URL=' . $frontendUrl;
                    $command[] = '-e';
                    $command[] = 'CORS_ALLOWED_ORIGINS=' . $frontendUrl;
                }
            }

            if (File::exists($projectPath . '/microblog.py')) {
                $command[] = '-e';
                $command[] = 'FLASK_APP=microblog.py';
            } elseif (File::exists($projectPath . '/app.py')) {
                $command[] = '-e';
                $command[] = 'FLASK_APP=app.py';
            } elseif (File::exists($projectPath . '/wsgi.py')) {
                $command[] = '-e';
                $command[] = 'FLASK_APP=wsgi.py';
            }

            if (!empty($project->db_name)) {
                $dbDriver = $project->db_driver ?: 'pgsql';
                
                if ($dbDriver === 'mongodb') {
                    $dbHost = 'uleam_mongodb_students';
                    $dbPort = '27017';
                    $mongoUri = "mongodb://{$project->db_user}:{$project->db_password}@{$dbHost}:{$dbPort}/{$project->db_name}?authSource={$project->db_name}";
                    
                    $command[] = '-e';
                    $command[] = 'DB_CONNECTION=mongodb';
                    $command[] = '-e';
                    $command[] = 'DB_HOST=' . $dbHost;
                    $command[] = '-e';
                    $command[] = 'DB_PORT=' . $dbPort;
                    $command[] = '-e';
                    $command[] = 'DB_DATABASE=' . $project->db_name;
                    $command[] = '-e';
                    $command[] = 'DB_USERNAME=' . $project->db_user;
                    $command[] = '-e';
                    $command[] = 'DB_PASSWORD=' . $project->db_password;
                    $command[] = '-e';
                    $command[] = 'DB_USER=' . $project->db_user;
                    $command[] = '-e';
                    $command[] = 'DB_NAME=' . $project->db_name;
                    $command[] = '-e';
                    $command[] = 'DB_PASS=' . $project->db_password;
                    $command[] = '-e';
                    $command[] = 'DATABASE_URL=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'DATABASE=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'MONGODB_URI=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'MONGO_URI=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'MONGO_URL=' . $mongoUri;
                    $command[] = '-e';
                    $command[] = 'MONGODB_URL=' . $mongoUri;
                } else {
                    $isSqlite = ($dbDriver === 'sqlite' || $dbDriver === 'sqlite3');
                    if ($project->language === 'ruby') {
                        $dbYml = $projectPath . '/config/database.yml';
                        if (File::exists($dbYml)) {
                            $dbYamlContent = File::get($dbYml);
                            if (str_contains($dbYamlContent, 'sqlite3') && !str_contains($dbYamlContent, 'postgresql') && !str_contains($dbYamlContent, 'postgres') && !str_contains($dbYamlContent, 'mysql')) {
                                $isSqlite = true;
                            }
                        }
                    }

                    if (!$isSqlite) {
                        $dbHost = $dbDriver === 'mysql' ? ($project->language === 'ruby' ? 'uleam-mysql-students' : 'uleam_mysql_students') : ($project->language === 'ruby' ? 'uleam-postgres-students' : 'uleam_postgres_students');
                        $dbPort = $dbDriver === 'mysql' ? '3306' : '5432';
                        $dbUrlScheme = $dbDriver === 'mysql' ? 'mysql2' : 'postgres';

                        $command[] = '-e';
                        $command[] = 'DB_CONNECTION=' . $dbDriver;
                        $command[] = '-e';
                        $command[] = 'DB_HOST=' . $dbHost;
                        $command[] = '-e';
                        $command[] = 'DB_PORT=' . $dbPort;
                        $command[] = '-e';
                        $command[] = 'DB_DATABASE=' . $project->db_name;
                        $command[] = '-e';
                        $command[] = 'DB_USERNAME=' . $project->db_user;
                        $command[] = '-e';
                        $command[] = 'DB_PASSWORD=' . $project->db_password;
                        $command[] = '-e';
                        $command[] = 'DB_USER=' . $project->db_user;
                        $command[] = '-e';
                        $command[] = 'DB_NAME=' . $project->db_name;
                        $command[] = '-e';
                        $command[] = 'DB_PASS=' . $project->db_password;
                        $command[] = '-e';
                        $command[] = 'DBHOST=' . $dbHost;
                        $command[] = '-e';
                        $command[] = 'DBPORT=' . $dbPort;
                        $command[] = '-e';
                        $command[] = 'DBNAME=' . $project->db_name;
                        $command[] = '-e';
                        $command[] = 'DBUSER=' . $project->db_user;
                        $command[] = '-e';
                        $command[] = 'DBPASSWORD=' . $project->db_password;
                        $command[] = '-e';
                        $command[] = 'DATABASE_URL=' . $dbUrlScheme . '://' . $project->db_user . ':' . $project->db_password . '@' . $dbHost . ':' . $dbPort . '/' . $project->db_name;
                        if ($dbDriver === 'mysql') {
                            $command[] = '-e';
                            $command[] = 'MYSQL_HOST=' . $dbHost;
                            $command[] = '-e';
                            $command[] = 'MYSQL_HOST_NAME=' . $dbHost;
                            $command[] = '-e';
                            $command[] = 'MYSQL_PORT=' . $dbPort;
                            $command[] = '-e';
                            $command[] = 'MYSQL_DATABASE=' . $project->db_name;
                            $command[] = '-e';
                            $command[] = 'MYSQL_DB_NAME=' . $project->db_name;
                            $command[] = '-e';
                            $command[] = 'MYSQL_USER=' . $project->db_user;
                            $command[] = '-e';
                            $command[] = 'MYSQL_USERNAME=' . $project->db_user;
                            $command[] = '-e';
                            $command[] = 'MYSQL_PASSWORD=' . $project->db_password;
                        }
                        if ($dbDriver === 'pgsql' || $dbDriver === 'postgres') {
                            $command[] = '-e';
                            $command[] = 'POSTGRES_HOST=' . $dbHost;
                            $command[] = '-e';
                            $command[] = 'POSTGRES_PORT=' . $dbPort;
                            $command[] = '-e';
                            $command[] = 'POSTGRES_DATABASE=' . $project->db_name;
                            $command[] = '-e';
                            $command[] = 'POSTGRES_USERNAME=' . $project->db_user;
                            $command[] = '-e';
                            $command[] = 'POSTGRES_DB=' . $project->db_name;
                            $command[] = '-e';
                            $command[] = 'POSTGRES_USER=' . $project->db_user;
                            $command[] = '-e';
                            $command[] = 'POSTGRES_PASSWORD=' . $project->db_password;
                            $command[] = '-e';
                            $command[] = 'PGHOST=' . $dbHost;
                            $command[] = '-e';
                            $command[] = 'PGPORT=' . $dbPort;
                            $command[] = '-e';
                            $command[] = 'PGDATABASE=' . $project->db_name;
                            $command[] = '-e';
                            $command[] = 'PGUSER=' . $project->db_user;
                            $command[] = '-e';
                            $command[] = 'PGPASSWORD=' . $project->db_password;
                        }

                        if ($project->language === 'java') {
                            $jdbcDriver = $project->db_driver === 'mysql' ? 'com.mysql.cj.jdbc.Driver' : 'org.postgresql.Driver';
                            $jdbcScheme = ($project->db_driver === 'pgsql' || $project->db_driver === 'postgres') ? 'postgresql' : $project->db_driver;
                            $jdbcParams = ($project->db_driver === 'mysql') ? '?useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=UTC' : '';
                            $jdbcUrl = "jdbc:{$jdbcScheme}://{$dbHost}:{$dbPort}/{$project->db_name}{$jdbcParams}";

                            $command[] = '-e';
                            $command[] = 'SPRING_DATASOURCE_URL=' . $jdbcUrl;
                            $command[] = '-e';
                            $command[] = 'SPRING_DATASOURCE_USERNAME=' . $project->db_user;
                            $command[] = '-e';
                            $command[] = 'SPRING_DATASOURCE_PASSWORD=' . $project->db_password;
                            $command[] = '-e';
                            $command[] = 'SPRING_DATASOURCE_DRIVER_CLASS_NAME=' . $jdbcDriver;
                        }
                    }
                }
            }

            // Inyectar servicio de Redis universitario para colas y WebSockets
            $redisHost = ($project->language === 'ruby') ? 'uleam-redis-students' : 'uleam_redis_students';
            $command[] = '-e';
            $command[] = "REDIS_URL=redis://{$redisHost}:6379";
            $command[] = '-e';
            $command[] = "REDIS_HOST={$redisHost}";
            $command[] = '-e';
            $command[] = 'REDIS_PORT=6379';
            $command[] = '-e';
            $command[] = "CELERY_BROKER_URL=redis://{$redisHost}:6379/0";
            $command[] = '-e';
            $command[] = "CELERY_RESULT_BACKEND=redis://{$redisHost}:6379/0";
            $command[] = '-e';
            $command[] = "CACHE_URL=redis://{$redisHost}:6379/1";

            // Para proyectos Node.js asegurar compatibilidad con OpenSSL 3.0, Webpack y secretos comunes
            if ($project->language === 'nodejs') {
                $command[] = '-e';
                $command[] = 'NODE_OPTIONS=--openssl-legacy-provider';
                $command[] = '-e';
                $command[] = 'PROTOCOL_HEADER=x-forwarded-proto';
                $command[] = '-e';
                $command[] = 'HOST_HEADER=x-forwarded-host';
                $command[] = '-e';
                $command[] = 'ORIGIN=' . $primaryUrl;
                $command[] = '-e';
                $command[] = 'JWT_SECRET=uleam_secret_jwt_token_' . md5($project->id);
                $command[] = '-e';
                $command[] = 'SECRET_KEY=uleam_secret_key_' . md5($project->id);
                $command[] = '-e';
                $command[] = 'SESSION_SECRET=uleam_session_secret_' . md5($project->id);
            }

            if ($project->language === 'php') {
                $command[] = '-e';
                $command[] = 'PHP_CLI_SERVER_WORKERS=4';
                $command[] = '-e';
                $command[] = "ALLOWED_HOSTNAMES={$project->subdomain}.localhost,{$project->subdomain}.{$domain},localhost,127.0.0.1";
                $command[] = '-e';
                $command[] = 'CI_ENVIRONMENT=development';
            }

            if ($project->language === 'ruby') {
                File::ensureDirectoryExists($projectPath . '/log', 0777);
                @chmod($projectPath . '/log', 0777);
                File::ensureDirectoryExists($projectPath . '/tmp/pids', 0777);
                @chmod($projectPath . '/tmp/pids', 0777);
                File::ensureDirectoryExists($projectPath . '/storage', 0777);
                @chmod($projectPath . '/storage', 0777);

                $command[] = '-e';
                $command[] = 'RAILS_ENV=production';
                $command[] = '-e';
                $command[] = 'RACK_ENV=production';
                $command[] = '-e';
                $command[] = 'RAILS_SERVE_STATIC_FILES=true';
                $command[] = '-e';
                $command[] = 'RAILS_LOG_TO_STDOUT=true';
                $command[] = '-e';
                $command[] = 'RAILS_FORCE_SSL=false';
                $command[] = '-e';
                $command[] = 'RAILS_ASSUME_SSL=false';
                $command[] = '-e';
                $command[] = 'DISABLE_SSL=true';
                $sessionSalt = md5($project->id . '_' . ($project->updated_at ? $project->updated_at->timestamp : time()));
                $command[] = '-e';
                $command[] = 'SECRET_KEY_BASE=uleam_rails_secret_key_base_' . $sessionSalt;
                $command[] = '-e';
                $command[] = 'PORT=3000';
                $command[] = '-e';
                $command[] = 'BUNDLE_PATH=vendor/bundle';
                $command[] = '-e';
                $command[] = 'PIDFILE=tmp/pids/server.pid';
                $command[] = '-e';
                $command[] = 'PUMA_WORKERS=0';
                $encKey1 = hash('sha256', $project->id . '_active_record_primary');
                $encKey2 = hash('sha256', $project->id . '_active_record_deterministic');
                $encKey3 = hash('sha256', $project->id . '_active_record_salt');
                $otpSecret = hash('sha256', $project->id . '_otp_secret');
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
            }

            if (File::exists($projectPath . '/manage.py')) {
                $djangoSettingsModule = $this->detectDjangoSettingsModule($projectPath);
                if ($djangoSettingsModule) {
                    $command[] = '-e';
                    $command[] = "DJANGO_SETTINGS_MODULE={$djangoSettingsModule}";
                }
                $command[] = '-e';
                $command[] = 'ENV_TYPE=dev';
                $command[] = '-e';
                $command[] = 'DEBUG=True';
                $command[] = '-e';
                $command[] = 'CORS_ALLOW_ALL=True';
                $command[] = '-e';
                $command[] = 'CELERY_TASK_ALWAYS_EAGER=True';
            }
            $command[] = '-e';
            $command[] = 'WEB_CONCURRENCY=1';
            $command[] = '-e';
            $command[] = 'ALLOWED_HOSTS=*';
            $command[] = '-e';
            $command[] = 'CSRF_TRUSTED_ORIGINS=' . $primaryUrl . ',http://' . $project->subdomain . '.localhost,http://localhost:5000,http://127.0.0.1:5000';
            if (!isset($sessionSalt)) {
                $sessionSalt = md5($project->id . '_' . ($project->updated_at ? $project->updated_at->timestamp : time()));
            }
            $command[] = '-e';
            $command[] = 'SECRET_KEY=uleam_secret_key_' . $sessionSalt;
            $command[] = '-e';
            $command[] = 'SESSION_SECRET=uleam_session_secret_' . $sessionSalt;
            $command[] = '-e';
            $command[] = 'JWT_SECRET=uleam_jwt_secret_' . $sessionSalt;

            // Append base image
            $command[] = $settings['image'];

            // Append start command
            foreach ($settings['command'] as $arg) {
                $command[] = $arg;
            }

            try {
                $process = new Process($command);
                $process->setTimeout(60);
                $process->mustRun();
            } catch (\Throwable $e) {
                // Si el host o filesystem no tiene soporte para pquota/storage-opt en overlay2, reintentar sin --storage-opt
                if (str_contains($e->getMessage(), 'storage-opt')) {
                    $fallbackCommand = [];
                    $skipNext = false;
                    foreach ($command as $arg) {
                        if ($arg === '--storage-opt') {
                            $skipNext = true;
                            continue;
                        }
                        if ($skipNext) {
                            $skipNext = false;
                            continue;
                        }
                        $fallbackCommand[] = $arg;
                    }
                    $process = new Process($fallbackCommand);
                    $process->setTimeout(60);
                    $process->mustRun();
                } else {
                    throw $e;
                }
            }

            $containerId = trim($process->getOutput());

            // Liveness Probe: Verificar durante 8 segundos que el contenedor se mantenga en ejecucion
            $isAlive = true;
            $exitCode = null;

            for ($i = 0; $i < 8; $i++) {
                sleep(1);
                $inspect = new Process(['docker', 'inspect', '--format', '{{.State.Status}} {{.State.ExitCode}}', $containerName]);
                $inspect->run();
                $stateOutput = trim($inspect->getOutput());
                $parts = explode(' ', $stateOutput);
                $status = $parts[0] ?? '';
                $exitCode = $parts[1] ?? '0';

                if ($status === 'exited' || $status === 'dead' || ($exitCode !== '0' && $exitCode !== '')) {
                    $isAlive = false;
                    break;
                }
            }

            if (!$isAlive) {
                $logsProcess = new Process(['docker', 'logs', '--tail', '300', $containerName]);
                $logsProcess->run();
                $crashLogs = trim($logsProcess->getOutput() . "\n" . $logsProcess->getErrorOutput());

                // Limpiar el contenedor fallido
                (new Process(['docker', 'rm', '-f', $containerName]))->run();

                $output .= $this->formatFriendlyCrashDiagnosis($crashLogs, $project, (string)$exitCode);

                return [
                    'success' => false,
                    'container_id' => null,
                    'output' => $output
                ];
            }

            // Readiness Probe: Esperar a que el servidor web esté listo y respondiendo tráfico HTTP (evitar Bad Gateway 502)
            $hostHeader = (config('app.env') === 'production')
                ? "{$project->subdomain}.{$domain}"
                : "{$project->subdomain}.localhost";
            $probeUrl = "http://127.0.0.1:80/";
            $maxWaitSeconds = ($isHeavyApp || $project->language === 'java') ? 35 : 10;
            $serverReady = false;

            for ($attempt = 1; $attempt <= $maxWaitSeconds; $attempt++) {
                // Verificar que el contenedor no haya muerto
                $inspect = new Process(['docker', 'inspect', '--format', '{{.State.Status}}', $containerName]);
                $inspect->run();
                if (trim($inspect->getOutput()) !== 'running') {
                    break;
                }

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $probeUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Host: {$hostHeader}"]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Cualquier código HTTP válido del backend (200, 301, 302, 401, 403, 404, etc.)
                // indica que el backend web ya aceptó la conexión y Traefik ya no retorna 502/503/504
                if ($httpCode > 0 && $httpCode !== 502 && $httpCode !== 503 && $httpCode !== 504) {
                    $serverReady = true;
                    break;
                }
                sleep(1);
            }

            $projectUrl = (config('app.env') === 'production')
                ? "https://{$project->subdomain}.{$domain}"
                : "http://{$project->subdomain}.localhost";
            $output .= "Servidor web verificado y activo (En ejecución).\n\n";
            $output .= "=======================================================\n";
            $output .= "PROYECTO DESPLEGADO CON EXITO\n";
            $output .= "Tu aplicacion se encuentra en ejecucion y lista para ser evaluada.\n";
            $output .= "URL: {$projectUrl}\n";
            $output .= "=======================================================\n";

            $project->status = 'running';
            $project->container_id = $containerId;
            $project->last_visited_at = now();
            $project->save();

            return [
                'success' => true,
                'container_id' => $containerId,
                'output' => $output
            ];

        } catch (ProcessFailedException $e) {
            return [
                'success' => false,
                'container_id' => null,
                'output' => $output . "Fallo al iniciar el contenedor: " . $e->getMessage() . "\n" . $e->getProcess()->getErrorOutput()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'container_id' => null,
                'output' => $output . "Error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get unique container name.
     */
    private function getContainerName(Project $project): string
    {
        return "project-{$project->id}";
    }

    /**
     * Ensure the shared Docker network exists.
     */
    private function ensureDockerNetworkExists(string $networkName): void
    {
        $check = new Process(['docker', 'network', 'inspect', $networkName]);
        $check->run();

        if (!$check->isSuccessful()) {
            $create = new Process(['docker', 'network', 'create', $networkName]);
            $create->run();
        }
    }

    /**
     * Detener y eliminar el contenedor si ya existe de forma inmediata.
     */
    private function stopAndRemoveContainer(string $name): void
    {
        // Forzar eliminación inmediata
        $rm = new Process(['docker', 'rm', '-f', $name]);
        $rm->run();
    }

    /**
     * Get container image, port, and start command based on language.
     */
    private function getLanguageSettings(?string $language, string $projectPath, ?Project $project = null): ?array
    {
        switch ($language) {
            case 'nodejs':
                // Soporte para Actual Budget (sync-server con backend y frontend integrado en Node 24)
                if (File::exists($projectPath . '/packages/sync-server/build/app.js')) {
                    File::ensureDirectoryExists($projectPath . '/data', 0777);
                    @chmod($projectPath . '/data', 0777);
                    return [
                        'image' => 'node:24-bookworm',
                        'port' => 3000,
                        'command' => ['node', 'packages/sync-server/build/app.js']
                    ];
                }

                // Soporte para Planka Kanban (Sails.js + PostgreSQL en puerto 1337)
                if (File::exists($projectPath . '/server/app.js') && (File::exists($projectPath . '/server/config') || File::exists($projectPath . '/client'))) {
                    return [
                        'image' => 'node:20',
                        'port' => 1337,
                        'command' => ['sh', '-c', 'cd /app/server && node app.js --prod']
                    ];
                }

                // Soporte para Rallly / Next.js standalone monorepo en Node 24 (puerto 3000)
                if (File::exists($projectPath . '/apps/web/.next/standalone/apps/web/server.js')) {
                    return [
                        'image' => 'node:24-bookworm',
                        'port' => 3000,
                        'command' => ['sh', '-c', 'cd /app/apps/web/.next/standalone && node apps/web/server.js']
                    ];
                }

                $command = ['node', 'index.js'];
                // Monorepos con client y api integrados
                if (File::exists($projectPath . '/client/server.js') && File::exists($projectPath . '/api')) {
                    $runnerContent = <<<'JS'
const { spawn } = require('child_process');

console.log('[Runner] Iniciando backend API en puerto 5000...');
const api = spawn('node', ['build/index.js'], {
    cwd: '/app/api',
    env: { ...process.env, PORT: '5000', NODE_PATH: '/app/api/build:/app/api/node_modules' },
    stdio: 'inherit'
});

console.log('[Runner] Esperando 3 segundos para que la API esté lista...');
setTimeout(() => {
    console.log('[Runner] Iniciando servidor frontend en puerto 3000...');
    const client = spawn('node', ['client/server.js'], {
        cwd: '/app',
        env: { ...process.env, PORT: '3000' },
        stdio: 'inherit'
    });

    client.on('exit', code => process.exit(code));
    api.on('exit', code => process.exit(code));
}, 3000);
JS;
                    File::put($projectPath . '/_monorepo_runner.js', $runnerContent);
                    $command = ['node', '_monorepo_runner.js'];
                } elseif (File::exists($projectPath . '/server.js')) {
                    $command = ['node', 'server.js'];
                } elseif (File::exists($projectPath . '/app.js')) {
                    $command = ['node', 'app.js'];
                } elseif (File::exists($projectPath . '/client/server.js')) {
                    $command = ['node', 'client/server.js'];
                } elseif (File::exists($projectPath . '/build/index.js')) {
                    $command = ['node', 'build/index.js'];
                } elseif (File::exists($projectPath . '/backend/src/server.js')) {
                    $command = ['sh', '-c', 'cd /app/backend && node src/server.js'];
                } elseif (File::exists($projectPath . '/backend/server.js')) {
                    $command = ['sh', '-c', 'cd /app/backend && node server.js'];
                } elseif (File::exists($projectPath . '/backend/package.json')) {
                    $command = ['sh', '-c', 'cd /app/backend && npm start'];
                } else {
                    // Detección universal de SPAs estáticas (Create React App, Vite, Vue, Angular, etc.)
                    $staticDirs = ['build', 'dist', 'out', 'public', 'client/build', 'frontend/dist', 'frontend/build'];
                    $servedStaticDir = null;
                    if (!File::exists($projectPath . '/server.js') && !File::exists($projectPath . '/app.js') && !File::exists($projectPath . '/api')) {
                        foreach ($staticDirs as $sDir) {
                            if (File::exists($projectPath . '/' . $sDir . '/index.html')) {
                                $servedStaticDir = $sDir;
                                break;
                            }
                        }
                    }

                    if ($servedStaticDir) {
                        // Servimos con serve en el puerto 3000 (consume < 25MB RAM y soporta SPA fallback)
                        if ($project && !empty($project->subdomain)) {
                            $subdomainLink = $projectPath . '/' . $servedStaticDir . '/' . trim($project->subdomain, '/');
                            if (!file_exists($subdomainLink) && !is_link($subdomainLink)) {
                                @symlink('.', $subdomainLink);
                            }
                        }
                        $command = ['npx', '-y', 'serve', '-s', $servedStaticDir, '-l', '3000'];
                    } elseif (File::exists($projectPath . '/package.json')) {
                        $packageJson = json_decode(File::get($projectPath . '/package.json'), true);
                        if (isset($packageJson['scripts']['start:production']) && !str_contains($packageJson['scripts']['start:production'], 'pm2')) {
                            $command = ['npm', 'run', 'start:production'];
                        } elseif (isset($packageJson['scripts']['start']) && !str_contains($packageJson['scripts']['start'], 'pm2')) {
                            $command = ['npm', 'start'];
                        } elseif (isset($packageJson['scripts']['preview'])) {
                            $command = ['npx', 'vite', 'preview', '--host', '0.0.0.0', '--port', '3000'];
                        } elseif (isset($packageJson['scripts']['dev'])) {
                            $command = ['npx', 'vite', '--host', '0.0.0.0', '--port', '3000'];
                        }
                    }
                }

                $isNode22Runtime = File::exists($projectPath . '/build/index.js')
                    || (File::exists($projectPath . '/package.json') && (str_contains(File::get($projectPath . '/package.json'), '@sveltejs/kit') || str_contains(File::get($projectPath . '/package.json'), 'svelte-kit')))
                    || File::exists($projectPath . '/svelte.config.js');

                return [
                    'image' => $isNode22Runtime ? 'node:22-slim' : 'node:20-alpine',
                    'port' => 3000,
                    'command' => $command
                ];

            case 'php':
                // Built-in PHP server con supresion de avisos deprecated y soporte de router para Laravel/frameworks
                $command = ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-S', '0.0.0.0:80'];
                if (File::exists($projectPath . '/server.php')) {
                    // Laravel incluye server.php en la raíz como emulador de mod_rewrite para el servidor integrado con document root public
                    $command = ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-S', '0.0.0.0:80', '-t', 'public', 'server.php'];
                } elseif (File::exists($projectPath . '/public/index.php')) {
                    $routerPath = $projectPath . '/_php_router.php';
                    $routerContent = <<<'PHP'
<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri) && !is_dir(__DIR__ . '/public' . $uri)) {
    return false;
}
chdir(__DIR__ . '/public');
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require_once __DIR__ . '/public/index.php';
PHP;
                    File::put($routerPath, $routerContent);
                    $command = ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-S', '0.0.0.0:80', '-t', 'public', '_php_router.php'];
                } elseif (File::exists($projectPath . '/index.php')) {
                    $routerPath = $projectPath . '/_root_php_router.php';
                    $routerContent = <<<'PHP'
<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require_once __DIR__ . '/index.php';
PHP;
                    File::put($routerPath, $routerContent);
                    $command = ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', '-S', '0.0.0.0:80', '_root_php_router.php'];
                }
                return [
                    'image' => 'webdevops/php:8.4',
                    'port' => 80,
                    'command' => $command
                ];

            case 'ruby':
                @unlink($projectPath . '/tmp/pids/server.pid');
                
                // Detección de proyecto Jekyll (sitios estáticos, blogs, docs o gemas de Jekyll)
                $isJekyll = File::exists($projectPath . '/_config.yml')
                    || File::exists($projectPath . '/docs/_config.yml')
                    || File::exists($projectPath . '/jekyll.gemspec')
                    || (File::exists($projectPath . '/Gemfile') && preg_match("/gem\s+['\"]jekyll['\"]/i", File::get($projectPath . '/Gemfile')));

                // Detección de Ruby on Rails
                $isRails = File::exists($projectPath . '/bin/rails')
                    || File::exists($projectPath . '/config/environment.rb')
                    || File::exists($projectPath . '/config/application.rb');

                if ($isRails) {
                    if (File::exists($projectPath . '/config/environments/production.rb')) {
                        $prodConfig = File::get($projectPath . '/config/environments/production.rb');
                        if (!str_contains($prodConfig, 'active_storage.service')) {
                            File::append($projectPath . '/config/environments/production.rb', "\nRails.application.configure do\n  config.active_storage.service = :local if config.respond_to?(:active_storage)\nend\n");
                        }
                    }
                    $railsBin = File::exists($projectPath . '/bin/rails') ? 'bin/rails' : 'rails';
                    $command = ['sh', '-c', "if [ ! -d public/assets ] || [ -z \"$(ls -A public/assets 2>/dev/null)\" ]; then (bundle exec {$railsBin} assets:precompile RAILS_ENV=production || true); fi && rm -f tmp/pids/server.pid && exec bundle exec {$railsBin} server -b 0.0.0.0 -p 3000 -e production"];
                } elseif ($isJekyll) {
                    $jekyllBin = File::exists($projectPath . '/exe/jekyll') ? 'exe/jekyll' : (File::exists($projectPath . '/bin/jekyll') ? 'bin/jekyll' : 'jekyll');
                    $srcFlag = File::exists($projectPath . '/docs/_config.yml') && !File::exists($projectPath . '/_config.yml')
                        ? '-s docs -d docs/_site'
                        : '';
                    $command = ['sh', '-c', "git config --global --add safe.directory '*' 2>/dev/null || true; exec bundle exec {$jekyllBin} serve {$srcFlag} -H 0.0.0.0 -P 3000 --no-watch"];
                } elseif (File::exists($projectPath . '/config.ru')) {
                    $command = ['bundle', 'exec', 'rackup', '-o', '0.0.0.0', '-p', '3000', '-E', 'production'];
                } elseif (File::exists($projectPath . '/app.rb')) {
                    $command = ['ruby', 'app.rb', '-o', '0.0.0.0', '-p', '3000'];
                } elseif (File::exists($projectPath . '/server.rb')) {
                    $command = ['ruby', 'server.rb', '-o', '0.0.0.0', '-p', '3000'];
                } elseif (File::exists($projectPath . '/main.rb')) {
                    $command = ['ruby', 'main.rb', '-o', '0.0.0.0', '-p', '3000'];
                } else {
                    $railsBin = File::exists($projectPath . '/bin/rails') ? 'bin/rails' : 'rails';
                    $command = ['sh', '-c', "rm -f tmp/pids/server.pid && exec bundle exec {$railsBin} server -b 0.0.0.0 -p 3000 -e production"];
                }
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
                return [
                    'image' => $rubyImage,
                    'port' => 3000,
                    'command' => $command
                ];

            case 'python':
                $entryFile = 'main.py';
                if (File::exists($projectPath . '/app.py')) {
                    $entryFile = 'app.py';
                }

                $entryModule = str_replace('.py', '', $entryFile);

                // Auto-generar manage.py si es un proyecto Django (o app Django empaquetada) que no lo incluye
                $isDjango = ($project->framework === 'django') 
                    || File::exists($projectPath . '/manage.py')
                    || File::exists($projectPath . '/test_settings.py')
                    || !empty(glob($projectPath . '/*settings*.py'));

                if ($isDjango && !File::exists($projectPath . '/manage.py')) {
                    $djangoSettingsModule = $this->detectDjangoSettingsModule($projectPath) ?: (File::exists($projectPath . '/test_settings.py') ? 'test_settings' : 'settings');
                    $managePyContent = "#!/usr/bin/env python\nimport os\nimport sys\n\ndef main():\n    os.environ.setdefault('DJANGO_SETTINGS_MODULE', '{$djangoSettingsModule}')\n    try:\n        from django.core.management import execute_from_command_line\n    except ImportError as exc:\n        raise ImportError(\"Couldn't import Django.\") from exc\n    execute_from_command_line(sys.argv)\n\nif __name__ == '__main__':\n    main()\n";
                    File::put($projectPath . '/manage.py', $managePyContent);
                    @chmod($projectPath . '/manage.py', 0755);
                }

                // Auto-parchear base_urls.py si existe para compatibilidad con Django 3/4 y habilitar admin
                if (File::exists($projectPath . '/base_urls.py')) {
                    $buContent = File::get($projectPath . '/base_urls.py');
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
                    File::put($projectPath . '/base_urls.py', $buContent);
                }

                // 1. Django: manage.py
                if (File::exists($projectPath . '/manage.py')) {
                    $djangoSettingsModule = $this->detectDjangoSettingsModule($projectPath);
                    $hasWhiteNoise = false;
                    $reqFiles = array_merge(
                        glob($projectPath . '/requirements.txt') ?: [],
                        glob($projectPath . '/requirements/*.txt') ?: [],
                        glob($projectPath . '/*requirements*.txt') ?: []
                    );
                    foreach ($reqFiles as $rf) {
                        if (str_contains(strtolower(@file_get_contents($rf) ?: ''), 'whitenoise')) {
                            $hasWhiteNoise = true;
                            break;
                        }
                    }
                    if (!$hasWhiteNoise && File::exists($projectPath . '/Pipfile')) {
                        $hasWhiteNoise = str_contains(strtolower(File::get($projectPath . '/Pipfile')), 'whitenoise');
                    }
                    if (!$hasWhiteNoise && File::exists($projectPath . '/pyproject.toml')) {
                        $hasWhiteNoise = str_contains(strtolower(File::get($projectPath . '/pyproject.toml')), 'whitenoise');
                    }

                    if ($hasWhiteNoise) {
                        $command = ['python', 'manage.py', 'runserver', '0.0.0.0:5000', '--nostatic'];
                    } else {
                        $command = ['python', 'manage.py', 'runserver', '0.0.0.0:5000', '--insecure'];
                    }

                    if ($djangoSettingsModule) {
                        $command[] = "--settings={$djangoSettingsModule}";
                    }
                }
                // 2. FastAPI: detect FastAPI
                elseif ($project->framework === 'fastapi' ||
                    File::exists($projectPath . '/.venv/bin/uvicorn') ||
                    (File::exists($projectPath . '/' . $entryFile) && str_contains(File::get($projectPath . '/' . $entryFile), 'FastAPI'))
                ) {
                    $appVar = 'app';
                    $entryContent = File::exists($projectPath . '/' . $entryFile) ? File::get($projectPath . '/' . $entryFile) : '';
                    if (str_contains($entryContent, 'application = FastAPI')) {
                        $appVar = 'application';
                    }
                    $command = ['uvicorn', "{$entryModule}:{$appVar}", '--host', '0.0.0.0', '--port', '5000'];
                }
                // 3. Flask
                elseif ($project->framework === 'flask' ||
                    (File::exists($projectPath . '/' . $entryFile) && str_contains(File::get($projectPath . '/' . $entryFile), 'Flask')) ||
                    File::exists($projectPath . '/.flaskenv') ||
                    File::exists($projectPath . '/microblog.py')
                ) {
                    $flaskApp = null;
                    if (File::exists($projectPath . '/microblog.py')) {
                        $flaskApp = 'microblog.py';
                    } elseif (File::exists($projectPath . '/app.py')) {
                        $flaskApp = 'app.py';
                    } elseif (File::exists($projectPath . '/wsgi.py')) {
                        $flaskApp = 'wsgi.py';
                    } elseif (File::exists($projectPath . '/main.py')) {
                        $flaskApp = 'main.py';
                    }

                    $command = $flaskApp
                        ? ['python', '-m', 'flask', '--app', $flaskApp, 'run', '--host=0.0.0.0', '--port=5000']
                        : ['python', '-m', 'flask', 'run', '--host=0.0.0.0', '--port=5000'];
                }
                // 4. Genérico: python app.py / python main.py
                else {
                    $command = ['python', $entryFile];
                }

                // If virtual env exists, run from it
                if (File::isDirectory($projectPath . '/.venv')) {
                    $command[0] = './.venv/bin/' . $command[0];
                }

                return [
                    'image' => 'python:3.12-slim',
                    'port' => 5000,
                    'command' => $command
                ];

            case 'java':
                // Detectar versión de Java configurada (Java 8 vs 17+)
                $javaImage = 'eclipse-temurin:17-jre-alpine';
                $pomFile = $projectPath . '/pom.xml';
                $gradleFile = $projectPath . '/build.gradle';
                $systemProps = $projectPath . '/system.properties';

                $isJava8 = false;
                if (File::exists($pomFile)) {
                    $pomXml = File::get($pomFile);
                    if (preg_match('/<(java\.version|maven\.compiler\.source|maven\.compiler\.target)>(1\.8|8)<\//i', $pomXml)) {
                        $isJava8 = true;
                    }
                }
                if (!$isJava8 && File::exists($gradleFile)) {
                    $gradleContent = File::get($gradleFile);
                    if (preg_match('/sourceCompatibility\s*=\s*[\'"]?(1\.8|8)[\'"]?/i', $gradleContent)) {
                        $isJava8 = true;
                    }
                }
                if (!$isJava8 && File::exists($systemProps)) {
                    $sysContent = File::get($systemProps);
                    if (preg_match('/java\.runtime\.version\s*=\s*(1\.8|8)/i', $sysContent)) {
                        $isJava8 = true;
                    }
                }

                if ($isJava8) {
                    $javaImage = 'eclipse-temurin:8-jre-alpine';
                }

                // 1. Soporte para aplicaciones WAR empaquetadas con webapp-runner (Tomcat embebido)
                $warFiles = glob($projectPath . '/target/*.war');
                $webappRunner = $projectPath . '/target/dependency/webapp-runner.jar';
                if (File::exists($webappRunner) && !empty($warFiles)) {
                    $warFile = basename($warFiles[0]);
                    return [
                        'image'   => $javaImage,
                        'port'    => 8080,
                        'command' => ['sh', '-c', "java -jar /app/target/dependency/webapp-runner.jar --port 8080 /app/target/{$warFile}"]
                    ];
                }

                // 2. Soporte si el proyecto incluye Procfile
                $procfile = $projectPath . '/Procfile';
                if (File::exists($procfile)) {
                    $procContent = File::get($procfile);
                    if (preg_match('/^web:\s*(.+)$/m', $procContent, $matches)) {
                        $webCmd = trim($matches[1]);

                        // Si el Procfile especifica ejecutar un JAR, verificar que el archivo o patrón exista en el proyecto
                        $jarExists = true;
                        if (preg_match('/-jar\s+([^\s]+)/', $webCmd, $jarMatches)) {
                            $jarPattern = trim($jarMatches[1], "'\"");
                            $foundJars = glob($projectPath . '/' . ltrim($jarPattern, '/'));
                            if (empty($foundJars)) {
                                $jarExists = false;
                            }
                        }

                        if ($jarExists) {
                            $webCmd = str_replace('$PORT', '8080', $webCmd);
                            $webCmd = str_replace('${PORT}', '8080', $webCmd);
                            $webCmd = str_replace('$JAVA_OPTS', '', $webCmd);
                            return [
                                'image'   => $javaImage,
                                'port'    => 8080,
                                'command' => ['sh', '-c', "cd /app && {$webCmd}"]
                            ];
                        }
                    }
                }

                // 3. Aplicaciones convencionales Spring Boot en JAR ejecutable
                return [
                    'image'   => $javaImage,
                    'port'    => 8080,
                    'command' => ['sh', '-c', "JAR_FILE=\$(ls /app/build/libs/*.jar /app/target/*.jar /app/*.jar 2>/dev/null | grep -v -- '-plain.jar' | head -n 1); if [ -z \"\$JAR_FILE\" ]; then JAR_FILE=\$(ls /app/build/libs/*.jar /app/target/*.jar /app/*.jar 2>/dev/null | head -n 1); fi; exec java -Dserver.port=8080 -jar \"\$JAR_FILE\" --server.port=8080"]
                ];

            case 'dotnet':
                // Detect target framework version from csproj or published runtimeconfig
                $dotnetVersion = '8.0';
                $runtimeConfigs = glob($projectPath . '/publish/*.runtimeconfig.json');
                $targetFile = !empty($runtimeConfigs) ? $runtimeConfigs[0] : null;
                if ($targetFile && File::exists($targetFile)) {
                    $jsonContent = @file_get_contents($targetFile) ?: '';
                    if (str_contains($jsonContent, '"version": "10.') || str_contains($jsonContent, 'net10')) {
                        $dotnetVersion = '10.0';
                    } elseif (str_contains($jsonContent, '"version": "9.') || str_contains($jsonContent, 'net9')) {
                        $dotnetVersion = '9.0';
                    }
                } else {
                    $csprojs = glob($projectPath . '/*.csproj');
                    if (empty($csprojs)) $csprojs = glob($projectPath . '/*/*.csproj');
                    if (empty($csprojs)) $csprojs = glob($projectPath . '/*/*/*.csproj');
                    if (!empty($csprojs) && File::exists($csprojs[0])) {
                        $csprojContent = @file_get_contents($csprojs[0]) ?: '';
                        if (str_contains($csprojContent, 'net10') || str_contains($csprojContent, 'net10.0')) {
                            $dotnetVersion = '10.0';
                        } elseif (str_contains($csprojContent, 'net9') || str_contains($csprojContent, 'net9.0')) {
                            $dotnetVersion = '9.0';
                        }
                    }
                }

                $aspnetImage = match ($dotnetVersion) {
                    '10.0' => 'mcr.microsoft.com/dotnet/aspnet:10.0-preview',
                    '9.0'  => 'mcr.microsoft.com/dotnet/aspnet:9.0-alpine',
                    default => 'mcr.microsoft.com/dotnet/aspnet:8.0-alpine',
                };

                return [
                    'image'   => $aspnetImage,
                    'port'    => 8080,
                    'command' => ['sh', '-c', 'ENTRY_DLL=$(ls /app/publish/*.runtimeconfig.json 2>/dev/null | sed "s/\\.runtimeconfig\\.json/\\.dll/" | head -n 1); if [ -f "$ENTRY_DLL" ]; then exec dotnet "$ENTRY_DLL"; else exec dotnet $(ls /app/publish/*.dll 2>/dev/null | head -n 1); fi']
                ];

            case 'dockerfile':
                // Use the image built by buildDockerfile().
                // basename($projectPath) = 'project-{UUID}', so image = 'project-{UUID}-img'
                // which matches what buildDockerfile() creates: 'project-' . $project->id . '-img'
                $port = $this->detectDockerfilePort($projectPath);
                $cmd = $this->detectDockerfileCommand($projectPath);
                return [
                    'image'   => basename($projectPath) . '-img',
                    'port'    => $port,
                    'command' => $cmd
                ];

            default:
                return null;
        }
    }

    /**
     * Detect the EXPOSE port from a project's Dockerfile.
     * Falls back to 8080 if not found.
     */
    private function detectDockerfilePort(string $projectPath): int
    {
        $dockerfilePath = File::exists($projectPath . '/Dockerfile')
            ? $projectPath . '/Dockerfile'
            : $projectPath . '/dockerfile';

        if (!File::exists($dockerfilePath)) {
            return 8080;
        }

        $content = File::get($dockerfilePath);
        if (preg_match('/^EXPOSE\s+(\d+)/mi', $content, $matches)) {
            return (int) $matches[1];
        }

        return 8080;
    }

    /**
     * Detect the WORKDIR from a project's Dockerfile.
     * Falls back to /app if not found.
     */
    private function detectDockerfileWorkdir(string $projectPath): string
    {
        $dockerfilePath = File::exists($projectPath . '/Dockerfile')
            ? $projectPath . '/Dockerfile'
            : $projectPath . '/dockerfile';

        if (!File::exists($dockerfilePath)) {
            return '/app';
        }

        $content = File::get($dockerfilePath);
        if (preg_match('/^WORKDIR\s+(\S+)/mi', $content, $matches)) {
            return trim($matches[1]);
        }

        return '/app';
    }

    /**
     * Detect and adapt the CMD from a project's Dockerfile.
     * Reduces multi-worker servers (like uvicorn/gunicorn --workers=2+) to 1 worker for the 256MB sandbox limit.
     */
    private function detectDockerfileCommand(string $projectPath): array
    {
        $dockerfilePath = File::exists($projectPath . '/Dockerfile')
            ? $projectPath . '/Dockerfile'
            : $projectPath . '/dockerfile';

        if (!File::exists($dockerfilePath)) {
            return [];
        }

        $content = File::get($dockerfilePath);
        if (preg_match('/CMD\s*(\[[^\]]+\])/i', $content, $matches)) {
            $cmdArray = json_decode($matches[1], true);
            if (is_array($cmdArray)) {
                // Adaptar flags de workers pesados para sandbox de 256MB
                return array_map(function($arg) {
                    return preg_replace('/--workers=\d+/', '--workers=1', $arg);
                }, $cmdArray);
            }
        }
        return [];
    }

    /**
     * Analiza los logs de colapso y genera un diagnóstico comprensible y pedagógico para el estudiante.
     */
    private function formatFriendlyCrashDiagnosis(string $crashLogs, Project $project, string $exitCode): string
    {
        $logLower = strtolower($crashLogs);

        // Extraer la linea significativa de error para casos no catalogados
        $lastErrorLine = '';
        if (!empty($crashLogs)) {
            // 1. Prioridad: Capturar la excepción o mensaje de error real (Ruby, Python, Node, Java, Go)
            if (preg_match('/([A-Z][a-zA-Z0-9_:]*(?:Error|Exception|Fault|Failure|Violation)[^:\r\n]*:[^\r\n]+)/m', $crashLogs, $excMatch)) {
                $lastErrorLine = trim($excMatch[1]);
            }

            // 2. Si no hubo coincidencia regex directa, buscar hacia atrás ignorando líneas de stacktrace (from, at, etc.)
            if (empty($lastErrorLine)) {
                $lines = array_filter(array_map('trim', explode("\n", $crashLogs)));
                $reversed = array_reverse($lines);
                foreach ($reversed as $l) {
                    if (strlen($l) > 6 && 
                        !str_starts_with($l, 'from ') && 
                        !str_starts_with($l, 'at ') && 
                        !str_starts_with($l, '#') &&
                        !str_starts_with($l, 'Tasks:') &&
                        !str_starts_with($l, 'npm notice') &&
                        !str_contains($l, ':in `<main>\'') &&
                        !str_contains($l, ':in `block')
                    ) {
                        $lastErrorLine = $l;
                        break;
                    }
                }

                if (empty($lastErrorLine) && !empty($lines)) {
                    foreach ($lines as $l) {
                        if (strlen($l) > 6 && !str_starts_with($l, 'from ') && !str_starts_with($l, 'at ')) {
                            $lastErrorLine = $l;
                            break;
                        }
                    }
                }
            }
        }

        $titulo = "Error Inesperado en la Aplicacion";
        $quePaso = "Tu servidor se inicio pero se cerro repentinamente al intentar ejecutarse.";
        if (!empty($lastErrorLine)) {
            $quePaso .= "\nDetalle reportado por el servidor: \"{$lastErrorLine}\"";
        }
        $solucion = "- Revisa el mensaje anterior para corregir el archivo o variable correspondiente.\n- Prueba ejecutar tu proyecto localmente para validar que el servidor inicie correctamente y luego haz un nuevo push.";

        // Caso 1: Software no soportado o que requiere binarios de sistema ajenos al hosting web (Leptonica, libvips, poppler, tesseract, etc.)
        if (str_contains($logLower, 'libleptonica') || str_contains($logLower, 'leptonica') || 
            str_contains($logLower, 'cannot open shared object file') || str_contains($logLower, 'shared object file') ||
            str_contains($logLower, 'could not open library') || str_contains($logLower, 'libvips') ||
            str_contains($logLower, 'libgomp') || str_contains($logLower, 'tesseract')) {
            $titulo = "Tipo de Proyecto No Soportado (Arquitectura Incompatible)";
            $quePaso = "Este repositorio no es una aplicacion web estandar, sino un software especializado (appliance) que exige herramientas binarias de C/C++ de bajo nivel. La plataforma esta diseñada exclusivamente para el despliegue de aplicaciones y sitios web academicos.";
            $solucion = "- Despliega unicamente proyectos de desarrollo web academico (Laravel, Node.js, Django, React, Rails, etc.) basados en librerias estandar del lenguaje.\n- El software empresarial de terceros o herramientas con dependencias nativas de bajo nivel del sistema operativo no son compatibles con este entorno de hosting.";
        }
        // Caso 2a: Error de conexión a la Base de Datos
        elseif (str_contains($logLower, 'connection refused') || str_contains($logLower, 'connectionbad') || 
                str_contains($logLower, 'econnrefused') || str_contains($logLower, 'password authentication failed') ||
                str_contains($logLower, 'could not connect to server') || str_contains($logLower, 'access denied for user')) {
            $titulo = "Error de Conexión con la Base de Datos";
            $quePaso = "El servidor intentó conectarse a la base de datos pero la conexión fue rechazada o las credenciales no son válidas.";
            $solucion = "- Verifica que tu base de datos esté encendida en la plataforma.\n- Revisa las variables de entorno en la configuración para confirmar que DB_HOST, DB_PORT, DB_USERNAME y DB_PASSWORD sean los asignados por el sistema.";
        }
        // Caso 2b: Formato inválido de URI / Hostname en base de datos
        elseif (str_contains($logLower, 'invalidurierror') || str_contains($logLower, 'does not accept registry part') || str_contains($logLower, 'bad hostname')) {
            $titulo = "Error de URI en Conexión de Base de Datos";
            $quePaso = "La URL de conexión a la base de datos no tiene un formato válido o contiene caracteres no permitidos en el nombre del host.";
            $solucion = "- Revisa la variable de entorno DATABASE_URL o el archivo config/database.yml.\n- Asegúrate de que el nombre de host no contenga caracteres inválidos como guiones bajos.";
        }
        // Caso 3: Módulos o dependencias faltantes
        elseif (str_contains($logLower, 'cannot find module') || str_contains($logLower, 'modulenotfounderror') || 
                str_contains($logLower, 'no module named') || str_contains($logLower, 'cannot load such file') ||
                str_contains($logLower, 'loaderror') || str_contains($logLower, 'class not found')) {
            $titulo = "Dependencia o Módulo No Instalado";
            $quePaso = "El código intenta importar un paquete o módulo que no se encuentra instalado en las dependencias.";
            if (!empty($lastErrorLine)) {
                $quePaso .= "\nDetalle del error reportado: \"{$lastErrorLine}\"";
            }
            $solucion = "- Declara todas tus librerías en tu archivo de configuración (package.json, requirements.txt, Gemfile o composer.json).\n- Asegúrate de hacer 'git commit' y 'git push' de dicho archivo en tu repositorio.";
        }
        // Caso 4: Falta el comando de inicio o script start
        elseif (str_contains($logLower, 'missing script: "start"') || str_contains($logLower, 'command not found') || $exitCode === '127') {
            $titulo = "Archivo o Comando de Inicio No Encontrado";
            $quePaso = "La plataforma intentó iniciar tu proyecto pero no encontró el script de inicio principal.";
            $solucion = "- En Node.js: Asegúrate de tener la clave \"start\" dentro de \"scripts\" en tu package.json (ej: \"start\": \"node index.js\").\n- En Python o PHP: Asegúrate de que el archivo principal (app.py, main.py, index.php) esté en la raíz de tu proyecto.";
        }
        // Caso 5: Error de Sintaxis o Excepción no controlada en el código
        elseif (str_contains($logLower, 'syntaxerror') || str_contains($logLower, 'parse error') || str_contains($logLower, 'syntax error') ||
                str_contains($logLower, 'indentationerror')) {
            $titulo = "Error de Sintaxis en el Código";
            $quePaso = "El código de tu aplicación contiene un error de sintaxis que impide al intérprete ejecutar el proyecto.";
            $solucion = "- Corrige el error de sintaxis en tu computadora localmente y haz un nuevo push a GitHub.";
        }
        // Caso 6: Conflicto de puerto
        elseif (str_contains($logLower, 'eaddrinuse') || str_contains($logLower, 'address already in use')) {
            $titulo = "Conflicto de Puerto de Red";
            $quePaso = "La aplicación intentó abrir un puerto que ya estaba en uso o no disponible.";
            $solucion = "- Configura tu servidor para escuchar en la variable de entorno PORT (ej: process.env.PORT || 3000).";
        }

        $headerText = str_contains($titulo, 'No Soportado') ? $titulo : "ERROR AL INICIAR EL SERVIDOR: {$titulo}";

        $out = "\n=======================================================\n";
        $out .= "{$headerText}\n";
        $out .= "=======================================================\n\n";
        $out .= "QUE PASO:\n";
        $out .= "{$quePaso}\n\n";
        $out .= "COMO SOLUCIONARLO:\n";
        $out .= "{$solucion}\n";
        $out .= "=======================================================\n";

        return $out;
    }

    private function detectDjangoSettingsModule(string $projectPath): ?string
    {
        // 1. Si manage.py define DJANGO_SETTINGS_MODULE por defecto
        if (File::exists($projectPath . '/manage.py')) {
            $manageContent = File::get($projectPath . '/manage.py');
            if (preg_match('/["\']DJANGO_SETTINGS_MODULE["\']\s*,\s*["\']([^"\']+)["\']/', $manageContent, $m)) {
                return $m[1];
            }
        }

        // 2. Si ya está definido en .env o .env.example
        foreach (['.env', '.env.example', 'example.env'] as $envFile) {
            if (File::exists($projectPath . '/' . $envFile)) {
                $envContent = File::get($projectPath . '/' . $envFile);
                if (preg_match('/^DJANGO_SETTINGS_MODULE\s*=\s*(.+)$/m', $envContent, $m)) {
                    $val = trim($m[1], " \t\n\r\0\x0B\"'");
                    if (!empty($val)) return $val;
                }
            }
        }

        // 3. Buscar en Procfile o Procfile.sh (común en babybuddy u otras apps)
        foreach (['Procfile', 'Procfile.sh'] as $pf) {
            if (File::exists($projectPath . '/' . $pf)) {
                $pfContent = File::get($projectPath . '/' . $pf);
                if (preg_match('/DJANGO_SETTINGS_MODULE=([a-zA-Z0-9_\.]+)/', $pfContent, $m)) {
                    return $m[1];
                }
                if (preg_match('/gunicorn\s+([a-zA-Z0-9_]+)\.wsgi/i', $pfContent, $m)) {
                    $pkg = $m[1];
                    if (File::exists($projectPath . "/{$pkg}/settings/production.py")) return "{$pkg}.settings.production";
                    if (File::exists($projectPath . "/{$pkg}/settings/base.py")) return "{$pkg}.settings.base";
                    if (File::exists($projectPath . "/{$pkg}/settings/development.py")) return "{$pkg}.settings.development";
                    if (File::exists($projectPath . "/{$pkg}/settings.py")) return "{$pkg}.settings";
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
