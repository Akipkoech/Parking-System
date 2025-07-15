<?php
header('Content-Type: application/json');

// Include database configuration
require_once 'includes/config.php';

// Handle AJAX request for slots
if (isset($_GET['get_slots']) && $_GET['get_slots'] === 'true') {
    try {
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        $stmt = $conn->prepare("SELECT id, slot_number, proximity, status FROM slots");
        $stmt->execute();
        $slots = $stmt->fetchAll();
        if ($slots === false) {
            throw new Exception("No slots retrieved from database");
        }
        echo json_encode($slots);
        error_log("Slots fetched: " . json_encode($slots)); // Debug log
    } catch (Exception $e) {
        error_log("Error in booking.php: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to fetch slots: ' . $e->getMessage()]);
    }
    exit();
}
?>