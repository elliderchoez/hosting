const express = require('express');
const { Client } = require('pg');

const app = express();
const port = process.env.PORT || 3000;

const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  console.error("CRÍTICO: DATABASE_URL no está configurada en las variables de entorno.");
  process.exit(1);
}

// Intentar conectar y consultar la tabla 'users'
async function checkDatabase() {
  console.log("Conectando a la base de datos PostgreSQL...");
  const client = new Client({
    connectionString: connectionString
  });

  try {
    await client.connect();
    console.log("Conexión exitosa a Postgres. Consultando la tabla 'users'...");
    
    // Esto fallará porque no se importó ningún archivo .sql para crear la tabla
    const res = await client.query('SELECT * FROM users');
    console.log("Usuarios encontrados:", res.rows);
  } catch (err) {
    console.error("\n==================================================");
    console.error("ERROR CRÍTICO DE BASE DE DATOS AL ARRANCAR:");
    console.error(err.message);
    console.error("==================================================\n");
    console.error("Deteniendo la aplicación debido a error de base de datos.");
    
    // Forzar la salida antes de que Express abra el puerto
    process.exit(1);
  } finally {
    try {
      await client.end();
    } catch (e) {}
  }
}

// Función principal autoejecutable para asegurar el orden secuencial
async function startApp() {
  // Await la comprobación: si falla, crasheará el proceso aquí y nunca abrirá el puerto
  await checkDatabase();

  app.get('/', (req, res) => {
    res.send('Si ves esto, la base de datos funcionó (lo cual no debería pasar en este demo sin SQL).');
  });

  app.listen(port, () => {
    console.log(`Aplicación sin SQL corriendo en puerto ${port}`);
  });
}

startApp().catch(err => {
  console.error("Error al arrancar la aplicación:", err);
  process.exit(1);
});
