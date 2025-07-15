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
    $start_time = filter_var($_POST['start_time'], FILTER_SANITIZE_STRING);
    
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

// Handle booking cancellation
if (isset($_POST['cancel_booking'])) {
    $booking_id = filter_var($_POST['booking_id'], FILTER_SANITIZE_NUMBER_INT);
    
    try {
        // Debug log for cancellation attempt
        error_log("Attempting to cancel booking_id: $booking_id for user_id: $user_id");
        $stmt = $conn->prepare("SELECT slot_id, status FROM bookings WHERE id = ? AND user_id = ? AND status IN ('pending', 'approved')");
        $stmt->execute([$booking_id, $user_id]);
        $booking = $stmt->fetch();
        error_log("Query result: " . json_encode($booking)); // Log query result
        if ($booking) {
            // Check if the booking is approved
            if ($booking['status'] === 'approved') {
                $error = "Cancellation not allowed. Booking is already approved.";
            } else {
                $stmt = $conn->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
                $stmt->execute([$booking_id]);
                $stmt = $conn->prepare("UPDATE slots SET status = 'available' WHERE id = ?");
                $stmt->execute([$booking['slot_id']]);
                $success = "Booking cancelled successfully.";
            }
        } else {
            $error = "Invalid booking or not authorized.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
        error_log("Cancellation error: " . $e->getMessage());
    }
}

if (isset($_GET['get_slots']) && $_GET['get_slots'] === 'true') {
    $stmt = $conn->prepare("SELECT id, slot_number, proximity FROM slots WHERE status = 'available'");
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($slots) {
        // Return as HTML cards for the grid
        foreach ($slots as $slot) {
            echo '<div class="bg-white p-4 rounded shadow">
                    <div class="font-bold">Slot ' . htmlspecialchars($slot['slot_number']) . '</div>
                    <div class="text-sm text-gray-600">Proximity: ' . htmlspecialchars($slot['proximity']) . '</div>
                  </div>';
        }
    } else {
        echo '<p class="text-gray-500">No available slots.</p>';
    }
    exit();
}

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
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/scripts.js" defer></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-6 max-w-5xl">
        <header class="flex flex-col sm:flex-row justify-between items-center mb-8 gap-4">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Welcome, <?php echo htmlspecialchars($user_name); ?>!</h1>
            <a href="logout.php" onclick="return confirm('Are you sure you want to logout?');"
               class="text-blue-600 hover:text-blue-800 font-medium transition">Logout</a>
        </header>

        <?php if ($notifications): ?>
            <section class="mb-6" id="notification-section">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 shadow-md relative">
                    <button type="button" id="dismiss-notification"
                        class="absolute top-2 right-2 text-blue-400 hover:text-blue-700 text-xl font-bold focus:outline-none"
                        aria-label="Dismiss">×</button>
                    <h2 class="text-lg font-semibold text-blue-700 mb-2 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 20a8 8 0 100-16 8 8 0 000 16z"/></svg>
                        Notifications
                    </h2>
                    <ul class="space-y-2">
                        <?php foreach ($notifications as $note): ?>
                            <li class="text-blue-900 font-semibold">
                                <div class="flex justify-between items-center">
                                    <span><?php echo htmlspecialchars($note['message']); ?></span>
                                    <span class="text-xs text-gray-400 ml-2"><?php echo $note['created_at']; ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <noscript>
            <div class="alert alert-error mb-4">JavaScript is disabled or not loading. Please enable it or check the file path.</div>
        </noscript>

        <!-- Toggle Button for Available Slots -->
        <div class="flex justify-end mb-2">
            <button id="toggle-slots-btn"
                class="flex items-center gap-2 px-4 py-2 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition font-medium focus:outline-none">
                <svg id="toggle-icon" class="w-5 h-5 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
                <span>Show Available Parking Slots</span>
            </button>
        </div>

        <!-- Parking Slots (hidden by default) -->
        <section id="slots-section" class="mb-10 hidden">
            <div id="slots-container" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                <p class="text-gray-500">Loading slots...</p>
            </div>
        </section>

        <!-- Booking Form -->
        <section class="mb-10 flex justify-center">
            <div class="w-full max-w-lg">
                <h2 class="text-xl font-semibold mb-4 text-gray-700 text-center">Book a Slot</h2>
                <form method="POST" id="bookingForm" class="space-y-6 bg-white p-6 rounded-lg shadow">
                    <div>
                        <label for="slot_id" class="block text-sm font-medium text-gray-700">Select Slot</label>
                        <select name="slot_id" id="slot_id" required
                                class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Choose a slot</option>
                        </select>
                    </div>
                    <div>
                        <label for="start_time" class="block text-sm font-medium text-gray-700">Start Time</label>
                        <?php
                            // Set min to current datetime in 'Y-m-d\TH:i' format for datetime-local input
                            $now = date('Y-m-d\TH:i');
                        ?>
                        <input type="datetime-local" name="start_time" id="start_time" required
                               min="<?php echo $now; ?>"
                               class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <button type="submit" name="book_slot"
                            class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600 transition">
                        Book Slot
                    </button>
                </form>
            </div>
        </section>

        <!-- My Bookings -->
        <section>
            <h2 class="text-xl font-semibold mb-4 text-gray-700">My Bookings</h2>
            <div class="bg-white p-6 rounded-lg shadow-lg overflow-x-auto">
                <?php
                $stmt = $conn->prepare("SELECT b.id, b.start_time, b.status, s.slot_number, s.proximity 
                                       FROM bookings b 
                                       JOIN slots s ON b.slot_id = s.id 
                                       WHERE b.user_id = ? AND b.status IN ('pending', 'approved')");
                $stmt->execute([$user_id]);
                $bookings = $stmt->fetchAll();
                if ($bookings) {
                    echo '<table class="w-full text-sm">';
                    echo '<thead><tr>
                            <th class="p-2">Slot</th>
                            <th class="p-2">Proximity</th>
                            <th class="p-2">Start Time</th>
                            <th class="p-2">Status</th>
                            <th class="p-2">Actions</th>
                          </tr></thead><tbody>';
                    foreach ($bookings as $booking) {
                        echo '<tr>
                                <td class="p-2" data-label="Slot">' . htmlspecialchars($booking['slot_number']) . '</td>
                                <td class="p-2" data-label="Proximity">' . htmlspecialchars($booking['proximity']) . '</td>
                                <td class="p-2" data-label="Start Time">' . htmlspecialchars($booking['start_time']) . '</td>
                                <td class="p-2" data-label="Status">' . htmlspecialchars($booking['status']) . '</td>
                                <td class="p-2" data-label="Actions">';
                        if ($booking['status'] === 'pending') {
                            echo '<form method="POST" class="inline">
                                    <input type="hidden" name="booking_id" value="' . $booking['id'] . '">
                                    <button type="submit" name="cancel_booking" class="text-red-500 hover:underline">Cancel</button>
                                  </form>';
                        }
                        echo '<a href="checkout.php?booking_id=' . $booking['id'] . '" class="text-blue-500 hover:underline ml-2">Check Out</a>
          </td>
          </tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>No active bookings found.</p>';
                }
                ?>
            </div>
        </section>
    </div>
</body>
</html>