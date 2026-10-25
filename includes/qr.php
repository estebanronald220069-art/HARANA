<?php
// includes/qr.php
class QR {
    public static function img(string $data, int $size = 220, string $alt = 'QR code'): string {
        // api.qrserver.com is free, requires no API key, and still works in 2026
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
             . '&margin=10&data=' . urlencode($data);
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" '
             . 'alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" '
             . 'width="' . (int)$size . '" height="' . (int)$size . '" '
             . 'style="display:block;">';
    }
}