<?php
/**
 * Get user profile photo URL
 * @param object $db Database instance
 * @param int $user_id User ID
 * @return string|null Profile photo URL or null
 */
function getUserProfilePhoto($db, $user_id) {
    if (empty($user_id)) return null;
    
    $member = $db->getSingle(
        "SELECT profile_photo FROM members WHERE user_id = ?",
        [$user_id],
        'i'
    );
    
    if ($member && !empty($member['profile_photo'])) {
        return '../' . $member['profile_photo'];
    }
    
    return null;
}
// includes/user_functions.php

/**
 * Get member data for current user
 * Updated to search by user_id first (most reliable)
 */
function getUserMemberData($db, $current_user) {
    $member = null;
    
    // Debug logging
    error_log("=== getUserMemberData Debug ===");
    error_log("Current user ID: " . ($current_user['user_id'] ?? 'NOT SET'));
    error_log("Current username: " . ($current_user['username'] ?? 'NOT SET'));
    error_log("Current email: " . ($current_user['email'] ?? 'NOT SET'));
    error_log("Current role: " . ($current_user['role'] ?? 'NOT SET'));
    
    // Try to find member by user_id (most reliable)
    if (!empty($current_user['user_id'])) {
        $member = $db->getSingle(
            "SELECT * FROM members WHERE user_id = ?",
            [$current_user['user_id']],
            'i'
        );
        if ($member) {
            error_log("Found member by user_id: " . ($member['member_code'] ?? 'unknown') . " (member_code: " . ($member['member_code'] ?? 'N/A') . ")");
        }
    }
    
    // If not found, try by username
    if (!$member && !empty($current_user['username'])) {
        $member = $db->getSingle(
            "SELECT * FROM members WHERE username = ?",
            [$current_user['username']],
            's'
        );
        if ($member) {
            error_log("Found member by username: " . ($member['member_code'] ?? 'unknown'));
        }
    }
    
    // Try by email if available
    if (!$member && !empty($current_user['email'])) {
        $member = $db->getSingle(
            "SELECT * FROM members WHERE email = ?",
            [$current_user['email']],
            's'
        );
        if ($member) {
            error_log("Found member by email: " . ($member['member_code'] ?? 'unknown'));
        }
    }
    
    // For admin users, don't force member data
    if (!$member && $current_user['role'] === 'admin') {
        error_log("Admin user, returning null (no member data needed)");
        return null;
    }
    
    // If still not found, return null instead of fallback (to avoid wrong data)
    if (!$member) {
        error_log("No member found for user!");
        return null;
    }
    
    error_log("Final member data - member_code: " . $member['member_code']);
    error_log("===============================");
    
    return $member;
}

/**
 * Get sample member data (fallback when no member exists)
 */
function getSampleMemberData() {
    return [
        'member_code' => '2024' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'middle_name' => 'Santos',
        'chapter' => 'GUIMBA',
        'group_name' => 'Group A',
        'barangay' => 'Singalat',
        'city' => 'Palayan City',
        'province' => 'Nueva Ecija',
        'contact_number' => '09123456789',
        'email' => 'juan.delacruz@example.com',
        'birth_date' => '1990-01-15',
        'gender' => 'Male',
        'civil_status' => 'Married',
        'date_joined' => date('Y-m-d', strtotime('-2 years')),
        'monthly_contribution' => 100.00,
        'beneficiary_name' => 'Maria Dela Cruz',
        'beneficiary_relation' => 'Spouse',
        'status' => 'active'
    ];
}

/**
 * Get user balance - FIXED to use member_code
 */
function getUserBalance($db, $member_code) {
    if (empty($member_code)) {
        error_log("getUserBalance called with empty member_code");
        return null;
    }
    
    $result = $db->getSingle(
        "SELECT * FROM member_balances WHERE member_code = ?",
        [$member_code],
        's'
    );
    
    // If no balance record exists, return default values
    if (!$result) {
        return [
            'total_paid' => 0,
            'total_due' => 0,
            'current_balance' => 0,
            'last_payment_date' => null
        ];
    }
    
    return $result;
}

/**
 * Get recent payments - FIXED to use member_code
 */
function getUserRecentPayments($db, $member_code, $limit = 5) {
    if (empty($member_code)) {
        error_log("getUserRecentPayments called with empty member_code");
        return [];
    }
    
    return $db->getAll(
        "SELECT p.* FROM payments p
         JOIN members m ON p.member_id = m.member_code
         WHERE m.member_code = ? AND p.payment_status = 'confirmed' 
         ORDER BY p.payment_date DESC 
         LIMIT ?",
        [$member_code, $limit],
        'si'
    );
}

/**
 * Get all payments with pagination - FIXED to use member_code
 */
function getUserPayments($db, $member_code, $limit, $offset) {
    if (empty($member_code)) {
        error_log("getUserPayments called with empty member_code");
        return [];
    }
    
    return $db->getAll(
        "SELECT p.* FROM payments p
         JOIN members m ON p.member_id = m.member_code
         WHERE m.member_code = ? 
         ORDER BY p.payment_date DESC 
         LIMIT ? OFFSET ?",
        [$member_code, $limit, $offset],
        'sii'
    );
}

/**
 * Get total payment count - FIXED to use member_code
 */
function getUserPaymentCount($db, $member_code) {
    if (empty($member_code)) {
        error_log("getUserPaymentCount called with empty member_code");
        return 0;
    }
    
    $result = $db->getSingle(
        "SELECT COUNT(*) as total FROM payments p
         JOIN members m ON p.member_id = m.member_code
         WHERE m.member_code = ?",
        [$member_code],
        's'
    );
    return $result['total'] ?? 0;
}

/**
 * Calculate months as member
 */
function calculateMonthsAsMember($member) {
    if (empty($member) || empty($member['date_joined']) || $member['date_joined'] == '0000-00-00') {
        return 0;
    }
    
    try {
        $join_date = new DateTime($member['date_joined']);
        $today = new DateTime();
        $diff = $join_date->diff($today);
        return ($diff->y * 12) + $diff->m;
    } catch (Exception $e) {
        error_log("Error calculating months: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get announcements
 */
function getAnnouncements() {
    return [
        [
            'title' => 'Monthly General Assembly',
            'content' => 'Join us for our monthly general assembly on March 15, 2026 at 2:00 PM at the main office.',
            'date' => '2026-03-01',
            'type' => 'meeting'
        ],
        [
            'title' => 'Payment Deadline Reminder',
            'content' => 'March contributions are due by March 10, 2026. Please settle your payments on time.',
            'date' => '2026-03-05',
            'type' => 'payment'
        ],
        [
            'title' => 'Financial Literacy Seminar',
            'content' => 'Free financial literacy seminar for members and their families on March 20, 2026.',
            'date' => '2026-03-10',
            'type' => 'event'
        ]
    ];
}

/**
 * Get upcoming events
 */
function getUpcomingEvents() {
    $announcements = getAnnouncements();
    $upcoming = [];
    foreach ($announcements as $a) {
        if (strtotime($a['date']) > time() && strtotime($a['date']) < strtotime('+30 days')) {
            $upcoming[] = $a;
        }
    }
    return $upcoming;
}

/**
 * Get birthdays this month
 */
function getBirthdaysThisMonth($member) {
    $birthdays = [];
    if (!empty($member) && !empty($member['birth_date']) && $member['birth_date'] != '0000-00-00') {
        $birth_month = date('m', strtotime($member['birth_date']));
        if ($birth_month == date('m')) {
            $birthdays[] = [
                'name' => ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''),
                'day' => date('j', strtotime($member['birth_date']))
            ];
        }
    }
    return $birthdays;
}

/**
 * Get council members
 */
function getCouncilMembers($db) {
    return $db->getAll(
        "SELECT full_name, position, contact_number, email FROM council WHERE status = 'active' ORDER BY position LIMIT 5"
    );
}

/**
 * Get chapter officials
 */
function getChapterOfficials($db) {
    return $db->getAll(
        "SELECT full_name, position FROM council 
         WHERE status = 'active' AND (position LIKE '%Coordinator%' OR position LIKE '%Leader%' OR position LIKE '%Officer%')
         ORDER BY position LIMIT 5"
    );
}

/**
 * Update user profile
 */
function updateUserProfile($db, $member_code, $data) {
    if (empty($member_code)) {
        return ['success' => false, 'message' => 'Invalid member code'];
    }
    
    $result = $db->execute(
        "UPDATE members SET 
         contact_number = ?, 
         alternate_number = ?, 
         email = ?, 
         present_address = ?, 
         permanent_address = ?,
         updated_at = NOW()
         WHERE member_code = ?",
        [
            $data['contact_number'] ?? '',
            $data['alternate_number'] ?? '',
            $data['email'] ?? '',
            $data['present_address'] ?? '',
            $data['permanent_address'] ?? '',
            $member_code
        ],
        'ssssss'
    );
    
    if ($result !== false) {
        return ['success' => true, 'message' => 'Profile updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update profile'];
}

/**
 * Request beneficiary update
 */
function requestBeneficiaryUpdate($db, $member_code, $data) {
    // In a real system, this would create a pending request
    return ['success' => true, 'message' => 'Beneficiary update request submitted for approval'];
}

// ============================================================
// NOTIFICATION FUNCTIONS
// ============================================================

/**
 * Create notification for a user
 */
function createNotification($db, $user_id, $title, $message, $type = 'system', $link = null, $icon = null, $is_deletable = true) {
    // Set icon based on type if not provided
    if (!$icon) {
        $icons = [
            'payment' => 'credit-card',
            'beneficiary' => 'heart',
            'announcement' => 'bullhorn',
            'account' => 'user-check',
            'reminder' => 'clock',
            'event' => 'calendar-alt',
            'system' => 'cog'
        ];
        $icon = $icons[$type] ?? 'bell';
    }
    
    // Set icon color based on type
    $icon_colors = [
        'payment' => 'success',
        'beneficiary' => 'danger',
        'announcement' => 'primary',
        'account' => 'info',
        'reminder' => 'warning',
        'event' => 'info',
        'system' => 'secondary'
    ];
    $icon_color = $icon_colors[$type] ?? 'primary';
    
    return $db->execute(
        "INSERT INTO notifications (user_id, title, message, type, icon, icon_color, link, is_deletable, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$user_id, $title, $message, $type, $icon, $icon_color, $link, $is_deletable ? 1 : 0],
        'issssssi'
    );
}

/**
 * Create notification for all users (announcements)
 */
function createNotificationForAll($db, $title, $message, $type = 'announcement', $link = null, $icon = null) {
    // Get all active users
    $users = $db->getAll("SELECT user_id FROM users WHERE is_active = 1");
    $count = 0;
    
    foreach ($users as $user) {
        if (createNotification($db, $user['user_id'], $title, $message, $type, $link, $icon)) {
            $count++;
        }
    }
    
    return $count;
}

/**
 * Create notification for member (by member_code)
 */
function createNotificationForMember($db, $member_code, $title, $message, $type = 'system', $link = null, $icon = null) {
    // Get user_id from member_code
    $member = $db->getSingle(
        "SELECT user_id FROM members WHERE member_code = ? AND user_id IS NOT NULL",
        [$member_code], 's'
    );
    
    if ($member && $member['user_id']) {
        return createNotification($db, $member['user_id'], $title, $message, $type, $link, $icon);
    }
    
    return false;
}

/**
 * Get unread notification count for user
 */
function getUnreadNotificationCount($db, $user_id) {
    $result = $db->getSingle(
        "SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0",
        [$user_id], 'i'
    );
    return $result['total'] ?? 0;
}

/**
 * Delete reminders after payment is made
 */
function deletePaidReminders($db, $user_id) {
    // This will be called when payment is made to delete related reminders
    $db->execute(
        "DELETE FROM notifications 
         WHERE type = 'reminder' 
         AND is_deletable = 1 
         AND user_id = ?",
        [$user_id], 'i'
    );
}

/**
 * Send payment due reminders
 * This should be called by a cron job daily
 */
function sendPaymentReminders($db) {
    // Get members with overdue payments
    $overdue_members = $db->getAll("
        SELECT m.member_code, m.user_id, m.first_name, m.last_name, 
               m.monthly_contribution, b.last_payment_date,
               DATEDIFF(NOW(), COALESCE(b.last_payment_date, m.date_joined)) as days_overdue
        FROM members m
        LEFT JOIN member_balances b ON m.member_code = b.member_code
        WHERE m.status = 'active' 
        AND (b.current_balance > 0 OR b.current_balance IS NULL)
        AND m.user_id IS NOT NULL
        AND m.user_id > 0
    ");
    
    $count = 0;
    foreach ($overdue_members as $member) {
        $days = $member['days_overdue'] ?? 30;
        $weeks_overdue = floor($days / 7);
        
        $title = "Payment Reminder";
        $message = "Dear " . ($member['first_name'] ?? 'Member') . ", your monthly contribution of ₱" . 
                   number_format($member['monthly_contribution'] ?? 100, 2) . 
                   " is overdue. Please settle your payment as soon as possible.";
        
        if ($weeks_overdue >= 4) {
            $message .= " Your account is " . $weeks_overdue . " weeks overdue. Please contact the office.";
        }
        
        createNotification($db, $member['user_id'], $title, $message, 'reminder', 
                          '../admin/payments.php?action=add&member_code=' . $member['member_code']);
        $count++;
    }
    
    return $count;
}
?>