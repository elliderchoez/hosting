# Expediente de Requerimientos Funcionales y No Funcionales

## Proyecto: ULEAM Academic Hosting
**Versión:** 1.0  
**Fecha:** 14 de Junio de 2026  
**Autor:** Choez David  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  
**Carrera:** Ingeniería de Tecnologías de la Información / Software  

---

## 1. Introducción
Este documento detalla las especificaciones de requerimientos funcionales y no funcionales del sistema **ULEAM Academic Hosting**. Su objetivo es servir como base para la validación de la viabilidad de desarrollo por parte de la comisión académica y el departamento de TI de la ULEAM.

---

## 2. Actores del Sistema
El sistema interactúa con tres roles principales:
1. **Estudiante (Usuario Académico):** Alumno regular de la carrera de TI y Software de la ULEAM con un correo institucional activo `@uleam.edu.ec`.
2. **Reclutador (Usuario Externo):** Representante de empresas del sector productivo/tecnológico interesado en evaluar y contratar talento de la universidad.
3. **Administrador (Personal de TI/Tutor):** Encargado de la supervisión del servidor, moderación de proyectos, gestión de cuotas de recursos y auditoría del sistema.

---

## 3. Requerimientos Funcionales (RF)

### 3.1 Módulo de Autenticación y Perfil (Común)
* **RF-01: Registro e Inicio de Sesión de Estudiantes:** El sistema permitirá el acceso exclusivo de estudiantes mediante autenticación institucional (OAuth2 o correo corporativo `@uleam.edu.ec`).
* **RF-02: Registro e Inicio de Sesión de Reclutadores:** El sistema permitirá a usuarios externos registrarse proporcionando nombre, empresa, cargo y correo corporativo validado para poder contactar a los estudiantes.
* **RF-03: Gestión de Perfil del Estudiante:** El estudiante podrá configurar su perfil incluyendo: foto de perfil, descripción profesional, enlaces a redes profesionales (LinkedIn, GitHub), tecnologías que domina, proyectos académicos y hoja de vida externa.
* **RF-04: Autogeneración de Hoja de Vida (CV) en PDF:** El sistema generará automáticamente un archivo PDF profesional con el formato estándar de la universidad basándose en los datos ingresados en el perfil del estudiante. Este archivo se guardará en almacenamiento externo (Cloudflare R2) para su descarga pública.

### 3.2 Módulo de Conexión de Código y Despliegue Automatizado
* **RF-05: Integración con GitHub:** El estudiante podrá vincular su cuenta de GitHub mediante OAuth para listar y seleccionar repositorios públicos para su despliegue.
* **RF-06: Detección Automática de Lenguaje:** El motor de despliegue analizará los archivos del repositorio de GitHub para identificar si es un proyecto basado en **Node.js**, **PHP** o **Python**.
* **RF-07: Proceso de Construcción (Build):** Al enviar el repositorio para despliegue, el sistema encolará el proceso de compilación (instalación de dependencias y empaquetamiento). El estudiante podrá ver el estado del build en tiempo real (Pendiente, Construyendo, Activo, Fallido).
* **RF-08: Gestión de Versiones (Re-deploy):** El estudiante podrá volver a desplegar su proyecto manualmente para actualizar el código si ha realizado cambios en su rama principal de GitHub (`main` o `master`).
* **RF-09: Visor de Logs de Construcción y Ejecución:** El sistema mostrará al estudiante las últimas 100 líneas del log de compilación y de los logs de ejecución del contenedor para facilitar la depuración de errores.

### 3.3 Módulo de Gestión de Contenedores (Panel de Control del Estudiante)
* **RF-10: Límite de Proyectos:** El sistema permitirá a cada estudiante registrar un máximo de **3 proyectos**.
* **RF-11: Control de Estado del Proyecto:** El estudiante podrá encender, apagar o reiniciar su contenedor manualmente desde su panel de control.
* **RF-12: Enrutamiento de Subdominios:** El sistema asignará automáticamente una URL única y segura con formato `https://[nombre-proyecto]-[usuario].uleam-academic.software` para cada proyecto activo.

### 3.4 Módulo de la Vitrina Pública de Talento
* **RF-13: Catálogo Público de Proyectos:** Acceso libre a la lista de proyectos desplegados de los estudiantes, ordenados por tecnologías, relevancia y fecha de actualización.
* **RF-14: Ejecución en Tiempo Real (Demo en Vivo):** Los visitantes y reclutadores podrán interactuar con el proyecto del estudiante directamente en el navegador a través de un visualizador seguro (`iframe` con directivas sandbox).
* **RF-15: Búsqueda y Filtros Avanzados:** El catálogo permitirá buscar proyectos por nombre, lenguaje de programación, habilidades del estudiante o año lectivo.
* **RF-16: Sistema de Contacto Segura:** El reclutador autenticado podrá enviar un mensaje de contacto al estudiante de forma directa a través de la plataforma, el cual se registrará y enviará por correo electrónico para prevenir el spam.

### 3.5 Módulo de Administración y Moderación (TI/Tutor)
* **RF-17: Panel de Administración General:** Vista global del estado del servidor (CPU, RAM, almacenamiento consumido por los contenedores).
* **RF-18: Moderación de Proyectos:** El administrador podrá pausar, reactivar o eliminar de forma manual cualquier contenedor que viole las políticas de uso de la universidad.
* **RF-19: Configuración de Políticas de Recursos:** Permite modificar dinámicamente los límites por defecto de CPU, RAM, almacenamiento y timeouts de compilación de los contenedores de los estudiantes.

---

## 4. Requerimientos No Funcionales (RNF)

### 4.1 Seguridad e Aislamiento
* **RNF-01: Aislamiento del Kernel (Sandboxing):** Toda aplicación de estudiante debe ejecutarse utilizando **gVisor (runsc)** como runtime de Docker, evitando la interacción directa del contenedor con el kernel del host.
* **RNF-02: Aislamiento de Red:** Los contenedores de los estudiantes no deben tener visibilidad ni comunicación con otros contenedores del sistema ni con la intranet privada de la ULEAM. Deben bloquearse los rangos de IP privadas `10.0.0.0/8`, `172.16.0.0/12` y `192.168.0.0/16`.
* **RNF-03: Integridad de Datos de la Plataforma:** La base de datos relacional PostgreSQL de la aplicación principal debe estar aislada y no ser accesible por ninguna app desplegada de los estudiantes.
* **RNF-04: Encriptación SSL/TLS:** Todo el tráfico HTTP del sistema principal y de los subdominios de estudiantes debe cifrarse mediante HTTPS utilizando certificados Let's Encrypt validados automáticamente.

### 4.2 Rendimiento y Escalabilidad
* **RNF-05: Escalamiento a Cero (Auto-Sleep):** Un contenedor activo pasará al estado apagado (STOPPED) automáticamente tras **15 minutos de inactividad** (sin peticiones HTTP entrantes) para liberar RAM del servidor.
* **RNF-06: Tiempo de Reactivación (Cold Start):** Cuando un usuario visite la URL de un proyecto en modo *sleep*, el proxy (Traefik) retendrá la petición mientras el backend reactiva el contenedor en un tiempo máximo de **3 a 8 segundos**.
* **RNF-07: Concurrencia de Compilación:** La cola de compilación de proyectos estará limitada a un máximo de **5 builds simultáneos** mediante Redis para evitar la saturación de los vCPUs del VPS.

### 4.3 Disponibilidad y Mantenimiento
* **RNF-08: Uptime Mínimo:** La aplicación principal debe garantizar una disponibilidad del **99.5%** durante el período lectivo.
* **RNF-09: Respaldo de Base de Datos:** Se realizarán backups automatizados diarios de la base de datos PostgreSQL y se almacenarán en Cloudflare R2 con retención de 30 días.
* **RNF-10: Recolección de Basura Docker (Garbage Collection):** El sistema ejecutará una limpieza programada semanal de imágenes y volúmenes Docker huérfanos para liberar disco duro.
