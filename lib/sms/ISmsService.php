<?php
/**
 * Cloud SMS Integration Module — Service Contracts
 *
 * Adapter / Repository pattern. Business logic (triggers, controllers) depends
 * only on ISmsService, never on a concrete gateway. The transport implementation
 * (Semaphore, ItexMo, Twilio, Simulation) is swappable.
 */

/**
 * A single outbound SMS payload, normalized and ready for transport.
 */
final class SmsMessage
{
    public function __construct(
        public string $recipient,   // E.164, e.g. +63917xxxxxxx
        public string $body,         // sanitized plain text (single segment or one of multiple)
        public string $category,     // Document | Summons | Broadcast | Custom
        public ?int $referenceId = null,   // doc_request id, blotter_case id, broadcast id
        public ?string $recipientName = null
    ) {}
}

/**
 * Result returned by a transport after attempting delivery of ONE segment.
 */
final class SmsResult
{
    public function __construct(
        public readonly bool $success,
        public ?string $messageId = null,
        public ?string $gatewayResponse = null,
        public ?string $error = null,
        public ?string $gateway = null,
        public readonly bool $simulated = false
    ) {}

    public static function ok(string $gateway, ?string $messageId, ?string $resp, bool $simulated = false): self
    {
        return new self(true, $messageId, $resp, null, $gateway, $simulated);
    }

    public static function fail(string $gateway, string $error, ?string $resp = null): self
    {
        return new self(false, null, $resp, $error, $gateway);
    }
}

/**
 * Transport contract. Any cloud provider (Semaphore, ItexMo, Twilio) or the
 * simulation adapter implements this so the orchestrator stays provider-agnostic.
 */
interface ISmsService
{
    /**
     * Send one already-normalized message body to one E.164 number.
     * Must NOT do segmentation — the orchestrator splits long messages.
     */
    public function sendOne(string $to, string $body): SmsResult;

    /** Human-readable gateway name, e.g. 'semaphore'. */
    public function getName(): string;

    /** True when running without real credentials (demo / offline). */
    public function isSimulation(): bool;
}
