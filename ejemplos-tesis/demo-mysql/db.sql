CREATE TABLE IF NOT EXISTS `students_demo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `students_demo` (`name`, `email`) VALUES
('David Choez (MySQL)', 'david.choez@live.uleam.edu.ec'),
('Maria Carmen Solorzano (MySQL)', 'maria.solorzano@live.uleam.edu.ec'),
('Juan Perez (MySQL)', 'juan.perez@example.com');
