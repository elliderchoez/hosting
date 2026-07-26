const express = require('express');
const mysql = require('mysql2/promise');

const app = express();
const port = process.env.PORT || 3000;

// Middleware for parsing form request bodies
app.use(express.urlencoded({ extended: true }));

const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  console.error("DATABASE_URL no está configurada.");
  process.exit(1);
}

// Connect to MySQL
let pool;
async function getPool() {
  if (!pool) {
    pool = mysql.createPool({
      uri: connectionString,
      waitForConnections: true,
      connectionLimit: 5,
      queueLimit: 0
    });
  }
  return pool;
}

app.get('/', async (req, res) => {
  try {
    const dbPool = await getPool();
    const [rows] = await dbPool.execute('SELECT * FROM messages ORDER BY id DESC');

    let messagesHtml = '';
    if (rows.length === 0) {
      messagesHtml = '<p class="no-messages">No hay mensajes guardados en MySQL.</p>';
    } else {
      rows.forEach(msg => {
        messagesHtml += `
          <div class="message-card">
            <span class="message-id">Mensaje #${msg.id}</span>
            <p class="message-text">${msg.content}</p>
          </div>
        `;
      });
    }

    res.send(`
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Demo de SQL en Subcarpeta</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
          :root {
            --bg-primary: #090d16;
            --bg-card: #111827;
            --text-primary: #f9fafb;
            --text-secondary: #9ca3af;
            --accent: #a855f7;
            --accent-glow: rgba(168, 85, 247, 0.15);
            --border: #1f2937;
            --success: #10b981;
          }
          * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
          }
          body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
          }
          .container {
            width: 100%;
            max-width: 600px;
            background-color: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.7);
            text-align: center;
          }
          .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background-color: var(--accent-glow);
            color: var(--accent);
            padding: 0.5rem 1rem;
            border-radius: 100px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(168, 85, 247, 0.2);
          }
          .badge-dot {
            width: 8px;
            height: 8px;
            background-color: var(--success);
            border-radius: 50%;
            box-shadow: 0 0 10px var(--success);
          }
          h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: -0.025em;
          }
          p.desc {
            color: var(--text-secondary);
            font-size: 1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
          }
          .add-message-form {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 2rem;
          }
          .form-input {
            flex: 1;
            background-color: #090d16;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
          }
          .form-input:focus {
            border-color: var(--accent);
          }
          .submit-btn {
            background-color: var(--accent);
            color: var(--text-primary);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: opacity 0.2s;
          }
          .submit-btn:hover {
            opacity: 0.9;
          }
          .message-list {
            max-height: 250px;
            overflow-y: auto;
            padding-right: 0.25rem;
          }
          .message-card {
            background-color: rgba(255,255,255,0.01);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            text-align: left;
            transition: border-color 0.2s;
          }
          .message-card:hover {
            border-color: var(--accent);
          }
          .message-id {
            color: var(--accent);
            font-size: 0.75rem;
            font-weight: 700;
            display: block;
            margin-bottom: 0.25rem;
          }
          .message-text {
            font-size: 1rem;
            line-height: 1.5;
          }
          .footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 0.85rem;
          }
        </style>
      </head>
      <body>
        <div class="container">
          <div class="badge">
            <span class="badge-dot"></span>
            <span>Búsqueda SQL Recursiva Exitosa</span>
          </div>
          <h1>Búsqueda SQL en Subcarpeta</h1>
          <p class="desc">Este proyecto demuestra que la plataforma de tesis escanea recursivamente el directorio del estudiante para encontrar archivos de base de datos .sql, incluso si están guardados en subcarpetas.</p>
          
          <form action="/messages" method="POST" class="add-message-form">
            <input type="text" name="content" placeholder="Escribe un mensaje de prueba..." required class="form-input">
            <button type="submit" class="submit-btn">Enviar Mensaje</button>
          </form>

          <div class="message-list">
            ${messagesHtml}
          </div>
          
          <div class="footer">
            <span>ULEAM Academic Hosting • Proyecto de Tesis</span>
          </div>
        </div>
      </body>
      </html>
    `);
  } catch (error) {
    res.status(500).send(`Error de base de datos: ${error.message}`);
  }
});

app.post('/messages', async (req, res) => {
  try {
    const { content } = req.body;
    if (content) {
      const dbPool = await getPool();
      await dbPool.execute('INSERT INTO messages (content) VALUES (?)', [content]);
    }
    res.redirect('/');
  } catch (error) {
    res.status(500).send(`Error al guardar el mensaje: ${error.message}`);
  }
});

app.listen(port, () => {
  console.log(`Aplicación SQL en subcarpeta corriendo en puerto ${port}`);
});
