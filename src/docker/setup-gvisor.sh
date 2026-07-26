#!/bin/bash

# Setup gVisor Sandbox Runtime for Docker Engine
# Suitable for Ubuntu Server 24.04 LTS / 22.04 LTS
# Autor: Choez David - ULEAM Academic Hosting

set -e

echo "=== INICIANDO INSTALACIÓN DE GVISOR (SANDBOX SEGURE) ==="

# 1. Verificar que el script se ejecute con privilegios sudo
if [ "$EUID" -ne 0 ]; then
  echo "Error: Este script debe ser ejecutado con sudo o privilegios de root."
  exit 1
fi

# 2. Descargar las llaves y repositorios oficiales de gVisor
echo "Descargando la última versión del binario runsc..."
ARCH=$(uname -m)
URL="https://storage.googleapis.com/gvisor/releases/release/latest/${ARCH}"

wget -O runsc "${URL}/runsc"
wget -O runsc.sha512 "${URL}/runsc.sha512"

# 3. Verificar suma de verificación para seguridad
echo "Verificando integridad del archivo..."
sha512sum -c runsc.sha512

# 4. Instalar el binario runsc en el sistema
echo "Instalando el ejecutable runsc en /usr/local/bin..."
chmod a+rx runsc
mv runsc /usr/local/bin/

# 5. Configurar Docker para registrar el runtime runsc
echo "Registrando el runtime de gVisor (runsc) en la configuración de Docker..."
DAEMON_JSON="/etc/docker/daemon.json"

# Crear directorio si no existe
mkdir -p /etc/docker

if [ -f "$DAEMON_JSON" ]; then
    echo "El archivo /etc/docker/daemon.json ya existe. Creando respaldo y agregando runtime..."
    cp "$DAEMON_JSON" "${DAEMON_JSON}.bak"
    
    # Utilizar jq o Python para modificar el json de forma segura sin borrar otras configs
    python3 -c "
import json
try:
    with open('$DAEMON_JSON', 'r') as f:
        data = json.load(f)
except Exception:
    data = {}
if 'runtimes' not in data:
    data['runtimes'] = {}
data['runtimes']['runsc'] = {'path': '/usr/local/bin/runsc'}
with open('$DAEMON_JSON', 'w') as f:
    json.dump(data, f, indent=4)
"
else:
    echo "Creando un nuevo archivo /etc/docker/daemon.json..."
    cat <<EOF > "$DAEMON_JSON"
{
    "runtimes": {
        "runsc": {
            "path": "/usr/local/bin/runsc"
        }
    }
}
EOF
fi

# 6. Reiniciar Docker para aplicar cambios
echo "Reiniciando el servicio de Docker..."
systemctl restart docker

# 7. Limpiar archivos temporales
rm -f runsc.sha512

# 8. Verificar que esté registrado correctamente
echo "=== VERIFICACIÓN DE INSTALACIÓN ==="
echo "Comprobando la lista de runtimes en Docker:"
docker info | grep -i runtime || true

echo ""
echo "Ejecutando contenedor de prueba bajo gVisor sandbox..."
docker run --rm --runtime=runsc alpine uname -a

echo ""
echo "=== INSTALACIÓN COMPLETADA EXITOSAMENTE ==="
echo "gVisor (runsc) está configurado como runtime de Docker y listo para aislar proyectos."
echo "Puedes invocarlo usando la opción: --runtime=runsc en tus comandos Docker."
