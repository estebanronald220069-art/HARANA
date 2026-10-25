<?php
require_once 'includes/mailer.php';

echo '<pre>';
echo "MAIL_USERNAME = " . ($_ENV['MAIL_USERNAME'] ?? '(not set)') . "\n";
echo "MAIL_HOST     = " . ($_ENV['MAIL_HOST']     ?? '(not set)') . "\n";
echo "APP_URL       = " . ($_ENV['APP_URL']       ?? '(not set)') . "\n";
echo "MAIL_DEBUG    = " . ($_ENV['MAIL_DEBUG']    ?? '(not set)') . "\n\n";

$ok = Mailer::send('estebanronald22.0069@gmail.com', 'Test', 'Harana SMTP test',
                   '<p>It works!</p>');

echo "\nResult: ";
var_dump($ok);