<?php
$sender_email = 'moodletechversant@gmail.com';
$app_password = 'exde smge goxw ubyj';
$recipient_email = 'vishnunarayanan@techversantinfo.com';
$subject = "Test Email via Gmail SMTP using plain PHP";
$body = "Hi there,\n\nThis is a test email sent via raw PHP and Gmail SMTP.\n\nCheers!";

// Connect to Gmail SMTP with STARTTLS (Port 587)
$socket = stream_socket_client("tcp://smtp.gmail.com:587", $errno, $errstr, 30);
if (!$socket) {
    exit("Connection failed: $errstr ($errno)\n");
}

function getResponse($socket) {
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (preg_match('/^\d{3} /', $line)) break;
    }
    return $response;
}

function sendCommand($cmd, $socket, $expectedCode) {
    fwrite($socket, $cmd . "\r\n");
    $response = getResponse($socket);
    if (strpos($response, (string)$expectedCode) !== 0) {
        exit("Error with command: $cmd\nResponse: $response\n");
    }
    return $response;
}

// Initial 220 response
getResponse($socket);

// Say EHLO
sendCommand("EHLO localhost", $socket, 250);

// Start TLS encryption
sendCommand("STARTTLS", $socket, 220);

// Enable crypto
if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
    exit("Failed to start TLS encryption\n");
}

// Say EHLO again after encryption
sendCommand("EHLO localhost", $socket, 250);

// Begin AUTH LOGIN
sendCommand("AUTH LOGIN", $socket, 334);
sendCommand(base64_encode($sender_email), $socket, 334);
sendCommand(base64_encode($app_password), $socket, 235);

// Send email headers and body
sendCommand("MAIL FROM:<$sender_email>", $socket, 250);
sendCommand("RCPT TO:<$recipient_email>", $socket, 250);
sendCommand("DATA", $socket, 354);

$headers = "From: $sender_email\r\n";
$headers .= "To: $recipient_email\r\n";
$headers .= "Subject: $subject\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$emailContent = $headers . "\r\n" . $body . "\r\n.";

// Send body and end with period
sendCommand($emailContent, $socket, 250);

// Quit
sendCommand("QUIT", $socket, 221);

fclose($socket);
echo "✅ Email sent successfully!\n";
?>

