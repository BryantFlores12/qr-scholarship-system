<?php
class QRGenerator {
    private string $secretKey;

    public function __construct() {
        $this->secretKey = getenv('APP_QR_SECRET') ?: '';
        if (strlen($this->secretKey) < 32) {
            throw new RuntimeException('APP_QR_SECRET must contain at least 32 characters.');
        }
    }

    public function generateSecureCode($studentId, $day, $startDate) {
        $data = sprintf('%d|%d|%s', $studentId, $day, $startDate);
        $signature = substr(hash_hmac('sha256', $data, $this->secretKey), 0, 16);
        return sprintf('%d_%d_%s_%s', $studentId, $day, str_replace('-', '', $startDate), $signature);
    }

    public function verifyCode($code, $studentId, $day, $startDate) {
        $parts = explode('_', $code);
        if (count($parts) !== 4) {
            return false;
        }
        [$codeStudent, $codeDay, $codeDate, $codeSignature] = $parts;
        if ((string)$codeStudent !== (string)$studentId || (string)$codeDay !== (string)$day) {
            return false;
        }
        if ($codeDate !== str_replace('-', '', $startDate)) {
            return false;
        }
        $data = sprintf('%d|%d|%s', $studentId, $day, $startDate);
        $expected = substr(hash_hmac('sha256', $data, $this->secretKey), 0, 16);
        return hash_equals($expected, $codeSignature);
    }
}
?>
