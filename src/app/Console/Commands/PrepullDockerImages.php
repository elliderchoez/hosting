<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PrepullDockerImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:prepull-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-descarga y pre-calienta las imágenes base de Docker para acelerar los despliegues de todas las tecnologías.';

    /**
     * Imágenes base oficiales requeridas para compilación y ejecución en la plataforma.
     */
    protected array $baseImages = [
        // Entornos de Compilación y Dependencias
        'node:20-alpine'                         => 'Node.js 20 (Compilador Frontend / NPM)',
        'node:18-alpine'                         => 'Node.js 18 (Compatibilidad heredada)',
        'composer:latest'                        => 'Composer (Gestor PHP/Laravel)',
        'python:3.12-alpine'                     => 'Python 3.12 (Gestor Pip/Venv)',
        'maven:3.9-eclipse-temurin-17-alpine'    => 'Java Maven 3.9 (Compilador Spring Boot)',
        'mcr.microsoft.com/dotnet/sdk:8.0-alpine'=> '.NET SDK 8.0 (Compilador ASP.NET Core)',
        
        // Entornos de Ejecución de Contenedores
        'webdevops/php:8.4'                      => 'PHP 8.4 FPM / Apache (Monolitos Laravel)',
        'eclipse-temurin:17-jre-alpine'          => 'Java 17 JRE (Runtime de Fat JARs)',
        'mcr.microsoft.com/dotnet/aspnet:8.0'    => '.NET ASP.NET 8.0 (Runtime C#)',
        
        // Bases de Datos y Proxy
        'postgres:15-alpine'                     => 'PostgreSQL 15 Client/Server',
        'mysql:8.0'                              => 'MySQL 8.0 Client/Server',
        'traefik:latest'                         => 'Traefik Reverse Proxy',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("===================================================================");
        $this->info(" Nexus Academic - Pre-calentamiento de Imágenes Docker Base");
        $this->info("===================================================================\n");

        // 1. Obtener lista de imágenes locales
        $checkProcess = new Process(['docker', 'images', '--format', '{{.Repository}}:{{.Tag}}']);
        $checkProcess->run();
        $localImages = array_filter(array_map('trim', explode("\n", $checkProcess->getOutput())));

        $total = count($this->baseImages);
        $current = 1;

        foreach ($this->baseImages as $image => $description) {
            $this->output->write("[{$current}/{$total}] {$image} ({$description})... ");

            $alreadyPulled = false;
            foreach ($localImages as $localImg) {
                if ($localImg === $image || str_starts_with($localImg, $image)) {
                    $alreadyPulled = true;
                    break;
                }
            }

            if ($alreadyPulled) {
                $this->info("✓ YA PRESENTE");
            } else {
                $this->warn("⬇ DESCARGANDO...");
                $pullProcess = new Process(['docker', 'pull', $image]);
                $pullProcess->setTimeout(600); // 10 minutes timeout per image
                $pullProcess->run();

                if ($pullProcess->isSuccessful()) {
                    $this->info("   ✓ Descarga completada exitosamente.");
                } else {
                    $this->error("   ✗ Error al descargar: " . $pullProcess->getErrorOutput());
                }
            }

            $current++;
        }

        $this->info("\n✓ Proceso de pre-calentamiento completado. El motor de compilación operará a máxima velocidad.");
        return 0;
    }
}
