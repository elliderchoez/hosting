const express = require('express');
const { Sequelize, DataTypes } = require('sequelize');

const app = express();
const port = process.env.PORT || 3000;

// Middleware for parsing form request bodies
app.use(express.urlencoded({ extended: true }));

// Setup Sequelize connection
const connectionString = process.env.DATABASE_URL;
if (!connectionString) {
  console.error("DATABASE_URL no está configurada.");
  process.exit(1);
}

const sequelize = new Sequelize(connectionString, {
  dialect: process.env.DB_CONNECTION === 'mysql' ? 'mysql' : 'postgres',
  logging: false,
  dialectOptions: process.env.DB_CONNECTION === 'mysql' ? {} : {
    ssl: false
  }
});

// Define User Model
const User = sequelize.define('User', {
  name: {
    type: DataTypes.STRING,
    allowNull: false
  },
  email: {
    type: DataTypes.STRING,
    allowNull: false,
    unique: true
  }
}, {
  timestamps: true
});

app.get('/', async (req, res) => {
  try {
    // Authenticate database connection
    await sequelize.authenticate();

    // Query all users from the migration-created table
    const users = await User.findAll({ order: [['id', 'DESC']] });

    let userListHtml = '';
    if (users.length === 0) {
      userListHtml = '<p class="no-users">No hay usuarios en la base de datos.</p>';
    } else {
      userListHtml = '<ul class="user-list">';
      users.forEach(user => {
        userListHtml += `
          <li class="user-card">
            <div class="user-info">
              <span class="user-id">#${user.id}</span>
              <span class="user-name">${user.name}</span>
              <span class="user-email">${user.email}</span>
            </div>
            <span class="user-date">${user.createdAt.toLocaleTimeString()}</span>
          </li>
        `;
      });
      userListHtml += '</ul>';
    }

    res.send(`
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Demo de Migraciones Automáticas (Sequelize)</title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
          :root {
            --bg-primary: #0b0f19;
            --bg-card: #151c2c;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent: #38bdf8;
            --accent-glow: rgba(56, 189, 248, 0.15);
            --border: #243049;
            --success: #34d399;
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
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.5);
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
            border: 1px solid rgba(56, 189, 248, 0.2);
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
          .add-user-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(255,255,255,0.01);
            border: 1px solid var(--border);
            border-radius: 16px;
            text-align: left;
          }
          .form-title {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.05em;
          }
          .form-input {
            width: 100%;
            background-color: #0b0f19;
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
            color: var(--bg-primary);
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: opacity 0.2s;
          }
          .submit-btn:hover {
            opacity: 0.9;
          }
          .user-list {
            list-style: none;
            text-align: left;
            margin-bottom: 2rem;
            max-height: 250px;
            overflow-y: auto;
            padding-right: 0.25rem;
          }
          .user-card {
            background-color: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            transition: transform 0.2s, border-color 0.2s;
          }
          .user-card:hover {
            transform: translateY(-2px);
            border-color: var(--accent);
          }
          .user-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
          }
          .user-id {
            color: var(--accent);
            font-size: 0.75rem;
            font-weight: 700;
            font-family: monospace;
          }
          .user-name {
            font-weight: 600;
            font-size: 1rem;
          }
          .user-email {
            color: var(--text-secondary);
            font-size: 0.87rem;
          }
          .user-date {
            font-size: 0.75rem;
            color: var(--text-secondary);
          }
          .footer {
            border-top: 1px solid var(--border);
            padding-top: 1.5rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
          }
        </style>
      </head>
      <body>
        <div class="container">
          <div class="badge">
            <span class="badge-dot"></span>
            <span>Migración Automática Exitosa</span>
          </div>
          <h1>Sequelize + Node.js Demo</h1>
          <p class="desc">Las tablas se crearon utilizando el ORM Sequelize sin ningún archivo .sql. ¡Agrega un usuario para testear la escritura en la base de datos!</p>
          
          <form action="/users" method="POST" class="add-user-form">
            <div class="form-title">Registrar Nuevo Usuario</div>
            <input type="text" name="name" placeholder="Nombre completo" required class="form-input">
            <input type="email" name="email" placeholder="Correo electrónico" required class="form-input">
            <button type="submit" class="submit-btn">Agregar Usuario</button>
          </form>

          ${userListHtml}
          
          <div class="footer">
            <span>ULEAM Academic Hosting • Proyecto de Tesis</span>
          </div>
        </div>
      </body>
      </html>
    `);
  } catch (error) {
    res.status(500).send(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>Error de Base de Datos</title>
        <style>
          body { font-family: sans-serif; background: #0b0f19; color: #ef4444; padding: 3rem; text-align: center; }
          .error-container { max-width: 600px; margin: auto; background: #151c2c; border: 1px solid #ef4444; padding: 2rem; border-radius: 12px; }
        </style>
      </head>
      <body>
        <div class="error-container">
          <h1>Error de Conexión a Base de Datos</h1>
          <p>${error.message}</p>
        </div>
      </body>
      </html>
    `);
  }
});

app.post('/users', async (req, res) => {
  try {
    const { name, email } = req.body;
    if (name && email) {
      await User.create({ name, email });
    }
    res.redirect('/');
  } catch (error) {
    res.status(500).send(`
      <div style="font-family:sans-serif; background:#0b0f19; color:#ef4444; padding:3rem; text-align:center;">
        <div style="max-width:600px; margin:auto; background:#151c2c; border:1px solid #ef4444; padding:2rem; border-radius:12px;">
          <h2>Error al registrar usuario</h2>
          <p>${error.message}</p>
          <a href="/" style="color:#38bdf8; text-decoration:none; font-weight:bold; display:inline-block; margin-top:1rem;">Volver</a>
        </div>
      </div>
    `);
  }
});

app.listen(port, () => {
  console.log(`Aplicación Sequelize corriendo en puerto ${port}`);
});
