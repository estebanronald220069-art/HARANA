<?php
// includes/settings.php
require_once __DIR__ . '/db_connection.php';

class Settings
{
    private static $cache = null;

    private static function db()
    {
        return Database::getInstance();
    }

    private static function load()
    {
        if (self::$cache !== null) return;
        self::$cache = [];
        try {
            $rows = self::db()->getAll(
                "SELECT setting_key, setting_value, default_value, value_type,
                        category, label, description, sort_order
                 FROM settings
                 ORDER BY category, sort_order, setting_key"
            );
            foreach ($rows as $r) {
                self::$cache[$r['setting_key']] = $r;
            }
        } catch (Throwable $e) {
            self::$cache = [];
        }
    }

    /** Get a setting's current value, cast to its declared type. */
    public static function get($key, $fallback = null)
    {
        self::load();
        if (!isset(self::$cache[$key])) return $fallback;
        return self::cast(self::$cache[$key]['setting_value'], self::$cache[$key]['value_type']);
    }

    /** Get a setting's default value. */
    public static function getDefault($key, $fallback = null)
    {
        self::load();
        if (!isset(self::$cache[$key])) return $fallback;
        return self::cast(self::$cache[$key]['default_value'], self::$cache[$key]['value_type']);
    }

    /** Get all settings (optionally filtered by category). */
    public static function all($category = null)
    {
        self::load();
        $out = [];
        foreach (self::$cache as $k => $row) {
            if ($category === null || $row['category'] === $category) {
                $out[$k] = $row;
            }
        }
        return $out;
    }

    /** Update a setting value. Writes an audit log row automatically. */
    public static function set($key, $value, $userId = null, $userName = null, $ip = null)
    {
        self::load();
        if (!isset(self::$cache[$key])) return false;

        $row     = self::$cache[$key];
        $old     = $row['setting_value'];
        $newStr  = self::stringify($value, $row['value_type']);

        if ((string)$old === (string)$newStr) return true; // no change

        try {
            self::db()->execute(
                "UPDATE settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?",
                [$newStr, $userId, $key],
                'sis'
            );
            self::db()->execute(
                "INSERT INTO settings_audit
                 (setting_key, old_value, new_value, admin_id, admin_name, ip_address, changed_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$key, (string)$old, $newStr, $userId, $userName, $ip],
                'sssiss'
            );
        } catch (Throwable $e) {
            return false;
        }

        self::$cache[$key]['setting_value'] = $newStr;
        return true;
    }

    /** Reset one setting to its default. */
    public static function reset($key, $userId = null, $userName = null, $ip = null)
    {
        self::load();
        if (!isset(self::$cache[$key])) return false;
        return self::set($key, self::$cache[$key]['default_value'], $userId, $userName, $ip);
    }

    /** Reset every setting in a category. */
    public static function resetCategory($category, $userId = null, $userName = null, $ip = null)
    {
        self::load();
        $count = 0;
        foreach (self::$cache as $k => $row) {
            if ($row['category'] === $category) {
                if (self::reset($k, $userId, $userName, $ip)) $count++;
            }
        }
        return $count;
    }

    /** Force a fresh load next request. */
    public static function flush()
    {
        self::$cache = null;
    }

    /* ---------------- internals ---------------- */

    private static function cast($value, $type)
    {
        switch ($type) {
            case 'int':   return (int)$value;
            case 'float': return (float)$value;
            case 'bool':  return $value === '1' || $value === 1 || $value === true || $value === 'true';
            case 'json':  return json_decode((string)$value, true);
            default:      return $value;
        }
    }

    private static function stringify($value, $type)
    {
        switch ($type) {
            case 'bool':  return $value ? '1' : '0';
            case 'json':  return is_string($value) ? $value : json_encode($value);
            case 'int':   return (string)(int)$value;
            case 'float': return number_format((float)$value, 2, '.', '');
            default:      return (string)$value;
        }
    }
}