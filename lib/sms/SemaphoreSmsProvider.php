<?php
/**
 * Semaphore Cloud SMS Provider Adapter
 *
 * Implements ISmsService. Transport is delegated to the existing SMSGateway
 * (which owns the live Semaphore/ItexMo/Twilio curl calls, credential loading,
 * and simulation fallback) so there is a single HTTP transport implementation.
 *
 * This adapter is the *boundary* between business logic and the cloud gateway:
 * it normalizes the phone number to E.164 and returns a typed SmsResult.
 */

require_once __DIR__ . '/ISmsService.php';
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2)); // lib/sms -> project root
}
require_once BASE_PATH . '/lib/SMSGateway.php';

class SemaphoreSmsProvider implements ISmsService
{
    private SMSGateway $gateway;

    /**
     * When true (default), a *connectivity* failure to the live gateway
     * (TLS rejection, timeout, DNS, HTTP 0, cURL error) is transparently
     * downgraded to a SIMULATED delivery instead of a hard failure. This keeps
     * the UI functional and the audit trail intact when the gateway host is
     * temporarily unreachable. Auth / invalid-number errors are NEVER faked.
     */
    private bool $simulationFallback = true;

    public function __construct(?SMSGateway $gateway = null)
    {
        $this->gateway = $gateway ?? SMSGateway::getInstance();
    }

    public function enableSimulationFallback(bool $on): void
    {
        $this->simulationFallback = $on;
    }

    /**
     * Connectivity/transport errors that should trigger the simulation fallback
     * (vs. hard failures like bad credentials or invalid numbers).
     */
    private const CONNECTIVITY_PATTERNS = [
        'http 0', 'http 000', 'curl error', 'curl errno', 'tls', 'ssl',
        'connection', 'timeout', 'timed out', 'could not resolve', 'resolve host',
        'errno 35', 'errno 7', 'network', 'no route', 'reset by peer', 'refused',
    ];

    private function isConnectivityError(string $error): bool
    {
        $e = strtolower($error);
        foreach (self::CONNECTIVITY_PATTERNS as $p) {
            if (str_contains($e, $p)) {
                return true;
            }
        }
        return false;
    }

    public function sendOne(string $to, string $body): SmsResult
    {
        $e164 = $this->gateway->normalizePhoneNumber($to);

        // Empty / invalid number guard
        if ($e164 === '+' || strlen(preg_replace('/\\D/', '', $e164)) < 10) {
            return SmsResult::fail($this->getName(), 'Invalid or missing phone number');
        }

        try {
            $raw = $this->gateway->send($e164, $body);
        } catch (Throwable $e) {
            $err = 'Transport exception: ' . $e->getMessage();
            if ($this->simulationFallback && $this->isConnectivityError($err)) {
                return SmsResult::ok($this->getName(), null, 'SIMULATED_FALLBACK: ' . $err, true);
            }
            return SmsResult::fail($this->getName(), $err);
        }

        if (!empty($raw['success'])) {
            return SmsResult::ok(
                $this->getName(),
                $raw['message_id'] ?? null,
                $raw['response'] ?? null,
                !empty($raw['simulated'])
            );
        }

        $err = $raw['error'] ?? 'Unknown gateway error';

        // Live-mode connectivity failure -> simulated delivery (keeps system usable)
        if ($this->simulationFallback && !$this->gateway->isSimulationMode() && $this->isConnectivityError($err)) {
            return SmsResult::ok($this->getName(), null, 'SIMULATED_FALLBACK: ' . $err, true);
        }

        return SmsResult::fail($this->getName(), $err, $raw['response'] ?? null);
    }

    public function getName(): string
    {
        return 'semaphore';
    }

    public function isSimulation(): bool
    {
        return $this->gateway->isSimulationMode();
    }
}
