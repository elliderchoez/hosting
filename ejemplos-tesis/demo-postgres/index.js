const express = require('express');
const { Pool } = require('pg');

const app = express();
const port = process.env.PORT || 3000;

// Connect using DATABASE_URL (injected automatically) or individual env vars
const connectionString = process.env.DATABASE_URL;
const pool = new Pool({
  connectionString: connectionString
});

app.get('/', async (req, res) => {
  try {
    const client = await pool.connect();
    const result = await client.query('SELECT * FROM students_demo ORDER BY id DESC');
    client.release();

    let rowsHtml = result.rows.map(row => `
      <tr style="border-bottom: 1px solid #ddd;">
        <td style="padding: 12px; font-weight: bold; color: #2d3748;">#${row.id}</td>
        <td style="padding: 12px; color: #4a5568;">${row.name}</td>
        <td style="padding: 12px; color: #718096;">${row.email}</td>
        <td style="padding: 12px; color: #a0aec0;">${row.created_at}</td>
      </tr>
    `).join('');

    res.send(`
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Demo PostgreSQL - ULEAM Academic</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
        <style>
          body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
          }
          .card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 800px;
            width: 100%;
          }
          h1 {
            color: #1a202c;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
          }
          .badge {
            background-color: #3182ce;
            color: white;
            font-size: 0.8rem;
            padding: 4px 12px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
          }
          table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
          }
          th {
            background-color: #edf2f7;
            text-align: left;
            padding: 12px;
            color: #4a5568;
            font-weight: 600;
          }
          .success-message {
            background-color: #c6f6d5;
            border-left: 4px solid #38a169;
            color: #22543d;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
          }
        </style>
      </head>
      <body>
        <div class="card">
          <span class="badge" style="background-color: #3182ce;">Conexión Exitosa</span>
          <h1>Demo con Base de Datos PostgreSQL</h1>
          <div class="success-message">
            <strong>¡Cero Configuración Logrado!</strong> La base de datos y este proyecto se aprovisionaron e importaron automáticamente de forma correcta en PostgreSQL.
          </div>
          <h3>Datos Leídos de la Tabla 'students_demo':</h3>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Nombre del Estudiante</th>
                <th>Correo Electrónico</th>
                <th>Fecha de Registro</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml || '<tr><td colspan="4" style="padding: 12px; text-align: center; color: #a0aec0;">No hay datos en la tabla.</td></tr>'}
            </tbody>
          </table>
        </div>
      </body>
      </html>
    `);
  } catch (err) {
    console.error(err);
    res.status(500).send(`
      <div style="padding: 20px; font-family: sans-serif; border: 1px solid red; background: #fff5f5; border-radius: 8px; max-width: 600px; margin: 40px auto;">
        <h2 style="color: red; margin-top: 0;">Error de Conexión a Base de Datos</h2>
        <p><strong>Detalle:</strong> ${err.message}</p>
        <p><strong>Database URL utilizada:</strong> ${process.env.DATABASE_URL || 'No definida'}</p>
      </div>
    `);
  }
});

app.listen(port, () => {
  console.log(`Demo app listening on port ${port}`);
});
