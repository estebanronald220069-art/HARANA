<?php
// api_test.php — TEMPORARY test harness. DELETE when done.
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>API Test</title>
    <style>
        body{font-family:monospace;background:#111;color:#d0d0d0;padding:24px;line-height:1.6;}
        h1,h2{color:#ffcf5c;}
        .step{background:#1c1c1d;padding:16px;border-radius:8px;margin-bottom:16px;border-left:4px solid #ffcf5c;}
        input,textarea,button,select{background:#2a2a2d;color:#fff;border:1px solid #444;padding:8px;border-radius:6px;font-family:monospace;font-size:14px;}
        input,textarea,select{width:100%;box-sizing:border-box;margin:4px 0 10px;}
        button{background:#1e3a5a;border-color:#1e3a5a;cursor:pointer;padding:10px 20px;font-weight:700;}
        button:hover{background:#2d5f92;}
        pre{background:#000;padding:12px;border-radius:6px;overflow-x:auto;color:#7ee787;white-space:pre-wrap;}
        .good{color:#7ee787;font-weight:700;}
        .bad{color:#ff9c95;font-weight:700;}
        .label{color:#ffcf5c;font-weight:700;}
        a{color:#79c0ff;}
    </style>
</head>
<body>
<h1>🧪 Harana API Test Harness</h1>
<p style="color:#8896a6;">This page talks to <code>/api/</code> on your own server. Delete it when tests pass.</p>

<?php
$action = $_POST['action'] ?? '';

$username    = $_POST['username'] ?? 'admin';
$password    = $_POST['password'] ?? '';
$token       = $_POST['token']    ?? ($_POST['token_remember'] ?? '');
$period      = $_POST['period']   ?? date('Y-m');
$group_id    = $_POST['group_id'] ?? '';
$member_code = $_POST['member_code'] ?? '';
$amount      = $_POST['amount']   ?? '100';

$scheme = 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host . '/harana_financial_system/api';

function call_api($method, $url, $body = null, $token = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? []));
    }

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    return ['http_code' => $code, 'error' => $cerr, 'raw' => $raw];
}

function show_result($res) {
    echo '<pre>';
    echo '<span class="label">HTTP ' . $res['http_code'] . '</span>' . "\n";
    if ($res['error']) echo '<span class="bad">CURL: ' . htmlspecialchars($res['error']) . '</span>' . "\n";
    echo htmlspecialchars($res['raw']);
    echo '</pre>';
}
?>

<!-- ============ 7a — LOGIN ============ -->
<div class="step">
    <h2>7a — Login and get a token</h2>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <label>Username or phone:</label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($username); ?>">
        <label>Password:</label>
        <input type="text" name="password" value="<?php echo htmlspecialchars($password); ?>">
        <button>Login</button>
    </form>
    <?php
    $issued_token = '';
    if ($action === 'login') {
        $res = call_api('POST', $base . '/auth/login', [
            'phone'        => $username,
            'password'     => $password,
            'device_label' => 'API Test',
        ]);
        show_result($res);
        $d = json_decode($res['raw'], true);
        if (!empty($d['token'])) {
            $issued_token = $d['token'];
            echo '<p class="good">✅ Token issued — copy it below.</p>';
            echo '<textarea rows="2" onclick="this.select()">' . htmlspecialchars($issued_token) . '</textarea>';
        } else {
            echo '<p class="bad">❌ No token. Check credentials or that the user has role=leader or admin.</p>';
        }
    }
    ?>
</div>

<!-- ============ 7b — MEMBERS ============ -->
<div class="step">
    <h2>7b — List members</h2>
    <form method="post">
        <input type="hidden" name="action" value="members">
        <label>Token:</label>
        <textarea name="token" rows="2"><?php echo htmlspecialchars($_POST['token'] ?? $issued_token); ?></textarea>
        <label>Period (YYYY-MM):</label>
        <input type="text" name="period" value="<?php echo htmlspecialchars($period); ?>">
        <button>Fetch members</button>
    </form>
    <?php
    if ($action === 'members') {
        $res = call_api('GET', $base . '/leader/members?period=' . urlencode($period), null, $token);
        show_result($res);
        $d = json_decode($res['raw'], true);
        if (!empty($d['success'])) {
            $group_count  = count($d['groups']  ?? []);
            $member_count = count($d['members'] ?? []);
            echo '<p class="good">Groups: ' . $group_count . ' · Members: ' . $member_count . '</p>';
            if ($group_count && $member_count) {
                echo '<p>Tip: use the first group_id and first member_code in 7c below.</p>';
            } else {
                echo '<p class="bad">One or both lists are empty. Check group_id assignments on members.</p>';
            }
        }
    }
    ?>
</div>

<!-- ============ 7c — CREATE BATCH ============ -->
<div class="step">
    <h2>7c — Create a collection batch</h2>
    <form method="post">
        <input type="hidden" name="action" value="batch">
        <label>Token:</label>
        <textarea name="token" rows="2"><?php echo htmlspecialchars($_POST['token'] ?? $issued_token); ?></textarea>
        <label>Period (YYYY-MM):</label>
        <input type="text" name="period" value="<?php echo htmlspecialchars($period); ?>">
        <label>Group ID:</label>
        <input type="text" name="group_id" value="<?php echo htmlspecialchars($group_id); ?>">
        <label>Member code:</label>
        <input type="text" name="member_code" value="<?php echo htmlspecialchars($member_code); ?>">
        <label>Amount (PHP):</label>
        <input type="text" name="amount" value="<?php echo htmlspecialchars($amount); ?>">
        <button>Create batch</button>
    </form>
    <?php
    if ($action === 'batch') {
        $res = call_api('POST', $base . '/leader/batches', [
            'period_month' => $period,
            'group_id'     => (int)$group_id,
            'items' => [[
                'member_code'  => $member_code,
                'amount'       => (float)$amount,
                'collected_at' => date('Y-m-d H:i:s'),
            ]],
        ], $token);
        show_result($res);
        $d = json_decode($res['raw'], true);
        if (!empty($d['qr_payload'])) {
            echo '<h3 class="good">✅ Batch created — QR payload ready!</h3>';
            echo '<p>Copy this payload:</p>';
            echo '<textarea rows="3" onclick="this.select()">' . htmlspecialchars($d['qr_payload']) . '</textarea>';
            $encoded = urlencode($d['qr_payload']);
            echo '<p>Or open the QR directly:</p>';
            echo '<p><a href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . $encoded . '" target="_blank">Open QR in new tab →</a></p>';
            echo '<p><a href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . $encoded . '" target="_blank" download="harana-test-qr.png">Download QR PNG →</a> (right-click the image → Save as)</p>';
        }
    }
    ?>
</div>

</body>
</html>