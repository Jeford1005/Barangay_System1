<?php
/**
 * AuditLogger - Reusable, decoupled logging service
 */

class AuditLogger
{
    private $pdo;

    private static $sensitiveKeys = [
        'password', 'password_hash', 'pwd', 'secret', 'token',
        'api_key', 'apikey', 'ssn', 'tin', 'gov_id', 'government_id',
        'reset_token', 'reset_code', 'session_id', 'csrf_token',
    ];

    private static $sensitivePatterns = [
        '/\b\d{3}-\d{3}-\d{3}-\d{3}\b/',
        '/\b\d{4}-\d{7}-\d\b/',
        '/\b(?:\+?63|0)9\d{9}\b/',
    ];

    public static function getInstance(): self
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }

    private function __construct(?\PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $this->pdo = $GLOBALS['pdo'];
        }
    }

    public function setPdo(\PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public static function log(
        string $actionType,
        string $module,
        $recordId = null,
        $oldValues = null,
        $newValues = null,
        string $severity = 'INFO',
        ?int $userId = null,
        ?string $userRole = null,
        ?string $description = null
    ) {
        return self::getInstance()->insertLog(
            $actionType, $module, $recordId, $oldValues, $newValues, $severity, $userId, $userRole, $description
        );
    }

    private function insertLog(
        string $actionType,
        string $module,
        $recordId,
        $oldValues,
        $newValues,
        string $severity,
        ?int $userId,
        ?string $userRole,
        ?string $description = null
    ) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        if ($userRole === null) {
            $userRole = $_SESSION['user_role'] ?? null;
        }

        $maskedOld = $this->maskSensitiveData($oldValues);
        $maskedNew = $this->maskSensitiveData($newValues);

        $oldJson = $maskedOld !== null ? json_encode($maskedOld, JSON_UNESCAPED_UNICODE) : null;
        $newJson = $maskedNew !== null ? json_encode($maskedNew, JSON_UNESCAPED_UNICODE) : null;

        $severity = in_array($severity, ['INFO', 'WARN', 'CRITICAL']) ? $severity : 'INFO';
        $actionType = in_array($actionType, ['CREATE', 'READ', 'UPDATE', 'DELETE', 'EXPORT', 'AUTH'])
            ? $actionType : 'READ';

        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        if (!$this->pdo) {
            error_log(sprintf(
                "[AUDIT] %s | user_id=%s | role=%s | %s:%s | %s | desc=%s | old=%s | new=%s | ip=%s",
                date('Y-m-d H:i:s'),
                $userId ?? 'NULL',
                $userRole ?? 'NULL',
                $module,
                $actionType,
                $recordId ?? 'NULL',
                $description ?? 'NULL',
                $oldJson ?? 'NULL',
                $newJson ?? 'NULL',
                $ipAddress ?? 'NULL'
            ));
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs
                    (timestamp, user_id, user_role, action_type, module_name,
                     record_id, old_values, new_values, ip_address, user_agent, severity_level, description)
                VALUES
                    (NOW(), :user_id, :user_role, :action_type, :module_name,
                     :record_id, :old_values, :new_values, :ip_address, :user_agent, :severity_level, :description)
            ");

            $stmt->execute([
                ':user_id'        => $userId,
                ':user_role'      => $userRole,
                ':action_type'    => $actionType,
                ':module_name'    => $module,
                ':record_id'      => $recordId,
                ':old_values'     => $oldJson,
                ':new_values'     => $newJson,
                ':ip_address'     => $ipAddress,
                ':user_agent'     => $userAgent,
                ':severity_level' => $severity,
                ':description'    => $description,
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            error_log('AuditLogger DB error: ' . $e->getMessage());
            return false;
        }
    }

    private function maskSensitiveData($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $masked = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);
            $isSensitive = false;
            foreach (self::$sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive && $value !== null) {
                $masked[$key] = '***MASKED***';
            } elseif (is_array($value)) {
                $masked[$key] = $this->maskSensitiveData($value);
            } else {
                if (is_string($value)) {
                    $masked[$key] = $this->maskSensitivePatterns($value);
                } else {
                    $masked[$key] = $value;
                }
            }
        }
        return $masked;
    }

    private function maskSensitivePatterns(string $value): string
    {
        foreach (self::$sensitivePatterns as $pattern) {
            $value = preg_replace($pattern, '[REDACTED]', $value);
        }
        return $value;
    }

    // Convenience methods
    public static function create(string $module, $recordId = null, $newValues = null, ?int $userId = null, ?string $description = null): void
    {
        self::log('CREATE', $module, $recordId, null, $newValues, 'INFO', $userId, null, $description);
    }

    public static function read(string $module, $recordId = null, ?int $userId = null): void
    {
        self::log('READ', $module, $recordId, null, null, 'INFO', $userId);
    }

    public static function update(string $module, $recordId = null, $oldValues = null, $newValues = null, ?int $userId = null, ?string $description = null): void
    {
        $severity = ($oldValues !== $newValues) ? 'WARN' : 'INFO';
        self::log('UPDATE', $module, $recordId, $oldValues, $newValues, $severity, $userId, null, $description);
    }

    public static function delete(string $module, $recordId = null, $oldValues = null, ?int $userId = null, ?string $description = null): void
    {
        self::log('DELETE', $module, $recordId, $oldValues, null, 'WARN', $userId, null, $description);
    }

    public static function export(string $module, $criteria = null, ?int $userId = null): void
    {
        self::log('EXPORT', $module, null, null, $criteria ?? null, 'WARN', $userId);
    }

    public static function auth(string $action, ?int $userId = null, ?string $userRole = null, string $severity = 'INFO'): void
    {
        self::log('AUTH', 'Auth', null, null, ['event' => $action], $severity, $userId, $userRole);
    }
}

// Backwards-compatible wrapper
if (!function_exists('log_audit')) {
    function log_audit($action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null, $description = null) {
        $actionMap = [
            'login' => ['AUTH', 'Auth', ['event' => 'login'], 'INFO'],
            'logout' => ['AUTH', 'Auth', ['event' => 'logout'], 'INFO'],
            'password_reset' => ['AUTH', 'Auth', ['event' => 'password_reset'], 'INFO'],
            'password_reset_request' => ['AUTH', 'Auth', ['event' => 'password_reset_request'], 'INFO'],
            'role_update' => ['UPDATE', 'Users', null, 'WARN'],
        ];

        if (isset($actionMap[$action])) {
            $mapped = $actionMap[$action];
            AuditLogger::log($mapped[0], $mapped[1], $entityId, $oldValues, $newValues ?? $mapped[2], $mapped[3], null, null, $description);
        } else {
            AuditLogger::log('READ', $entityType ?? 'System', $entityId, $oldValues, $newValues, 'INFO', null, null, $description);
        }
    }
}
