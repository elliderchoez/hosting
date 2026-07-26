CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `content` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `messages` (`content`) VALUES
('Hola Mundo desde un SQL guardado en la subcarpeta /database !'),
('Esta base de datos se importó con éxito gracias al escaneo recursivo.');
