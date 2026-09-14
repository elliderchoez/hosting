# Arquitectura y Aprovisionamiento Autónomo de Bases de Datos

**Documento:** Guía Técnica y Conceptual del Sistema de Bases de Datos Multi-Tenant  
**Proyecto:** ULEAM Academic Hosting (PaaS)  
**Autor:** David Choez  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  

---

## 1. Introducción y Filosofía Arquitectural

En la mayoría de plataformas PaaS comerciales (como Heroku, Railway o Render), el aprovisionamiento de una base de datos requiere que el usuario cree manualmente un recurso adjunto, copie cadenas de conexión complejas (`DATABASE_URL`) y configure a mano sus variables de entorno. 

Para el entorno universitario de la **ULEAM**, este enfoque tradicional presenta dos barreras críticas:
1. **Para los estudiantes:** Fricción técnica elevada. Muchos alumnos principiantes no saben cómo configurar cadenas de conexión remotas, perdiendo horas valiosas o fracasando en la entrega de sus proyectos de materia o titulación.
2. **Para los evaluadores y reclutadores:** Proyectos rotos o vacíos. Si la base de datos requiere configuración manual o está desconectada, el evaluador abandona la revisión inmediatamente.

### La Solución Implementada: *Zero-Configuration & Zero-Click*
ULEAM Academic Hosting implementa un **motor de aprovisionamiento autónomo multitenant**, capaz de:
* Analizar el código fuente del estudiante e identificar automáticamente el motor requerido (MySQL, PostgreSQL o MongoDB).
* Validar que el proyecto incluya los esquemas necesarios antes de consumir recursos del servidor.
* Crear de forma dinámica bases de datos aisladas con usuarios y contraseñas de alta entropía.
* Inyectar de manera transparente las credenciales en todos los archivos de configuración (`.env`).
* Importar respaldos SQL o ejecutar migraciones ORM sin intervención humana.
* Capturar una "instantánea" (*Snapshot*) de fábrica para restaurar la base de datos a su estado original cada vez que un reclutador termine de interactuar con el proyecto.

---

## 2. Diagrama de Arquitectura de Datos Multi-Tenant

A continuación se ilustra cómo conviven los contenedores de los proyectos de los estudiantes con los tres motores centrales de bases de datos dentro de la red privada de Docker:

```mermaid
graph TD
    subgraph Red_Privada ["Red Interna Docker: uleam_academic_network (Sin puertos expuestos a Internet)"]
        subgraph Motores_Centrales ["Contenedores Centrales de Bases de Datos"]
            MySQL["uleam_mysql_students (MySQL 8.0:3306)<br/>• db_proj1 (User: u_proj1)<br/>• db_proj2 (User: u_proj2)"]
            Postgres["uleam_postgres_students (PostgreSQL 15:5432)<br/>• db_proj3 (User: u_proj3)<br/>• db_proj4 (User: u_proj4)"]
            Mongo["uleam_mongodb_students (MongoDB 7.0:27017)<br/>• db_proj5 (User: u_proj5)"]
        end

        subgraph Proyectos_Estudiantes ["Contenedores Aislados de Proyectos (Sandboxes)"]
            P1["project-uuid-1 (PHP / Laravel)<br/>Variables: DB_DATABASE=db_proj1"]
            P2["project-uuid-2 (Node.js / Express)<br/>Variables: DB_DATABASE=db_proj2"]
            P3["project-uuid-3 (Python / Django)<br/>Variables: DB_DATABASE=db_proj3"]
            P4["project-uuid-4 (NestJS / Prisma)<br/>Variables: DB_DATABASE=db_proj4"]
            P5["project-uuid-5 (MERN Stack)<br/>Variables: MONGODB_URI=..."]
        end

        P1 -->|Conexión TCP Interna| MySQL
        P2 -->|Conexión TCP Interna| MySQL
        P3 -->|Conexión TCP Interna| Postgres
        P4 -->|Conexión TCP Interna| Postgres
        P5 -->|Conexión TCP Interna| Mongo
    end

    subgraph Orquestador_PaaS ["Orquestador Central ULEAM (Laravel Queue Worker)"]
        Job["BuildProjectJob<br/>• detectDbDriver()<br/>• provisionDatabase()<br/>• syncProjectEnvironmentVariables()<br/>• generateInitialDatabaseSnapshot()"]
        ResetCmd["ResetStudentDatabases<br/>(projects:reset-databases)"]
    end

    Job -->|Aprovisiona DB y Credenciales| MySQL
    Job -->|Aprovisiona DB y Credenciales| Postgres
    Job -->|Aprovisiona DB y Credenciales| Mongo
    ResetCmd -->|Restaura Snapshot tras salida de reclutador| MySQL
    ResetCmd -->|Restaura Snapshot tras salida de reclutador| Postgres
```

---

## 3. Ciclo de Vida del Aprovisionamiento Paso a Paso

El aprovisionamiento ocurre durante la ejecución asíncrona de `BuildProjectJob`. El pipeline de base de datos comprende siete etapas rigurosas:

### Etapa 1: Detección Heurística del Motor (`detectDbDriver`)
La plataforma no obliga al estudiante a especificar parámetros técnicos complejos. Inspecciona el código de forma jerárquica:
1. **Preferencia explícita:** Si el estudiante seleccionó un motor en la interfaz web (`mysql`, `pgsql` o `mongodb`), se respeta su elección.
2. **Inspección de Dependencias (`package.json`):**
   * Detecta `mongoose`, `mongodb` $\rightarrow$ Asigna `mongodb`.
   * Detecta `pg`, `@prisma/client` con dialecto Postgres $\rightarrow$ Asigna `pgsql`.
   * Detecta `mysql`, `mysql2` $\rightarrow$ Asigna `mysql`.
3. **Inspección de Frameworks Python (`requirements.txt`):**
   * Detecta `pymongo`, `mongoengine` $\rightarrow$ Asigna `mongodb`.
   * Detecta `psycopg2`, `asyncpg` $\rightarrow$ Asigna `pgsql`.
   * Detecta `mysqlclient`, `pymysql` $\rightarrow$ Asigna `mysql`.
4. **Inspección de Frameworks PHP / Laravel (`config/database.php`):**
   * Lee la clave `'default' => env('DB_CONNECTION', '...')`. Si es Laravel puro, usa MySQL por convención estándar de la industria.
5. **Análisis Sintáctico de Scripts `.sql`:**
   * Busca palabras clave exclusivas como `ENGINE=InnoDB`, `AUTO_INCREMENT`, `\`backticks\`` $\rightarrow$ Asigna `mysql`.

### Etapa 2: Validación Temprana de Integridad (*Early Rejection - HTTP 422*)
Un error común en defensas de tesis anteriores era desplegar aplicaciones que requerían base de datos pero cuyo repositorio venía vacío (sin tablas ni datos).
* La plataforma escanea recursivamente el proyecto:
  * Si detecta dependencias de bases de datos (ej. TypeORM, Prisma, Sequelize, Laravel, Django, SQLAlchemy, PDO).
  * Y **NO** encuentra archivos `.sql` ni scripts de migraciones (como `artisan`, `manage.py`, `schema.prisma`, etc.).
  * El sistema aborta el despliegue de inmediato con un error descriptivo:
    > *"No se pudo desplegar el proyecto porque falta el archivo de base de datos SQL o archivos de migraciones. Por favor, revisa el proyecto y vuélvelo a subir."*
* Esto protege los recursos del servidor y guía al alumno a corregir su entrega antes de la presentación.

### Etapa 3: Generación Criptográfica de Identidad
Para garantizar que dos proyectos nunca colisionen y que ningún estudiante pueda leer los datos de otro:
* **Nombre de la Base de Datos (`db_name`):**
  ```php
  $uuidClean = str_replace('-', '_', $project->id);
  $project->db_name = "db_{$uuidClean}";
  ```
* **Nombre de Usuario (`db_user`):**
  MySQL impone un límite estricto de 32 caracteres para los nombres de usuario. El sistema genera un identificador seguro truncado:
  ```php
  $project->db_user = "u_" . substr($uuidClean, 0, 24); // 26 caracteres en total
  ```
* **Contraseña (`db_password`):**
  Cadena alfanumérica criptográfica de 24 caracteres aleatorios generada con `Str::random(24)`.

### Etapa 4: Ejecución DDL según el Motor

#### Caso A: MySQL 8.0
A través de la conexión administrativa interna `students_mysql` hacia `uleam_mysql_students:3306`:
```sql
-- 1. Destruir versiones previas si existiesen
DROP DATABASE IF EXISTS db_01a08d90_b5bf_722a_b122_3f8a13f3f94a;
CREATE DATABASE db_01a08d90_b5bf_722a_b122_3f8a13f3f94a;

-- 2. Crear usuario aislado con permisos restringidos
CREATE USER IF NOT EXISTS 'u_01a08d90_b5bf_722a_b122'@'%' IDENTIFIED BY 'clave_segura_24_chars';
ALTER USER 'u_01a08d90_b5bf_722a_b122'@'%' IDENTIFIED BY 'clave_segura_24_chars';

-- 3. Asignar privilegios EXCLUSIVAMENTE a su base de datos
GRANT ALL PRIVILEGES ON db_01a08d90_b5bf_722a_b122_3f8a13f3f94a.* TO 'u_01a08d90_b5bf_722a_b122'@'%';
FLUSH PRIVILEGES;
```

#### Caso B: PostgreSQL 15
A través de la conexión administrativa interna `students_postgres` hacia `uleam_postgres_students:5432`:
```sql
-- 1. Crear rol con contraseña mediante bloque PL/pgSQL seguro
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'u_01a08d90_b5bf_722a_b122') THEN
        CREATE ROLE u_01a08d90_b5bf_722a_b122 WITH LOGIN PASSWORD 'clave_segura_24_chars';
    END IF;
END
$$;

-- 2. Terminar conexiones concurrentes si existieran
SELECT pg_terminate_backend(pg_stat_activity.pid)
FROM pg_stat_activity
WHERE pg_stat_activity.datname = 'db_01a08d90_b5bf_722a_b122_3f8a13f3f94a'
  AND pid <> pg_backend_pid();

-- 3. Recrear base de datos y transferir propiedad al usuario
DROP DATABASE IF EXISTS db_01a08d90_b5bf_722a_b122_3f8a13f3f94a;
CREATE DATABASE db_01a08d90_b5bf_722a_b122_3f8a13f3f94a OWNER u_01a08d90_b5bf_722a_b122;
GRANT ALL PRIVILEGES ON DATABASE db_01a08d90_b5bf_722a_b122_3f8a13f3f94a TO u_01a08d90_b5bf_722a_b122;
```

#### Caso C: MongoDB 7.0
Ejecutando un proceso en `uleam_mongodb_students` vía `mongosh`:
```javascript
db = db.getSiblingDB('db_01a08d90_b5bf_722a_b122_3f8a13f3f94a');
db.dropDatabase();
try { db.dropUser('u_01a08d90_b5bf_722a_b122'); } catch (e) {}
db.createUser({
    user: 'u_01a08d90_b5bf_722a_b122',
    pwd: 'clave_segura_24_chars',
    roles: [ { role: 'readWrite', db: 'db_01a08d90_b5bf_722a_b122_3f8a13f3f94a' } ]
});
```

---

### Etapa 5: Inyección Universal de Variables de Entorno (`syncProjectEnvironmentVariables`)
El orquestador no asume que el estudiante organizó su proyecto en una sola carpeta. Escanea la raíz del proyecto y las subcarpetas típicas (`/backend`, `/api`, `/server`).

Si no existe archivo `.env`, busca plantillas (`.env.example`, `.env.sample`, `.env.local`) y genera el `.env` definitivo.

Inyecta un mapa exhaustivo de variables compatibles con cualquier framework del mercado:
```ini
# Identificación de Dominio
APP_URL=http://subdominio.localhost

# Dialectos SQL Tradicionales (Laravel, CodeIgniter, Symfony, Express)
DB_CONNECTION=mysql
DB_HOST=uleam_mysql_students
DB_PORT=3306
DB_DATABASE=db_01a08d90_b5bf_722a_b122_3f8a13f3f94a
DB_USERNAME=u_01a08d90_b5bf_722a_b122
DB_PASSWORD=clave_segura_24_chars

# Cadenas de Conexión Universales (Prisma, TypeORM, Django, Sequelize)
DATABASE_URL=mysql://u_01a08d90_b5bf_722a_b122:clave_segura@uleam_mysql_students:3306/db_01a08d90_b5bf_722a_b122_3f8a13f3f94a
DB_URI=mysql://u_01a08d90_b5bf_722a_b122:clave_segura@uleam_mysql_students:3306/db_01a08d90_b5bf_722a_b122_3f8a13f3f94a

# NoSQL (Mongoose, MongoClient)
MONGODB_URI=mongodb://u_proj:pass@uleam_mongodb_students:27017/db_proj?authSource=admin
MONGO_URI=mongodb://u_proj:pass@uleam_mongodb_students:27017/db_proj?authSource=admin

# Variables Estándar de PostgreSQL (Python / libpq)
PGHOST=uleam_postgres_students
PGPORT=5432
PGDATABASE=db_proj
PGUSER=u_proj
PGPASSWORD=clave_segura

# Desactivación de depuradores que filtren credenciales
APP_DEBUG=false
DEBUGBAR_ENABLED=false
```

---

### Etapa 6: Importación de Respaldos SQL y Migraciones Autónomas
1. **Importación con Clientes Efímeros de Docker (`importSqlFiles`):**
   Si el repositorio contiene archivos `.sql`, la plataforma no ejecuta comandos vulnerables en el host; levanta un contenedor efímero aislado que se conecta a la red interna y se autoelimina (`--rm`):
   ```bash
   docker run --rm --network uleam_academic_network \
     -v /ruta/proyecto:/workspace:ro \
     mysql:8.0 sh -c \
     "mysql -h uleam_mysql_students -u 'u_proj' -p'pass' db_proj < /workspace/database.sql"
   ```
2. **Ejecución de Migraciones del Framework:**
   * En Laravel: `php artisan migrate --force` y `php artisan db:seed --force`.
   * En Django: `python manage.py migrate --no-input`.
   * En Prisma: `npx prisma db push --accept-data-loss` o `npx prisma migrate deploy`.

---

### Etapa 7: Instant Snapshot y Reseteo Efímero de Fábrica

Para que un reclutador pueda probar libremente la aplicación (crear usuarios, borrar registros, alterar tablas) sin dejar el proyecto inservible para el siguiente visitante:

1. **Captura del Snapshot (`generateInitialDatabaseSnapshot`):**
   Apenas finaliza el despliegue con éxito, el sistema genera un volcado limpio del estado original:
   ```bash
   docker exec uleam_mysql_students mysqldump --no-tablespaces -u root -p*** db_proj > /ruta/proyecto/.initial_db_snapshot.sql
   ```
2. **Restauración Instantánea (< 1 segundo) (`ResetStudentDatabases`):**
   Cuando el evaluador hace clic en "Cerrar" en la Vitrina de Talento (`ShowcaseController::stopDemo`):
   * Se ejecuta en segundo plano:
     ```bash
     php artisan projects:reset-databases --project={id}
     ```
   * El comando detecta `.initial_db_snapshot.sql` y lo restaura de inmediato con el cliente de Docker.
   * La base de datos vuelve a estar 100% limpia y nueva en menos de un segundo.

---

## 4. Matriz de Trazabilidad en el Código Fuente

| Componente / Acción | Archivo Fuente | Método / Función | Líneas de Código |
| :--- | :--- | :--- | :--- |
| **Detección de Motor y Dialecto** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `detectDbDriver()` | L1150 - L1209 |
| **Validación Previa de Esquemas (422)** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `provisionDatabase()` | L476 - L554 |
| **Generación de Credenciales Seguras** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `provisionDatabase()` | L556 - L572 |
| **Aprovisionamiento DDL (MySQL, Postgres, Mongo)** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `provisionDatabase()` | L573 - L665 |
| **Importación de Archivos .sql con Docker** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `importSqlFiles()` | L670 - L737 |
| **Inyección de Credenciales en `.env`** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `syncProjectEnvironmentVariables()` | L1243 - L1334 |
| **Migraciones y Seeders Automáticos** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `runDatabaseMigrations()` | L745 - L830 |
| **Generación de Snapshot de Fábrica** | [`BuildProjectJob.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Jobs/BuildProjectJob.php) | `generateInitialDatabaseSnapshot()` | L1340 - L1372 |
| **Comando de Reseteo Automático** | [`ResetStudentDatabases.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Console/Commands/ResetStudentDatabases.php) | `handle()` / `restoreSnapshot()` | L30 - L120 / L240 - L275 |
| **Disparo Asíncrono al Salir el Reclutador** | [`ShowcaseController.php`](file:///run/media/davidchoez/DATA/ULEAM_Academic/src/app/Http/Controllers/ShowcaseController.php) | `stopDemo()` | L315 - L335 |

---

## 5. Medidas de Seguridad y Políticas de Aislamiento

1. **Principio de Mínimo Privilegio:** Cada proyecto solo tiene permisos `ALL PRIVILEGES` sobre su propio catálogo `db_{uuid}`. Intentar ejecutar `SHOW DATABASES` o consultar tablas de otros proyectos es rechazado a nivel de motor de base de datos.
2. **Sin Exposición a Internet:** Los puertos 3306, 5432 y 27017 **no** están mapeados a la interfaz pública del host (`0.0.0.0`). Solo son accesibles dentro de la red privada virtual `uleam_academic_network`.
3. **Protección contra Inyecciones DDL:** Los nombres de bases de datos y usuarios son sanitizados estrictamente con expresiones regulares permitiendo únicamente caracteres alfanuméricos y guiones bajos (`[a-zA-Z0-9_]`), eliminando cualquier vector de inyección SQL en la orquestación.
4. **Resistencia a Denegación de Servicio (DoS) en Almacenamiento:** El snapshot de respaldo se limita a tablas y datos iniciales, y la limpieza periódica por cron previene la acumulación desmedida de registros temporales.
