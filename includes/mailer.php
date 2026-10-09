<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';


function createLocaliteaMailer(): PHPMailer
{
    $configPath = __DIR__ . '/mail-config.php';
    $localConfig = [];

    // Use the private config locally when it exists.
    if (is_file($configPath)) {
        $loadedConfig = require $configPath;

        if (is_array($loadedConfig)) {
            $localConfig = $loadedConfig;
        }
    }

    // Environment variables take priority on Railway.
    $getSetting = static function (
        string $key,
        $fallback = ''
    ) {
        $value = getenv($key);

        return ($value !== false && $value !== '')
            ? $value
            : $fallback;
    };

    $mailConfig = [
        'host' => $getSetting(
            'MAIL_HOST',
            $localConfig['host'] ?? 'smtp.gmail.com'
        ),
        'username' => $getSetting(
            'MAIL_USERNAME',
            $localConfig['username'] ?? ''
        ),
        'password' => $getSetting(
            'MAIL_PASSWORD',
            $localConfig['password'] ?? ''
        ),
        'port' => (int)$getSetting(
            'MAIL_PORT',
            (string)($localConfig['port'] ?? 465)
        ),
        'encryption' => strtolower((string)$getSetting(
            'MAIL_ENCRYPTION',
            $localConfig['encryption'] ?? 'ssl'
        )),
        'from_email' => $getSetting(
            'MAIL_FROM_EMAIL',
            $localConfig['from_email']
                ?? ($localConfig['username'] ?? '')
        ),
        'from_name' => $getSetting(
            'MAIL_FROM_NAME',
            $localConfig['from_name'] ?? 'Localitea'
        ),
    ];

    foreach (['host', 'username', 'password', 'from_email'] as $key) {
        if (trim((string)$mailConfig[$key]) === '') {
            throw new RuntimeException(
                "Missing mail configuration: {$key}"
            );
        }
    }

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $mailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];

    $mail->SMTPSecure =
        in_array($mailConfig['encryption'], ['tls', 'starttls'], true)
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;

    $mail->Port = $mailConfig['port'];

    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name']
    );

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}


/* =========================================================
   Verification EMAIL
========================================================= */

function sendVerificationEmail($recipientEmail, $recipientName, $verificationLink)
{
    $mail = createLocaliteaMailer();

    $mail->addAddress($recipientEmail, $recipientName);
    $mail->Subject = 'Verify Your Localitea Account';

    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8');

    $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 30px; color: #2c221e;">
            <div style="text-align: center; margin-bottom: 25px;">
                <h2 style="color: #4A3525;">Welcome to Localitea!</h2>
            </div>

            <p>Hello <strong>' . $safeName . '</strong>,</p>

            <p>
                Thank you for creating a Localitea account.
                Please verify your email address by clicking the button below.
            </p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $safeLink . '"
                   style="background-color: #4A3525; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold;">
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
/* =========================================================
   Password Reset EMAIL
========================================================= */
function sendPasswordResetEmail($recipientEmail, $recipientName, $resetLink)
{
    $mail = createLocaliteaMailer();

    $mail->addAddress($recipientEmail, $recipientName);
    $mail->Subject = 'Reset Your Localitea Password';

    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

    $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 30px; color: #2c221e;">
            <div style="text-align: center; margin-bottom: 25px;">
                <h2 style="color: #4A3525;">Localitea Password Reset</h2>
            </div>

            <p>Hello <strong>' . $safeName . '</strong>,</p>

            <p>
                We received a request to reset the password for your Localitea account.
            </p>

            <p>
                Click the button below to create a new password.
            </p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $safeLink . '"
                   style="background-color: #6F4E37; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold;">
                    Reset My Password
                </a>
            </div>

            <p style="font-size: 14px; color: #666;">
                This password reset link will expire after 1 hour.
            </p>

            <p style="font-size: 14px; color: #666;">
                If you did not request a password reset, you can safely ignore this email.
            </p>

            <hr style="border: 0; border-top: 1px solid #ddd; margin: 30px 0;">

            <p style="font-size: 12px; color: #999; text-align: center;">
                Localitea — Online Ordering and Sales Management System
            </p>
        </div>
    ';

    $mail->AltBody =
        "Hello $recipientName,\n\n" .
        "We received a request to reset your Localitea password.\n\n" .
        "Open this link to create a new password:\n\n" .
        "$resetLink\n\n" .
        "This link will expire after 1 hour.";

    $mail->send();
}


/* =========================================================
   ORDER PREPARING EMAIL
========================================================= */
function sendOrderPreparingEmail(
    $recipientEmail,
    $recipientName,
    $orderIdentifier,
    $claimNumber = ''
) {
    $mail = createLocaliteaMailer();

    $mail->addAddress(
        $recipientEmail,
        $recipientName
    );

    $mail->Subject =
        'Your Localitea Order Is Being Prepared';

    $safeName = htmlspecialchars(
        $recipientName,
        ENT_QUOTES,
        'UTF-8'
    );

    $safeOrder = htmlspecialchars(
        $orderIdentifier,
        ENT_QUOTES,
        'UTF-8'
    );

    $safeClaim = htmlspecialchars(
        $claimNumber,
        ENT_QUOTES,
        'UTF-8'
    );

    $claimHtml = '';

    if ($safeClaim !== '') {
        $claimHtml = '
            <p>
                <strong>Claim Number:</strong>
                ' . $safeClaim . '
            </p>
        ';
    }

    $mail->Body = '
        <div style="
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: auto;
            padding: 30px;
            color: #2c221e;
        ">

            <div style="
                text-align: center;
                margin-bottom: 25px;
            ">
                <h2 style="color: #4A3525;">
                    Your Order Is Being Prepared
                </h2>
            </div>

            <p>
                Hello <strong>' . $safeName . '</strong>,
            </p>

            <p>
                Just a reminder that your Localitea order
                <strong>' . $safeOrder . '</strong>
                is now being prepared.
            </p>

            ' . $claimHtml . '

            <div style="
                text-align: center;
                margin: 25px 0;
            ">
                <div style="
                    display: inline-block;
                    background-color: #F7F3EE;
                    border: 1px solid #6F4E37;
                    border-radius: 10px;
                    padding: 14px 20px;
                    color: #4A3525;
                    font-weight: bold;
                ">
                    Order Status: Preparing
                </div>
            </div>

            <p>
                Please get ready to pick up your order once it is
                marked as <strong>Ready for Pick-up</strong>.
            </p>

            <hr style="
                border: 0;
                border-top: 1px solid #ddd;
                margin: 30px 0;
            ">

            <p style="
                font-size: 12px;
                color: #999;
                text-align: center;
            ">
                Localitea — Online Ordering and Sales Management System
            </p>

        </div>
    ';

    $mail->AltBody =
        "Hello $recipientName,\n\n" .
        "Just a reminder that your Localitea order " .
        "$orderIdentifier is now being prepared.\n\n" .
        ($claimNumber !== ''
            ? "Claim Number: $claimNumber\n\n"
            : '') .
        "Order Status: Preparing\n\n" .
        "Please get ready to pick up your order once it is " .
        "marked as Ready for Pick-up.";

    $mail->send();
}