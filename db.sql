-- Create the database
CREATE DATABASE IF NOT EXISTS traffic_management;
USE traffic_management;

-- ====================================================
-- 1. USERS TABLE (Handles Admins, Officers, and Citizens)
-- ====================================================
CREATE TABLE IF NOT EXISTS Users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT NOT NULL COMMENT '1=Admin, 2=Officer, 3=Citizen',
    is_approved TINYINT(1) DEFAULT 0 COMMENT '0=Pending, 1=Approved',
    district VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ====================================================
-- 2. DISTRICT LANDMARKS TABLE
-- ====================================================
CREATE TABLE IF NOT EXISTS Landmarks (
    landmark_id INT PRIMARY KEY AUTO_INCREMENT,
    district VARCHAR(50) NOT NULL,
    landmark_name VARCHAR(100) NOT NULL
);

-- ====================================================
-- 3. DUTY SCHEDULES TABLE (Officer Roster)
-- ====================================================
CREATE TABLE IF NOT EXISTS DutySchedules (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    landmark_id INT NOT NULL,
    day_of_week VARCHAR(15) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (landmark_id) REFERENCES Landmarks(landmark_id) ON DELETE CASCADE
);

-- ====================================================
-- 4. VEHICLES TABLE (Citizen Garage)
-- ====================================================
CREATE TABLE IF NOT EXISTS Vehicles (
    license_plate VARCHAR(20) PRIMARY KEY,
    user_id INT NOT NULL,
    vehicle_model VARCHAR(100) NOT NULL,
    registered_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- ====================================================
-- 5. VIOLATIONS MASTER TABLE (List of Offenses)
-- ====================================================
CREATE TABLE IF NOT EXISTS Violations (
    violation_id INT PRIMARY KEY AUTO_INCREMENT,
    violation_name VARCHAR(100) NOT NULL,
    standard_fine DECIMAL(10,2) NOT NULL
);

-- ====================================================
-- 6. FINES TABLE (Traffic Citations)
-- ====================================================
CREATE TABLE IF NOT EXISTS Fines (
    ticket_id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_plate VARCHAR(20) NOT NULL,
    violation_id INT NOT NULL,
    issuing_officer_id INT NOT NULL,
    status VARCHAR(20) DEFAULT 'Unpaid',
    issued_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Note: vehicle_plate is NOT a strict foreign key here because your PHP logic 
    -- correctly allows officers to issue tickets to unregistered vehicles.
    FOREIGN KEY (violation_id) REFERENCES Violations(violation_id) ON DELETE CASCADE,
    FOREIGN KEY (issuing_officer_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- ====================================================
-- 7. INCIDENT REPORTS TABLE (Hazard tracking)
-- ====================================================
CREATE TABLE IF NOT EXISTS Reports (
    report_id INT PRIMARY KEY AUTO_INCREMENT,
    reporter_id INT NOT NULL,
    hazard_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    location_from VARCHAR(50) NOT NULL COMMENT 'District Name',
    specific_landmark VARCHAR(100) NOT NULL,
    status VARCHAR(30) DEFAULT 'Investigating',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES Users(user_id) ON DELETE CASCADE
);


-- ====================================================
-- INITIAL DATA SETUP (Run these to populate required data)
-- ====================================================

-- Insert a Default Admin Account
-- Email: admin@system.com | Password: password123
INSERT INTO Users (name, email, password_hash, role_id, is_approved) 
VALUES (
    'System Administrator', 
    'admin@system.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- This is the bcrypt hash for 'password123'
    1, 
    1
);

-- Insert Default Traffic Violations so Officers can issue tickets
INSERT INTO Violations (violation_name, standard_fine) VALUES 
('Speeding (10-20km/h over limit)', 3000.00),
('Speeding (20+ km/h over limit)', 5000.00),
('Running a Red Light', 2500.00),
('Illegal Parking', 1500.00),
('Driving Without a Seatbelt', 1000.00),
('Using Mobile Phone While Driving', 2000.00),
('Reckless Driving', 10000.00),
('Driving Under the Influence', 25000.00);