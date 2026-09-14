# Flujo Técnico Detallado de Despliegue de Proyectos desde Cero

**Documento:** Especificación Técnica de Despliegue y Pipeline de Contenedores  
**Proyecto:** ULEAM Academic Hosting (PaaS)  
**Autor:** David Choez  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  

---

## 1. Diagrama de Arquitectura y Flujo de Despliegue

### 1.1 Diagrama de Secuencia del Pipeline (De Cero a Producción)

```mermaid
sequenceDiagram
    autonumber
    actor Estudiante as Estudiante (Frontend)
    participant Core as Laravel 11 Core (API / Controller)
    participant Redis as Cola Redis (Queue Worker)
    participant Job as BuildProjectJob (Worker)
    participant DBServer as Clúster BD Alumnos (MySQL / Postgres / Mongo)
    participant Docker as Docker Engine & gVisor (runsc)
    participant Traefik as Traefik Proxy 3.0
    actor Reclutador as Reclutador / Evaluador

    Estudiante->>Core: Enviar Repositorio Git o Archivo ZIP + Configuración BD
    Core->>Core: Crear Registro Project (Status: Pendiente)
    Core->>Redis: Despachar BuildProjectJob
    Redis->>Job: Iniciar Ejecución Asíncrona del Job
    
    rect rgb(20, 25, 45)
        note right of Job: PASO 1: Descarga y Extracción
        Job->>Job: Git Clone o Unpack ZIP en /storage/app/projects/project-{id}
    end

    rect rgb(25, 35, 55)
        note right of Job: PASO 2: Aprovisionamiento de BD
        Job->>DBServer: CREATE DATABASE db_{id}; CREATE USER u_{id}; GRANT ALL...
        Job->>Job: Inyectar Variables en .env (DB_HOST, DB_USER, DB_PASS, SANCTUM, etc.)
    end

    rect rgb(30, 45, 65)
        note right of Job: PASO 3: Detección y Compilación
        Job->>Job: Detectar Lenguaje (Laravel / Node / Django / Estático)
        Job->>Docker: Ejecutar Contenedor Temporal de Build (npm install / composer install)
        Job->>Job: Parchear Compatibilidades PHP 8.2+ (Carbon, Ignition, Migraciones)
        Job->>Job: Ejecutar Migraciones y Seeders (artisan migrate / db:seed)
        Job->>Job: Auto-Instalación Silenciosa Zero-Click (flags, super admin, company)
        Job->>Job: Detección de Credenciales de Prueba y Actualización de demo_instructions
        Job->>DBServer: Generar Snapshot de Respaldo (.initial_db_snapshot.sql)
    end

    rect rgb(35, 55, 75)
        note right of Job: PASO 4: Despliegue en Sandbox gVisor
        Job->>Docker: docker run -d --runtime=runsc --memory 256m -l traefik.http.routers...
        Job->>Traefik: Notificación automática vía socket Docker (/var/run/docker.sock)
        Traefik->>Traefik: Registrar Ruta Host(`subdominio.uleam-academic.software`) + SSL
        Job->>Core: Actualizar Estado a "Activo" y Notificar Finalización
    end

    Reclutador->>Traefik: Petición HTTPS: https://subdominio.uleam-academic.software
    Traefik->>Docker: Reenvío seguro a Contenedor gVisor (runsc)
    Docker-->>Reclutador: Carga inmediata de la Demo con Credenciales Preconfiguradas
```

---

## 2. Desglose Paso a Paso del Pipeline de Despliegue

### Paso 1: Recepción del Código Fuente
1. **Entrada de Datos:**
   - Si es **GitHub:** El estudiante provee la URL del repositorio (público) y la rama. El sistema ejecuta:
     ```bash
     git clone --depth 1 --branch {branch} {url} /storage/app/projects/project-{id}
     ```
   - Si es **ZIP:** Se sube el archivo a almacenamiento temporal, se valida su integridad y tamaño (<50 MB) y se descomprime preservando la estructura interna.
2. **Validación Antimalware Básica:** Se inspeccionan archivos ejecutables binarios sospechosos (`.exe`, `.sh` fuera de contexto) antes de pasar a la fase de construcción.

---

### Paso 2: Aprovisionamiento de Base de Datos y Sincronización `.env`
1. **Aislamiento Multitenant de Datos:**
   Cada proyecto recibe una base de datos física y credenciales únicas creadas al vuelo:
   - **MySQL 8.0:**
     ```sql
     CREATE DATABASE db_01a08d90... CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER 'u_01a08d90...'@'%' IDENTIFIED BY 'PasswordSeguraGenerada';
     GRANT ALL PRIVILEGES ON db_01a08d90...* TO 'u_01a08d90...'@'%';
     FLUSH PRIVILEGES;
     ```
   - **PostgreSQL 15:**
     ```sql
     CREATE USER "u_01a08d90..." WITH PASSWORD 'PasswordSeguraGenerada';
     CREATE DATABASE "db_01a08d90..." OWNER "u_01a08d90...";
     ```
   - **MongoDB 7:** Se genera la URI con autenticación vinculada a la colección de la aplicación.
2. **Inyección Dinámica de Variables de Entorno (`.env`):**
   El sistema busca o crea los archivos `.env` (en raíz, `/backend`, `/api` o `/server`) e inyecta:
   ```dotenv
   APP_URL=https://{subdominio}.uleam-academic.software
   DB_CONNECTION={driver}
   DB_HOST={host_interno_docker}
   DB_PORT={puerto_interno}
   DB_DATABASE={nombre_bd}
   DB_USERNAME={usuario_bd}
   DB_PASSWORD={password_bd}
   DATABASE_URL={connection_string}
   SANCTUM_STATEFUL_DOMAINS={subdominio}.localhost,{subdominio}.uleam-academic.software,localhost
   SESSION_DOMAIN=
   SESSION_DRIVER=file
   CACHE_DRIVER=file
   QUEUE_CONNECTION=sync
   MAIL_MAILER=log
   ```

---

### Paso 3: Detección Automática de Tecnologías y Pipeline de Construcción
El sistema escanea la estructura de archivos y aplica el pipeline correspondiente:

#### A. Proyectos PHP / Laravel
1. **Ejecución de Composer:**
   Se corre `composer install --no-dev --no-scripts --prefer-dist` dentro de un contenedor efímero `composer:latest`.
2. **Parche Automático de Compatibilidad Legacy (`patchLegacyPhpPackages`):**
   Muchos proyectos de estudiantes utilizan paquetes de versiones anteriores de Laravel que fallan en entornos PHP 8.2+:
   - Corrige compatibilidad de `Carbon::setLastErrors($lastErrors = [])`.
   - Reemplaza sintaxis deprecada de interpolación `${variable}` en `facade/ignition`.
   - Ajusta migraciones antiguas que asumen que `$user->companies()->first()` nunca es nulo.
3. **Descubrimiento de Paquetes:** Se ejecuta `php artisan package:discover` bajo `webdevops/php:8.4`.
4. **Migraciones y Seeders:** Se ejecutan `php artisan migrate --force` y `php artisan db:seed --force` (si existe `DatabaseSeeder.php`).
5. **Auto-Instalación Silenciosa (Zero-Click para el Reclutador):**
   - Toca automáticamente las banderas de instalación (`storage/app/database_created`, `storage/installed`).
   - Para sistemas con asistentes web (como Crater Invoice, CMS, ERPs): ejecuta un script Tinker que marca `profile_complete = 'COMPLETED'`, asigna la empresa propietaria y otorga permisos de `super admin`.
6. **Auto-Detección y Provisión de Credenciales de Prueba:**
   - Si la tabla `users` existe, el sistema actualiza de forma limpia la contraseña del primer usuario a `password` (mediante `Hash::make('password')` directo en base de datos para evitar doble hashing).
   - Si la tabla está vacía, crea `admin@example.com` / `password`.
   - El sistema captura el correo detectado y actualiza automáticamente el campo `demo_instructions` del proyecto en la base de datos central de la plataforma:
     ```text
     Acceso predeterminado para pruebas:
     Usuario: {correo_detectado}
     Clave: password
     ```
7. **Snapshot Inicial de Base de Datos:**
   Se ejecuta `mysqldump` o `pg_dump` y se almacena `/workspace/.initial_db_snapshot.sql` (~60 KB a 90 KB).

#### B. Proyectos Node.js / Fullstack
1. Detecta `package.json`.
2. Ejecuta `npm install --production=false` para instalar dependencias.
3. Si existe script `"build"`, ejecuta `npm run build` para generar el bundle estático (React, Vue, Vite, Next.js estático).
4. Si detecta migraciones de Sequelize o Prisma, ejecuta `npx sequelize-cli db:migrate` o `npx prisma migrate deploy`.

#### C. Proyectos Python / Django
1. Detecta `manage.py` o `requirements.txt`.
2. Crea un entorno virtual `.venv` y ejecuta `pip install -r requirements.txt`.
3. Ejecuta migraciones de Django: `python manage.py migrate`.
4. Crea superusuario de pruebas: `admin` / `password`.

---

### Paso 4: Aislamiento en el Sandbox gVisor (`runsc`)
Para garantizar que ningún estudiante pueda ejecutar llamadas al kernel vulnerables ni escapar del contenedor:
1. **Lanzamiento del Contenedor de Ejecución:**
   ```bash
   docker run -d \
     --name project-{id} \
     --runtime=runsc \
     --memory 256m \
     --cpus 0.5 \
     --network uleam_academic_network \
     -v /storage/app/projects/project-{id}:/app \
     -w /app \
     -l "traefik.enable=true" \
     -l "traefik.http.routers.{subdominio}.rule=Host(`{subdominio}.uleam-academic.software`)" \
     -l "traefik.http.routers.{subdominio}.entrypoints=websecure" \
     -l "traefik.http.routers.{subdominio}.tls.certresolver=myresolver" \
     uleam/runner-php:8.4 \
     php -S 0.0.0.0:80 -t public server.php
   ```
2. **Explicación de Flags de Seguridad:**
   - `--runtime=runsc`: Intercepta todas las llamadas del sistema (*syscalls*) a través del kernel en espacio de usuario desarrollado por Google (gVisor).
   - `--memory 256m`: Previene ataques de denegación de servicio por agotamiento de RAM (*OOM Killer*).
   - `--cpus 0.5`: Impide el acaparamiento de procesamiento por minería o bucles infinitos.
   - `-t public server.php`: Servidor PHP emulador de mod_rewrite para resolver rutas limpias y estáticos sin requerir un Nginx por cada alumno.

---

### Paso 5: Enrutamiento Dinámico y Showcase del Evaluador
1. **Traefik Proxy:** Detecta la creación del contenedor mediante el socket de Docker, añade la ruta dinámicamente y solicita el certificado SSL comodín mediante Let's Encrypt sin interrupción de servicio.
2. **Interacción del Reclutador en el Modal:**
   - El evaluador hace clic en "Probar Proyecto".
   - Si el contenedor estaba suspendido (*sleep*), se reactiva automáticamente en ~3 segundos.
   - El modal muestra el proyecto y en la barra lateral derecha presenta la **Guía del Evaluador** con las credenciales listas para copiar.
   - Al hacer clic en "Cerrar", se ejecuta en segundo plano:
     ```bash
     php artisan projects:reset-databases --project={id}
     ```
     El comando toma `/workspace/.initial_db_snapshot.sql` y en menos de 1 segundo restaura la base de datos a su estado original limpio.

---

## 3. Matriz Tecnológica Utilizada

| Componente | Tecnología | Versión | Justificación Técnica |
| :--- | :--- | :--- | :--- |
| **Backend Core** | Laravel | 11.x | Framework robusto con soporte nativo para colas asíncronas, Eloquent ORM, Jobs y gestión de procesos del sistema. |
| **Frontend UI** | React | 18.x | Biblioteca declarativa de alto rendimiento para interfaces dinámicas reactivas. |
| **Adaptador SPA** | Inertia.js | 1.x / 2.x | Conecta Laravel y React sin la sobrecarga de crear y mantener una API REST intermedia, unificando rutas y validaciones. |
| **Empaquetador** | Vite | 5.x / 6.x | Tiempos de compilación ultrarrápidos (Hot Module Replacement) y bundles optimizados. |
| **Estilos** | Tailwind CSS | 3.x | Sistema de diseño atómico moderno y responsivo con tema oscuro institucional. |
| **Base de Datos Core**| PostgreSQL | 16.x | Almacenamiento relacional confiable para usuarios, roles, métricas y registros de auditoría. |
| **Colas y Caché** | Redis | 7.x | Cola en memoria de bajísima latencia para orquestar la compilación concurrente de proyectos. |
| **Motor de Contenedores**| Docker Engine | 25.x+ | Estandarización de entornos de ejecución mediante contenedores ligeros. |
| **Sandbox de Seguridad**| Google gVisor (`runsc`) | Latest | Kernel en espacio de usuario para aislamiento estricto de llamadas de sistema (*defense in depth*). |
| **Proxy Inverso** | Traefik Proxy | 3.x | Enrutador perimetral dinámico con autodetección de Docker y gestión automática de certificados SSL ACME. |
| **Clúster BD Alumnos** | MySQL 8.0 / Postgres 15 / Mongo 7 | Latest | Motores independientes donde los proyectos de los alumnos crean sus tablas y datos sin contaminar el sistema base. |
