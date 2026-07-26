# Tipo de Licencia de Software y Consideraciones Legales

## Proyecto: ULEAM Academic Hosting
**Versión:** 1.0  
**Fecha:** 14 de Junio de 2026  
**Autor:** Choez David  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  

---

## 1. Tipo de Licencia del Software Principal
Para la plataforma **ULEAM Academic Hosting** se recomienda adoptar la **Licencia MIT**, la cual es una licencia de software libre permisiva y de amplio estándar en la industria.

### 1.1 Términos de la Licencia MIT:
* **Permisiones:** Se permite el uso, copia, modificación, fusión, publicación, distribución, sublicenciamiento y venta de copias del software de forma gratuita.
* **Condiciones:** El único requisito es que el aviso de copyright y este permiso se incluyan en todas las copias o partes sustanciales del software.
* **Garantía:** El software se proporciona "tal cual", sin garantía de ningún tipo. La universidad y el autor quedan exentos de toda responsabilidad en caso de fallos, reclamaciones o daños.

### 1.2 Justificación de la Licencia MIT para la ULEAM:
* **Fomento Académico:** Permite que futuras cohortes de estudiantes de la carrera de TI y Software puedan auditar el código, mejorarlo, adaptarlo o desarrollar extensiones como parte de nuevos proyectos de titulación o integradores.
* **Transparencia Institucional:** Al ser de código abierto, la universidad demuestra soberanía tecnológica y promueve el conocimiento abierto en la región de Manabí.

---

## 2. Consideraciones Legales y Propiedad Intelectual

### 2.1 Propiedad Intelectual del Código de los Estudiantes
Un aspecto legal clave es que **la plataforma es únicamente un entorno de alojamiento (Hosting PaaS) y vitrina profesional**. 
* **Titularidad del Código:** Los estudiantes de la ULEAM retienen el 100% de la propiedad intelectual y derechos de autor sobre el código fuente de los proyectos que despliegan en la plataforma.
* **Uso de Repositorios Públicos:** Al exigir que los despliegues se realicen desde repositorios públicos de GitHub, se asume que el código tiene un carácter de exposición abierta. Sin embargo, la plataforma no reclama ningún derecho de propiedad sobre el software desplegado.

### 2.2 Limitación de Responsabilidad de la Universidad (Disclaimer)
Dado que la plataforma permite ejecutar código escrito por estudiantes (código de terceros), se debe incluir un descargo de responsabilidad legal visible en los términos y condiciones de la plataforma:
* **Responsabilidad del Contenido:** Cada estudiante es el único responsable legal del código que ejecuta y el contenido que sirve en su subdominio asignado.
* **Uso no Autorizado:** Se prohíbe explícitamente el uso del hosting para alojar contenido ilegal, pornográfico, difamatorio, de minería de criptomonedas (cryptomining), phishing o actividades de hacking/escaneo de redes.
* **Respaldo de Información:** La plataforma no se hace responsable por la pérdida de datos de las aplicaciones de los estudiantes. Los contenedores son efímeros y pueden destruirse o archivarse en caso de inactividad.

---

## 3. Protección de Datos Personales (Cumplimiento LOPDP Ecuador)
La plataforma maneja información de perfiles estudiantiles y hojas de vida (CVs). Por lo tanto, debe cumplir con la **Ley Orgánica de Protección de Datos Personales (LOPDP) de Ecuador**:

### 3.1 Consentimiento Informado
* **Estudiantes:** Al registrarse con su correo institucional, los estudiantes otorgan su consentimiento explícito para que sus datos académicos (nombre, carrera, proyectos, habilidades) y su hoja de vida en PDF sean de acceso público para los reclutadores autorizados en Internet.
* **Reclutadores:** Se recopilan datos básicos (nombre, correo corporativo, empresa) únicamente con el fin de validar su identidad y permitir la comunicación directa con el estudiante, evitando el envío de correo no deseado (spam).

### 3.2 Almacenamiento Seguro
* Los datos personales y archivos PDF de las hojas de vida se almacenan de manera cifrada en la base de datos PostgreSQL de la universidad y en **Cloudflare R2** utilizando protocolos de comunicación seguros (HTTPS/SSL).

### 3.3 Derechos ARCO (Acceso, Rectificación, Cancelación y Oposición)
* El sistema permitirá a los estudiantes rectificar su información en cualquier momento a través de su dashboard de perfil y eliminar su cuenta por completo, lo que borrará de forma inmediata su registro de la base de datos y sus archivos de CV en PDF de los servidores de la universidad.
