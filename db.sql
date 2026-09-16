CREATE DATABASE IF NOT EXISTS traffic_management;
USE traffic_management;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS Payments;
DROP TABLE IF EXISTS Fines;
DROP TABLE IF EXISTS Violations;
DROP TABLE IF EXISTS DutySchedules;
DROP TABLE IF EXISTS Landmarks;
DROP TABLE IF EXISTS Reports;
DROP TABLE IF EXISTS Vehicles;
DROP TABLE IF EXISTS Officers;
DROP TABLE IF EXISTS Users;
DROP TABLE IF EXISTS Districts;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE Districts (
    district_id INT PRIMARY KEY AUTO_INCREMENT,
    district_name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE Users (
    user_id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash CHAR(60) NOT NULL,
    role ENUM('Admin', 'Officer', 'Citizen') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    district_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (district_id) REFERENCES Districts(district_id) ON DELETE SET NULL
);

CREATE TABLE Officers (
    officer_id CHAR(36) PRIMARY KEY,
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    phone_number VARCHAR(20) DEFAULT NULL,
    FOREIGN KEY (officer_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE TABLE Landmarks (
    landmark_id INT PRIMARY KEY AUTO_INCREMENT,
    district_id INT NOT NULL,
    landmark_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (district_id) REFERENCES Districts(district_id) ON DELETE CASCADE
);

CREATE TABLE DutySchedules (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    officer_id CHAR(36) NOT NULL,
    landmark_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    FOREIGN KEY (officer_id) REFERENCES Officers(officer_id) ON DELETE CASCADE,
    FOREIGN KEY (landmark_id) REFERENCES Landmarks(landmark_id) ON DELETE CASCADE
);

CREATE TABLE Vehicles (
    license_plate VARCHAR(20) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    vehicle_model VARCHAR(100) NOT NULL,
    registered_date DATE DEFAULT (CURRENT_DATE),
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE TABLE Violations (
    violation_id INT PRIMARY KEY AUTO_INCREMENT,
    violation_name VARCHAR(100) NOT NULL,
    fine_amount DECIMAL(10,2) NOT NULL
);

CREATE TABLE Fines (
    fine_id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    vehicle_plate VARCHAR(20) NOT NULL,
    violation_id INT NOT NULL,
    officer_id CHAR(36) NOT NULL,
    status ENUM('Unpaid', 'Paid', 'Appealed', 'Overdue') DEFAULT 'Unpaid',
    issued_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_plate) REFERENCES Vehicles(license_plate) ON DELETE CASCADE,
    FOREIGN KEY (violation_id) REFERENCES Violations(violation_id) ON DELETE RESTRICT,
    FOREIGN KEY (officer_id) REFERENCES Officers(officer_id) ON DELETE CASCADE
);
CREATE INDEX idx_fines_status ON Fines(status);

CREATE TABLE Payments (
    payment_id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    fine_id CHAR(36) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fine_id) REFERENCES Fines(fine_id) ON DELETE CASCADE
);

CREATE TABLE Reports (
    report_id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    reporter_id CHAR(36) NOT NULL,
    district_id INT NOT NULL,
    investigator_id CHAR(36) DEFAULT NULL,
    hazard_type ENUM('Accident', 'Pothole', 'Signal Failure', 'Traffic Jam', 'Other') NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Pending', 'Investigating', 'Resolved', 'Dismissed') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (district_id) REFERENCES Districts(district_id) ON DELETE CASCADE,
    FOREIGN KEY (investigator_id) REFERENCES Officers(officer_id) ON DELETE SET NULL
);
CREATE INDEX idx_reports_status ON Reports(status);

INSERT INTO Districts (district_name) VALUES
('Colombo'), ('Gampaha'), ('Kalutara'), ('Kandy'), ('Jaffna');

INSERT INTO Users (user_id, name, email, password_hash, role, is_active)
VALUES (
    UUID(),
    'System Administrator',
    'admin@system.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Admin',
    1
);

INSERT INTO Violations (violation_name, fine_amount) VALUES
('Speeding (10-20km/h over limit)', 3000.00),
('Speeding (20+ km/h over limit)', 5000.00),
('Running a Red Light', 2500.00),
('Illegal Parking', 1500.00),
('Driving Without a Seatbelt', 1000.00),
('Using Mobile Phone While Driving', 2000.00),
('Reckless Driving', 10000.00),
('Driving Under the Influence', 25000.00);
