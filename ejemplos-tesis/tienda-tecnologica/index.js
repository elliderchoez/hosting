const express = require('express');
const bodyParser = require('body-parser');
const mysql = require('mysql2/promise');
const path = require('path');

const app = express();
const port = process.env.PORT || 3000;

app.use(bodyParser.json());
app.use(express.static(path.join(__dirname, 'public')));

// Connect to MySQL
let pool;

async function getPool() {
  if (!pool) {
    const connectionString = process.env.DATABASE_URL;
    if (!connectionString) {
      throw new Error("DATABASE_URL no está configurada.");
    }
    pool = mysql.createPool({
      uri: connectionString,
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0
    });
  }
  return pool;
}

// Check database connection
app.get('/api/health', async (req, res) => {
  try {
    const dbPool = await getPool();
    const [rows] = await dbPool.execute('SELECT 1');
    res.json({ status: 'OK', message: 'Conexión a MySQL exitosa.' });
  } catch (err) {
    res.status(500).json({ status: 'ERROR', error: err.message });
  }
});

// Reset database
app.post('/api/reset-db', async (req, res) => {
  try {
    const dbPool = await getPool();
    
    // Drop tables
    await dbPool.execute('SET FOREIGN_KEY_CHECKS = 0');
    await dbPool.execute('DROP TABLE IF EXISTS cart_items');
    await dbPool.execute('DROP TABLE IF EXISTS products');
    await dbPool.execute('DROP TABLE IF EXISTS users');
    await dbPool.execute('SET FOREIGN_KEY_CHECKS = 1');
    
    // Recreate tables
    await dbPool.execute(`
      CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);
    
    await dbPool.execute(`
      CREATE TABLE products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        image_url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);
    
    await dbPool.execute(`
      CREATE TABLE cart_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);

    // Insert seeds
    await dbPool.execute(`
      INSERT INTO products (name, description, price, image_url) VALUES
      ('Mouse Gaming Pro RGB', 'Mouse óptico ergonómico de 16000 DPI con luces RGB personalizables.', 45.99, 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?w=500&auto=format&fit=crop'),
      ('Teclado Mecánico Switch Blue', 'Teclado mecánico con switches azules táctiles, retroiluminación y layout en español.', 79.99, 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=500&auto=format&fit=crop'),
      ('Auriculares Headset 7.1', 'Auriculares circumaurales con sonido envolvente 7.1 y micrófono con cancelación de ruido.', 60.50, 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500&auto=format&fit=crop'),
      ('Monitor Curvo 24" 144Hz', 'Monitor curvo Full HD ideal para gaming, tasa de refresco de 144Hz y 1ms de respuesta.', 199.99, 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?w=500&auto=format&fit=crop');
    `);
    
    await dbPool.execute(`
      INSERT INTO users (username, password, role) VALUES
      ('admin', 'admin123', 'admin'),
      ('estudiante', 'user123', 'user');
    `);

    res.json({ success: true, message: 'Base de datos restablecida al estado original.' });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// Login
app.post('/api/login', async (req, res) => {
  const { username, password } = req.body;
  try {
    const dbPool = await getPool();
    const [users] = await dbPool.execute(
      'SELECT id, username, role FROM users WHERE username = ? AND password = ?',
      [username, password]
    );

    if (users.length === 0) {
      return res.status(401).json({ error: 'Credenciales inválidas.' });
    }

    res.json({ user: users[0] });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Register
app.post('/api/register', async (req, res) => {
  const { username, password, role } = req.body;
  try {
    const dbPool = await getPool();
    const userRole = role === 'admin' ? 'admin' : 'user';
    
    await dbPool.execute(
      'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
      [username, password, userRole]
    );

    const [users] = await dbPool.execute(
      'SELECT id, username, role FROM users WHERE username = ?',
      [username]
    );

    res.status(201).json({ user: users[0] });
  } catch (err) {
    if (err.code === 'ER_DUP_ENTRY') {
      return res.status(400).json({ error: 'El nombre de usuario ya existe.' });
    }
    res.status(500).json({ error: err.message });
  }
});

// Get Products
app.get('/api/products', async (req, res) => {
  try {
    const dbPool = await getPool();
    const [products] = await dbPool.execute('SELECT * FROM products ORDER BY id DESC');
    res.json(products);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Add Product (Admin)
app.post('/api/products', async (req, res) => {
  const { name, description, price, image_url } = req.body;
  try {
    const dbPool = await getPool();
    const resolvedImageUrl = image_url || 'https://images.unsplash.com/photo-1531403009284-440f080d1e12?w=500&auto=format&fit=crop';
    const [result] = await dbPool.execute(
      'INSERT INTO products (name, description, price, image_url) VALUES (?, ?, ?, ?)',
      [name, description, price, resolvedImageUrl]
    );
    res.status(201).json({ id: result.insertId, name, description, price, image_url: resolvedImageUrl });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Edit Product (Admin)
app.put('/api/products/:id', async (req, res) => {
  const { id } = req.params;
  const { name, description, price, image_url } = req.body;
  try {
    const dbPool = await getPool();
    const resolvedImageUrl = image_url || 'https://images.unsplash.com/photo-1531403009284-440f080d1e12?w=500&auto=format&fit=crop';
    await dbPool.execute(
      'UPDATE products SET name = ?, description = ?, price = ?, image_url = ? WHERE id = ?',
      [name, description, price, resolvedImageUrl, id]
    );
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Delete Product (Admin)
app.delete('/api/products/:id', async (req, res) => {
  const { id } = req.params;
  try {
    const dbPool = await getPool();
    await dbPool.execute('DELETE FROM products WHERE id = ?', [id]);
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get Cart Items
app.get('/api/cart/:userId', async (req, res) => {
  const { userId } = req.params;
  try {
    const dbPool = await getPool();
    const [items] = await dbPool.execute(`
      SELECT c.id, c.quantity, p.id as product_id, p.name, p.price, p.image_url 
      FROM cart_items c 
      JOIN products p ON c.product_id = p.id 
      WHERE c.user_id = ?
    `, [userId]);
    res.json(items);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Add to Cart
app.post('/api/cart', async (req, res) => {
  const { user_id, product_id, quantity } = req.body;
  try {
    const dbPool = await getPool();
    
    // Check if item already in cart
    const [existing] = await dbPool.execute(
      'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?',
      [user_id, product_id]
    );

    if (existing.length > 0) {
      const newQty = existing[0].quantity + (quantity || 1);
      await dbPool.execute(
        'UPDATE cart_items SET quantity = ? WHERE id = ?',
        [newQty, existing[0].id]
      );
    } else {
      await dbPool.execute(
        'INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)',
        [user_id, product_id, quantity || 1]
      );
    }
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Remove from Cart
app.delete('/api/cart/:id', async (req, res) => {
  const { id } = req.params;
  try {
    const dbPool = await getPool();
    await dbPool.execute('DELETE FROM cart_items WHERE id = ?', [id]);
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Checkout (Clear Cart simulation)
app.post('/api/cart/checkout/:userId', async (req, res) => {
  const { userId } = req.params;
  try {
    const dbPool = await getPool();
    await dbPool.execute('DELETE FROM cart_items WHERE user_id = ?', [userId]);
    res.json({ success: true, message: 'Pago simulado con éxito.' });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.listen(port, () => {
  console.log(`Tienda Tecnologica corriendo en puerto ${port}`);
});
