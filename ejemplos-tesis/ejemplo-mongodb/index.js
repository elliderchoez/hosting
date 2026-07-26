const express = require('express');
const mongoose = require('mongoose');

const app = express();
const port = process.env.PORT || 3000;

app.use(express.urlencoded({ extended: true }));
app.use(express.json());

// Leer URI de conexión inyectada automáticamente por la plataforma
const mongoUri = process.env.MONGODB_URI || process.env.MONGO_URL || process.env.DATABASE_URL;

console.log("Intentando conectar a MongoDB...");
console.log("URI de Conexión:", mongoUri ? mongoUri.replace(/:([^:@]+)@/, ':****@') : "NO DEFINIDA");

// Definir Schema y Modelo de Mongoose
const UserSchema = new mongoose.Schema({
  name: { type: String, required: true },
  role: { type: String, required: true },
  createdAt: { type: Date, default: Date.now }
});

const User = mongoose.model('User', UserSchema);

// Función para inicializar datos de semilla (Seed) si la base de datos está vacía
async function seedDatabase() {
  try {
    const count = await User.countDocuments();
    if (count === 0) {
      console.log("Base de datos limpia. Insertando usuario de semilla original...");
      await User.create({
        name: "Administrador de Pruebas",
        role: "Docente Evaluador"
      });
      console.log("Semilla insertada con éxito.");
    }
  } catch (err) {
    console.error("Error al insertar semilla:", err);
  }
}

// Conectar a MongoDB
mongoose.connect(mongoUri)
  .then(async () => {
    console.log("Conectado con éxito a MongoDB!");
    await seedDatabase();
  })
  .catch(err => {
    console.error("ERROR CRÍTICO DE CONEXIÓN A MONGODB:", err.message);
    process.exit(1);
  });

// Ruta principal - Renderizar interfaz premium
app.get('/', async (req, res) => {
  try {
    const users = await User.find().sort({ createdAt: -1 });

    const html = `
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Demo NoSQL - ULEAM Academic</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=JetBrains+Mono&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg: #090d16;
                --card-bg: rgba(17, 25, 40, 0.75);
                --border: rgba(255, 255, 255, 0.08);
                --primary: #a855f7;
                --primary-glow: rgba(168, 85, 247, 0.4);
                --cyan: #06b6d4;
                --text: #f3f4f6;
                --text-muted: #9ca3af;
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: 'Outfit', sans-serif;
                background-color: var(--bg);
                color: var(--text);
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 20px;
                overflow-x: hidden;
                background-image: 
                    radial-gradient(circle at 10% 20%, rgba(168, 85, 247, 0.08) 0%, transparent 40%),
                    radial-gradient(circle at 90% 80%, rgba(6, 182, 212, 0.08) 0%, transparent 40%);
            }

            .container {
                width: 100%;
                max-width: 650px;
                background: var(--card-bg);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                border: 1px border var(--border);
                border-radius: 24px;
                padding: 40px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
                border: 1px solid var(--border);
            }

            header {
                text-align: center;
                margin-bottom: 35px;
            }

            h1 {
                font-size: 2.2rem;
                font-weight: 800;
                background: linear-gradient(135deg, var(--primary) 0%, var(--cyan) 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                margin-bottom: 8px;
            }

            .subtitle {
                font-size: 0.95rem;
                color: var(--text-muted);
            }

            .status-badge {
                display: inline-flex;
                align-items: center;
                padding: 6px 14px;
                border-radius: 50px;
                font-size: 0.75rem;
                font-weight: 600;
                background: rgba(6, 182, 212, 0.1);
                color: var(--cyan);
                border: 1px solid rgba(6, 182, 212, 0.2);
                margin-top: 15px;
                letter-spacing: 0.5px;
                text-transform: uppercase;
            }

            .status-dot {
                width: 7px;
                height: 7px;
                border-radius: 50%;
                background-color: var(--cyan);
                margin-right: 8px;
                box-shadow: 0 0 10px var(--cyan);
                animation: pulse 1.8s infinite;
            }

            @keyframes pulse {
                0% { opacity: 0.4; }
                50% { opacity: 1; }
                100% { opacity: 0.4; }
            }

            .section-title {
                font-size: 1.1rem;
                font-weight: 600;
                margin-bottom: 15px;
                color: var(--text);
                border-left: 3px solid var(--primary);
                padding-left: 10px;
            }

            .form-card {
                background: rgba(255, 255, 255, 0.02);
                border: 1px solid var(--border);
                border-radius: 16px;
                padding: 20px;
                margin-bottom: 30px;
            }

            .form-group {
                margin-bottom: 15px;
            }

            label {
                display: block;
                font-size: 0.8rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 6px;
                color: var(--text-muted);
            }

            input {
                width: 100%;
                background: rgba(0, 0, 0, 0.2);
                border: 1px solid var(--border);
                padding: 12px 16px;
                border-radius: 10px;
                color: var(--text);
                font-family: inherit;
                font-size: 0.9rem;
                transition: all 0.2s;
            }

            input:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 10px var(--primary-glow);
            }

            button {
                width: 100%;
                background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%);
                color: white;
                border: none;
                padding: 14px;
                border-radius: 12px;
                font-size: 0.9rem;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.2s;
                box-shadow: 0 4px 12px rgba(168, 85, 247, 0.2);
            }

            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(168, 85, 247, 0.35);
            }

            .user-list {
                max-height: 250px;
                overflow-y: auto;
                list-style: none;
                border: 1px solid var(--border);
                border-radius: 16px;
                background: rgba(0, 0, 0, 0.15);
            }

            .user-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 14px 20px;
                border-bottom: 1px solid var(--border);
                transition: background 0.2s;
            }

            .user-item:last-child {
                border-bottom: none;
            }

            .user-item:hover {
                background: rgba(255, 255, 255, 0.02);
            }

            .user-info {
                display: flex;
                flex-direction: column;
            }

            .user-name {
                font-weight: 600;
                font-size: 0.95rem;
                color: var(--text);
            }

            .user-role {
                font-size: 0.8rem;
                color: var(--text-muted);
                margin-top: 2px;
            }

            .user-date {
                font-family: 'JetBrains Mono', monospace;
                font-size: 0.7rem;
                color: var(--cyan);
                background: rgba(6, 182, 212, 0.08);
                padding: 3px 8px;
                border-radius: 4px;
            }

            .empty-state {
                padding: 30px;
                text-align: center;
                color: var(--text-muted);
                font-size: 0.9rem;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <h1>Proyecto Demo NoSQL</h1>
                <p class="subtitle">Aprovisionamiento Automático de MongoDB - ULEAM Academic</p>
                <div class="status-badge">
                    <span class="status-dot"></span>
                    MongoDB Conectado
                </div>
            </header>

            <div class="section-title">Agregar Nuevo Registro (MongoDB Test)</div>
            <div class="form-card">
                <form action="/add" method="POST">
                    <div class="form-group">
                        <label for="name">Nombre del Usuario</label>
                        <input type="text" id="name" name="name" placeholder="Ej. Juan Pérez" required>
                    </div>
                    <div class="form-group">
                        <label for="role">Cargo / Rol</label>
                        <input type="text" id="role" name="role" placeholder="Ej. Reclutador / Estudiante" required>
                    </div>
                    <button type="submit">Guardar en Base de Datos NoSQL</button>
                </form>
            </div>

            <div class="section-title">Colección de Usuarios en MongoDB</div>
            <ul class="user-list">
                ${users.length === 0 ? `
                    <li class="empty-state">No hay usuarios en la colección. Usa el formulario de arriba para agregar uno.</li>
                ` : users.map(user => `
                    <li class="user-item">
                        <div class="user-info">
                            <span class="user-name">${user.name}</span>
                            <span class="user-role">${user.role}</span>
                        </div>
                        <span class="user-date">${user.createdAt.toLocaleTimeString()}</span>
                    </li>
                `).join('')}
            </ul>
        </div>
    </body>
    </html>
    `;
    res.send(html);
  } catch (err) {
    res.status(500).send("Error interno: " + err.message);
  }
});

// Guardar nuevo registro
app.post('/add', async (req, res) => {
  try {
    const { name, role } = req.body;
    if (name && role) {
      await User.create({ name, role });
    }
    res.redirect('/');
  } catch (err) {
    res.status(500).send("Error al guardar: " + err.message);
  }
});

// Iniciar servidor
app.listen(port, () => {
  console.log(`Aplicación Demo MongoDB corriendo en http://localhost:${port}`);
});
