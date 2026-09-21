-- CutTime — Etap 1. Pełny import do pustej bazy (jednorazowo).
-- Wymagane: MySQL >= 8.0.16 lub MariaDB >= 10.2.1 z egzekwowaniem CHECK.
-- Bez DROP/TRUNCATE: skrypt nie usuwa istniejących tabel ani danych.
-- To skrypt odtworzeniowy, nie migracja istniejących tabel.
CREATE DATABASE IF NOT EXISTS cuttime
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cuttime;
SET NAMES utf8mb4;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE service_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    duration INT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,

    -- UNSIGNED dopuszcza zero; cena DECIMAL bez CHECK dopuszcza wartości ujemne.
    CONSTRAINT chk_services_duration CHECK (duration > 0),
    CONSTRAINT chk_services_price CHECK (price >= 0),

    CONSTRAINT fk_services_category
        FOREIGN KEY (category_id)
        REFERENCES service_categories(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE employee_availability (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,

    -- Wyszukiwanie przedziałów pracy konkretnego pracownika w danym dniu tygodnia.
    INDEX idx_availability_employee_day_start (employee_id, day_of_week, start_time),

    CONSTRAINT fk_availability_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT chk_availability_day
        CHECK (day_of_week BETWEEN 1 AND 7),

    CONSTRAINT chk_availability_hours
        CHECK (start_time >= '00:00:00' AND start_time < end_time
            AND end_time < '24:00:00')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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

    -- Terminarz i kandydaci do konfliktu: employee_id = ?, reservation_date = ?,
    -- start_time < nowy_koniec; dodatkowo sprawdź end_time > nowy_początek
    -- oraz status IN ('pending', 'confirmed'). Indeks nie blokuje kolizji samodzielnie.
    INDEX idx_reservations_employee_date_start (employee_id, reservation_date, start_time),
    -- Historia i nadchodzące wizyty klienta w kolejności terminów.
    INDEX idx_reservations_user_date_start (user_id, reservation_date, start_time),
    -- Lista wizyt o wybranym statusie w zakresie dat, np. oczekujących na potwierdzenie.
    -- Sam status ma niewiele wartości, dlatego nie otrzymuje osobnego indeksu.
    INDEX idx_reservations_status_date_start (status, reservation_date, start_time),

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
        CHECK (start_time >= '00:00:00' AND start_time < end_time
            AND end_time < '24:00:00')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Reguły do walidacji przy zapisie w przyszłym etapie: rola 'client' rezerwującego,
-- rola 'employee' właściciela profilu pracownika, aktywność, przypisanie usługi,
-- zgodność z dostępnością i brak kolizji (z zabezpieczeniem równoległych zapisów).
-- Osobne FK rezerwacji celowo zachowują historię po usunięciu przypisania
-- employee_services; nie wiążemy historycznej wizyty z aktualną ofertą pracownika.
-- end_time przechowuje ustalony koniec wizyty, niezależny od późniejszych zmian
-- services.duration. Nie powielamy w rezerwacji nazw ani danych kontaktowych.
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

-- DANE TESTOWE
-- Poniższy blok uruchom jednorazowo. W istniejącej bazie z powyższymi
-- kategoriami i usługami wykonaj tylko ten blok, bez CREATE TABLE.
-- Wszystkie konta testowe mają hasło: password
-- Hash bcrypt w formacie PHP password_hash(..., PASSWORD_BCRYPT):
-- $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.
-- Jest zgodny z PHP password_verify(); konta służą wyłącznie do testów.
-- day_of_week: 1 = poniedziałek, ..., 7 = niedziela.
-- Daty są stałe, aby dane testowe były powtarzalne.
START TRANSACTION;

SET @test_password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

INSERT INTO users (name, surname, email, password, phone, role) VALUES
('Aleksandra', 'Nowak', 'admin@cuttime.test', @test_password_hash, '500100100', 'admin');

INSERT INTO users (name, surname, email, password, phone, role) VALUES
('Anna', 'Kowalska', 'anna.kowalska@cuttime.test', @test_password_hash, '500100101', 'employee');
SET @anna_user_id = LAST_INSERT_ID();

INSERT INTO users (name, surname, email, password, phone, role) VALUES
('Piotr', 'Wiśniewski', 'piotr.wisniewski@cuttime.test', @test_password_hash, '500100102', 'employee');
SET @piotr_user_id = LAST_INSERT_ID();

INSERT INTO users (name, surname, email, password, phone, role) VALUES
('Maria', 'Zielińska', 'maria.zielinska@cuttime.test', @test_password_hash, '500100103', 'client');
SET @maria_user_id = LAST_INSERT_ID();

INSERT INTO users (name, surname, email, password, phone, role) VALUES
('Jan', 'Wójcik', 'jan.wojcik@cuttime.test', @test_password_hash, '500100104', 'client');
SET @jan_user_id = LAST_INSERT_ID();

INSERT INTO employees (user_id, description) VALUES
(@anna_user_id, 'Specjalistka od strzyżenia damskiego, koloryzacji i pielęgnacji włosów.');
SET @anna_employee_id = LAST_INSERT_ID();

INSERT INTO employees (user_id, description) VALUES
(@piotr_user_id, 'Specjalista od strzyżenia męskiego, dziecięcego i stylizacji.');
SET @piotr_employee_id = LAST_INSERT_ID();

-- Wyszukanie istniejących usług bez zakładania konkretnych wartości ich ID.
SET @women_service_id = (SELECT id FROM services WHERE name = 'Strzyżenie damskie' ORDER BY id LIMIT 1);
SET @men_service_id = (SELECT id FROM services WHERE name = 'Strzyżenie męskie' ORDER BY id LIMIT 1);
SET @children_service_id = (SELECT id FROM services WHERE name = 'Strzyżenie dziecięce' ORDER BY id LIMIT 1);
SET @color_service_id = (SELECT id FROM services WHERE name = 'Koloryzacja' ORDER BY id LIMIT 1);
SET @styling_service_id = (SELECT id FROM services WHERE name = 'Modelowanie' ORDER BY id LIMIT 1);
SET @care_service_id = (SELECT id FROM services WHERE name = 'Regeneracja włosów' ORDER BY id LIMIT 1);

INSERT INTO employee_services (employee_id, service_id) VALUES
(@anna_employee_id, @women_service_id),
(@anna_employee_id, @color_service_id),
(@anna_employee_id, @styling_service_id),
(@anna_employee_id, @care_service_id),
(@piotr_employee_id, @men_service_id),
(@piotr_employee_id, @children_service_id),
(@piotr_employee_id, @styling_service_id);

-- Anna: poniedziałek–piątek 09:00–17:00; Piotr: poniedziałek–piątek 10:00–18:00.
INSERT INTO employee_availability (employee_id, day_of_week, start_time, end_time) VALUES
(@anna_employee_id, 1, '09:00:00', '17:00:00'),
(@anna_employee_id, 2, '09:00:00', '17:00:00'),
(@anna_employee_id, 3, '09:00:00', '17:00:00'),
(@anna_employee_id, 4, '09:00:00', '17:00:00'),
(@anna_employee_id, 5, '09:00:00', '17:00:00'),
(@piotr_employee_id, 1, '10:00:00', '18:00:00'),
(@piotr_employee_id, 2, '10:00:00', '18:00:00'),
(@piotr_employee_id, 3, '10:00:00', '18:00:00'),
(@piotr_employee_id, 4, '10:00:00', '18:00:00'),
(@piotr_employee_id, 5, '10:00:00', '18:00:00');

-- Czas każdej wizyty odpowiada services.duration (w minutach).
-- Wizyty nie kolidują ani po stronie pracowników, ani klientów.
INSERT INTO reservations
(user_id, employee_id, service_id, reservation_date, start_time, end_time, status, comment)
VALUES
(@maria_user_id, @anna_employee_id, @women_service_id, '2026-09-18', '09:00:00', '10:00:00', 'completed', 'Strzyżenie z zachowaniem długości do ramion.'),
(@jan_user_id, @piotr_employee_id, @men_service_id, '2026-09-18', '10:00:00', '10:30:00', 'completed', 'Krótkie boki, góra skrócona nożyczkami.'),
(@maria_user_id, @anna_employee_id, @color_service_id, '2026-09-22', '09:00:00', '11:00:00', 'confirmed', 'Koloryzacja w naturalnym odcieniu.'),
(@jan_user_id, @piotr_employee_id, @men_service_id, '2026-09-22', '11:00:00', '11:30:00', 'confirmed', 'Powtórzenie poprzedniej fryzury.'),
(@maria_user_id, @anna_employee_id, @styling_service_id, '2026-09-22', '12:00:00', '12:45:00', 'pending', 'Modelowanie po koloryzacji.'),
(@jan_user_id, @piotr_employee_id, @children_service_id, '2026-09-23', '10:00:00', '10:30:00', 'pending', 'Rezerwacja dla dziecka pod opieką klienta.'),
(@maria_user_id, @anna_employee_id, @care_service_id, '2026-09-24', '15:00:00', '16:00:00', 'cancelled', 'Klientka anulowała wizytę z powodu zmiany planów.'),
(@jan_user_id, @piotr_employee_id, @styling_service_id, '2026-09-25', '16:00:00', '16:45:00', 'confirmed', 'Modelowanie przed uroczystością.');

COMMIT;

