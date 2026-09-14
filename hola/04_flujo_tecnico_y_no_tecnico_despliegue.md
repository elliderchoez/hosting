# Flujo Técnico y No Técnico de Despliegue de Proyectos desde Cero

**Documento:** Guía Comparativa de Flujo de Despliegue (Conceptual y de Código Fuente)  
**Proyecto:** ULEAM Academic Hosting (PaaS)  
**Autor:** David Choez  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  

---

# PARTE 1: FLUJO NO TÉCNICO (Para Explicar al Tutor o Tribunal)

Esta sección está redactada en un lenguaje claro, accesible y orientado al valor funcional del proyecto, ideal para presentar la idea general a docentes o evaluadores sin abrumarlos con código.

### 1. El Problema que ve el Usuario
Un estudiante de Ingeniería en Software termina un proyecto en su computadora. Hasta hoy, para presentarlo, debía llevar su laptop, conectar cables, rezar para que no falle la versión de su base de datos local, o peor: enviar un enlace a un repositorio de GitHub que el docente o reclutador rara vez descarga e instala por falta de tiempo.

### 2. La Experiencia del Estudiante ("Publicar en 3 Pasos")
* **Paso 1 (Identificación):** El alumno ingresa a la plataforma web de la ULEAM con su cuenta institucional y va a su panel de control.
* **Paso 2 (Configuración mínima):** Escribe el nombre de su proyecto (ej. *Sistema de Facturación Crater*), elige un subdominio (ej. `crater`), selecciona qué base de datos necesita (MySQL, Postgres o Mongo) y pega el enlace de su GitHub o arrastra un archivo ZIP.
* **Paso 3 (Publicar):** Presiona el botón **"Desplegar Proyecto"**. A partir de ese segundo, el estudiante se desentiende de cualquier configuración de servidores. La plataforma asume el control total de forma autónoma.

### 3. Lo que hace el "Cerebro" de la Plataforma en Segundo Plano (La Fábrica Invisible)
Mientras el estudiante ve una barra de progreso en pantalla, el sistema trabaja como una fábrica automatizada:
1. **Descarga:** Toma el código del estudiante y lo coloca en un espacio privado del servidor.
2. **Inspección Inteligente:** La plataforma "lee" los archivos para adivinar qué es: ¿es Laravel?, ¿es Node.js con React?, ¿es Python con Django?
3. **Crea la Base de Datos:** Abre un almacén de datos exclusivo para ese proyecto, le crea un usuario con llave secreta y lo conecta al código del alumno.
4. **Arma el Proyecto:** Descarga e instala todas las librerías que el alumno usó en su computadora (`npm install`, `composer install`).
5. **Puebla los Datos y Crea el Administrador:** Si el sistema tiene tablas, corre las migraciones y datos de prueba. Si el sistema tiene un instalador web (asistente de instalación), la plataforma lo completa en silencio para que el reclutador no tenga que configurar nada. Además, crea o ajusta un usuario administrador de pruebas (`admin@...` con clave `password`).
6. **Guarda una "Foto de Respaldo" (Snapshot):** Guarda una copia exacta de la base de datos recién instalada para poder reiniciarla cada vez que alguien termine de probarla.
7. **Encapsula en una Bóveda Segura (Sandbox):** Encierra la aplicación en una cápsula aislada (contenedor con gVisor) para que, si el código del alumno tiene un virus o error grave, no pueda dañar al servidor de la universidad ni a otros alumnos.
8. **Genera la Dirección Web Oficial:** Le asigna una dirección en internet con candado de seguridad SSL (`https://crater.uleam-academic.software`) y lo publica en la Vitrina de Talento.

### 4. La Experiencia del Reclutador ("Evaluar con Zero-Click")
1. El reclutador entra a la vitrina pública de la ULEAM y busca proyectos por carrera.
2. Encuentra el proyecto y hace clic en **"Probar Proyecto"**.
3. Se abre un visor en vivo interactivo. Al lado derecho, la **Guía del Evaluador** le indica el usuario y la clave predeterminados listos para copiar.
4. El evaluador inicia sesión, crea registros, prueba funciones y califica al alumno.
5. Al hacer clic en **"Cerrar"**, la plataforma borra los datos temporales del evaluador y restaura la base de datos limpia en 1 segundo, lista para el siguiente reclutador.

---

# PARTE 2: FLUJO TÉCNICO DETALLADO CON TRAZABILIDAD DE CÓDIGO FUENTE

A continuación se detalla la correspondencia exacta entre cada fase del pipeline y los archivos, clases, métodos y números de línea en el código fuente de la plataforma (`src/`).

---

### Fase 1: Recepción de Solicitud y Encolamiento Asíncrono
* **¿Qué hace técnicamente?:**
  Valida la entrada del formulario (URL de GitHub o archivo ZIP, subdominio único, tipo de base de datos), registra el proyecto en estado `pending` en la tabla `projects` de PostgreSQL y despacha el Job asíncrono a la cola de Redis.
* **Ubicación en el Código:**
  * **Controlador HTTP:** [`ProjectController.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Http/Controllers/ProjectController.php)
    * Método: `store(ProjectRequest $request)` (Líneas 35 - 110)
    * Validación de subdominio, carga de ZIP o validación de URL GitHub.
    * Despacho del Job:
      ```php
      BuildProjectJob::dispatch($project);
      ```
  * **Manejador de Cola (Job):** [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php)
    * Método: `handle()` (Líneas 68 - 180)
    * Orquesta todo el pipeline registrando los logs paso a paso en el campo `build_logs` del proyecto.

---

### Fase 2: Descarga del Código Fuente y Extracción
* **¿Qué hace técnicamente?:**
  Crea el directorio aislado en `/storage/app/projects/project-{uuid}`. Si es Git, ejecuta un clon superficial (`--depth 1`). Si es ZIP, descomprime el archivo binario validando rutas relativas para evitar vulnerabilidades de *Zip Slip*.
* **Ubicación en el Código:**
  * Archivo: [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php)
  * Método: `cloneOrExtractRepository(Project $project, string $projectPath, &$logs)` (Líneas 230 - 320)
  * Ejecución de comandos mediante `Symfony\Component\Process\Process`.

---

### Fase 3: Aprovisionamiento de Base de Datos Multitenant
* **¿Qué hace técnicamente?:**
  1. Detecta qué motor requiere el proyecto (MySQL, PostgreSQL o MongoDB).
  2. Verifica si el proyecto requiere base de datos y valida que contenga archivos `.sql` o migraciones (si requiere BD pero no tiene esquema, lanza excepción temprana `422`).
  3. Genera el nombre único de base de datos `db_{uuid_limpio}` y usuario `u_{hash_24}` con contraseña criptográfica de 24 caracteres (`Str::random(24)`).
  4. Ejecuta sentencias DDL en la conexión administrativa (`students_mysql`, `students_postgres` o `uleam_mongodb_students`): `CREATE DATABASE`, `CREATE USER`, `GRANT ALL PRIVILEGES`.
* **Ubicación en el Código:**
  * Archivo: [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php)
  * Método: `provisionDatabase(Project $project, string $projectPath, &$logs)` (Líneas 467 - 665)
  * Detección de motor: `detectDbDriver(Project $project, string $projectPath)` (Líneas 1090 - 1160)

---

### Fase 4: Sincronización e Inyección de Variables de Entorno (`.env`)
* **¿Qué hace técnicamente?:**
  Busca archivos `.env` (en raíz, `/backend`, `/api`, `/server`) o plantillas `.env.example`. Inyecta automáticamente los valores generados de base de datos (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), cadenas de conexión completas (`DATABASE_URL`, `MONGODB_URI`) y directivas de sesión y cookies para localhost y producción (`SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`).
* **Ubicación en el Código:**
  * Archivo: [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php)
  * Método: `syncProjectDatabaseEnv(Project $project, string $projectPath, &$logs)` (Líneas 1180 - 1270)

---

### Fase 5: Pipeline de Compilación y Resolución de Dependencias
* **¿Qué hace técnicamente?:**
  Ejecuta contenedores efímeros de compilación para instalar librerías sin ensuciar el host:
  * **PHP/Laravel:** Ejecuta `composer install --no-dev --no-scripts` bajo `composer:latest`.
  * **Parche Automático Legacy (`patchLegacyPhpPackages`):** Aplica expresiones regulares en caliente sobre el código de paquetes antiguos para resolver errores de PHP 8.2+ (firmas de Carbon, sintaxis de `${var}` en Ignition, etc.).
  * **Node.js:** Ejecuta `npm install` y `npm run build` en `node:18-alpine`.
  * **Python:** Configura entorno virtual `.venv` con `pip install -r requirements.txt`.
* **Ubicación en el Código:**
  * Archivo: [`BuildProjectAction.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Actions/Docker/BuildProjectAction.php)
    * Método de construcción: `execute(Project $project)` (Líneas 70 - 450)
    * Parches de compatibilidad: `patchLegacyPhpPackages(string $projectPath)` (Líneas 965 - 1030)
  * Detección de entorno: `detectEnvironment(string $projectPath)` (Líneas 890 - 950)

---

### Fase 6: Importación SQL, Migraciones y "Auto-Instalación Silenciosa" (Zero-Click)
* **¿Qué hace técnicamente?:**
  1. Si existen volcados `.sql`, los importa directamente al motor del estudiante (`importSqlFiles`, Líneas 670 - 737).
  2. Si es Laravel, ejecuta `php artisan migrate --force` y `db:seed --force` (Líneas 748 - 910).
  3. **Auto-Instalación Zero-Click:** Toca flags de instalación (`database_created`, `installed`). Ejecuta script Tinker para marcar `profile_complete = 'COMPLETED'`, vincular al usuario como propietario de la empresa y otorgar rol `super admin` (Líneas 912 - 955).
  4. **Provisión Universal de Credenciales:** Detecta el usuario administrador de la tabla `users`, establece su contraseña de forma limpia a `password` (usando `Hash::make('password')` directo en base de datos para evitar doble mutación) y actualiza automáticamente el campo `demo_instructions` del proyecto (Líneas 956 - 985).
  5. **Snapshot Inicial:** Ejecuta volcado de base de datos a `.initial_db_snapshot.sql` para el reseteo efímero (Líneas 1275 - 1315).
* **Ubicación en el Código:**
  * Archivo: [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php)
  * Métodos: `runFrameworkMigrations`, `generateInitialDatabaseSnapshot`.

---

### Fase 7: Encendido del Contenedor en Sandbox Google gVisor (`runsc`)
* **¿Qué hace técnicamente?:**
  Instancia el contenedor en Docker con el runtime seguro `runsc`, asignando límites de 256 MB RAM y 0.5 CPU, montando la carpeta del proyecto como volumen y asociando las etiquetas dinámicas de Traefik.
* **Ubicación en el Código:**
  * Archivo: [`StartProjectContainerAction.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Actions/Docker/StartProjectContainerAction.php)
  * Método: `execute(Project $project)` (Líneas 40 - 280)
  * Comando Docker con gVisor:
    ```php
    '--runtime=runsc',
    '--memory', '256m',
    '--cpus', '0.5',
    ```
  * Enrutamiento PHP mod_rewrite: `php -S 0.0.0.0:80 -t public server.php` (Líneas 374 - 400).

---

### Fase 8: Showcase, Visor en Vivo y Reseteo Instantáneo
* **¿Qué hace técnicamente?:**
  1. La página de inicio (`Welcome.jsx`) presenta los proyectos en vivo.
  2. Al abrir la demo, si el contenedor estaba dormido, se envía `POST /projects/{id}/start` y se reactiva.
  3. La interfaz lee `demo_instructions` y presenta las credenciales destacadas en la **Guía del Evaluador**.
  4. Al cerrar el modal, el frontend invoca `POST /showcase/projects/{id}/stop`, que ejecuta en segundo plano el reseteo de la base de datos mediante el snapshot inicial.
* **Ubicación en el Código:**
  * **Frontend Modal:** [`Welcome.jsx`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/resources/js/Pages/Welcome.jsx) (Líneas 480 - 580, `getDemoCredentials` en 698 - 737)
  * **Controlador de Parada:** [`ShowcaseController.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Http/Controllers/ShowcaseController.php)
    * Método: `stopDemo(Project $project)` (Líneas 306 - 334)
    * Lanza comando `projects:reset-databases --project={id}`.
  * **Comando de Reseteo:** [`ResetStudentDatabases.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Console/Commands/ResetStudentDatabases.php)
    * Método: `handle()` (Líneas 40 - 275)
    * Restaura `.initial_db_snapshot.sql` en < 1 segundo.
