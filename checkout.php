<?php
session_start();
require_once 'includes/config.php';

// Add M-Pesa API credentials
$consumerKey = 'z1jc69gjbagg7H9Gox3kfA0cpsQ81PJn0UAz9shlRy4LgCdZ';
$consumerSecret = 'BjNXOCNVLGM4gY5lRCvkAs6Js3Qu67yBT8cD8bYpKEDl89qPWTvhQifXWYhS1WQl';
$shortCode = '174379'; // Example sandbox shortcode
$passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
try {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $booking_id = filter_var($_GET['booking_id'], FILTER_SANITIZE_NUMBER_INT);

    // Fetch booking and user details
    $stmt = $conn->prepare("SELECT b.start_time, b.status, s.slot_number, s.id AS slot_id, u.phone_number 
                            FROM bookings b 
                            JOIN slots s ON b.slot_id = s.id 
                            JOIN users u ON b.user_id = u.id 
                            WHERE b.id = ? AND b.user_id = ? AND b.status = 'approved'");
    $stmt->execute([$booking_id, $user_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Invalid or unauthorized booking.");
    }

    // Ensure phone number is in correct format (254...)
    $phone_number = preg_replace('/^0/', '254', $booking['phone_number']);
    if (!preg_match('/^254[17][0-9]{8}$/', $phone_number)) {
        throw new Exception("Invalid phone number format.");
    }

    // Only run STK push if not simulating or completing checkout
    if (!isset($_GET['simulate']) && !isset($_POST['complete_checkout'])) {

        // Step 1: Prompt for phone number if not submitted yet
        if (!isset($_POST['phone_number'])) {
            // Show form to confirm or enter phone number
            $default_phone = htmlspecialchars($booking['phone_number']);
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

        // Prevent duplicate payments
        $stmt = $conn->prepare("SELECT id, payment_status, checkout_time, duration_minutes, amount FROM payments WHERE booking_id = ? AND (payment_status = 'pending' OR payment_status = 'completed')");
        $stmt->execute([$booking_id]);
        $existing_payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_payment) {
            // Use stored duration and amount if available
            if ($existing_payment['duration_minutes']) {
                $duration_minutes = $existing_payment['duration_minutes'];
                $hours = $duration_minutes / 60;
            }
            if ($existing_payment['amount']) {
                $amount = $existing_payment['amount'];
            }
            if ($existing_payment['payment_status'] === 'completed') {
                $success = "Payment already completed for this booking.";
            } else {
                $success = "A payment request is already pending for this booking.";
            }
            $success .= " <a href='?booking_id=$booking_id&simulate=true'>Simulate Payment for Receipt</a>";
        } else {
            // Set checkout_time now
            $checkout_time = new DateTime();
            $start_time = new DateTime($booking['start_time']);
            $duration_seconds = $checkout_time->getTimestamp() - $start_time->getTimestamp();
            $duration_minutes = max(1, (int) round($duration_seconds / 60));
            $hours = $duration_minutes / 60;

            // Calculate amount based on tiered pricing
            if ($hours <= 5) {
                $amount = 60;
            } elseif ($hours <= 6) {
                $amount = 150;
            } else {
                $amount = 150 + (($hours - 7) * 100);
            }

            // Record payment request (store duration, amount, and checkout_time)
            $stmt = $conn->prepare("INSERT INTO payments (booking_id, amount, duration_minutes, transaction_id, payment_status, checkout_time) VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$booking_id, $amount, $duration_minutes, 'TMP_' . uniqid(), $checkout_time->format('Y-m-d H:i:s')]);
            $payment_id = $conn->lastInsertId();

            // Show loading spinner and instructions
            echo "<div id='spinner' style='display:flex;align-items:center;gap:10px;'><span>Processing payment request...</span><img src='spinner.gif' alt='Loading...' width='24'></div>";
            echo "<script>
                setTimeout(function() {
                    document.getElementById('spinner').style.display = 'none';
                }, 3000);
            </script>";
            echo "<p>Please check your phone for the M-Pesa prompt and complete the payment. Do not refresh this page.</p>";

            // --- M-Pesa STK Push ---
            $timestamp = date('YmdHis');
            $password = base64_encode($shortCode . $passkey . $timestamp);
            // Helper function to get M-Pesa access token
            function getAccessToken($consumerKey, $consumerSecret) {
                $credentials = base64_encode($consumerKey . ':' . $consumerSecret);
                $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Basic ' . $credentials
                ]);
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
                $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
                $stmt->execute([$payment_id]);
                $error_msg = $stk_result ? $stk_result->errorMessage : "No valid response from server";
                throw new Exception("STK Push failed (HTTP $http_code): $error_msg");
            }

            $success .= " <a href='?booking_id=$booking_id&simulate=true'>Simulate Payment for Receipt</a>";
        }
    }

    // Simulate successful payment for testing
    if (isset($_GET['simulate']) && $_GET['simulate'] === 'true') {
        // Fetch booking start_time
        $stmt = $conn->prepare("SELECT b.start_time FROM bookings b WHERE b.id = ?");
        $stmt->execute([$booking_id]);
        $booking_row = $stmt->fetch(PDO::FETCH_ASSOC);
        $start_time = new DateTime($booking_row['start_time']);
        $now = new DateTime();

        // Efficient duration calculation in minutes
        $duration_seconds = $now->getTimestamp() - $start_time->getTimestamp();
        $duration_minutes = max(1, (int) round($duration_seconds / 60)); // At least 1 minute

        // Fetch amount from payments table
        $stmt = $conn->prepare("SELECT amount FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$booking_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        $amount = $payment ? $payment['amount'] : 0;

        // Set payment_time and checkout_time to NOW(), update duration_minutes
        $stmt = $conn->prepare("UPDATE payments SET payment_status = 'completed', transaction_id = ?, payment_time = NOW(), checkout_time = NOW(), duration_minutes = ? WHERE booking_id = ?");
        $stmt->execute(['TEST_' . uniqid(), $duration_minutes, $booking_id]);

        // Generate downloadable receipt as text file
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

        // Update booking and slot after download/dismiss
        echo "<form method='post' action=''>";
        echo "<button type='submit' name='complete_checkout'>Dismiss and Complete Checkout</button>";
        echo "</form>";

        if (isset($_POST['complete_checkout'])) {
            try {
                $stmt = $conn->prepare("UPDATE bookings SET end_time = NOW(), status = 'completed' WHERE id = ?");
                $stmt->execute([$booking_id]);
                $stmt = $conn->prepare("UPDATE slots SET status = 'available' WHERE id = ?");
                $stmt->execute([$booking['slot_id']]);
                header("Location: dashboard.php");
                exit();
            } catch (PDOException $e) {
                $error = "Failed to clear booking/slot: " . $e->getMessage();
            }
        }
        exit; // Prevent further output
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

// Output result
if (isset($success)) {
    echo "<h2>Payment Request</h2>";
    echo "<p>Slot: " . htmlspecialchars($booking['slot_number']) . "</p>";
    echo "<p>Duration: " . floor($duration_minutes / 1440) . " days, " . floor(($duration_minutes % 1440) / 60) . " hours, " . ($duration_minutes % 60) . " minutes</p>";
    echo "<p>Amount: KES " . number_format($amount, 2) . "</p>";
    echo "<p>$success</p>";
} elseif (isset($error)) {
    echo "<h2>Error</h2><p>" . htmlspecialchars($error) . "</p>";
}
?>