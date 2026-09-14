# Banco de Preguntas y Respuestas para la Defensa de Tesis

**Proyecto:** ULEAM Academic Hosting (PaaS)  
**Autor:** David Choez  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  
**Destinatarios:** Tutor de Tesis, Miembros del Tribunal Calificador y Evaluadores Técnicos  

---

## Bloque 1: Justificación Académica y Planteamiento del Problema

### P1: ¿Por qué desarrollar una plataforma propia en lugar de enseñar a los estudiantes a usar nubes comerciales como AWS, Azure, Heroku o Render?
**Respuesta del Tesista:**
> "Las plataformas comerciales presentan tres barreras insalvables en el contexto universitario público:
> 1. **Barrera Financiera y Métodos de Pago:** AWS y Azure exigen tarjetas de crédito internacionales y conllevan riesgos de cobros inesperados en dólares si un estudiante olvida apagar una instancia. Heroku eliminó sus tiers gratuitos, y servicios como Render o Railway suspenden o eliminan las bases de datos tras periodos cortos de inactividad.
> 2. **Complejidad de DevOps vs. Objetivos Pedagógicos:** En materias como Programación Web o Bases de Datos, el objetivo es aprender desarrollo y lógica de negocio, no lidiar con políticas complejas de IAM, VPCs o facturación de nube.
> 3. **Fricción de Reclutamiento y Retención del Patrimonio:** En las nubes comerciales, la universidad no tiene visibilidad de los proyectos creados por sus alumnos. Con ULEAM Academic Hosting, la universidad cuenta con un **repositorio auditable y centralizado de software en vivo** con subdominios institucionales (`*.uleam-academic.software`), permitiendo a los reclutadores probar el talento en un entorno estandarizado sin fricciones."

---

### P2: ¿Cuál es el aporte científico o técnico original de esta tesis frente a un hosting web convencional?
**Respuesta del Tesista:**
> "Un hosting compartido tradicional (como cPanel) solo permite subir archivos PHP estáticos mediante FTP y carece de aislamiento moderno de procesos. El aporte técnico de este trabajo radica en:
> 1. **Diseño de un Pipeline PaaS Multi-Tenant Desatendido:** Detección automática del stack (Laravel, Node.js, Django, HTML), resolución y parcheo automático de compatibilidades legacy para PHP 8.2+, aprovisionamiento dinámico de bases de datos relacionales y NoSQL al vuelo, y generación de credenciales automáticas.
> 2. **Aislamiento Seguro mediante Google gVisor (`runsc`):** Implementación de virtualización a nivel de llamadas del sistema (kernel en espacio de usuario), evitando el escape de contenedores en código no confiable escrito por estudiantes.
> 3. **Arquitectura de Escalamiento a Cero y Bases de Datos Efímeras:** El sistema mantiene dormidos los contenedores inactivos para no consumir RAM y restaura instantáneamente la base de datos a su estado original mediante snapshots cada vez que un evaluador cierra la demo."

---

## Bloque 2: Arquitectura de Software y Decisiones de Implementación

### P3: ¿Por qué eligió Laravel 11 junto a React 18 a través de Inertia.js en lugar de una arquitectura desacoplada tradicional de API REST?
**Respuesta del Tesista:**
> "Se evaluó la arquitectura desacoplada (Frontend React independiente comunicándose con una API REST Laravel independiente con tokens Sanctum/JWT) frente a **Inertia.js**, y se eligió Inertia por las siguientes ventajas clave:
> 1. **Eliminación de la Redundancia:** Con una API REST tradicional, se requiere duplicar la definición de rutas, esquemas de validación y control de estado tanto en el frontend como en el backend. Inertia permite programar el backend como un monolito clásico (controladores, redirecciones, sesiones nativas) mientras renderiza componentes de React de última generación como vistas.
> 2. **Velocidad de Desarrollo y Rendimiento:** Inertia evita las peticiones HTTP adicionales iniciales para obtener datos del usuario o sesión; los datos viajan directamente serializados en la primera respuesta del servidor, garantizando tiempos de carga casi instantáneos sin sacrificar la interactividad de React.
> 3. **Seguridad Nativa:** Las solicitudes de Inertia utilizan la protección CSRF integrada de Laravel y sesiones cifradas de primer nivel, eliminando los riesgos de almacenar tokens JWT desprotegidos en `localStorage`."

---

### P4: ¿Por qué utiliza PostgreSQL para el núcleo de la plataforma y clusters separados para los estudiantes?
**Respuesta del Tesista:**
> "Por el principio de **Separación de Responsabilidades y Tolerancia a Fallos**:
> * La **base de datos principal** (PostgreSQL 16) almacena información crítica del negocio: usuarios, contraseñas hash, roles, logs de compilación y metadata de proyectos.
> * Las **bases de datos de estudiantes** (MySQL 8.0, PostgreSQL 15 y MongoDB 7) corren en instancias separadas. Si un estudiante ejecuta una consulta destructiva (`DROP TABLE`, `FLUSH TABLES`) o una consulta ineficiente que consuma memoria, **el núcleo de la plataforma nunca se ve afectado ni se bloquea**."

---

## Bloque 3: Seguridad, Sandbox y Aislamiento

### P5: Si los estudiantes pueden subir código arbitrario, ¿qué impide que un estudiante ejecute comandos dañinos como `rm -rf /`, lea los archivos del servidor o robe variables de entorno de otros alumnos?
**Respuesta del Tesista:**
> "El sistema implementa una estrategia de **Defensa en Profundidad (Defense in Depth)** con 4 capas de seguridad:
> 1. **Kernel en Espacio de Usuario con Google gVisor (`runsc`):** En un contenedor Docker normal (`runc`), los contenedores comparten el mismo kernel del sistema operativo anfitrión. Si hay una vulnerabilidad en el kernel de Linux (como Dirty COW o CVEs de privilegios), un atacante puede escapar al host. gVisor intercepta todas las llamadas del sistema (*syscalls*) y las ejecuta en un kernel emulado en Go. Las llamadas peligrosas nunca tocan el kernel real de la máquina virtual.
> 2. **Montaje de Volúmenes Restringido:** Cada contenedor solo tiene montada su propia carpeta de proyecto (`/storage/app/projects/project-{id}`). El sistema de archivos raíz del host es invisible para el contenedor.
> 3. **Aislamiento de Red:** Los contenedores están conectados a una red interna bridge que tiene restringido el acceso al socket de Docker (`docker.sock`) y a los servicios de infraestructura de la universidad.
> 4. **Límites de Recursos cgroups:** Con `--memory 256m` y `--cpus 0.5`, se previene cualquier ataque de denegación de servicio por saturación de RAM (*fork bombs*) o uso de recursos para minería de criptomonedas."

---

### P6: ¿Cómo se gestiona el acceso a las bases de datos de los estudiantes para evitar que un alumno lea la información de otro?
**Respuesta del Tesista:**
> "El aprovisionamiento es estrictamente programático y automatizado en `BuildProjectJob`:
> 1. Para cada proyecto se crea un nombre de base de datos único (`db_{uuid}`) y un usuario exclusivo con contraseña aleatoria de 24 caracteres (`u_{uuid}`).
> 2. Mediante directivas SQL (`GRANT ALL ON db_{uuid}.* TO 'u_{uuid}'@'%'`), los privilegios se acotan **únicamente** a esa base de datos específica.
> 3. El usuario del estudiante no tiene privilegios para ver (`SHOW DATABASES`) ni consultar esquemas de otros proyectos, garantizando aislamiento total multitenant."

---

## Bloque 4: Rendimiento, Infraestructura y Escalamiento a Cero

### P7: Un servidor universitario promedio tiene recursos moderados (ej. 12 GB o 24 GB de RAM). ¿Cómo puede este sistema soportar 300 o más proyectos sin colapsar?
**Respuesta del Tesista:**
> "A través de dos innovaciones clave de la arquitectura:
> 1. **Algoritmo de Escalamiento a Cero (Auto-Sleep):** De 300 proyectos, rara vez están más de 10 o 15 evaluándose al mismo tiempo. Los contenedores inactivos se apagan automáticamente a los 15 minutos de inactividad, liberando su memoria RAM a 0 MB. Cuando un reclutador entra a su URL, el sistema tarda apenas **2 a 3 segundos** en despertar el contenedor.
> 2. **Imágenes Base Genéricas en lugar de Imágenes Propias:** Crear una imagen Docker por proyecto ocuparía ~1 GB de disco (`300 × 1 GB = 300 GB`). Nuestro PaaS mantiene precargadas imágenes genéricas ligeras (`runner-php`, `runner-node`, `runner-python`) y monta el código fuente del alumno (~30 MB). `300 proyectos × 30 MB = solo 9 GB`, reduciendo el uso de almacenamiento en más de un 95%."

---

## Bloque 5: La "Auto-Instalación Silenciosa" (Zero-Click) y Experiencia del Reclutador

### P8: ¿Qué sucede con proyectos complejos como Crater Invoice, WordPress o sistemas que exigen un instalador web (`/installation`) para configurar la base de datos?
**Respuesta del Tesista:**
> "Este era uno de los mayores desafíos del proyecto y se resolvió con la **Opción 2: Auto-Instalación Silenciosa (Zero-Click)**:
> 1. Si un reclutador tuviera que ingresar datos de conexión (host, puerto, credenciales de base de datos) en una pantalla de instalación, la experiencia de evaluación fracasaría.
> 2. Por ello, el pipeline `BuildProjectJob` automatiza la finalización del instalador en segundo plano:
>    - Crea automáticamente los archivos bandera de instalación (`database_created`, `installed`).
>    - Ejecuta un script Tinker en segundo plano que marca la configuración inicial (`profile_complete = COMPLETED`), inicializa la empresa por defecto y otorga permisos de `super admin`.
>    - Detecta automáticamente el usuario administrador y establece su contraseña a una conocida (`password`) sin sufrir problemas de doble hashing.
>    - Inyecta estas credenciales directamente en las instrucciones de la demo en la interfaz del evaluador.
> 3. El reclutador entra directamente al **Login** o al **Dashboard** listo para interactuar."

---

### P9: ¿Qué ocurre cuando un reclutador interactúa con un proyecto, crea facturas o modifica registros? ¿Quedan esos datos para el siguiente visitante?
**Respuesta del Tesista:**
> "No, gracias al sistema de **Snapshots Iniciales y Reseteo Efímero**:
> 1. Inmediatamente después de que el proyecto se compila y puebla por primera vez, el sistema genera un volcado SQL ligero (`.initial_db_snapshot.sql` de ~80 KB).
> 2. Cuando el evaluador termina su prueba y presiona 'Cerrar' en el modal del Showcase, el servidor ejecuta en segundo plano el comando:
>    `php artisan projects:reset-databases --project={id}`
> 3. Este comando restaura la base de datos a su estado original en menos de **1 segundo**. De este modo, la demo siempre luce profesional, limpia y predecible para cada nuevo evaluador."

---

## Bloque 6: Compatibilidad de Navegadores y Entorno de Producción

### P10: Durante las pruebas locales se observó que al iniciar sesión en el visor salía un error de 'CSRF token mismatch / Unauthenticated' en Brave Browser, pero en ventana nueva funcionaba perfecto. ¿Ocurrirá esto en producción?
**Respuesta del Tesista:**
> "Definitivamente **NO ocurrirá en producción**, por razones bien fundamentadas en las especificaciones de seguridad de cookies de la IETF:
> 1. **Mismo Dominio Registrable (*Same-Site*):** En desarrollo local, se accedía a la plataforma principal por IP (`http://127.0.0.1:8000`) mientras el iframe corría en subdominio (`http://crater.localhost`). Para el navegador, una IP y un dominio son orígenes completamente cruzados (*Cross-Site*), por lo que las políticas de privacidad de Brave bloquearon las cookies de terceros dentro del iframe.
> 2. **En Producción con Dominio y HTTPS:** La plataforma correrá en `https://uleam-academic.software` y las aplicaciones de los estudiantes en `https://proyecto.uleam-academic.software`. Al compartir el mismo dominio raíz (`uleam-academic.software`) bajo cifrado SSL, las cookies se consideran **Same-Site**, permitiendo la navegación e inicio de sesión tanto dentro del visor modal como en pantalla completa sin ningún tipo de restricción."

---

## Bloque 7: Conclusiones e Impacto del Proyecto de Tesis

### P11: ¿Qué impacto medible tiene este proyecto para la carrera de Ingeniería de Software de la ULEAM?
**Respuesta del Tesista:**
> "El impacto se mide en tres dimensiones:
> 1. **Inserción Laboral:** Permite a los estudiantes adjuntar en sus hojas de vida enlaces a proyectos en vivo (*live demos*), aumentando drásticamente la tasa de respuesta en entrevistas técnicas de trabajo frente a simples enlaces a código estático en GitHub.
> 2. **Eficiencia Académica:** Reduce a cero el tiempo perdido durante defensas de materia o proyectos integradores configurando entornos locales de bases de datos o servidores en las computadoras de los estudiantes.
> 3. **Alineación con Estándares de la Industria:** Familiariza a los estudiantes con conceptos contemporáneos del desarrollo moderno: contenedores Docker, variables de entorno, pipelines CI/CD y despliegues en la nube."
