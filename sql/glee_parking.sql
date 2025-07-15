CREATE DATABASE IF NOT EXISTS glee_parking;
USE glee_parking;

-- Users table for clients and admins
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('client', 'admin') DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Parking slots table
CREATE TABLE slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slot_number VARCHAR(10) NOT NULL UNIQUE,
    proximity ENUM('near_entrance', 'standard') DEFAULT 'standard',
    status ENUM('available', 'booked', 'disabled') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bookings table
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slot_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (slot_id) REFERENCES slots(id)
);

HANDBREAKS);

-- Payments table
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_id VARCHAR(50) NOT NULL,
    payment_status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    payment_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
);

-- Insert sample data for testing
INSERT INTO users (name, email, password, role) VALUES 
('Admin User', 'admin@glee.com', '$2y$10$J9Z8X8gZ3Z8Z8X8gZ3Z8Z8X8Z8X8gZ3Z8X8gZ3Z8', 'admin'),
('Test Client', 'client@glee.com', '$2y$10$J9Z8X8gZ3Z8Z8X8gZ3Z8Z8X8Z8X8gZ3Z8X8gZ3Z8', 'client');

INSERT INTO slots (slot_number, proximity, status) VALUES 
('A1', 'near_entrance', 'available'),
('A2', 'standard', 'available'),
('A3', 'near_entrance', 'available'),
('A4', 'standard', 'available');