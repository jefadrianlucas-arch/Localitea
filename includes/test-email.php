<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

$mailConfig = require_once __DIR__ . '/mail-config.php';

$mail = new PHPMailer(true);

try {

    // SMTP settings
    $mail->isSMTP();
    $mail->Host = $mailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $mailConfig['port'];

    // Sender
    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name']
    );

    // TEST RECIPIENT
    $mail->addAddress('localitea.testing@gmail.com');

    // Email content
    $mail->isHTML(true);
    $mail->Subject = 'Localitea SMTP Test';

    $mail->Body = '
        <div style="font-family: Arial, sans-serif;">
            <h2>Localitea Email Test</h2>
            <p>Hello!</p>
            <p>This is a test email from the Localitea system.</p>
            <p>If you received this message, PHPMailer SMTP is working correctly.</p>
        </div>
    ';

    $mail->AltBody = 'This is a test email from the Localitea system.';

    $mail->send();

    echo '<h3 style="color: green;">Email sent successfully!</h3>';
    echo '<p>Check the Localitea testing Gmail inbox.</p>';

} catch (Exception $e) {

    echo '<h3 style="color: red;">Email could not be sent.</h3>';
    echo '<p>Mailer Error: ' .
         htmlspecialchars($mail->ErrorInfo) .
         '</p>';
}