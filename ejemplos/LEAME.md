# Proyectos de Prueba para ULEAM Academic

Este directorio contiene dos ejemplos prácticos y sencillos listos para ser subidos a GitHub y probar el sistema de hosting:

1. **`php-portfolio`**: Una página de portafolio académica interactiva en PHP.
2. **`nodejs-express`**: Un servidor web dinámico en Node.js que utiliza Express.

---

## Instrucciones para probar en tu GitHub

### Opción 1: Probar con el proyecto PHP (Recomendado por rapidez)
El proyecto de PHP no requiere dependencias pesadas y compila al instante:

1. Ve a tu cuenta de GitHub y crea un nuevo repositorio público (ej. `mi-portafolio-php`).
2. Abre una terminal en tu computadora y entra a la carpeta del ejemplo:
   ```bash
   cd ejemplos/php-portfolio
   ```
3. Inicializa Git y sube el código a tu nuevo repositorio:
   ```bash
   git init
   git add .
   git commit -m "primer commit"
   git branch -M main
   git remote add origin https://github.com/TU_USUARIO/mi-portafolio-php.git
   git push -u origin main
   ```
4. Copia la URL de tu repositorio (`https://github.com/TU_USUARIO/mi-portafolio-php`) y pégala en el panel de **ULEAM Academic** para desplegarlo.

---

### Opción 2: Probar con el proyecto Node.js Express
Este proyecto incluye un servidor de Node.js que corre en el puerto 3000 (el puerto por defecto configurado en el PaaS):

1. Crea un nuevo repositorio público en GitHub (ej. `mi-servidor-node`).
2. Abre tu terminal y accede a la carpeta del ejemplo:
   ```bash
   cd ejemplos/nodejs-express
   ```
3. Inicializa Git y sube el código a tu repositorio:
   ```bash
   git init
   git add .
   git commit -m "primer commit"
   git branch -M main
   git remote add origin https://github.com/TU_USUARIO/mi-servidor-node.git
   git push -u origin main
   ```
4. Copia la URL de tu repositorio (`https://github.com/TU_USUARIO/mi-servidor-node`) y despliégala en **ULEAM Academic**. El sistema instalará automáticamente las dependencias con `npm install` e iniciará la ejecución de la demo.
