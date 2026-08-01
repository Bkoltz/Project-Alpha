<?php

declare(strict_types=1);

final class NotificationRelayRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly string $reason = 'invalid_request'
    ) {
        parent::__construct($message);
    }
}

/**
 * Loads the operator-owned relay policy and turns narrow request data into a
 * fixed, server-owned message. Callers can select only configured aliases,
 * actions and templates; they cannot supply message headers or content.
 */
final class NotificationRelayPolicy
{
    public const DEFAULT_CONFIG_PATH = '/var/www/config/notification-relay.json';
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
    private const VARIABLE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    public static function isEnabled(): bool
    {
        $value = getenv('NOTIFICATION_RELAY_ENABLED');
        return $value !== false && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function load(?string $path = null): array
    {
        $path = $path ?? (getenv('NOTIFICATION_RELAY_CONFIG_PATH') ?: self::DEFAULT_CONFIG_PATH);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Notification relay policy is unavailable');
        }
        $size = filesize($path);
        if ($size === false || $size < 2 || $size > 262144) {
            throw new RuntimeException('Notification relay policy has an invalid size');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Notification relay policy could not be read');
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Notification relay policy is not valid JSON', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Notification relay policy must be a JSON object');
        }
        return self::validate($decoded);
    }

    public static function validate(array $policy): array
    {
        self::assertOnlyKeys($policy, ['version', 'recipients', 'templates', 'actions', 'limits'], 'policy');
        if (($policy['version'] ?? null) !== 1) {
            throw new RuntimeException('Notification relay policy version must be 1');
        }

        $recipients = [];
        foreach (self::objectMap($policy['recipients'] ?? null, 'recipients') as $alias => $email) {
            self::assertName((string)$alias, 'Recipient alias');
            if (!is_string($email)) {
                throw new RuntimeException("Recipient alias {$alias} must map to one email address");
            }
            $normalized = strtolower(trim($email));
            if (strlen($normalized) > 254 || !filter_var($normalized, FILTER_VALIDATE_EMAIL)
                || preg_match('/[\r\n]/', $normalized)) {
                throw new RuntimeException("Recipient alias {$alias} has an invalid email address");
            }
            $recipients[(string)$alias] = $normalized;
        }
        if ($recipients === []) {
            throw new RuntimeException('Notification relay policy must define at least one recipient alias');
        }

        $templates = [];
        foreach (self::objectMap($policy['templates'] ?? null, 'templates') as $name => $definition) {
            self::assertName((string)$name, 'Template name');
            if (!is_array($definition) || array_is_list($definition)) {
                throw new RuntimeException("Template {$name} must be an object");
            }
            self::assertOnlyKeys(
                $definition,
                ['subject', 'html', 'text', 'required_variables', 'optional_variables'],
                "template {$name}"
            );
            $subject = $definition['subject'] ?? null;
            if (!is_string($subject) || $subject === '' || strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) {
                throw new RuntimeException("Template {$name} has an invalid subject");
            }
            $hasHtml = isset($definition['html']);
            $hasText = isset($definition['text']);
            if ($hasHtml === $hasText) {
                throw new RuntimeException("Template {$name} must define exactly one of html or text");
            }
            $body = $hasHtml ? $definition['html'] : $definition['text'];
            if (!is_string($body) || $body === '' || strlen($body) > 100000) {
                throw new RuntimeException("Template {$name} has an invalid body");
            }

            $required = self::variableList($definition['required_variables'] ?? [], "Template {$name} required_variables");
            $optional = self::variableList($definition['optional_variables'] ?? [], "Template {$name} optional_variables");
            if (array_intersect($required, $optional)) {
                throw new RuntimeException("Template {$name} declares a variable as both required and optional");
            }
            $declared = array_fill_keys(array_merge($required, $optional), true);
            preg_match_all('/{{\s*([A-Za-z][A-Za-z0-9_]*)\s*}}/', $subject . "\n" . $body, $matches);
            foreach (array_unique($matches[1] ?? []) as $placeholder) {
                if (!isset($declared[$placeholder])) {
                    throw new RuntimeException("Template {$name} uses undeclared variable {$placeholder}");
                }
            }
            if (preg_match('/{{{?|}}}?/', preg_replace('/{{\s*[A-Za-z][A-Za-z0-9_]*\s*}}/', '', $subject . $body))) {
                throw new RuntimeException("Template {$name} contains an invalid placeholder");
            }
            $templates[(string)$name] = [
                'subject' => $subject,
                'body' => $body,
                'is_html' => $hasHtml,
                'required_variables' => $required,
                'optional_variables' => $optional,
            ];
        }
        if ($templates === []) {
            throw new RuntimeException('Notification relay policy must define at least one template');
        }

        $actions = [];
        foreach (self::objectMap($policy['actions'] ?? null, 'actions') as $name => $definition) {
            self::assertName((string)$name, 'Action name');
            if (!is_array($definition) || array_is_list($definition)) {
                throw new RuntimeException("Action {$name} must be an object");
            }
            self::assertOnlyKeys($definition, ['templates', 'recipients'], "action {$name}");
            $actionTemplates = self::nameList($definition['templates'] ?? null, "Action {$name} templates");
            $actionRecipients = self::nameList($definition['recipients'] ?? null, "Action {$name} recipients");
            foreach ($actionTemplates as $template) {
                if (!isset($templates[$template])) {
                    throw new RuntimeException("Action {$name} references unknown template {$template}");
                }
            }
            foreach ($actionRecipients as $recipient) {
                if (!isset($recipients[$recipient])) {
                    throw new RuntimeException("Action {$name} references unknown recipient {$recipient}");
                }
            }
            $actions[(string)$name] = [
                'templates' => $actionTemplates,
                'recipients' => $actionRecipients,
            ];
        }
        if ($actions === []) {
            throw new RuntimeException('Notification relay policy must define at least one action');
        }

        $limits = is_array($policy['limits'] ?? null) ? $policy['limits'] : [];
        self::assertOnlyKeys($limits, [
            'per_key_per_minute',
            'per_key_recipient_per_hour',
            'max_active_per_key',
            'worker_batch_size',
            'lease_seconds',
            'retry_delays_seconds',
            'payload_retention_days',
            'event_retention_days',
        ], 'limits');
        $retryDelays = $limits['retry_delays_seconds'] ?? [60, 300, 900, 3600];
        if (!is_array($retryDelays) || $retryDelays === [] || count($retryDelays) > 9) {
            throw new RuntimeException('limits.retry_delays_seconds must contain one to nine delays');
        }
        $retryDelays = array_map(static function ($delay): int {
            if (!is_int($delay) || $delay < 1 || $delay > 86400) {
                throw new RuntimeException('Each retry delay must be an integer from 1 through 86400');
            }
            return $delay;
        }, array_values($retryDelays));

        return [
            'version' => 1,
            'recipients' => $recipients,
            'templates' => $templates,
            'actions' => $actions,
            'limits' => [
                'per_key_per_minute' => self::boundedInt($limits, 'per_key_per_minute', 30, 1, 600),
                'per_key_recipient_per_hour' => self::boundedInt($limits, 'per_key_recipient_per_hour', 10, 1, 1000),
                'max_active_per_key' => self::boundedInt($limits, 'max_active_per_key', 100, 1, 10000),
                'worker_batch_size' => self::boundedInt($limits, 'worker_batch_size', 25, 1, 250),
                'lease_seconds' => self::boundedInt($limits, 'lease_seconds', 300, 30, 3600),
                'payload_retention_days' => self::boundedInt($limits, 'payload_retention_days', 30, 1, 365),
                'event_retention_days' => self::boundedInt($limits, 'event_retention_days', 365, 30, 2555),
                'retry_delays_seconds' => $retryDelays,
                'max_attempts' => count($retryDelays) + 1,
            ],
        ];
    }

    public static function prepareRequest(array $payload, array $policy): array
    {
        $allowedKeys = ['action', 'template', 'recipient', 'variables', 'idempotency_key'];
        $unknown = array_diff(array_keys($payload), $allowedKeys);
        if ($unknown !== []) {
            throw new NotificationRelayRequestException('Unknown request field: ' . (string)reset($unknown), 400, 'unknown_field');
        }
        foreach (['action', 'template', 'recipient', 'idempotency_key'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                throw new NotificationRelayRequestException("{$field} is required and must be a string", 400, 'invalid_request');
            }
        }
        $action = trim($payload['action']);
        $template = trim($payload['template']);
        $recipientAlias = trim($payload['recipient']);
        $idempotencyKey = trim($payload['idempotency_key']);
        if (!preg_match(self::NAME_PATTERN, $action) || !isset($policy['actions'][$action])) {
            throw new NotificationRelayRequestException('Action is not allowed', 422, 'action_not_allowed');
        }
        if (!preg_match(self::NAME_PATTERN, $template)
            || !in_array($template, $policy['actions'][$action]['templates'], true)) {
            throw new NotificationRelayRequestException('Template is not allowed for this action', 422, 'template_not_allowed');
        }
        if (!preg_match(self::NAME_PATTERN, $recipientAlias)
            || !in_array($recipientAlias, $policy['actions'][$action]['recipients'], true)) {
            throw new NotificationRelayRequestException('Recipient is not allowed for this action', 422, 'recipient_not_allowed');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $idempotencyKey)) {
            throw new NotificationRelayRequestException('idempotency_key must be 8-128 safe characters', 400, 'invalid_idempotency_key');
        }
        $variables = $payload['variables'] ?? [];
        if (!is_array($variables) || array_is_list($variables) && $variables !== []) {
            throw new NotificationRelayRequestException('variables must be a JSON object', 400, 'invalid_variables');
        }
        $templateDefinition = $policy['templates'][$template];
        $allowedVariables = array_fill_keys(array_merge(
            $templateDefinition['required_variables'],
            $templateDefinition['optional_variables']
        ), true);
        $normalizedVariables = [];
        foreach ($variables as $name => $value) {
            if (!is_string($name) || !isset($allowedVariables[$name])) {
                throw new NotificationRelayRequestException("Variable {$name} is not allowed", 422, 'variable_not_allowed');
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new NotificationRelayRequestException("Variable {$name} must be scalar", 400, 'invalid_variable');
            }
            $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            if (strlen($stringValue) > 2048 || str_contains($stringValue, "\0")) {
                throw new NotificationRelayRequestException("Variable {$name} is too long or invalid", 413, 'variable_too_large');
            }
            $normalizedVariables[$name] = $stringValue;
        }
        foreach ($templateDefinition['required_variables'] as $required) {
            if (!array_key_exists($required, $normalizedVariables) || $normalizedVariables[$required] === '') {
                throw new NotificationRelayRequestException("Required variable {$required} is missing", 422, 'missing_variable');
            }
        }
        ksort($normalizedVariables, SORT_STRING);
        $variablesJson = json_encode($normalizedVariables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($variablesJson) > 16384) {
            throw new NotificationRelayRequestException('variables exceed the request limit', 413, 'variables_too_large');
        }
        $canonical = json_encode([
            'action' => $action,
            'template' => $template,
            'recipient' => $recipientAlias,
            'recipient_email' => $policy['recipients'][$recipientAlias],
            'variables' => $normalizedVariables,
            'template_fingerprint' => hash('sha256', json_encode($templateDefinition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'action' => $action,
            'template' => $template,
            'recipient_alias' => $recipientAlias,
            'recipient_email' => $policy['recipients'][$recipientAlias],
            'variables' => $normalizedVariables,
            'variables_json' => $variablesJson,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => hash('sha256', $canonical),
        ];
    }

    public static function render(array $request, array $policy): array
    {
        $definition = $policy['templates'][$request['template']] ?? null;
        if (!is_array($definition)) {
            throw new NotificationRelayRequestException('Template is no longer available', 422, 'template_not_allowed');
        }
        $replace = static function (string $source, bool $escapeHtml) use ($request): string {
            return (string)preg_replace_callback(
                '/{{\s*([A-Za-z][A-Za-z0-9_]*)\s*}}/',
                static function (array $match) use ($request, $escapeHtml): string {
                    $value = (string)($request['variables'][$match[1]] ?? '');
                    return $escapeHtml
                        ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        : $value;
                },
                $source
            );
        };
        $subject = $replace($definition['subject'], false);
        $subject = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
        if ($subject === '' || strlen($subject) > 255) {
            throw new NotificationRelayRequestException('Rendered subject is invalid', 422, 'render_failed');
        }
        $body = $replace($definition['body'], (bool)$definition['is_html']);
        if ($body === '' || strlen($body) > 120000) {
            throw new NotificationRelayRequestException('Rendered body is invalid', 422, 'render_failed');
        }
        return ['subject' => $subject, 'body' => $body, 'is_html' => (bool)$definition['is_html']];
    }

    private static function objectMap(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException("Notification relay {$label} must be a JSON object");
        }
        return $value;
    }

    private static function variableList(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) {
            throw new RuntimeException("{$label} must be an array of at most 32 names");
        }
        $result = [];
        foreach ($value as $name) {
            if (!is_string($name) || !preg_match(self::VARIABLE_PATTERN, $name)) {
                throw new RuntimeException("{$label} contains an invalid name");
            }
            $result[$name] = true;
        }
        return array_keys($result);
    }

    private static function nameList(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 64) {
            throw new RuntimeException("{$label} must be a non-empty array of at most 64 names");
        }
        $result = [];
        foreach ($value as $name) {
            if (!is_string($name)) {
                throw new RuntimeException("{$label} contains an invalid name");
            }
            self::assertName($name, $label);
            $result[$name] = true;
        }
        return array_keys($result);
    }

    private static function assertName(string $name, string $label): void
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new RuntimeException("{$label} contains an invalid name");
        }
    }

    private static function boundedInt(array $source, string $key, int $default, int $min, int $max): int
    {
        $value = $source[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new RuntimeException("limits.{$key} must be an integer from {$min} through {$max}");
        }
        return $value;
    }

    private static function assertOnlyKeys(array $source, array $allowed, string $label): void
    {
        $unknown = array_diff(array_keys($source), $allowed);
        if ($unknown !== []) {
            throw new RuntimeException("Notification relay {$label} contains unknown field " . (string)reset($unknown));
        }
    }
}
