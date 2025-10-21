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
$user_id = $_SESSION['user_id'];

try {
    // Fetch booking + slot + user info in one query so we have slot_id for updates
    $stmt = $conn->prepare("SELECT b.id, b.start_time, b.status, b.user_id, s.slot_number, s.id AS slot_id, u.phone_number
                            FROM bookings b
                            JOIN slots s ON b.slot_id = s.id
                            JOIN users u ON b.user_id = u.id
                            WHERE b.id = ? AND b.user_id = ?");
    $stmt->execute([$booking_id, $user_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $_SESSION['error'] = "Booking not found or you are not authorized.";
        header('Location: dashboard.php');
        exit;
    }

    // Only allow checkout when user has already checked in
    if ($booking['status'] !== 'checked_in') {
        $_SESSION['error'] = "Checkout not allowed. You must check in first.";
        header('Location: dashboard.php');
        exit;
    }

    // Add M-Pesa API credentials (keep as-is for sandbox/testing)
    $consumerKey = 'z1jc69gjbagg7H9Gox3kfA0cpsQ81PJn0UAz9shlRy4LgCdZ';
    $consumerSecret = 'BjNXOCNVLGM4gY5lRCvkAs6Js3Qu67yBT8cD8bYpKEDl89qPWTvhQifXWYhS1WQl';
    $shortCode = '174379';
    $passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';

    // Ensure phone number is in correct sandbox format
    $phone_number_stored = isset($booking['phone_number']) ? $booking['phone_number'] : '';
    $phone_number_stored = preg_replace('/^0/', '254', $phone_number_stored);

    if (!isset($_GET['simulate']) && !isset($_POST['complete_checkout'])) {

        // Step 1: Prompt user to confirm/enter phone if not posted
        if (!isset($_POST['phone_number'])) {
            $default_phone = htmlspecialchars($phone_number_stored);
            echo "<form method='post' action=''>
                <label class='block mb-2 font-medium'>Phone Number for Payment</label>
                <input type='text' name='phone_number' value='$default_phone' class='border p-2 rounded w-full mb-2' required pattern='^254[17][0-9]{8}$' placeholder='2547XXXXXXXX'>
                <button type='submit' class='bg-blue-500 text-white px-4 py-2 rounded'>Proceed to Pay</button>
            </form>";
            exit;
        }

        // Step 2: Use submitted phone number
        $phone_number = preg_replace('/^0/', '254', $_POST['phone_number']);
        if (!preg_match('/^254[17][0-9]{8}$/', $phone_number)) {
            $error = "Invalid phone number format. Use 2547XXXXXXXX.";
            echo "<p style='color:red;'>$error</p>";
            exit;
        }

        // Prevent duplicate payments for this booking
        $stmt = $conn->prepare("SELECT id, payment_status, checkout_time, duration_minutes, amount FROM payments WHERE booking_id = ? AND (payment_status = 'pending' OR payment_status = 'completed') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$booking_id]);
        $existing_payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_payment) {
            if (!empty($existing_payment['duration_minutes'])) {
                $duration_minutes = $existing_payment['duration_minutes'];
                $hours = $duration_minutes / 60;
            }
            if (!empty($existing_payment['amount'])) {
                $amount = $existing_payment['amount'];
            }
            if ($existing_payment['payment_status'] === 'completed') {
                $success = "Payment already completed for this booking.";
            } else {
                $success = "A payment request is already pending for this booking.";
            }
            $success .= " <a href='?booking_id=$booking_id&simulate=true'>Simulate Payment for Receipt</a>";
        } else {
            // Compute duration from start_time to now
            $checkout_time = new DateTime();
            $start_time = new DateTime($booking['start_time']);
            $duration_seconds = $checkout_time->getTimestamp() - $start_time->getTimestamp();
            $duration_minutes = max(1, (int) round($duration_seconds / 60));
            $hours = $duration_minutes / 60;

            // Tiered pricing (adjust as needed)
            if ($hours <= 5) {
                $amount = 60;
            } elseif ($hours <= 6) {
                $amount = 150;
            } else {
                $amount = 150 + (($hours - 7) * 100);
            }

            // Record pending payment
            $stmt = $conn->prepare("INSERT INTO payments (booking_id, amount, duration_minutes, transaction_id, payment_status, checkout_time) VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$booking_id, $amount, $duration_minutes, 'TMP_' . uniqid(), $checkout_time->format('Y-m-d H:i:s')]);
            $payment_id = $conn->lastInsertId();

            echo "<div id='spinner' style='display:flex;align-items:center;gap:10px;'><span>Processing payment request...</span><img src='spinner.gif' alt='Loading...' width='24'></div>";
            echo "<script>setTimeout(function(){document.getElementById('spinner').style.display='none';},3000);</script>";
            echo "<p>Please check your phone for the M-Pesa prompt and complete the payment. Do not refresh this page.</p>";

            // M-Pesa STK Push
            $timestamp = date('YmdHis');
            $password = base64_encode($shortCode . $passkey . $timestamp);

            function getAccessToken($consumerKey, $consumerSecret) {
                $credentials = base64_encode($consumerKey . ':' . $consumerSecret);
                $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                curl_close($ch);
                $result = json_decode($response);
                if (isset($result->access_token)) {
                    return $result->access_token;
                } else {
                    throw new Exception('Failed to obtain M-Pesa access token.');
                }
            }

            $token = getAccessToken($consumerKey, $consumerSecret);
            $stk_url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
            $payload = [
                'BusinessShortCode' => $shortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $amount,
                'PartyA' => $phone_number,
                'PartyB' => $shortCode,
                'PhoneNumber' => $phone_number,
                'CallBackURL' => 'https://webhook.site/your-callback-url',
                'AccountReference' => "GleeHotel_$booking_id",
                'TransactionDesc' => 'Parking Fee Payment'
            ];
            $ch = curl_init($stk_url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $stk_response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $stk_result = json_decode($stk_response);
            if ($stk_result && isset($stk_result->ResponseCode) && $stk_result->ResponseCode === '0') {
                $success = "STK Push request sent successfully. Check your phone.";
            } else {
                // rollback payment record
                $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
                $stmt->execute([$payment_id]);
                $error_msg = $stk_result ? ($stk_result->errorMessage ?? json_encode($stk_result)) : "No valid response from server";
                throw new Exception("STK Push failed (HTTP $http_code): $error_msg");
            }

            $success .= " <a href='?booking_id=$booking_id&simulate=true'>Simulate Payment for Receipt</a>";
        }
    }

    // Simulate successful payment for testing and produce receipt
    if (isset($_GET['simulate']) && $_GET['simulate'] === 'true') {
        // Re-fetch start_time (we already have it in $booking)
        $start_time = new DateTime($booking['start_time']);
        $now = new DateTime();
        $duration_seconds = $now->getTimestamp() - $start_time->getTimestamp();
        $duration_minutes = max(1, (int) round($duration_seconds / 60));

        // Fetch latest amount if stored
        $stmt = $conn->prepare("SELECT amount FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$booking_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        $amount = $payment ? $payment['amount'] : 0;

        // Update payments as completed
        $stmt = $conn->prepare("UPDATE payments SET payment_status = 'completed', transaction_id = ?, payment_time = NOW(), checkout_time = NOW(), duration_minutes = ? WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
        // Some DB engines don't allow ORDER BY in UPDATE — fallback: update by latest id previously fetched
        // We'll update by booking_id (affects latest record in typical simple setups); adjust if needed
        $stmt = $conn->prepare("UPDATE payments SET payment_status = 'completed', transaction_id = ?, payment_time = NOW(), checkout_time = NOW(), duration_minutes = ? WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
        try {
            $stmt->execute(['TEST_' . uniqid(), $duration_minutes, $booking_id]);
        } catch (PDOException $e) {
            // fallback: update all pending payments for booking
            $stmt = $conn->prepare("UPDATE payments SET payment_status = 'completed', transaction_id = ?, payment_time = NOW(), checkout_time = NOW(), duration_minutes = ? WHERE booking_id = ?");
            $stmt->execute(['TEST_' . uniqid(), $duration_minutes, $booking_id]);
        }

        // Build receipt
        $receipt_text = "Parking Receipt\n" .
                        "Slot: " . htmlspecialchars($booking['slot_number']) . "\n" .
                        "Duration: " . floor($duration_minutes / 1440) . " days, " . floor(($duration_minutes % 1440) / 60) . " hours, " . ($duration_minutes % 60) . " minutes\n" .
                        "Amount: KES " . number_format($amount, 2) . "\n" .
                        "Transaction ID: TEST_" . uniqid() . "\n" .
                        "Date: " . date('Y-m-d H:i:s') . "\n" .
                        "Status: Completed (Simulated)";
        $receipt_data = 'data:text/plain;charset=utf-8,' . rawurlencode($receipt_text);
        echo "<h2>Receipt</h2>";
        echo "<a href='$receipt_data' download='receipt_$booking_id.txt'>Download Receipt</a>";
        echo "<p>Click 'Download Receipt' or dismiss to complete checkout.</p>";

        echo "<form method='post' action=''>
                <button type='submit' name='complete_checkout'>Dismiss and Complete Checkout</button>
              </form>";

        if (isset($_POST['complete_checkout'])) {
            try {
                // Mark booking completed and free up slot
                $stmt = $conn->prepare("UPDATE bookings SET end_time = NOW(), status = 'completed' WHERE id = ?");
                $stmt->execute([$booking_id]);

                $stmt = $conn->prepare("UPDATE slots SET status = 'available' WHERE id = ?");
                $stmt->execute([$booking['slot_id']]);

                header("Location: dashboard.php");
                exit();
            } catch (PDOException $e) {
                $error = "Failed to complete checkout: " . $e->getMessage();
            }
        }
        exit;
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

// Output result
if (isset($success)) {
    echo "<h2>Payment Request</h2>";
    echo "<p>Slot: " . htmlspecialchars($booking['slot_number']) . "</p>";
    if (isset($duration_minutes)) {
        echo "<p>Duration: " . floor($duration_minutes / 1440) . " days, " . floor(($duration_minutes % 1440) / 60) . " hours, " . ($duration_minutes % 60) . " minutes</p>";
    }
    echo "<p>Amount: KES " . (isset($amount) ? number_format($amount, 2) : '0.00') . "</p>";
    echo "<p>" . htmlspecialchars($success) . "</p>";
} elseif (isset($error)) {
    echo "<h2>Error</h2><p>" . htmlspecialchars($error) . "</p>";
}
?>