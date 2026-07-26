# Justificación Técnica de Requerimientos de Infraestructura

## Proyecto: ULEAM Academic Hosting
**Versión:** 1.0  
**Fecha:** 14 de Junio de 2026  
**Autor:** Choez David  
**Institución:** Universidad Laica Eloy Alfaro de Manabí (ULEAM)  

---

## 1. Introducción
Este documento detalla la justificación técnica de los recursos de hardware, red, direccionamiento IP, enrutamiento y puertos necesarios para la implementación de la plataforma **ULEAM Academic Hosting** en los servidores de la universidad. 

Este informe sirve como requerimiento formal para la asignación de la Máquina Virtual (VM) por parte del departamento de TI de la ULEAM.

---

## 2. Requerimientos de Hardware (Servidor Principal)
La plataforma alojará el sistema principal y ejecutará de manera concurrente las aplicaciones de estudiantes de las carreras de TI y Software. Se justifica la necesidad de los siguientes recursos mínimos calculados para un universo de **300 estudiantes**:

| Recurso | Requisito Mínimo | Requisito Óptimo (Recomendado) | Justificación Técnica |
| :--- | :--- | :--- | :--- |
| **CPU** | 8 vCPUs | 12 vCPUs | El procesamiento principal ocurre durante el build de proyectos (compilación de código Node, PHP y Python) y la conversión de PDFs con Puppeteer. Se limita la CPU a un máximo de **0.5 cores por contenedor** de estudiante. |
| **Memoria RAM** | 12 GB | 36 GB | * **Escenario 12 GB:** ~4 GB para el sistema base y Postgres. Deja ~8 GB libres. Soporta **32 contenedores activos** concurrentes a 256 MB. Requiere auto-sleep rápido (10 min).<br>* **Escenario 36 GB:** ~32 GB libres para contenedores. Soporta **128 contenedores activos** concurrentes a 256 MB. Alta estabilidad en defensas y evaluaciones. |
| **Almacenamiento** | 200 GB NVMe / SSD | 250 GB NVMe / SSD | Para evitar el uso excesivo de disco, implementamos **Imágenes Base Genéricas + Volumen de Código**. De esta manera, en lugar de 1 GB por proyecto, cada uno pesa en promedio **30 MB** (código puro). <br> `900 proyectos × 30 MB = 27 GB` de almacenamiento neto para proyectos. El resto del disco se asigna al OS, base de datos y cachés. |

---

## 3. Requerimientos de Red y Direccionamiento IP

### 3.1 Direccionamiento IP
* **IP Pública Estática Directa:** La Máquina Virtual asignada requiere obligatoriamente una dirección IP pública estática y directa (v4) configurada en su interfaz de red, o en su defecto un direccionamiento NAT 1:1 en el firewall corporativo de la ULEAM que redirija todo el tráfico público de Internet hacia la IP privada de la VM.

### 3.2 Puertos de Red Necesarios
Para la correcta operación y enrutamiento del hosting, se requiere la apertura exclusiva de los siguientes puertos de entrada:

| Puerto | Protocolo | Dirección | Origen | Propósito Técnico |
| :--- | :--- | :--- | :--- | :--- |
| **22** | TCP | Entrada | Administrador (Restringido o Público) | Acceso de administración remota SSH para el despliegue del código principal e instalación del sistema. |
| **80** | TCP | Entrada | Cualquiera (Público) | Tráfico HTTP estándar. Usado por el proxy **Traefik** para la redirección obligatoria a HTTPS y la validación de certificados SSL ACME. |
| **443** | TCP | Entrada | Cualquiera (Público) | Tráfico HTTPS seguro. Es el puerto por donde se servirá la plataforma principal y todas las aplicaciones desplegadas por los estudiantes de forma segura. |

*Nota: Puertos internos como el de PostgreSQL (5432) o Redis (6379) deben estar bloqueados para el tráfico externo y solo serán accesibles a nivel de localhost o redes internas Docker.*

---

## 4. Requerimientos de DNS (Nombres de Dominio)
El sistema requiere el enrutamiento dinámico para crear subdominios automáticos por cada proyecto de estudiante (Multi-tenant).

* **Requerimiento en Producción:** Un registro **A** y un registro **A de Comodín (Wildcard)** configurado en el servidor DNS de la ULEAM apuntando a la IP pública de la VM.
  * Dominio base: `uleam-academic.edu.ec` -> Apunta a `IP_PUBLICA`
  * Dominio wildcard: `*.uleam-academic.edu.ec` -> Apunta a `IP_PUBLICA`
* **Alternativa para Pruebas y Desarrollo:** En caso de demoras en la configuración DNS de la ULEAM, el sistema puede operar de forma independiente adquiriendo un dominio externo genérico (ej. `uleam-academic.software` o `*.uleam-hosting.site`) administrado mediante Cloudflare DNS.

---

## 5. Software Base Requerido (Entorno de la VM)
La VM entregada por la universidad debe poseer o permitir la instalación de:
1. **Ubuntu Server 24.04 LTS (x64)** como sistema operativo anfitrión.
2. **Docker Engine v25+** y **Docker Compose v2+** para la orquestación local de contenedores.
3. **gVisor (runsc)** instalado y configurado en el archivo `/etc/docker/daemon.json` para proveer el sandbox de aislamiento seguro.
4. **Soporte de Kernel KVM** (virtualización anidada en el hipervisor) para mejorar el rendimiento de gVisor.
