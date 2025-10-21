CREATE DATABASE IF NOT EXISTS glee_parking;
USE glee_parking;

-- Users table for clients and admins
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    role ENUM('client', 'admin') DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Parking slots table
CREATE TABLE slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slot_number VARCHAR(10) NOT NULL UNIQUE,
    proximity ENUM('near_entrance', 'standard') DEFAULT 'standard',
    status ENUM('available', 'booked', 'occupied', 'disabled') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bookings table (updated)
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slot_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    check_in_time DATETIME NULL,
    end_time DATETIME,
    cancelled_at DATETIME NULL,
    status ENUM('pending','approved','checked_in','completed','cancelled','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (slot_id) REFERENCES slots(id),
    INDEX (status)
);

-- Payments table (updated)
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('mpesa','cash','card') DEFAULT 'mpesa',
    duration_minutes INT,
    transaction_id VARCHAR(100),
    payer_phone VARCHAR(20),
    payment_status ENUM('pending','completed','failed') DEFAULT 'pending',
    checkout_time DATETIME,
    payment_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    INDEX (booking_id)
);

-- Notifications table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

INSERT INTO slots (slot_number, proximity, status) VALUES 
('A1', 'near_entrance', 'available'),
('A2', 'standard', 'available'),
('A3', 'near_entrance', 'available'),
('A4', 'standard', 'available');