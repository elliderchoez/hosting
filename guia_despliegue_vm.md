# Guía de Despliegue y Aseguramiento en la Máquina Virtual (VM)
## Plataforma de Vitrina Académica y DevOps (ULEAM Academic)

Esta guía contiene la lista de pasos ordenados y comandos necesarios para instalar, configurar y asegurar la plataforma en la Máquina Virtual provista por el departamento de TI de la universidad.

---

## 1. Especificaciones Recomendadas para la VM
* **Sistema Operativo:** Ubuntu Server 22.04 LTS o 24.04 LTS (Arquitectura x86_64).
* **CPU:** Mínimo 2 Cores (Recomendado 4 Cores para compilaciones simultáneas).
* **Memoria RAM:** Mínimo 4 GB (Recomendado 8 GB si se ejecutarán muchas demos concurrentes).
* **Almacenamiento:** 40 GB SSD (o superior).
* **Red:** Dirección IP estática accesible públicamente o en subred autorizada por TI.

---

## 2. Paso 1: Instalación de Docker y Dependencias del Sistema

Ejecuta las actualizaciones básicas e instala las herramientas requeridas en la VM:

```bash
# Actualizar el sistema
sudo apt update && sudo apt upgrade -y

# Instalar dependencias iniciales
sudo apt install -y curl git unzip supervisor ufw php8.2-cli php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip php8.2-sqlite3 php8.2-mysql php8.2-pgsql php-pear

# Instalar Composer de forma global
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Instala Docker Engine oficial:

```bash
# Agregar la clave oficial de Docker GPG
sudo apt-get update
sudo apt-get install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

# Agregar el repositorio de Docker a las fuentes de Apt
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

---

## 3. Paso 2: Instalación y Configuración de gVisor (runsc)

Para aislar el kernel de la VM frente a posibles exploits o virus subidos por los estudiantes:

```bash
# Descargar binario de gVisor
(
  set -e
  ARCH=$(uname -m)
  URL="https://storage.googleapis.com/gvisor/releases/release/latest/${ARCH}"
  wget "${URL}/runsc"
  wget "${URL}/runsc.sha256"
  sha256sum -c runsc.sha256
  chmod a+rx runsc
  sudo mv runsc /usr/local/bin
)

# Configurar gVisor en el demonio de Docker
sudo runsc install

# Reiniciar Docker para aplicar los cambios
sudo systemctl restart docker
```

> **Verificación:** Para confirmar que gVisor está listo, ejecuta: `docker run --rm --runtime=runsc alpine uname -a`. Si la salida del kernel muestra algo diferente a tu kernel de Ubuntu (generalmente muestra `Linux ... gvisor`), gVisor está funcionando de forma correcta.

---

## 4. Paso 3: Configuración de la Aplicación Laravel (ULEAM Academic)

Mueve los archivos de tu proyecto a `/var/www/uleam-academic` y realiza los siguientes pasos:

```bash
# Crear directorio y clonar/copiar el proyecto
sudo mkdir -p /var/www/uleam-academic
sudo chown -R $USER:$USER /var/www/uleam-academic
# (Copia los archivos a esta carpeta)

cd /var/www/uleam-academic/src

# Instalar dependencias de PHP
composer install --no-dev --optimize-autoloader

# Crear archivo de entorno de producción
cp .env.example .env
```

Edita el archivo `.env` (`nano .env`) y asegúrate de configurar los siguientes parámetros clave:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://uleam-academic.software
APP_DOMAIN=uleam-academic.software

# Habilitar gVisor para producción
DOCKER_RUNTIME=runsc

# Configurar base de datos interna de la plataforma
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/uleam-academic/src/database/database.sqlite
```

Genera la llave de cifrado e inicializa las tablas del sistema:
```bash
# Generar llave de Laravel
php artisan key:generate

# Crear base de datos SQLite interna si no existe
touch /var/www/uleam-academic/src/database/database.sqlite

# Correr migraciones y seeders
php artisan migrate --force
php artisan db:seed --force

# Asignar permisos correctos a carpetas de escritura
sudo chown -R www-data:www-data /var/www/uleam-academic/src/storage
sudo chown -R www-data:www-data /var/www/uleam-academic/src/bootstrap/cache
sudo chmod -R 775 /var/www/uleam-academic/src/storage
```

---

## 5. Paso 4: Aseguramiento de Red a nivel de Servidor (Firewall iptables)

Para evitar que un contenedor realice escaneos de puertos o intente acceder a los servidores internos de la universidad (intranet):

1. Crea un script de configuración llamado `/usr/local/bin/secure-docker-net.sh`:
```bash
sudo nano /usr/local/bin/secure-docker-net.sh
```

2. Pega el siguiente contenido (Ajustar los nombres de la red si es necesario):
```bash
#!/bin/bash
# --- ASEGURAMIENTO DE RED PARA CONTENEDORES DE ESTUDIANTES ---

# 1. Obtener la subred asignada a la red de Docker de la tesis
SUBRED_DOCKER=$(docker network inspect uleam_academic_network -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null)

if [ -z "$SUBRED_DOCKER" ]; then
    echo "La red uleam_academic_network aún no existe. Omitiendo bloqueo."
    exit 0
fi

echo "Aplicando reglas de bloqueo de intranet para la red: $SUBRED_DOCKER"

# 2. Bloquear cualquier intento de enviar tráfico desde los contenedores hacia rangos privados (Intranet)
iptables -I FORWARD -s "$SUBRED_DOCKER" -d 10.0.0.0/8 -j REJECT
iptables -I FORWARD -s "$SUBRED_DOCKER" -d 172.16.0.0/12 -j REJECT
iptables -I FORWARD -s "$SUBRED_DOCKER" -d 192.168.0.0/16 -j REJECT

# 3. Bloquear el acceso desde los contenedores hacia puertos administrativos de este propio host (Servidor)
iptables -I INPUT -s "$SUBRED_DOCKER" -p tcp --dport 22 -j REJECT   # SSH
iptables -I INPUT -s "$SUBRED_DOCKER" -p tcp --dport 3306 -j REJECT # MySQL Centralizado
iptables -I INPUT -s "$SUBRED_DOCKER" -p tcp --dport 5432 -j REJECT # PostgreSQL Centralizado

echo "Reglas de Firewall aplicadas con éxito."
```

3. Dale permisos de ejecución al script:
```bash
sudo chmod +x /usr/local/bin/secure-docker-net.sh
```

4. Configura el script para que se ejecute de forma automática al iniciar la máquina virtual. Puedes hacerlo agregándolo en las tareas de cron de root:
```bash
# Abrir tareas programadas de root
sudo crontab -e
```
Agrega la siguiente línea al final del archivo:
```text
@reboot /usr/local/bin/secure-docker-net.sh
```

---

## 6. Paso 5: Levantamiento de Traefik (Proxy Reverso)

Traefik escuchará el puerto 80 y 443 del servidor principal y enrutará las peticiones web hacia los contenedores de los estudiantes de forma dinámica.

1. Crea el archivo de configuración para Traefik en `/var/www/uleam-academic/traefik.yml`:
```yaml
entryPoints:
  web:
    address: ":80"
    http:
      redirections:
        entryPoint:
          to: websecure
          scheme: https
  websecure:
    address: ":443"

providers:
  docker:
    exposedByDefault: false
    network: uleam_academic_network

certificatesResolvers:
  myresolver:
    acme:
      email: tu_correo@uleam.edu.ec
      storage: acme.json
      httpChallenge:
        entryPoint: web
```

2. Levanta Traefik como contenedor de Docker persistente:
```bash
# Crear la red de la tesis primero para que exista antes de levantar Traefik
docker network create uleam_academic_network

# Crear archivo vacío para almacenar los certificados SSL de Let's Encrypt
touch /var/www/uleam-academic/acme.json
chmod 600 /var/www/uleam-academic/acme.json

# Ejecutar contenedor de Traefik
docker run -d \
  --name traefik \
  --restart always \
  -p 80:80 \
  -p 443:443 \
  -v /var/run/docker.sock:/var/run/docker.sock:ro \
  -v /var/www/uleam-academic/traefik.yml:/traefik.yml:ro \
  -v /var/www/uleam-academic/acme.json:/acme.json \
  --network uleam_academic_network \
  traefik:v2.10
```

---

## 7. Paso 6: Configuración del Demonio del Sistema (Supervisor y Cron)

La compilación y despliegue de proyectos se ejecuta en segundo plano. Necesitamos configurar un demonio que mantenga activo el procesador de trabajos:

1. Crea el archivo de configuración de Supervisor en `/etc/supervisor/conf.d/uleam-worker.conf`:
```ini
[program:uleam-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/uleam-academic/src/artisan queue:work --sleep=3 --tries=1
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/uleam-academic/src/storage/logs/worker.log
stopwaitsecs=3600
```

2. Actualiza y arranca el Worker:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start uleam-worker:*
```

3. Registra el planificador de tareas de Laravel (necesario para el sistema de **Auto-Sleep** inactivo):
```bash
# Abrir tareas programadas de tu usuario actual
crontab -e
```
Agrega la siguiente línea al final para ejecutar el planificador cada minuto:
```text
* * * * * cd /var/www/uleam-academic/src && php artisan schedule:run >> /dev/null 2>&1
```

---

## 8. Verificación Final para la defensa de Tesis
Una vez ejecutados estos pasos, realiza los siguientes controles:
1. Sube un proyecto de prueba y comprueba que se le asigne su subdominio TLS de forma exitosa.
2. Abre la demo e intenta escribir archivos en carpetas protegidas del sistema para demostrar que el sistema de archivos está bloqueado en solo lectura.
3. Intenta realizar ping o escaneos hacia subredes de la intranet universitaria desde la bitácora o consola de depuración del proyecto para evidenciar el bloqueo absoluto por parte del Firewall de la VM.
