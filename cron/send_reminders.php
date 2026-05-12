<?php
// cron/send_reminders.php - Run this daily via cron job
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/user_functions.php';

$db = Database::getInstance();

// Send payment reminders
$reminders_sent = sendPaymentReminders($db);

echo "[" . date('Y-m-d H:i:s') . "] Sent $reminders_sent payment reminders\n";