<?php
// includes/logger.php
require_once __DIR__ . '/db_connection.php';

class Log
{
    /* Log level constants */
    const DEBUG    = 'debug';
    const INFO     = 'info';
    const WARNING  = 'warning';
    const ERROR    = 'error';
    const SECURITY = 'security';
    const AUDIT    = 'audit';

    /** Prevent the same request from writing hundreds of duplicate rows. */
    private static $dedupe = [];

    private static function db()
    {
        return Database::getInstance();
    }

    /**
     * Core write method. All other helpers call this.
     */
    public static function write(
        string $level,
        string $category,
        string $action,
        string $message = '',
        array  $context = [],
        ?int   $userId = null,
        ?string $userName = null
    ): bool {
        // Dedupe identical events in the same request
        $sig = $level . '|' . $category . '|' . $action . '|' . md5($message . json_encode($context));
        if (isset(self::$dedupe[$sig])) return true;
        self::$dedupe[$sig] = true;

        // Resolve user from session if not provided
        if ($userId === null || $userName === null) {
            if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
                @session_start();
            }
            if ($userId === null && !empty($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
            }
            if ($userName === null) {
                if (!empty($_SESSION['username']))       $userName = $_SESSION['username'];
                elseif (!empty($_SESSION['full_name']))  $userName = $_SESSION['full_name'];
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255)
            : null;

        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;

        try {
            self::db()->execute(
                "INSERT INTO system_logs
                 (log_level, category, action, message, context,
                  user_id, user_name, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$level, $category, $action, $message, $contextJson,
                 $userId, $userName, $ip, $ua],
                'sssss' . 'i' . 'sss'
            );
            return true;
        } catch (Throwable $e) {
            // Never let logging break the app — swallow and move on
            error_log('Log::write failed: ' . $e->getMessage());
            return false;
        }
    }

    /* ---------------- Public helpers ---------------- */

    public static function debug($category, $action, $message = '', $context = []) {
        return self::write(self::DEBUG, $category, $action, $message, $context);
    }
    public static function info($category, $action, $message = '', $context = []) {
        return self::write(self::INFO, $category, $action, $message, $context);
    }
    public static function warning($category, $action, $message = '', $context = []) {
        return self::write(self::WARNING, $category, $action, $message, $context);
    }
    public static function error($category, $action, $message = '', $context = []) {
        return self::write(self::ERROR, $category, $action, $message, $context);
    }
    public static function security($category, $action, $message = '', $context = []) {
        return self::write(self::SECURITY, $category, $action, $message, $context);
    }
    public static function audit($category, $action, $message = '', $context = []) {
        return self::write(self::AUDIT, $category, $action, $message, $context);
    }

    /**
     * Convenience: log before/after of an update.
     */
    public static function change($category, $action, $message, $old = [], $new = [], $extra = []) {
        $context = array_merge(
            ['old' => $old, 'new' => $new],
            $extra
        );
        return self::audit($category, $action, $message, $context);
    }

    /* ---------------- Read / query ---------------- */

    /**
     * Fetch logs with filters + pagination.
     * Returns ['rows' => [...], 'total' => int].
     */
    public static function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        $types  = '';

        if (!empty($filters['level'])) {
            if (is_array($filters['level'])) {
                $ph = implode(',', array_fill(0, count($filters['level']), '?'));
                $where[] = "log_level IN ($ph)";
                foreach ($filters['level'] as $l) { $params[] = $l; $types .= 's'; }
            } else {
                $where[] = "log_level = ?"; $params[] = $filters['level']; $types .= 's';
            }
        }
        if (!empty($filters['category'])) {
            $where[] = "category = ?"; $params[] = $filters['category']; $types .= 's';
        }
        if (!empty($filters['action'])) {
            $where[] = "action LIKE ?"; $params[] = '%' . $filters['action'] . '%'; $types .= 's';
        }
        if (!empty($filters['user'])) {
            $where[] = "(user_name LIKE ? OR user_id = ?)";
            $params[] = '%' . $filters['user'] . '%';
            $params[] = ctype_digit((string)$filters['user']) ? (int)$filters['user'] : 0;
            $types .= 'si';
        }
        if (!empty($filters['ip'])) {
            $where[] = "ip_address LIKE ?"; $params[] = '%' . $filters['ip'] . '%'; $types .= 's';
        }
        if (!empty($filters['search'])) {
            $where[] = "(message LIKE ? OR action LIKE ?)";
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
            $types .= 'ss';
        }
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?"; $params[] = $filters['date_from'] . ' 00:00:00'; $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?"; $params[] = $filters['date_to'] . ' 23:59:59'; $types .= 's';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        try {
            $total = (int)(self::db()->getSingle(
                "SELECT COUNT(*) as t FROM system_logs $whereSql", $params, $types
            )['t'] ?? 0);

            $offset = max(0, ($page - 1) * $perPage);
            $rows = self::db()->getAll(
                "SELECT * FROM system_logs $whereSql
                 ORDER BY created_at DESC, log_id DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset]),
                $types . 'ii'
            );

            return ['rows' => $rows, 'total' => $total];
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => 0];
        }
    }

    /**
     * Count logs matching filters — useful for badge counts.
     */
    public static function count(array $filters = []): int
    {
        return self::query($filters, 1, 1)['total'];
    }

    /**
     * Number of unread errors (last 7 days). Used for sidebar badge.
     */
    public static function unreadErrorCount(): int
    {
        try {
            $r = self::db()->getSingle(
                "SELECT COUNT(*) as t FROM system_logs
                 WHERE log_level IN ('error','security')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            return (int)($r['t'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* ---------------- Housekeeping ---------------- */

    /**
     * Delete logs older than the configured retention.
     * Returns number of rows deleted.
     */
    public static function cleanup(int $days = 90): int
    {
        $days = max(1, min(3650, $days));
        try {
            $before = (int)(self::db()->getSingle("SELECT COUNT(*) as t FROM system_logs")['t'] ?? 0);
            self::db()->execute(
                "DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days], 'i'
            );
            $after = (int)(self::db()->getSingle("SELECT COUNT(*) as t FROM system_logs")['t'] ?? 0);
            return $before - $after;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Wipe everything (admin action from Logs page).
     */
    public static function purgeAll(): bool
    {
        try {
            self::db()->execute("TRUNCATE TABLE system_logs");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Distinct categories present in the log for the filter dropdown.
     */
    public static function categories(): array
    {
        try {
            $rows = self::db()->getAll("SELECT DISTINCT category FROM system_logs ORDER BY category");
            return array_column($rows, 'category');
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Distinct actions present in the log.
     */
    public static function actions(): array
    {
        try {
            $rows = self::db()->getAll("SELECT DISTINCT action FROM system_logs ORDER BY action LIMIT 200");
            return array_column($rows, 'action');
        } catch (Throwable $e) {
            return [];
        }
    }
}