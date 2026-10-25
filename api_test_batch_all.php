<?php
// api_test_batch_all.php — TEMPORARY. Delete when done testing.
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Batch All Members — Test</title>
<style>
    body{font-family:monospace;background:#111;color:#d0d0d0;padding:24px;line-height:1.6;}
    h1,h2{color:#ffcf5c;}
    .step{background:#1c1c1d;padding:16px;border-radius:8px;margin-bottom:16px;border-left:4px solid #ffcf5c;}
    input,textarea,button,select{background:#2a2a2d;color:#fff;border:1px solid #444;padding:8px;border-radius:6px;font-family:monospace;font-size:14px;}
    input,textarea,select{width:100%;box-sizing:border-box;margin:4px 0 10px;}
    button{background:#1e3a5a;border-color:#1e3a5a;cursor:pointer;padding:10px 20px;font-weight:700;}
    button:hover{background:#2d5f92;}
    button.green{background:#16a34a;border-color:#16a34a;}
    button.green:hover{background:#15803d;}
    pre{background:#000;padding:12px;border-radius:6px;overflow-x:auto;color:#7ee787;white-space:pre-wrap;}
    .good{color:#7ee787;font-weight:700;}
    .bad{color:#ff9c95;font-weight:700;}
    a{color:#79c0ff;}
    table{width:100%;border-collapse:collapse;margin:10px 0;font-size:13px;}
    th,td{border:1px solid #333;padding:6px 10px;text-align:left;}
    th{background:#2a2a2d;color:#ffcf5c;}
</style>
</head>
<body>
<h1>🎯 Batch ALL Members — Test</h1>
<p style="color:#8896a6;">Simulates the mobile app: leader collects from every member, then submits one batch with everyone.</p>

<?php
$action   = $_POST['action']   ?? '';
$username = $_POST['username'] ?? 'Ronnie';
$password = $_POST['password'] ?? '';
$token    = $_POST['token']    ?? '';
$period   = $_POST['period']   ?? date('Y-m');
$group_id = $_POST['group_id'] ?? '';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = 'http://' . $host . '/harana_financial_system/api';

function call_api($method, $url, $body = null, $token = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? []));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    return ['http_code' => $code, 'error' => $cerr, 'raw' => $raw];
}

function show($res) {
    echo '<pre><span style="color:#ffcf5c;">HTTP ' . $res['http_code'] . '</span>' . "\n";
    if ($res['error']) echo '<span class="bad">CURL: ' . htmlspecialchars($res['error']) . "</span>\n";
    echo htmlspecialchars($res['raw']) . '</pre>';
}
?>

<!-- ============ STEP 1: LOGIN ============ -->
<div class="step">
    <h2>Step 1 — Login</h2>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <label>Username:</label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($username); ?>">
        <label>Password:</label>
        <input type="text" name="password" value="<?php echo htmlspecialchars($password); ?>">
        <button>Login</button>
    </form>
    <?php
    $issued_token = '';
    if ($action === 'login') {
        $res = call_api('POST', $base . '/auth/login', [
            'phone' => $username, 'password' => $password,
            'device_label' => 'Batch-All Test'
        ]);
        show($res);
        $d = json_decode($res['raw'], true);
        if (!empty($d['token'])) {
            $issued_token = $d['token'];
            echo '<p class="good">✅ Token ready. It will auto-fill below.</p>';
        }
    }
    ?>
</div>

<!-- ============ STEP 2: FETCH ============ -->
<div class="step">
    <h2>Step 2 — Fetch leader's members</h2>
    <form method="post">
        <input type="hidden" name="action" value="fetch">
        <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
        <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
        <label>Token:</label>
        <textarea name="token" rows="2"><?php echo htmlspecialchars($_POST['token'] ?? $issued_token); ?></textarea>
        <label>Period (YYYY-MM):</label>
        <input type="text" name="period" value="<?php echo htmlspecialchars($period); ?>">
        <button>Fetch members</button>
    </form>
    <?php
    $loaded_members = [];
    $loaded_groups  = [];
    if ($action === 'fetch' || $action === 'batch_all') {
        $res = call_api('GET', $base . '/leader/members?period=' . urlencode($period), null, $token);
        // don't re-show the raw JSON for batch_all
        if ($action === 'fetch') show($res);

        $d = json_decode($res['raw'], true);
        if (!empty($d['success'])) {
            $loaded_groups  = $d['groups']  ?? [];
            $loaded_members = $d['members'] ?? [];

            if ($action === 'fetch') {
                echo '<p class="good">Groups: ' . count($loaded_groups) . ' · Members: ' . count($loaded_members) . '</p>';

                if (!empty($loaded_members)) {
                    echo '<table><thead><tr><th>#</th><th>Code</th><th>Name</th><th>Group ID</th><th>Amount</th></tr></thead><tbody>';
                    foreach ($loaded_members as $i => $m) {
                        echo '<tr>';
                        echo '<td>' . ($i + 1) . '</td>';
                        echo '<td><code>' . htmlspecialchars($m['member_code']) . '</code></td>';
                        echo '<td>' . htmlspecialchars($m['name']) . '</td>';
                        echo '<td>' . (int)$m['group_id'] . '</td>';
                        echo '<td>₱' . number_format((float)$m['monthly_contribution'], 2) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }
        }
    }
    ?>
</div>

<!-- ============ STEP 3: BATCH ALL ============ -->
<div class="step">
    <h2>Step 3 — Create ONE batch with ALL members</h2>
    <p class="text-muted" style="color:#8896a6; font-size:13px;">
        This mirrors exactly what the mobile app does when the leader taps <strong>"Submit batch"</strong>:
        sends every collected payment as one request.
    </p>
    <form method="post">
        <input type="hidden" name="action" value="batch_all">
        <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
        <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
        <label>Token:</label>
        <textarea name="token" rows="2"><?php echo htmlspecialchars($_POST['token'] ?? $issued_token); ?></textarea>
        <label>Period (YYYY-MM):</label>
        <input type="text" name="period" value="<?php echo htmlspecialchars($period); ?>">
        <label>Group ID (use 2 for GUIMBA → Group A):</label>
        <input type="text" name="group_id" value="<?php echo htmlspecialchars($group_id); ?>">
        <button class="green">Submit batch with ALL members</button>
    </form>
    <?php
    if ($action === 'batch_all') {
        if (empty($loaded_members)) {
            echo '<p class="bad">No members loaded. Do Step 2 first (fetch), then click Submit.</p>';
        } elseif (empty($group_id)) {
            echo '<p class="bad">Enter a group_id (e.g. 2).</p>';
        } else {
            // Build items array from ALL loaded members in that group
            $items = [];
            foreach ($loaded_members as $m) {
                if ((int)$m['group_id'] === (int)$group_id) {
                    $items[] = [
                        'member_code'  => $m['member_code'],
                        'amount'       => (float)$m['monthly_contribution'],
                        'collected_at' => date('Y-m-d H:i:s'),
                    ];
                }
            }

            if (empty($items)) {
                echo '<p class="bad">No members in group ' . (int)$group_id . ' from the loaded set.</p>';
            } else {
                echo '<p style="color:#ffcf5c;">Sending batch with <strong>' . count($items) . '</strong> members…</p>';
                $res = call_api('POST', $base . '/leader/batches', [
                    'period_month' => $period,
                    'group_id'     => (int)$group_id,
                    'items'        => $items,
                ], $token);
                show($res);

                $d = json_decode($res['raw'], true);
                if (!empty($d['qr_payload'])) {
                    $encoded = urlencode($d['qr_payload']);
                    echo '<h3 class="good">✅ Batch created — ' . (int)$d['member_count'] . ' members · ₱' . number_format((float)$d['total_amount'], 2) . '</h3>';
                    echo '<p>QR payload:</p>';
                    echo '<textarea rows="3" onclick="this.select()">' . htmlspecialchars($d['qr_payload']) . '</textarea>';
                    echo '<p><a href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . $encoded . '" target="_blank">Open QR in new tab →</a></p>';
                    echo '<p><a href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . $encoded . '" target="_blank" download="harana-batch-all-qr.png">Download QR PNG →</a></p>';
                }
            }
        }
    }
    ?>
</div>

</body>
</html>