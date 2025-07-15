<?php
// Database configuration
$servername = "localhost";
$username = "root";
$password = ""; // Default XAMPP MySQL password is empty
$dbname = "glee_parking";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// M-Pesa configuration (Safaricom Daraja API - Sandbox for testing)
$consumerKey = "NW9X3XvcQcSHbSdBeYObPrNb2FJYpgsJMys9kC5OCQ2FVoZZ"; // Your consumer key
$consumerSecret = "tDAS5S96zsEjk4XxJPpiHTBuKk7CFArXZTbOZ2sxqHGG9YHMAxoBjeDbeSYcb2OR"; // Your consumer secret
$shortCode = "174379"; // Your business shortcode
$passkey = "bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919"; // Your passkey
$callbackURL = "https://webhook.site/81e8754b-c531-4bd0-b0aa-36fb3f33cfaa"; // Callback URL for M-Pesa

// Function to get M-Pesa access token
function getAccessToken($consumerKey, $consumerSecret) {
    $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret)]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for localhost testing
    $response = curl_exec($curl);
    if ($response === false) {
        die("cURL error: " . curl_error($curl));
    }
    curl_close($curl);
    return json_decode($response)->access_token;
}
?>