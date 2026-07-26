CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` VARCHAR(10) NOT NULL DEFAULT 'user',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `image_url` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default products
INSERT INTO `products` (`name`, `description`, `price`, `image_url`) VALUES
('Mouse Gaming Pro RGB', 'Mouse óptico ergonómico de 16000 DPI con luces RGB personalizables.', 45.99, 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?w=500&auto=format&fit=crop'),
('Teclado Mecánico Switch Blue', 'Teclado mecánico con switches azules táctiles, retroiluminación y layout en español.', 79.99, 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=500&auto=format&fit=crop'),
('Auriculares Headset 7.1', 'Auriculares circumaurales con sonido envolvente 7.1 y micrófono con cancelación de ruido.', 60.50, 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500&auto=format&fit=crop'),
('Monitor Curvo 24" 144Hz', 'Monitor curvo Full HD ideal para gaming, tasa de refresco de 144Hz y 1ms de respuesta.', 199.99, 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?w=500&auto=format&fit=crop');

-- Seed default admin and user
INSERT INTO `users` (`username`, `password`, `role`) VALUES
('admin', 'admin123', 'admin'),
('estudiante', 'user123', 'user');
