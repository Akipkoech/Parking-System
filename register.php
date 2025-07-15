<?php
session_start();

// Include database configuration
require_once 'includes/config.php';

// Handle registration
if (isset($_POST['register'])) {
    $name = filter_var($_POST['name'], FILTER_SANITIZE_STRING);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone_number = filter_var($_POST['phone_number'], FILTER_SANITIZE_STRING);
    
    // Server-side validation
    if (empty($name) || empty($email) || empty($password) || empty($confirm_password) || empty($phone_number)) {
        $error = "All fields are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (!preg_match('/^0[17][0-9]{8}$|^254[17][0-9]{8}$/', $phone_number)) {
        $error = "Invalid phone number format (e.g., 0712345678 or 254712345678)";
    } else {
        try {
            // Check if email or phone number already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone_number = ?");
            $stmt->execute([$email, $phone_number]);
            if ($stmt->fetch()) {
                $error = "Email or phone number already registered";
            } else {
                // Hash password and insert user
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, phone_number) VALUES (?, ?, ?, 'client', ?)");
                $stmt->execute([$name, $email, $hashed_password, $phone_number]);
                $success = "Registration successful. Redirecting to login...";
                // JavaScript for delayed redirect
                echo "<script>setTimeout(() => { window.location.href = 'index.php'; }, 3000);</script>";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glee Hotel Parking - Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/scripts.js" defer></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="max-w-md w-full bg-white p-8 rounded-lg shadow-lg">
        <h1 class="text-2xl font-bold text-center mb-6">Glee Hotel Parking System</h1>
        <h2 class="text-xl font-semibold mb-4">Register</h2>
        <?php if (isset($error)): ?>
            <div class="alert alert-error mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <form method="POST" id="registerForm" class="space-y-4" onsubmit="return validateRegisterForm()">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                <input type="text" name="name" id="name" required
                       class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500"
                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" name="email" id="email" required
                       class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500"
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            <div>
                <label for="phone_number" class="block text-sm font-medium text-gray-700">Phone Number</label>
                <input type="tel" name="phone_number" id="phone_number" required
                       class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500"
                       placeholder="e.g., 0712345678 or 254712345678"
                       value="<?php echo isset($_POST['phone_number']) ? htmlspecialchars($_POST['phone_number']) : ''; ?>">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" id="password" required
                       class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" required
                       class="mt-1 w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
            </div>
            <button type="submit" name="register"
                    class="w-full bg-green-500 text-white p-2 rounded hover:bg-green-600 transition">
                Register
            </button>
        </form>
        <p class="mt-4 text-center text-sm">
            Already have an account? <a href="index.php" class="text-blue-500 hover:underline">Login</a>
        </p>
    </div>
</body>
</html>