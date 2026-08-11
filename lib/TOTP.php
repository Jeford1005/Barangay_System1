<?php
/**
 * TOTP (Time-based One-Time Password) Implementation
 * RFC 6238 compliant
 * 
 * Usage:
 *   $totp = new TOTP($secret);
 *   $code = $totp->generate(); // Generate current code
 *   $isValid = $totp->verify($inputCode); // Verify user input
 */

class TOTP {
    private $secret;
    private $digits = 6;
    private $period = 30;
    private $algorithm = 'sha1';
    private $window = 1; // Allow 1 period before/after for clock drift
    
    public function __construct($secret, $digits = 6, $period = 30, $algorithm = 'sha1') {
        $this->secret = $secret;
        $this->digits = $digits;
        $this->period = $period;
        $this->algorithm = $algorithm;
    }
    
    /**
     * Generate current TOTP code
     */
    public function generate($timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $counter = floor($timestamp / $this->period);
        return $this->generateCode($counter);
    }
    
    /**
     * Verify a TOTP code
     * @param string $code User input code
     * @param int|null $timestamp Current timestamp (null = now)
     * @return bool
     */
    public function verify($code, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $code = str_pad(trim($code), $this->digits, '0', STR_PAD_LEFT);
        
        if (!preg_match('/^\d{' . $this->digits . '}$/', $code)) {
            return false;
        }
        
        // Check current period and adjacent periods (for clock drift)
        for ($i = -$this->window; $i <= $this->window; $i++) {
            $checkTimestamp = $timestamp + ($i * $this->period);
            $expectedCode = $this->generate($checkTimestamp);
            
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get provisioning URI for QR code
     * @param string $label Account label (e.g., "user@example.com")
     * @param string $issuer Issuer name (e.g., "Barangay Bidduang")
     * @return string otpauth:// URI
     */
    public function getProvisioningUri($label, $issuer = 'Barangay Bidduang') {
        $params = [
            'secret' => $this->secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period
        ];
        
        return 'otpauth://totp/' . urlencode($issuer . ':' . $label) . '?' . http_build_query($params);
    }
    
    /**
     * Generate QR code data URI for Google Charts API
     * @param string $label
     * @param string $issuer
     * @return string data:image/png;base64,...
     */
    public function getQrCodeDataUri($label, $issuer = 'Barangay Bidduang', $size = 200) {
        $uri = $this->getProvisioningUri($label, $issuer);
        $url = 'https://chart.googleapis.com/chart?' . http_build_query([
            'chs' => $size . 'x' . $size,
            'chld' => 'M|0',
            'cht' => 'qr',
            'chl' => $uri
        ]);
        
        // Note: In production, use a local QR code library instead of Google Charts
        return $url;
    }
    
    /**
     * Generate a new random base32 secret
     * @param int $length Length in bytes (default 20 = 160 bits)
     * @return string Base32 encoded secret
     */
    public static function generateSecret($length = 20) {
        $bytes = random_bytes($length);
        return self::base32Encode($bytes);
    }
    
    /**
     * Base32 encode (RFC 4648)
     */
    private static function base32Encode($data) {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $output = '';
        
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        
        $bits = str_pad($bits, ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT);
        
        foreach (str_split($bits, 5) as $chunk) {
            $output .= $base32chars[bindec($chunk)];
        }
        
        // Add padding
        $padding = (8 - (strlen($output) % 8)) % 8;
        return $output . str_repeat('=', $padding);
    }
    
    /**
     * Generate HMAC
     */
    private function hmac($key, $data) {
        return hash_hmac($this->algorithm, $data, $key, true);
    }
    
    /**
     * Generate code from counter
     */
    private function generateCode($counter) {
        // Pack counter as 8-byte big-endian
        $counterBytes = pack('N*', 0, $counter);
        
        // Decode base32 secret
        $key = $this->base32Decode($this->secret);
        
        // HMAC
        $hash = $this->hmac($key, $counterBytes);
        
        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, $this->digits);
        
        return str_pad($code, $this->digits, '0', STR_PAD_LEFT);
    }
    
    /**
     * Base32 decode (RFC 4648)
     */
    private function base32Decode($data) {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(str_replace('=', '', $data));
        $bits = '';
        
        foreach (str_split($data) as $char) {
            $pos = strpos($base32chars, $char);
            if ($pos === false) {
                throw new Exception('Invalid base32 character: ' . $char);
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        
        return $bytes;
    }
}