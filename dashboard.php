<?php
session_start();

// Include database configuration
require_once 'includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: index.php");
    exit();
}

// Fetch user details
$user_id = $_SESSION['user_id'];
// Fetch user name
$stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$user_name = $user ? $user['name'] : 'User';

// Set timezone explicitly to avoid mismatches
$timezone = date_default_timezone_get() ?: 'Africa/Nairobi';
date_default_timezone_set($timezone);

// Fetch notifications
$stmt = $conn->prepare("SELECT id, message, is_read, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();
if ($notifications) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
}

// Handle booking submission
if (isset($_POST['book_slot'])) {
    $slot_id = filter_var($_POST['slot_id'], FILTER_SANITIZE_NUMBER_INT);
    $start_time = filter_var($_POST['start_time']);
    
    try {
        $stmt = $conn->prepare("SELECT status FROM slots WHERE id = ? AND status = 'available'");
        $stmt->execute([$slot_id]);
        if (!$stmt->fetch()) {
            $error = "Selected slot is not available";
        } else {
            $stmt = $conn->prepare("INSERT INTO bookings (user_id, slot_id, start_time, status) VALUES (?, ?, ?, 'pending')");
            $stmt->execute([$user_id, $slot_id, $start_time]);
            $stmt = $conn->prepare("UPDATE slots SET status = 'booked' WHERE id = ?");
            $stmt->execute([$slot_id]);
            $success = "Booking submitted successfully. Awaiting admin approval.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle check-in
if (isset($_POST['check_in'])) {
    $booking_id = filter_var($_POST['booking_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        $stmt = $conn->prepare("SELECT slot_id, status FROM bookings WHERE id = ? AND user_id = ?");
        $stmt->execute([$booking_id, $user_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $error = "Invalid booking or not authorized.";
        } else {
            if ($booking['status'] !== 'approved') {
                $error = "Check-in is only allowed for approved bookings.";
            } else {
                $check_in_time = date('Y-m-d H:i:s'); // Current timestamp for check-in
                $stmt = $conn->prepare("UPDATE bookings SET status = 'checked_in', check_in_time = ? WHERE id = ?");
                $stmt->execute([$check_in_time, $booking_id]);
                $stmt = $conn->prepare("UPDATE slots SET status = 'occupied' WHERE id = ?");
                $stmt->execute([$booking['slot_id']]);
                $success = "Checked in successfully.";
            }
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
        error_log("Check-in error: " . $e->getMessage());
    }
}

// Handle booking cancellation
if (isset($_POST['cancel_booking'])) {
    $booking_id = filter_var($_POST['booking_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        $stmt = $conn->prepare("SELECT slot_id, status FROM bookings WHERE id = ? AND user_id = ? AND status IN ('pending', 'approved')");
        $stmt->execute([$booking_id, $user_id]);
        $booking = $stmt->fetch();

        if ($booking) {
            $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$booking_id]);
            $stmt = $conn->prepare("UPDATE slots SET status = 'available' WHERE id = ?");
            $stmt->execute([$booking['slot_id']]);
            $success = "Booking cancelled successfully.";
        } else {
            $error = "Invalid booking, not authorized, or cannot cancel (already checked-in/completed).";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
        error_log("Cancellation error: " . $e->getMessage());
    }
}

// AJAX endpoint for slots
if (isset($_GET['get_slots']) && $_GET['get_slots'] === 'true') {
    $stmt = $conn->prepare("SELECT id, slot_number, proximity FROM slots WHERE status = 'available'");
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($slots) {
        foreach ($slots as $slot) {
            echo '<div class="bg-white p-4 rounded-lg shadow-sm hover:shadow-md transition duration-300 transform hover:-translate-y-1">
                    <div class="font-semibold text-gray-900">Slot ' . htmlspecialchars($slot['slot_number']) . '</div>
                    <div class="text-sm text-gray-600">Proximity: ' . htmlspecialchars($slot['proximity']) . '</div>
                  </div>';
        }
    } else {
        echo '<p class="text-gray-500 text-center col-span-full">No available slots.</p>';
    }
    exit();
}

// AJAX endpoint for slots select dropdown
if (isset($_GET['get_slots_select']) && $_GET['get_slots_select'] === 'true') {
    $stmt = $conn->prepare("SELECT id, slot_number, proximity FROM slots WHERE status = 'available'");
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($slots as $slot) {
        echo '<option value="' . htmlspecialchars($slot['id']) . '">Slot ' . htmlspecialchars($slot['slot_number']) . ' (' . htmlspecialchars($slot['proximity']) . ')</option>';
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glee Hotel Parking - Client Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen font-sans antialiased">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <header class="flex flex-col sm:flex-row justify-between items-center mb-8 gap-4">
            <div class="flex items-center gap-3">
                <img src="assets/images/logo.png" alt="Glee Hotel Logo" class="h-10 w-10 object-contain">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900">Welcome, <?php echo htmlspecialchars($user_name); ?>!</h1>
            </div>
            <a href="logout.php" onclick="return confirm('Are you sure you want to logout?');"
               class="text-green-600 hover:text-green-800 font-medium transition duration-300">Logout</a>
        </header>

        <?php if ($notifications): ?>
            <section class="mb-8" id="notification-section">
                <div class="bg-white border border-green-200 rounded-lg p-6 shadow-md relative transform transition-all duration-300 hover:shadow-lg">
                    <button type="button" id="dismiss-notification"
                        class="absolute top-3 right-3 text-green-500 hover:text-green-700 text-xl font-bold focus:outline-none"
                        aria-label="Dismiss">×</button>
                    <h2 class="text-lg font-semibold text-green-700 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 20a8 8 0 100-16 8 8 0 000 16z"/>
                        </svg>
                        Notifications
                    </h2>
                    <ul class="space-y-3">
                        <?php foreach ($notifications as $note): ?>
                            <li class="text-gray-700">
                                <div class="flex justify-between items-center">
                                    <span><?php echo htmlspecialchars($note['message']); ?></span>
                                    <span class="text-xs text-gray-500 ml-2"><?php echo htmlspecialchars($note['created_at']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-6 shadow-sm"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="bg-green-50 text-green-700 p-4 rounded-lg mb-6 shadow-sm"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <noscript>
            <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-6 shadow-sm">JavaScript is disabled or not loading. Please enable it or check the file path.</div>
        </noscript>

        <!-- Toggle Button for Available Slots -->
        <div class="flex justify-end mb-4">
            <button id="toggle-slots-btn"
                class="flex items-center gap-2 px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition duration-300 font-medium focus:outline-none">
                <svg id="toggle-icon" class="w-5 h-5 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
                <span>Show Available Parking Slots</span>
            </button>
        </div>

        <!-- Parking Slots (hidden by default) -->
        <section id="slots-section" class="mb-10 hidden">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Available Slots</h2>
            <div id="slots-container" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                <p class="text-gray-500 text-center col-span-full">Loading slots...</p>
            </div>
        </section>

        <!-- Booking Form -->
        <section class="mb-12 flex justify-center">
            <div class="w-full max-w-lg">
                <h2 class="text-xl font-semibold mb-4 text-gray-900 text-center">Book a Slot</h2>
                <form method="POST" id="bookingForm" class="space-y-6 bg-white p-6 rounded-lg shadow-md transform transition-all duration-300 hover:shadow-lg">
                    <div>
                        <label for="slot_id" class="block text-sm font-medium text-gray-700">Select Slot</label>
                        <select name="slot_id" id="slot_id" required
                                class="mt-1 w-full p-3 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 transition duration-300">
                            <option value="">Choose a slot</option>
                        </select>
                    </div>
                    <div>
                        <label for="start_time" class="block text-sm font-medium text-gray-700">Start Time</label>
                        <?php
                            $now = date('Y-m-d\TH:i', strtotime('+1 minute')); // Set min to 1 minute from now
                        ?>
                        <input type="datetime-local" name="start_time" id="start_time" required
                               min="<?php echo $now; ?>"
                               class="mt-1 w-full p-3 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 transition duration-300">
                    </div>
                    <button type="submit" name="book_slot"
                            class="w-full bg-green-600 text-white p-3 rounded-lg hover:bg-green-700 transition duration-300 font-medium">
                        Book Slot
                    </button>
                </form>
            </div>
        </section>

        <!-- My Bookings -->
        <section>
            <h2 class="text-xl font-semibold mb-4 text-gray-900">My Bookings</h2>
            <div class="bg-white p-6 rounded-lg shadow-md overflow-x-auto">
                <?php
                $stmt = $conn->prepare("SELECT b.id, b.start_time, b.status, b.check_in_time, s.slot_number, s.proximity, b.slot_id 
                                       FROM bookings b 
                                       JOIN slots s ON b.slot_id = s.id 
                                       WHERE b.user_id = ? AND b.status IN ('pending', 'approved', 'checked_in')");
                $stmt->execute([$user_id]);
                $bookings = $stmt->fetchAll();
                if ($bookings) {
                    echo '<table class="w-full text-sm text-gray-700">';
                    echo '<thead class="bg-gray-50"><tr>
                            <th class="p-3 text-left font-semibold">Slot</th>
                            <th class="p-3 text-left font-semibold">Proximity</th>
                            <th class="p-3 text-left font-semibold">Start Time</th>
                            <th class="p-3 text-left font-semibold">Status</th>
                            <th class="p-3 text-left font-semibold">Actions</th>
                          </tr></thead><tbody>';
                    foreach ($bookings as $booking) {
                        $can_cancel = in_array($booking['status'], ['pending', 'approved']);
                        $can_checkin = ($booking['status'] === 'approved');
                        $can_checkout = ($booking['status'] === 'checked_in');

                        echo '<tr class="border-t">
                                <td class="p-3" data-label="Slot">' . htmlspecialchars($booking['slot_number']) . '</td>
                                <td class="p-3" data-label="Proximity">' . htmlspecialchars($booking['proximity']) . '</td>
                                <td class="p-3" data-label="Start Time">' . htmlspecialchars($booking['start_time']) . '</td>
                                <td class="p-3" data-label="Status">' . htmlspecialchars($booking['status']) . '</td>
                                <td class="p-3" data-label="Actions">';

                        // Cancel for pending or approved
                        if ($can_cancel) {
                            echo '<form method="POST" class="inline" onsubmit="return confirm(\'Are you sure you want to cancel this booking?\');">
                                    <input type="hidden" name="booking_id" value="' . htmlspecialchars($booking['id']) . '">
                                    <button type="submit" name="cancel_booking" class="text-red-600 hover:text-red-800 transition duration-300">Cancel</button>
                                  </form>';
                        }

                        // Approved -> show Check In
                        if ($can_checkin) {
                            echo '<form method="POST" class="inline ml-3">
                                    <input type="hidden" name="booking_id" value="' . htmlspecialchars($booking['id']) . '">
                                    <button type="submit" name="check_in" class="text-blue-600 hover:text-blue-800 transition duration-300">Check In</button>
                                  </form>';
                        }

                        // After checked-in: show Check Out link
                        if ($can_checkout) {
                            echo '<a href="checkout.php?booking_id=' . urlencode($booking['id']) . '" class="text-green-600 hover:text-green-800 ml-3 transition duration-300">Check Out</a>';
                        }

                        echo '</td></tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p class="text-gray-500 text-center">No active bookings found.</p>';
                }
                ?>
            </div>
        </section>
    </div>

    <script>
        // Toggle slots section
        const toggleBtn = document.getElementById('toggle-slots-btn');
        const toggleIcon = document.getElementById('toggle-icon');
        const slotsSection = document.getElementById('slots-section');
        toggleBtn?.addEventListener('click', () => {
            slotsSection.classList.toggle('hidden');
            toggleIcon.classList.toggle('rotate-180');
            if (!slotsSection.classList.contains('hidden')) {
                fetchSlots();
            }
        });

        // Dismiss notification
        const dismissBtn = document.getElementById('dismiss-notification');
        const notificationSection = document.getElementById('notification-section');
        dismissBtn?.addEventListener('click', () => {
            notificationSection.classList.add('hidden');
        });

        // Fetch available slots
        function fetchSlots() {
            fetch('?get_slots=true')
                .then(response => response.text())
                .then(data => {
                    document.getElementById('slots-container').innerHTML = data;
                })
                .catch(error => {
                    console.error('Error fetching slots:', error);
                    document.getElementById('slots-container').innerHTML = '<p class="text-red-500 text-center col-span-full">Error loading slots.</p>';
                });
        }

        // Fetch slots for select dropdown
        function fetchSlotsForSelect() {
            fetch('?get_slots_select=true')
                .then(response => response.text())
                .then(data => {
                    document.getElementById('slot_id').innerHTML = '<option value="">Choose a slot</option>' + data;
                })
                .catch(error => console.error('Error fetching slots for select:', error));
        }

        // Initialize slots for select on page load
        document.addEventListener('DOMContentLoaded', fetchSlotsForSelect);
    </script>
</body>
</html>