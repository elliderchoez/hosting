# ULEAM Academic Hosting: Plataforma PaaS Académica y Portafolio en Vivo para Estudiantes de Ingeniería de Software y Tecnologías de la Información

**Documento:** Memoria Técnica y Explicación Integral del Proyecto de Tesis  
**Autor:** David Choez  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  
**Facultad:** Ciencias Informáticas / Ingeniería de Software y TI  
**Fecha:** 2026  

---

## 1. ¿Qué es el Proyecto de Tesis?

**ULEAM Academic Hosting** es una plataforma de **Plataforma como Servicio (PaaS - Platform as a Service)** académica y de portafolio profesional en vivo, diseñada y desarrollada a medida para los estudiantes de las carreras de Ingeniería de Software y Tecnologías de la Información de la Universidad Laica Eloy Alfaro de Manabí.

El sistema funciona de forma análoga a soluciones comerciales como *Render*, *Vercel* o *Heroku*, pero optimizado específicamente para el contexto universitario y de inserción laboral:
1. Permite a los estudiantes subir sus aplicaciones web académicas (mediante enlace a repositorio de **GitHub** o mediante archivo comprimido **ZIP**).
2. El sistema analiza automáticamente el código fuente, detecta el lenguaje y framework (PHP/Laravel, Node.js, Python/Django, etc.), compila las dependencias, aprovisiona bases de datos dedicadas (MySQL, PostgreSQL, MongoDB) y despliega la aplicación en un contenedor aislado con su propio subdominio institucional (`https://proyecto.uleam-academic.software`).
3. Provee un **Showcase Público (Vitrina de Talento)** donde reclutadores de empresas y evaluadores pueden probar los proyectos en vivo en tiempo real con **credenciales automáticas de prueba**, sin necesidad de descargar código, instalar dependencias ni configurar servidores locales.

---

## 2. ¿Para qué es y para qué sirve? (Problemática y Misión)

### 2.1 Problemática Identificada
En la formación universitaria tradicional de carreras de computación:
* **"En mi máquina sí funciona":** Los estudiantes desarrollan proyectos complejos para materias como Desarrollo Web, Sistemas Distribuidos, Bases de Datos o Titulación. Sin embargo, al momento de evaluarlos o presentarlos a reclutadores, el código queda archivado en repositorios estáticos de GitHub o carpetas locales.
* **Barrera de Entrada en la Nube Comercial:** Servicios como AWS, Google Cloud o Azure requieren tarjetas de crédito internacionales, conllevan costos impredecibles de facturación y exigen configuraciones complejas de DevOps que superan el alcance de un estudiante promedio. Además, plataformas gratuitas (Heroku retiró sus tiers gratuitos, Render suspende bases de datos y borra registros).
* **Fricción para Reclutadores Técnicos:** Un reclutador laboral tiene un promedio de **30 a 60 segundos** para evaluar a un postulante. Si para evaluar un proyecto de un estudiante debe clonar el repositorio, configurar Node/PHP/Python, crear bases de datos y ejecutar migraciones, simplemente **no lo hace**.
* **Pérdida del Patrimonio de Software Universitario:** Cientos de proyectos de gran calidad desarrollados semestralmente en la ULEAM se pierden en el olvido al terminar el periodo académico.

### 2.2 Para qué sirve la solución
* **Demostración Inmediata en Vivo:** Transforma código estático en aplicaciones funcionales accesibles mediante un enlace directo de internet con SSL.
* **Acreditación del Talento Real:** El reclutador interactúa con software real: crea facturas, registra compras, prueba pasarelas o consulta catálogos.
* **Reseteo Efímero Seguro:** Las bases de datos se restauran a su snapshot inicial cuando el reclutador cierra la demo, garantizando que el sistema siempre esté listo para la siguiente evaluación sin ensuciar datos.

---

## 3. ¿Para quién es? (Público Objetivo)

| Actor | Beneficio Principal |
| :--- | :--- |
| **Estudiantes de la ULEAM** | Alojamiento gratuito, inmediato y desatendido de sus proyectos académicos. Disponen de un perfil público profesional y una URL funcional para su CV y LinkedIn. |
| **Reclutadores de Empresas de TI** | Visualización inmediata del software funcionando con "Zero-Click": credenciales de prueba preconfiguradas, datos de catálogo precargados y visor en vivo. |
| **Docentes y Tribunales de Calificación** | Calificación centralizada y en tiempo real de prácticas y defensas de tesis sin lidiar con problemas de configuración local en las laptops de los alumnos. |
| **Dirección de Carrera y ULEAM** | Repositorio vivo y auditable del patrimonio de software generado por los estudiantes de la universidad, fortaleciendo los indicadores de vinculación y acreditación. |

---

## 4. Requisitos para el Despliegue en Producción

Para garantizar que el PaaS funcione de forma estable para un universo de **300 estudiantes concurrentes**, se estipulan los siguientes requerimientos de infraestructura:

### 4.1 Requerimientos de Hardware (Máquina Virtual / Servidor Dedicado)
* **Procesador (CPU):** Mínimo **8 vCPUs** (Recomendado: 12 vCPUs). Justificación: El consumo principal de CPU es transitorio y ocurre durante la fase de *Build* (compilación de assets con Vite/Webpack, instalación de dependencias `npm`/`composer` y análisis estático). Cada contenedor en ejecución se restringe a un tope de **0.5 cores**.
* **Memoria RAM:** Mínimo **12 GB RAM** (Recomendado: **24 GB a 36 GB RAM**).
  * Con 12 GB: ~4 GB para el sistema base (OS, PostgreSQL, Redis, Traefik). Deja ~8 GB libres, soportando **32 contenedores activos simultáneamente** a 256 MB c/u con política de *Auto-Sleep* a los 10-15 minutos de inactividad.
  * Con 36 GB: Permite hasta **128 contenedores activos en tiempo real**, ideal para eventos de evaluación masiva o defensas de grado.
* **Almacenamiento:** **200 GB a 250 GB SSD NVMe**. El sistema implementa una arquitectura de **Imágenes Base Genéricas + Volumen de Código**. En lugar de crear una imagen Docker pesada de 1 GB por cada proyecto, el sistema monta el código compilado (~30 MB promedio) sobre ejecutores base pre-cargados. `300 proyectos × 30 MB = ~9 GB netos`.

### 4.2 Requisitos de Red, Dominios y SSL
* **Dirección IP Pública Estática:** IPv4 directa con puertos abiertos:
  * **22 (TCP):** SSH para administración.
  * **80 (TCP):** HTTP para redirección a HTTPS y validación ACME de Let's Encrypt.
  * **443 (TCP):** HTTPS para la navegación segura de la plataforma y todas las apps de los estudiantes.
* **DNS Wildcard (Comodín):**
  * Registro `A`: `uleam-academic.software` -> `IP_DEL_SERVIDOR`
  * Registro `A Wildcard`: `*.uleam-academic.software` -> `IP_DEL_SERVIDOR`
* **Proxy Inverso Dinámico:** **Traefik 3.0** conectado al socket de Docker (`/var/run/docker.sock`) para descubrir contenedores y emitir certificados SSL automáticamente sin reiniciar el servidor.

### 4.3 Software Base del Servidor Anfitrión
* **Sistema Operativo:** Ubuntu Server 24.04 LTS (x64) o Debian 12 con soporte de virtualización KVM habilitado.
* **Docker Engine:** v25+ y Docker Compose v2+.
* **Runtime de Sandbox:** **Google gVisor (`runsc`)** configurado como runtime de Docker en `/etc/docker/daemon.json`.

---

## 5. Limitaciones del Sistema y Políticas de Cuotas

Para preservar la estabilidad, seguridad e integridad del servidor compartido (*Multi-Tenant*), se definen formalmente las siguientes limitaciones:

1. **Límite de Memoria RAM por Proyecto:** Cada contenedor tiene asignado un límite estricto de **256 MB de RAM** (`--memory 256m`). Si una aplicación excede este consumo, Docker reinicia el proceso de forma controlada sin comprometer el host.
2. **Límite de CPU por Proyecto:** Cuota máxima de **0.5 vCPUs** (`--cpus 0.5`) para evitar que un bucle infinito o proceso malicioso sature la CPU compartida.
3. **Escalamiento a Cero (Auto-Sleep):** Los contenedores activos se apagan automáticamente tras **15 minutos de inactividad**. Cuando un usuario visita su URL o presiona "Probar Proyecto", el sistema lo reactiva en 2 a 4 segundos.
4. **Almacenamiento de Código:** Límite máximo de **50 MB** por repositorio o archivo ZIP subido (excluyendo `node_modules` o carpetas `vendor`, que se instalan dinámicamente).
5. **Aislamiento de Red:** Los contenedores de los estudiantes corren en redes virtuales independientes (`bridge`). Tienen bloqueado el acceso a la red interna de gestión y solo pueden acceder al host de su base de datos asignada.
6. **Bases de Datos Efímeras con Reseteo:** Cuando un evaluador finaliza la prueba de un proyecto o presiona "Cerrar", el sistema restaura la base de datos a su estado inicial mediante el snapshot `.initial_db_snapshot.sql`. No está diseñado para ser un servicio de hosting de producción permanente con persistencia de datos comerciales críticos, sino un entorno de evaluación de alto impacto.

---

## 6. ¿Cómo Funciona Todo el Proyecto? (Arquitectura Integral)

El sistema opera a través de 4 subsistemas coordinados:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        ULEAM ACADEMIC HOSTING                          │
├───────────────────────────┬────────────────────────────────────────────┤
│ 1. Plataforma Central     │ Laravel 11 + Inertia.js + React 18 + Vite   │
│ 2. Pipeline Asíncrono     │ Redis Queue + Horizon + BuildProjectJob    │
│ 3. Runtime Sandbox        │ Docker Engine + Google gVisor (runsc)      │
│ 4. Clúster de Datos       │ PostgreSQL 15, MySQL 8.0, MongoDB 7        │
│ 5. Enrutador Perimetral   │ Traefik 3.0 con Wildcard Let's Encrypt     │
└───────────────────────────┴────────────────────────────────────────────┘
```

### 6.1 Fase 1: Recepción y Creación
El alumno inicia sesión con su cuenta institucional, define el nombre del proyecto, subdominio deseado (ej. `tienda-online`), selecciona el motor de base de datos requerido (MySQL, Postgres o MongoDB) e introduce el enlace de su repositorio de GitHub o arrastra un archivo ZIP.

### 6.2 Fase 2: Pipeline de Aprovisionamiento Asíncrono (`BuildProjectJob`)
1. **Clonación y Desempaquetado:** Se descarga el código en una carpeta aislada bajo `/storage/app/projects/project-{id}`.
2. **Aprovisionamiento de Base de Datos:** El sistema crea dinámicamente un usuario y una base de datos independientes en el clúster de estudiantes (`db_{id}`).
3. **Inyección `.env`:** Se genera o actualiza el archivo `.env` del estudiante con las credenciales de base de datos, el subdominio asignado y configuraciones de sesión.
4. **Detección e Instalación de Dependencias:**
   - Si detecta `composer.json` (PHP/Laravel): ejecuta `composer install --no-dev --no-scripts` bajo contenedor efímero, aplica parches de compatibilidad para PHP 8.2+ y ejecuta `package:discover`.
   - Si detecta `package.json` (Node.js): ejecuta `npm install` y `npm run build` para compilar los estáticos.
   - Si detecta `requirements.txt` / `manage.py` (Python/Django): configura un entorno virtual `.venv` e instala librerías.
5. **Migraciones y Datos de Prueba:** Ejecuta `artisan migrate --force`, seeders y el proceso de auto-instalación silenciosa.
6. **Snapshot Inicial:** Captura una copia íntegra de la base de datos configurada (`.initial_db_snapshot.sql`).
7. **Provisión de Credenciales:** Detecta o crea el usuario administrador y añade las credenciales directamente en las instrucciones de la demo.

### 6.3 Fase 3: Encendido del Contenedor Bajo gVisor
El sistema invoca a Docker para iniciar el contenedor de ejecución con la imagen base adecuada, montando la carpeta del proyecto y asignando las etiquetas de Traefik para enrutar el tráfico de `subdominio.uleam-academic.software`. El contenedor corre bajo el runtime seguro `runsc`.

### 6.4 Fase 4: Showcase y Evaluación Recíproca
En la página principal de la plataforma, los proyectos aprobados se muestran públicamente organizados por carrera y estudiante. Al hacer clic en "Probar Proyecto":
- Se abre un modal de prueba en vivo con visor integrado y guía lateral de evaluación.
- Si el contenedor estaba dormido (*sleep*), el sistema lo despierta automáticamente mostrando una animación de carga interactiva.
- El evaluador puede probar el proyecto en el visor o hacer clic en "Abrir en Pantalla Completa".
- Al terminar y cerrar la ventana, el sistema lanza en segundo plano el comando `projects:reset-databases` para restaurar la base de datos al snapshot original.
