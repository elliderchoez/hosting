CREATE TABLE IF NOT EXISTS students_demo (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO students_demo (name, email) VALUES 
('David Choez', 'david.choez@live.uleam.edu.ec'),
('Maria Carmen Solorzano', 'maria.solorzano@live.uleam.edu.ec'),
('Juan Perez', 'juan.perez@example.com')
ON CONFLICT DO NOTHING;
