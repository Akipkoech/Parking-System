<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (!isset($_GET['booking_id'])) {
    header('Location: dashboard.php');
    exit;
}

$booking_id = (int) $_GET['booking_id'];
$user_id = (int) $_SESSION['user_id'];

// Set timezone to EAT (UTC+3)
date_default_timezone_set('Africa/Nairobi');

function formatDurationParts(int $minutes): string {
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;
    $parts = [];
    if ($days) $parts[] = "{$days} day" . ($days > 1 ? 's' : '');
    if ($hours) $parts[] = "{$hours} hour" . ($hours > 1 ? 's' : '');
    if ($mins || empty($parts)) $parts[] = "{$mins} minute" . ($mins > 1 ? 's' : '');
    return implode(', ', $parts);
}

// === M-Pesa STK Push helpers & credentials ===
$consumerKey = 'z1jc69gjbagg7H9Gox3kfA0cpsQ81PJn0UAz9shlRy4LgCdZ';
$consumerSecret = 'BjNXOCNVLGM4gY5lRCvkAs6Js3Qu67yBT8cD8bYpKEDl89qPWTvhQifXWYhS1WQl';
$shortCode = '174379'; // sandbox shortcode
$passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
$mpesa_env = 'sandbox'; // change to 'production' when ready and update endpoints

function getMpesaAccessToken(string $consumerKey, string $consumerSecret, string $env = 'sandbox') {
    $url = ($env === 'production')
        ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
        : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $consumerKey . ':' . $consumerSecret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error getting access token: $err");
    }
    curl_close($ch);
    if ($code !== 200) {
        throw new Exception("Failed to obtain M-Pesa access token (HTTP $code): $res");
    }
    $json = json_decode($res, true);
    if (!isset($json['access_token'])) {
        throw new Exception("Access token not present in response: $res");
    }
    return $json['access_token'];
}

function formatPhoneNumber(string $phone): string {
    // Remove any spaces or special characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Convert to international format if needed
    if (strlen($phone) === 9 && substr($phone, 0, 1) === '7') {
        $phone = '254' . $phone;
    } else if (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
        $phone = '254' . substr($phone, 1);
    }
    
    return $phone;
}

function sendStkPush(string $accessToken, string $shortCode, string $passkey, int $amount, string $phone, string $accountRef, string $desc = 'Parking Payment', string $env = 'sandbox') {
    // Format phone number
    $phone = formatPhoneNumber($phone);
    
    // Validate phone number format
    if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
        throw new Exception('Invalid phone number format. Must be 254XXXXXXXXX');
    }

    $timestamp = (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('YmdHis');
    $password = base64_encode($shortCode . $passkey . $timestamp);

    $url = ($env === 'production')
        ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
        : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

    $payload = [
        'BusinessShortCode' => $shortCode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $shortCode,
        'PhoneNumber' => $phone,
        'CallBackURL' => 'https://webhook.site/3cbb0855-b626-40ce-8152-1ea09316c5fe',  // Dummy callback URL
        'AccountReference' => $accountRef,
        'TransactionDesc' => $desc
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error sending STK Push: $err");
    }
    curl_close($ch);
    $decoded = json_decode($res, true);
    if ($decoded === null) {
        throw new Exception("Invalid JSON response from STK Push: $res (HTTP $code)");
    }
    return array_merge(['http_status' => $code], $decoded);
}

try {
    // Fetch booking + slot + user phone
    $stmt = $conn->prepare("
        SELECT b.id, b.start_time, b.status, b.user_id, s.slot_number, s.id AS slot_id, u.phone_number
        FROM bookings b
        JOIN slots s ON b.slot_id = s.id
        JOIN users u ON b.user_id = u.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$booking_id, $user_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $_SESSION['error'] = "Booking not found or you are not authorized.";
        header('Location: dashboard.php');
        exit;
    }

    if ($booking['status'] !== 'checked_in') {
        $_SESSION['error'] = "Checkout not allowed. You must be checked in to checkout.";
        header('Location: dashboard.php');
        exit;
    }

    // Normalize stored phone
    $phone_stored = isset($booking['phone_number']) ? preg_replace('/^0/', '254', $booking['phone_number']) : '';

    $error = $success = null;
    $receipt = null;

    // Utility: compute duration & amount from booking start -> now
    $computeAmountAndDuration = function ($start_time_str) {
        $start = new DateTime($start_time_str, new DateTimeZone('Africa/Nairobi'));
        $now = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
        $duration_seconds = $now->getTimestamp() - $start->getTimestamp();
        $duration_minutes = max(1, (int) round($duration_seconds / 60));
        
        // Calculate hours, rounding up partial hours
        $hours = ceil($duration_minutes / 60);
        
        // Pricing structure:
        // First hour (or part thereof): KES 100
        // Each additional hour (or part thereof): KES 50
        // Maximum daily rate: KES 500
        
        if ($hours <= 24) {
            // For stays up to 24 hours
            $amount = 60; // First hour base rate
            if ($hours > 1) {
                $amount += ($hours - 1) * 50; // Additional hours
            }
            // Cap at daily maximum
            $amount = min($amount, 500);
        } else {
            // For stays longer than 24 hours
            $days = ceil($hours / 24);
            $amount = $days * 500; // Daily rate applied
        }

        return [$duration_minutes, $amount, $start, $now];
    };

    // Handle "Proceed to Pay" -> record a pending payment and send STK Push
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone_number']) && !isset($_POST['simulate_payment'])) {
        $phone = trim($_POST['phone_number']);
        $phone = preg_replace('/^0/', '254', $phone);
        if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
            $error = "Invalid phone number. Use 2547XXXXXXXX format.";
        } else {
            list($duration_minutes, $amount) = $computeAmountAndDuration($booking['start_time']);
            // Prevent duplicate pending/completed payment record
            $pstmt = $conn->prepare("SELECT id, payment_status FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
            $pstmt->execute([$booking_id]);
            $existing = $pstmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['payment_status'] === 'pending') {
                $success = "A payment request is already pending. You can use 'Simulate Payment' for testing.";
            } elseif ($existing && $existing['payment_status'] === 'completed') {
                $success = "Payment already completed for this booking. Use 'Simulate Payment' to re-generate a receipt if needed.";
            } else {
                try {
                    // Get access token
                    $accessToken = getMpesaAccessToken($consumerKey, $consumerSecret, $mpesa_env);

                    // Account reference and description
                    $accountRef = 'Booking-' . $booking_id;
                    $desc = 'Parking fee for slot ' . $booking['slot_number'];

                    // Send STK Push
                    $apiResp = sendStkPush($accessToken, $shortCode, $passkey, (int)$amount, $phone, $accountRef, $desc, $mpesa_env);

                    if (isset($apiResp['ResponseCode']) && $apiResp['ResponseCode'] === '0') {
                        // success: record pending payment with CheckoutRequestID as transaction_id
                        $checkoutRequestId = $apiResp['CheckoutRequestID'] ?? null;
                        $tx = $checkoutRequestId ?: ('MP_' . uniqid());
                        $insert = $conn->prepare("INSERT INTO payments (booking_id, amount, duration_minutes, transaction_id, payment_status, checkout_time) VALUES (?, ?, ?, ?, 'pending', NOW())");
                        $insert->execute([$booking_id, $amount, $duration_minutes, $tx]);

                        $success = "Payment prompt sent. Please check your phone and complete the payment.";
                    } else {
                        // API returned an error
                        $respText = isset($apiResp['errorMessage']) ? $apiResp['errorMessage'] : json_encode($apiResp);
                        $error = "Failed to send payment prompt: " . $respText;
                    }
                } catch (Exception $ex) {
                    $error = "M-Pesa request failed: " . $ex->getMessage();
                }
            }
        }
    }

    // Handle simulate payment: mark payment completed, free slot, complete booking, and show receipt
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simulate_payment'])) {
        // Compute amounts
        list($duration_minutes, $amount, $start_dt, $now_dt) = $computeAmountAndDuration($booking['start_time']);
        $transaction_id = 'SIM_' . strtoupper(uniqid());
        // Generate a 4-digit numeric checkout code (allows leading zeros)
        $checkout_code = sprintf('%04d', random_int(0, 9999));

        try {
            $conn->beginTransaction();

            // If there's an existing latest payment record, mark it completed; otherwise insert completed
            $pstmt = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
            $pstmt->execute([$booking_id]);
            $latest = $pstmt->fetch(PDO::FETCH_ASSOC);

            if ($latest) {
                $up = $conn->prepare("UPDATE payments SET payment_status = 'completed', transaction_id = ?, payment_time = NOW(), checkout_time = NOW(), duration_minutes = ?, amount = ?, checkout_code = ? WHERE id = ?");
                $up->execute([$transaction_id, $duration_minutes, $amount, $checkout_code, $latest['id']]);
            } else {
                $ins = $conn->prepare("INSERT INTO payments (booking_id, amount, duration_minutes, transaction_id, payment_status, payment_time, checkout_time, checkout_code) VALUES (?, ?, ?, ?, 'completed', NOW(), NOW(), ?)");
                $ins->execute([$booking_id, $amount, $duration_minutes, $transaction_id, $checkout_code]);
            }

            // Complete booking
            $bup = $conn->prepare("UPDATE bookings SET end_time = NOW(), status = 'completed' WHERE id = ?");
            $bup->execute([$booking_id]);

            // Free slot
            $sup = $conn->prepare("UPDATE slots SET status = 'available' WHERE id = ?");
            $sup->execute([$booking['slot_id']]);

            $conn->commit();

            // Build receipt array for view and download
            $receipt = [
                'slot' => $booking['slot_number'],
                'start_time' => $start_dt->format('Y-m-d H:i:s'),
                'end_time' => $now_dt->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration_minutes,
                'duration_text' => formatDurationParts($duration_minutes),
                'amount' => $amount,
                'transaction_id' => $transaction_id,
                'status' => 'Completed (Simulated)',
                'checkout_code' => $checkout_code
            ];

            $success = "Payment simulated and checkout completed. Receipt below.";

        } catch (Exception $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Simulation failed: " . $ex->getMessage();
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - Glee Hotel Parking</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto py-10 px-4">
        <header class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3">
                <img src="assets/images/logo.png" alt="Logo" class="h-10 w-10 object-contain">
                <h1 class="text-2xl font-bold text-gray-800">Checkout</h1>
            </div>
            <a href="dashboard.php" class="text-green-600 hover:underline">Back to Dashboard</a>
        </header>

        <?php if (!empty($error)): ?>
            <div class="bg-red-50 text-red-700 p-4 rounded shadow mb-6"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="bg-green-50 text-green-700 p-4 rounded shadow mb-6"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <section class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold mb-4">Booking Details</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <div><span class="font-medium">Slot:</span> <?php echo htmlspecialchars($booking['slot_number']); ?></div>
                <div><span class="font-medium">Start Time:</span> <?php echo htmlspecialchars($booking['start_time']); ?></div>
                <div><span class="font-medium">Status:</span> <?php echo htmlspecialchars($booking['status']); ?></div>
                <div><span class="font-medium">Stored Phone:</span> <?php echo htmlspecialchars($phone_stored ?: 'N/A'); ?></div>
            </div>

            <hr class="my-6">

            <?php if (!$receipt): ?>
                <div class="grid gap-4 sm:grid-cols-2">
                    <!-- Phone / Proceed to pay -->
                    <form method="post" action="?booking_id=<?php echo $booking_id; ?>" class="space-y-4">
                        <label class="text-sm font-medium text-gray-700">Phone Number for Payment</label>
                        <input name="phone_number" type="text"
                               value="<?php echo htmlspecialchars($phone_stored); ?>"
                               placeholder="2547XXXXXXXX"
                               pattern="^254[17][0-9]{8}$"
                               required
                               class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-200">
                        <button type="submit" class="w-full bg-green-600 text-white p-3 rounded-lg hover:bg-green-700">Proceed to Pay</button>
                    </form>

                    <!-- Simulate payment -->
                    <form method="post" action="?booking_id=<?php echo $booking_id; ?>" class="space-y-4">
                        <input type="hidden" name="simulate_payment" value="1">
                        <div class="text-sm text-gray-600">For testing: instantly simulate a successful payment and complete the checkout. This will mark the booking completed and free the slot.</div>
                        <button type="submit" onclick="return confirm('Simulate payment and complete checkout?');" class="w-full bg-blue-600 text-white p-3 rounded-lg hover:bg-blue-700">Simulate Payment</button>
                    </form>
                </div>
            <?php else: ?>
                <h3 class="text-lg font-semibold mb-4">Receipt</h3>
                <div class="bg-gray-50 p-4 rounded mb-4">
                    <p><strong>Slot:</strong> <?php echo htmlspecialchars($receipt['slot']); ?></p>
                    <p><strong>Start:</strong> <?php echo htmlspecialchars($receipt['start_time']); ?></p>
                    <p><strong>End:</strong> <?php echo htmlspecialchars($receipt['end_time']); ?></p>
                    <p><strong>Duration:</strong> <?php echo htmlspecialchars($receipt['duration_text']); ?> (<?php echo $receipt['duration_minutes']; ?> minutes)</p>
                    <p><strong>Amount:</strong> KES <?php echo number_format($receipt['amount'], 2); ?></p>
                    <p><strong>Transaction ID:</strong> <?php echo htmlspecialchars($receipt['transaction_id']); ?></p>
                    <p><strong>Checkout Code (Gate Pass):</strong> <?php echo htmlspecialchars($receipt['checkout_code']); ?></p>
                    <p><strong>Status:</strong> <?php echo htmlspecialchars($receipt['status']); ?></p>
                </div>

                <?php
                // Prepare receipt text for download
                $receipt_text = "Parking Receipt\n";
                $receipt_text .= "Slot: {$receipt['slot']}\n";
                $receipt_text .= "Start Time: {$receipt['start_time']}\n";
                $receipt_text .= "End Time: {$receipt['end_time']}\n";
                $receipt_text .= "Duration: {$receipt['duration_text']} ({$receipt['duration_minutes']} minutes)\n";
                $receipt_text .= "Amount: KES " . number_format($receipt['amount'], 2) . "\n";
                $receipt_text .= "Transaction ID: {$receipt['transaction_id']}\n";
                $receipt_text .= "Checkout Code (Gate Pass): {$receipt['checkout_code']}\n";
                $receipt_text .= "Status: {$receipt['status']}\n";
                $data_uri = 'data:text/plain;charset=utf-8,' . rawurlencode($receipt_text);
                ?>
                <div class="flex gap-3">
                    <a href="<?php echo $data_uri; ?>" download="receipt_<?php echo $booking_id; ?>.txt" class="inline-block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Download Receipt</a>
                    <a href="dashboard.php" class="inline-block bg-gray-200 text-gray-800 px-4 py-2 rounded hover:bg-gray-300">Back to Dashboard</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>