<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendVerificationEmail($recipientEmail, $recipientName, $verificationLink)
{
    $mailConfig = require __DIR__ . '/mail-config.php';

    $mail = new PHPMailer(true);

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

    // Recipient
    $mail->addAddress($recipientEmail, $recipientName);

    // Email content
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    $mail->Subject = 'Verify Your Localitea Account';

    $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 30px; color: #2c221e;">

            <div style="text-align: center; margin-bottom: 25px;">
                <h2 style="color: #4A3525;">Welcome to Localitea!</h2>
            </div>

            <p>Hello <strong>' . htmlspecialchars($recipientName) . '</strong>,</p>

            <p>
                Thank you for creating a Localitea account.
                Please verify your email address by clicking the button below.
            </p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($verificationLink) . '"
                   style="
                       background-color: #4A3525;
                       color: #ffffff;
                       padding: 12px 25px;
                       text-decoration: none;
                       border-radius: 6px;
                       display: inline-block;
                       font-weight: bold;
                   ">
                    Verify My Email
                </a>
            </div>

            <p style="font-size: 14px; color: #666;">
                This verification link will expire after 30 minutes.
            </p>

            <p style="font-size: 14px; color: #666;">
                If you did not create a Localitea account, you can ignore this email.
            </p>

            <hr style="border: 0; border-top: 1px solid #ddd; margin: 30px 0;">

            <p style="font-size: 12px; color: #999; text-align: center;">
                Localitea — Online Ordering and Sales Management System
            </p>

        </div>
    ';

    $mail->AltBody =
        "Hello $recipientName,\n\n" .
        "Thank you for creating a Localitea account.\n" .
        "Please verify your email by opening this link:\n\n" .
        "$verificationLink\n\n" .
        "This link will expire after 30 minutes.";

    $mail->send();
}