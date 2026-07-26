# Manual Técnico de Implementación y Arquitectura

## Proyecto: ULEAM Academic Hosting
**Versión:** 1.0  
**Fecha:** 14 de Junio de 2026  
**Autor:** Choez David  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  

---

## 1. Arquitectura de Software
La plataforma principal está diseñada bajo un enfoque monolítico híbrido utilizando el patrón **MVC (Modelo-Vista-Controlador)** extendido con **Service-Action Pattern**, implementando las siguientes capas tecnológicas:

```
[ Frontend: React 18 + Vite ] <--- (Inertia.js Protocol) ---> [ Backend: Laravel 11 ]
                                                                   │
                                                                   ├── Eloquent ORM --> PostgreSQL
                                                                   ├── Jobs / Horizon --> Redis (Build Queue)
                                                                   └── Docker HTTP API / Shell --> Docker Engine
```

* **Frontend:** Desarrollado en **React 18** utilizando **Vite** como empaquetador y **TailwindCSS** para el diseño de interfaces. La comunicación con el backend se realiza a través de **Inertia.js**, eliminando la necesidad de APIs complejas (REST/GraphQL) y manejo manual de estados de sesión.
* **Backend:** Desarrollado en **Laravel 11 (PHP 8.2+)**. Se encarga de la lógica de negocio, autenticación, gestión de base de datos relacional y comunicación con el daemon de Docker del servidor.
* **Base de Datos Principal:** **PostgreSQL 16** para el almacenamiento estructurado de datos (usuarios, perfiles profesionales, registros de proyectos e historial de despliegues).
* **Cola de Procesos y Caché:** **Redis 7** en conjunto con **Laravel Horizon** para gestionar la cola asíncrona de clonación y compilación de proyectos.

---

## 2. Arquitectura de Contenedores y Seguridad (PaaS)
La infraestructura del servidor ejecuta Docker y gVisor para proveer aislamiento total de las aplicaciones de los estudiantes.

```
                  Petición Externa (*.uleam-academic.software)
                                │
                                ▼
                       [ Puerto 80/443 ]
                                │
                      [ Proxy Traefik ]
                                │
        ┌───────────────────────┴───────────────────────┐
        ▼ (Red Aislada 1)                               ▼ (Red Aislada 2)
[ Runner Node.js - gVisor ]                     [ Runner PHP - gVisor ]
 ├── Código Estudiante A                         ├── Código Estudiante B
 └── Sandbox runsc                               └── Sandbox runsc
```

### 2.1 Proxy Inverso: Traefik
**Traefik Proxy 3.0** se ejecuta en un contenedor Docker con acceso de solo lectura al socket de Docker (`/var/run/docker.sock`).
* **Enrutamiento Dinámico:** Traefik escucha los eventos del daemon de Docker. Cuando la aplicación principal enciende un contenedor de estudiante con las etiquetas (labels) de Traefik correspondientes, este actualiza automáticamente sus tablas de enrutamiento sin necesidad de recargar la configuración.
* **Certificados SSL Automáticos:** Integra un resolvedor ACME configurado con **Let's Encrypt** para generar, servir y renovar automáticamente certificados HTTPS de comodín (wildcard) o subdominios específicos.

### 2.2 Motor de Contenedores: Docker Engine
Administra el ciclo de vida de los proyectos. Para mitigar problemas de almacenamiento (límite de 200 GB en disco), el sistema no genera imágenes Docker individuales. En su lugar, utiliza **Imágenes Base Genéricas (Alpine Linux)** pre-instaladas:
* `uleam/runner-node:18-alpine`
* `uleam/runner-php:8.2-alpine`
* `uleam/runner-python:3.11-alpine`

Cuando el estudiante activa su proyecto, el sistema crea un contenedor utilizando la imagen base y **monta como un volumen de solo lectura** la carpeta de su código previamente clonado y compilado.

### 2.3 Capa de Aislamiento de Seguridad: gVisor (`runsc`)
Para evitar el escape de contenedores (container escape), registramos gVisor como un runtime de Docker.
* **Definición:** gVisor es una solución desarrollada por Google que implementa un kernel en espacio de usuario. Intercepta todas las llamadas del sistema que realiza la aplicación del estudiante y filtra solo llamadas seguras al kernel de Linux del host.
* **Configuración del Daemon de Docker (`/etc/docker/daemon.json`):**
```json
{
  "runtimes": {
    "runsc": {
      "path": "/usr/local/bin/runsc"
    }
  }
}
```
* **Uso:** Al crear los contenedores de los estudiantes, Laravel agregará el parámetro `--runtime=runsc` a la API de Docker, forzando a que la app se ejecute dentro del sandbox seguro.

---

## 3. Flujo de Trabajo del Despliegue (Build Pipeline)
El proceso de despliegue automático consta de las siguientes fases asíncronas controladas por **Laravel Jobs**:

1. **Recepción del Evento:** El estudiante envía la URL de su repositorio público de GitHub y la rama (ej. `main`).
2. **Encolamiento:** Laravel crea un registro de despliegue con estado `Pending` e introduce la tarea `BuildProjectJob` en la cola de Redis.
3. **Clonación:** El worker de Laravel clona el repositorio en una ruta local aislada del servidor (ej. `/var/www/uleam-academic/storage/projects/[proyecto_id]`).
4. **Instalación y Compilación:** El sistema ejecuta de forma temporal un contenedor de compilación para instalar dependencias según el lenguaje detectado:
   * Node.js: ejecuta `npm install && npm run build` (si tiene scripts de build).
   * PHP: ejecuta `composer install --no-dev --optimize-autoloader`.
5. **Configuración de Red y Traefik:** Laravel crea una red Docker puente aislada para el proyecto y asocia los labels de Traefik para el subdominio:
   * `traefik.http.routers.[name].rule=Host([subdominio])`
   * `traefik.http.routers.[name].entrypoints=websecure`
   * `traefik.http.routers.[name].tls.certresolver=myresolver`
6. **Ejecución en gVisor:** Se inicia el contenedor de ejecución correspondiente con los límites estipulados (0.5 cores CPU, 256MB RAM, `--runtime=runsc`).

---

## 4. Algoritmo de Auto-Sleep (Escalamiento a Cero)
Para conservar memoria RAM en el servidor, las aplicaciones inactivas deben apagarse.

```
       [ Petición HTTP ] ──> [ Traefik ] ──> [ ¿Contenedor Activo? ]
                                                    │
                                           ┌────────┴────────┐
                                         SI│                 │NO
                                           ▼                 ▼
                                    Enrutar Petición    [ Cold Start ]
                                                        1. Laravel levanta contenedor
                                                        2. Traefik re-enruta
                                                        3. Retraso: 3-8 segundos
```

### Mecanismo Técnico:
1. **Monitoreo de Tráfico:** Traefik registra las peticiones HTTP de cada subdominio.
2. **Script Demonio (Cron/Daemon):** Un proceso en Laravel (ejecutado cada 5 minutos mediante programador de tareas `php artisan schedule:run`) consulta el consumo de red de cada contenedor a través de la API de Docker.
3. **Apagado por Inactividad:** Si un contenedor no ha procesado ninguna petición en los últimos 15 minutos, Laravel envía una instrucción a Docker para cambiar su estado a `STOPPED`. Esto libera inmediatamente los 256 MB de RAM asignados al contenedor.
4. **Cold Start (Reactivación al Vuelo):** Cuando llega una nueva petición a un subdominio cuyo contenedor está en modo *sleep*:
   * Traefik, mediante el middleware de Laravel, detecta que el contenedor no está activo.
   * Redirige la petición a una página de carga del sistema principal.
   * El sistema envía un comando de Docker Start en segundo plano.
   * En 3-8 segundos, el contenedor está en ejecución y Traefik completa la redirección de forma transparente para el visitante.
