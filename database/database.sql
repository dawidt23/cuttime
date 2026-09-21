CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    surname VARCHAR(80) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    role ENUM('client', 'employee', 'admin') NOT NULL DEFAULT 'client',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE service_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    active BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE TABLE services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    duration INT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_services_category
        FOREIGN KEY (category_id)
        REFERENCES service_categories(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);
CREATE TABLE employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    description TEXT,
    active BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_employees_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);
CREATE TABLE employee_services (
    employee_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (employee_id, service_id),

    CONSTRAINT fk_employee_services_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_employee_services_service
        FOREIGN KEY (service_id)
        REFERENCES services(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
CREATE TABLE employee_availability (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,

    CONSTRAINT fk_availability_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT chk_availability_day
        CHECK (day_of_week BETWEEN 1 AND 7),

    CONSTRAINT chk_availability_hours
        CHECK (start_time < end_time)
);
CREATE TABLE reservations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,

    reservation_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,

    status ENUM(
        'pending',
        'confirmed',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    comment TEXT,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_reservations_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_reservations_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_reservations_service
        FOREIGN KEY (service_id)
        REFERENCES services(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_reservation_time
        CHECK (start_time < end_time)
);
-- KATEGORIE
INSERT INTO service_categories (name, description) VALUES
('Strzyżenie', 'Usługi związane ze strzyżeniem włosów'),
('Koloryzacja', 'Farbowanie i zmiana koloru włosów'),
('Stylizacja', 'Modelowanie i stylizacja włosów'),
('Pielęgnacja', 'Zabiegi pielęgnacyjne włosów');

-- USŁUGI
INSERT INTO services
(category_id, name, description, duration, price)
VALUES
(1, 'Strzyżenie damskie', 'Strzyżenie włosów damskich', 60, 80.00),
(1, 'Strzyżenie męskie', 'Strzyżenie włosów męskich', 30, 50.00),
(1, 'Strzyżenie dziecięce', 'Strzyżenie dla dzieci', 30, 40.00),
(2, 'Koloryzacja', 'Profesjonalna koloryzacja włosów', 120, 180.00),
(3, 'Modelowanie', 'Modelowanie i stylizacja włosów', 45, 60.00),
(4, 'Regeneracja włosów', 'Zabieg regenerujący włosy', 60, 100.00);

