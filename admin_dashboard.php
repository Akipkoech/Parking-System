<?php
session_start();

// Include database configuration
require_once 'includes/config.php';

// Check if user is logged in
$is_admin = isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin';
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Fetch user details
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$user_name = $user['name'];

// Handle booking approval/rejection
if (isset($_POST['approve_booking']) || isset($_POST['reject_booking'])) {
    $booking_id = filter_var($_POST['booking_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        $stmt = $conn->prepare("SELECT slot_id, status, user_id FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        if ($booking) {
            if (isset($_POST['approve_booking']) && $booking['status'] === 'pending') {
                $stmt = $conn->prepare("UPDATE bookings SET status = 'approved' WHERE id = ?");
                $stmt->execute([$booking_id]);
                // Notify user
                $msg = "Your booking (ID: $booking_id) has been approved.";
                $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $stmt->execute([$booking['user_id'], $msg]);
                $success = "Booking approved successfully.";
            } elseif (isset($_POST['reject_booking']) && $booking['status'] === 'pending') {
                $stmt = $conn->prepare("UPDATE bookings SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$booking_id]);
                $stmt = $conn->prepare("UPDATE slots SET status = 'available' WHERE id = ?");
                $stmt->execute([$booking['slot_id']]);
                // Notify user
                $msg = "Your booking (ID: $booking_id) has been rejected.";
                $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $stmt->execute([$booking['user_id'], $msg]);
                $success = "Booking rejected successfully.";
            } else {
                $error = "Invalid action or booking status.";
            }
        } else {
            $error = "Booking not found.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle slot management (add/delete)
if (isset($_POST['add_slot'])) {
    $slot_number = filter_var($_POST['slot_number'], FILTER_SANITIZE_STRING);
    $proximity = filter_var($_POST['proximity'], FILTER_SANITIZE_STRING);
    
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM slots WHERE slot_number = ?");
        $stmt->execute([$slot_number]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Slot number '$slot_number' is already in use.";
        } else {
            $stmt = $conn->prepare("INSERT INTO slots (slot_number, proximity, status) VALUES (?, ?, 'available')");
            $stmt->execute([$slot_number, $proximity]);
            $success = "Slot added successfully.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

if (isset($_POST['delete_slot'])) {
    $slot_id = filter_var($_POST['slot_id'], FILTER_SANITIZE_NUMBER_INT);
    
    try {
        $stmt = $conn->prepare("DELETE FROM slots WHERE id = ?");
        $stmt->execute([$slot_id]);
        $success = "Slot deleted successfully.";
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle user update
if (isset($_POST['update_user'])) {
    $user_id_to_update = filter_var($_POST['user_id'], FILTER_SANITIZE_NUMBER_INT);
    $name = filter_var($_POST['name'], FILTER_SANITIZE_STRING);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $role = filter_var($_POST['role'], FILTER_SANITIZE_STRING);
    
    try {
        if ($user_id_to_update != $user_id) { // Prevent self-edit
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
            $stmt->execute([$name, $email, $role, $user_id_to_update]);
            $success = "User updated successfully.";
        } else {
            $error = "Cannot edit your own account.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle user deletion
if (isset($_POST['delete_user'])) {
    $user_id_to_delete = filter_var($_POST['user_id'], FILTER_SANITIZE_NUMBER_INT);
    
    try {
        if ($user_id_to_delete != $user_id) { // Prevent self-deletion
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id_to_delete]);
            $success = "User deleted successfully.";
        } else {
            $error = "Cannot delete your own account.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle slot status toggle
if (isset($_POST['toggle_slot_status'])) {
    $slot_id = filter_var($_POST['slot_id'], FILTER_SANITIZE_NUMBER_INT);
    $new_status = ($_POST['new_status'] === 'available') ? 'available' : 'disabled';
    try {
        $stmt = $conn->prepare("UPDATE slots SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $slot_id]);
        $success = "Slot status updated.";
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// AJAX endpoints
if (isset($_GET['get_bookings']) && $_GET['get_bookings'] === 'true') {
    $stmt = $conn->prepare("SELECT b.id, u.name, s.slot_number, s.proximity, b.start_time, b.status 
                           FROM bookings b 
                           JOIN users u ON b.user_id = u.id 
                           JOIN slots s ON b.slot_id = s.id 
                           WHERE b.status = 'pending'");
    $stmt->execute();
    $pending_bookings = $stmt->fetchAll();
    if ($pending_bookings && $is_admin) {
        echo '<table class="w-full text-sm"><thead><tr><th class="p-2">User</th><th class="p-2">Slot</th><th class="p-2">Proximity</th><th class="p-2">Start Time</th><th class="p-2">Actions</th></tr></thead><tbody>';
        foreach ($pending_bookings as $booking) {
            echo '<tr><td class="p-2">' . htmlspecialchars($booking['name']) . '</td><td class="p-2">' . htmlspecialchars($booking['slot_number']) . '</td><td class="p-2">' . htmlspecialchars($booking['proximity']) . '</td><td class="p-2">' . htmlspecialchars($booking['start_time']) . '</td><td class="p-2"><form method="POST" class="inline mr-2"><input type="hidden" name="booking_id" value="' . $booking['id'] . '"><button type="submit" name="approve_booking" class="text-green-500 hover:underline">Approve</button></form><form method="POST" class="inline"><input type="hidden" name="booking_id" value="' . $booking['id'] . '"><button type="submit" name="reject_booking" class="text-red-500 hover:underline">Reject</button></form></td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No pending bookings or not authorized.</p>';
    }
    exit();
}

if (isset($_GET['get_users']) && $_GET['get_users'] === 'true') {
    $stmt = $conn->prepare("SELECT id, name, email, role FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll();
    if ($users && $is_admin) {
        echo '<table class="w-full text-sm"><thead><tr><th class="p-2">Name</th><th class="p-2">Email</th><th class="p-2">Role</th><th class="p-2">Actions</th></tr></thead><tbody>';
        foreach ($users as $user) {
            echo '<tr><td class="p-2">' . htmlspecialchars($user['name']) . '</td><td class="p-2">' . htmlspecialchars($user['email']) . '</td><td class="p-2">' . htmlspecialchars($user['role']) . '</td><td class="p-2"><form method="POST" class="inline mr-2"><input type="hidden" name="user_id" value="' . $user['id'] . '"><button type="submit" name="delete_user" class="text-red-500 hover:underline">Delete</button></form><button class="text-blue-500 hover:underline edit-user-btn" data-user-id="' . $user['id'] . '">Edit</button></td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No users registered or not authorized.</p>';
    }
    exit();
}

if (isset($_GET['get_slot']) && isset($_GET['slot_id'])) {
    $slot_id = filter_var($_GET['slot_id'], FILTER_SANITIZE_NUMBER_INT);
    $stmt = $conn->prepare("SELECT id, slot_number, proximity, status FROM slots WHERE id = ?");
    $stmt->execute([$slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($slot) {
        echo json_encode($slot);
    } else {
        echo json_encode(['error' => 'Slot not found']);
    }
    exit();
}

if (isset($_GET['get_user']) && isset($_GET['user_id'])) {
    $user_id_to_get = filter_var($_GET['user_id'], FILTER_SANITIZE_NUMBER_INT);
    $stmt = $conn->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt->execute([$user_id_to_get]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo json_encode($user);
    } else {
        echo json_encode(['error' => 'User not found']);
    }
    exit();
}

if (isset($_GET['get_slots']) && $_GET['get_slots'] === 'true') {
    $stmt = $conn->prepare("SELECT id, slot_number, proximity, status FROM slots");
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($slots && $is_admin) {
        echo '<table class="w-full text-sm"><thead><tr><th class="p-2">Slot Number</th><th class="p-2">Proximity</th><th class="p-2">Status</th><th class="p-2">Actions</th></tr></thead><tbody>';
        foreach ($slots as $slot) {
            echo '<tr>
                    <td class="p-2">' . htmlspecialchars($slot['slot_number']) . '</td>
                    <td class="p-2">' . htmlspecialchars($slot['proximity']) . '</td>
                    <td class="p-2">' . htmlspecialchars($slot['status']) . '</td>
                    <td class="p-2">
                        <button class="text-yellow-500 hover:underline toggle-slot-btn" data-slot-id="' . $slot['id'] . '" data-current-status="' . $slot['status'] . '">'
                            . ($slot['status'] === 'disabled' ? 'Enable' : 'Disable') .
                        '</button>
                        <button class="text-red-500 hover:underline delete-slot-btn ml-2" data-slot-id="' . $slot['id'] . '">Delete</button>
                    </td>
                  </tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No slots found or not authorized.</p>';
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glee Hotel Parking - <?php echo $is_admin ? 'Admin' : 'User'; ?> Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/scripts.js" defer></script>
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            width: 70%;
            max-width: 500px;
            border-radius: 5px;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto p-4">
        <header class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Welcome, <?php echo htmlspecialchars($user_name); ?> (<?php echo $is_admin ? 'Admin' : 'User'; ?>)</h1>
            <a href="logout.php" onclick="return confirm('Are you sure you want to logout?');" class="text-blue-500 hover:underline">Logout</a>
        </header>

        <?php if (isset($error)): ?>
            <div class="alert alert-error mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        

        <!-- Booking Management -->
        <section class="mb-8" id="bookings-section">
            <h2 class="text-xl font-semibold mb-4">Pending Bookings</h2>
            <div class="bg-white p-4 rounded-lg shadow-lg" id="bookings-container">
                <?php
                $stmt = $conn->prepare("SELECT b.id, u.name, s.slot_number, s.proximity, b.start_time, b.status 
                                       FROM bookings b 
                                       JOIN users u ON b.user_id = u.id 
                                       JOIN slots s ON b.slot_id = s.id 
                                       WHERE b.status = 'pending'");
                $stmt->execute();
                $pending_bookings = $stmt->fetchAll();
                if ($pending_bookings && $is_admin) {
                    echo '<table class="w-full text-sm">
                            <thead><tr><th class="p-2">User</th><th class="p-2">Slot</th><th class="p-2">Proximity</th><th class="p-2">Start Time</th><th class="p-2">Actions</th></tr></thead><tbody>';
                    foreach ($pending_bookings as $booking) {
                        echo '<tr>
                                <td class="p-2">' . htmlspecialchars($booking['name']) . '</td>
                                <td class="p-2">' . htmlspecialchars($booking['slot_number']) . '</td>
                                <td class="p-2">' . htmlspecialchars($booking['proximity']) . '</td>
                                <td class="p-2">' . htmlspecialchars($booking['start_time']) . '</td>
                                <td class="p-2">
                                    <form method="POST" class="inline mr-2">
                                        <input type="hidden" name="booking_id" value="' . $booking['id'] . '">
                                        <button type="submit" name="approve_booking" class="text-green-500 hover:underline">Approve</button>
                                    </form>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="booking_id" value="' . $booking['id'] . '">
                                        <button type="submit" name="reject_booking" class="text-red-500 hover:underline">Reject</button>
                                    </form>
                                </td>
                              </tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>No pending bookings or not authorized.</p>';
                }
                ?>
            </div>
        </section>

        <!-- Slot Management -->
        <section class="mb-8" id="slots-section">
            <h2 class="text-xl font-semibold mb-4">Manage Parking Slots</h2>
            <?php if ($is_admin): ?>
            <div class="bg-white p-4 rounded-lg shadow-lg mb-4">
                <h3 class="text-lg font-medium mb-2">Add New Slot</h3>
                <form method="POST" class="space-y-4 max-w-md">
                    <div>
                        <label for="slot_number" class="block text-sm font-medium text-gray-700">Slot Number</label>
                        <input type="text" name="slot_number" id="slot_number" required
                               class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="proximity" class="block text-sm font-medium text-gray-700">Proximity</label>
                        <select name="proximity" id="proximity" required
                                class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                            <option value="near_entrance">Near Entrance</option>
                            <option value="standard">Standard</option>
                        </select>
                    </div>
                    <button type="submit" name="add_slot"
                            class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600 transition">
                        Add Slot
                    </button>
                </form>
            </div>
            <?php endif; ?>
            <div class="bg-white p-4 rounded-lg shadow-lg" id="slots-container">
                <h3 class="text-lg font-medium mb-2">Existing Slots</h3>
                <!-- Slots will be populated by JavaScript -->
            </div>
        </section>

        <!-- User Management -->
        <section class="mb-8" id="users-section">
            <h2 class="text-xl font-semibold mb-4">User Management</h2>
            <?php if ($is_admin): ?>
            <div class="bg-white p-4 rounded-lg shadow-lg" id="users-container">
                <?php
                $stmt = $conn->prepare("SELECT id, name, email, role FROM users");
                $stmt->execute();
                $users = $stmt->fetchAll();
                if ($users) {
                    echo '<table class="w-full text-sm">
                            <thead><tr><th class="p-2">Name</th><th class="p-2">Email</th><th class="p-2">Role</th><th class="p-2">Actions</th></tr></thead><tbody>';
                    foreach ($users as $user) {
                        echo '<tr>
                                <td class="p-2">' . htmlspecialchars($user['name']) . '</td>
                                <td class="p-2">' . htmlspecialchars($user['email']) . '</td>
                                <td class="p-2">' . htmlspecialchars($user['role']) . '</td>
                                <td class="p-2">
                                    <form method="POST" class="inline mr-2">
                                        <input type="hidden" name="user_id" value="' . $user['id'] . '">
                                        <button type="submit" name="delete_user" class="text-red-500 hover:underline">Delete</button>
                                    </form>
                                    <button class="text-blue-500 hover:underline edit-user-btn" data-user-id="' . $user['id'] . '">Edit</button>
                                </td>
                              </tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>No users registered.</p>';
                }
                ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- Report Generation -->
        <section id="reports-section">
            <h2 class="text-xl font-semibold mb-4">Reports</h2>
            <?php if ($is_admin): ?>
            <!-- Registered Users Table & CSV Download -->
            <div class="bg-white p-4 rounded-lg shadow-lg mb-8">
                <h3 class="text-lg font-medium mb-4">Registered Users</h3>
                <form method="post" action="" class="mb-2">
                    <button type="submit" name="download_users_csv" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">Download CSV</button>
                </form>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr>
                                <th class="p-2">ID</th>
                                <th class="p-2">Name</th>
                                <th class="p-2">Email</th>
                                <th class="p-2">Role</th>
                                <th class="p-2">Registered At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $conn->prepare("SELECT id, name, email, role, created_at FROM users");
                            $stmt->execute();
                            $users = $stmt->fetchAll();
                            foreach ($users as $user) {
                                echo '<tr>
                                    <td class="p-2">'.htmlspecialchars($user['id']).'</td>
                                    <td class="p-2">'.htmlspecialchars($user['name']).'</td>
                                    <td class="p-2">'.htmlspecialchars($user['email']).'</td>
                                    <td class="p-2">'.htmlspecialchars($user['role']).'</td>
                                    <td class="p-2">'.htmlspecialchars($user['created_at']).'</td>
                                </tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Finance & Revenue Table -->
            <div class="bg-white p-4 rounded-lg shadow-lg mb-8">
                <h3 class="text-lg font-medium mb-4">Finance & Revenue</h3>
                <?php
                // Weekly Revenue
                $stmt = $conn->prepare("SELECT IFNULL(SUM(amount),0) as revenue, COUNT(*) as count 
                    FROM payments 
                    WHERE payment_status='completed' AND payment_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $stmt->execute();
                $week = $stmt->fetch();

                // Monthly Revenue
                $stmt = $conn->prepare("SELECT IFNULL(SUM(amount),0) as revenue, COUNT(*) as count 
                    FROM payments 
                    WHERE payment_status='completed' AND payment_time >= DATE_SUB(NOW(), INTERVAL 1 MONTH)");
                $stmt->execute();
                $month = $stmt->fetch();

                // Revenue by week for chart (last 8 weeks)
                $stmt = $conn->prepare("
                    SELECT YEARWEEK(payment_time, 1) as week, IFNULL(SUM(amount),0) as revenue
                    FROM payments
                    WHERE payment_status='completed' AND payment_time >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
                    GROUP BY week
                    ORDER BY week ASC
                ");
                $stmt->execute();
                $weekly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Revenue by month for chart (last 6 months)
                $stmt = $conn->prepare("
                    SELECT DATE_FORMAT(payment_time, '%Y-%m') as month, IFNULL(SUM(amount),0) as revenue
                    FROM payments
                    WHERE payment_status='completed' AND payment_time >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY month
                    ORDER BY month ASC
                ");
                $stmt->execute();
                $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <ul class="list-disc pl-5 mb-4">
                    <li>Completed Payments (Last 7 days): <?php echo $week['count']; ?></li>
                    <li>Revenue (Last 7 days): Ksh <?php echo number_format($week['revenue'], 2); ?></li>
                    <li>Completed Payments (Last 30 days): <?php echo $month['count']; ?></li>
                    <li>Revenue (Last 30 days): Ksh <?php echo number_format($month['revenue'], 2); ?></li>
                </ul>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <h4 class="font-semibold mb-2">Weekly Revenue (Last 8 Weeks)</h4>
                        <canvas id="weeklyRevenueChart" height="120"></canvas>
                    </div>
                    <div>
                        <h4 class="font-semibold mb-2">Monthly Revenue (Last 6 Months)</h4>
                        <canvas id="monthlyRevenueChart" height="120"></canvas>
                    </div>
                </div>
            </div>

            <!-- User Registration Analytics -->
            <div class="bg-white p-4 rounded-lg shadow-lg mb-8">
                <h3 class="text-lg font-medium mb-4">User Registration Analytics</h3>
                <?php
                // Users registered per month (last 6 months)
                $stmt = $conn->prepare("
                    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
                    FROM users
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY month
                    ORDER BY month ASC
                ");
                $stmt->execute();
                $user_monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <canvas id="userRegistrationChart" height="120"></canvas>
            </div>
            <?php endif; ?>
        </section>

        <!-- Chart.js CDN -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        <?php if ($is_admin): ?>
        // Weekly Revenue Chart
        const weeklyLabels = <?php echo json_encode(array_map(function($row){ return $row['week']; }, $weekly_data)); ?>;
        const weeklyRevenue = <?php echo json_encode(array_map(function($row){ return (float)$row['revenue']; }, $weekly_data)); ?>;
        new Chart(document.getElementById('weeklyRevenueChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: weeklyLabels,
                datasets: [{
                    label: 'Revenue (Ksh)',
                    data: weeklyRevenue,
                    backgroundColor: '#3b82f6'
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });

        // Monthly Revenue Chart
        const monthlyLabels = <?php echo json_encode(array_map(function($row){ return $row['month']; }, $monthly_data)); ?>;
        const monthlyRevenue = <?php echo json_encode(array_map(function($row){ return (float)$row['revenue']; }, $monthly_data)); ?>;
        new Chart(document.getElementById('monthlyRevenueChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Revenue (Ksh)',
                    data: monthlyRevenue,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.2)',
                    fill: true
                }]
            },
            options: { responsive: true }
        });

        // User Registration Chart
        const userRegLabels = <?php echo json_encode(array_map(function($row){ return $row['month']; }, $user_monthly)); ?>;
        const userRegCounts = <?php echo json_encode(array_map(function($row){ return (int)$row['count']; }, $user_monthly)); ?>;
        new Chart(document.getElementById('userRegistrationChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: userRegLabels,
                datasets: [{
                    label: 'New Users',
                    data: userRegCounts,
                    backgroundColor: '#f59e42'
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
        <?php endif; ?>
        </script>

        <?php
        // CSV Download Handler
        if (isset($_POST['download_users_csv']) && $is_admin) {
            $stmt = $conn->prepare("SELECT id, name, email, role, created_at FROM users");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment;filename=registered_users.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Name', 'Email', 'Role', 'Registered At']);
            foreach ($users as $user) {
                fputcsv($output, $user);
            }
            fclose($output);
            exit();
        }
        ?>

        <!-- Modal for User Management -->
        <div id="userModal" class="modal">
            <div class="modal-content">
                <h3 class="text-lg font-medium mb-2">Edit User</h3>
                <form method="POST" id="userForm" class="space-y-4">
                    <input type="hidden" name="user_id" id="user_id">
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-700">Name</label>
                        <input type="text" name="name" id="edit_name" required
                               class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="edit_email" class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email" id="edit_email" required
                               class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="edit_role" class="block text-sm font-medium text-gray-700">Role</label>
                        <select name="role" id="edit_role" required
                                class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                            <option value="client">Client</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" name="update_user" id="saveUserBtn"
                            class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600 transition">
                        Save Changes
                    </button>
                    <button type="button" id="closeUserModal" class="w-full bg-gray-500 text-white p-2 rounded hover:bg-gray-600 transition mt-2">
                        Close
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>