<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/services/NotificationRelayPolicy.php';
require_once dirname(__DIR__, 2) . '/src/services/NotificationRelayQueue.php';
require_once dirname(__DIR__, 2) . '/src/services/NotificationRelayWorker.php';
require_once dirname(__DIR__, 2) . '/src/services/EmailProviderManager.php';
require_once dirname(__DIR__, 2) . '/src/utils/api_scopes.php';
require_once dirname(__DIR__, 2) . '/src/utils/api_ip_allowlist.php';

use PHPUnit\Framework\TestCase;

final class NotificationRelayTest extends TestCase
{
    private array $policy;

    protected function setUp(): void
    {
        $this->policy = NotificationRelayPolicy::validate([
            'version' => 1,
            'recipients' => [
                'operations' => 'operations@example.invalid',
                'security' => 'security@example.invalid',
            ],
            'templates' => [
                'event-summary' => [
                    'subject' => 'Service event {{reference}}',
                    'html' => '<p><strong>{{reference}}</strong>: {{summary}}</p><p>{{optional_note}}</p>',
                    'required_variables' => ['reference', 'summary'],
                    'optional_variables' => ['optional_note'],
                ],
            ],
            'actions' => [
                'service.event' => [
                    'templates' => ['event-summary'],
                    'recipients' => ['operations'],
                ],
            ],
            'limits' => [
                'per_key_per_minute' => 5,
                'per_key_recipient_per_hour' => 10,
                'max_active_per_key' => 20,
                'worker_batch_size' => 5,
                'lease_seconds' => 60,
                'retry_delays_seconds' => [10, 30],
            ],
        ]);
    }

    public function testRelayIsDisabledUnlessExplicitlyEnabled(): void
    {
        $previous = getenv('NOTIFICATION_RELAY_ENABLED');
        putenv('NOTIFICATION_RELAY_ENABLED');
        self::assertFalse(NotificationRelayPolicy::isEnabled());
        putenv('NOTIFICATION_RELAY_ENABLED=true');
        self::assertTrue(NotificationRelayPolicy::isEnabled());
        $previous === false
            ? putenv('NOTIFICATION_RELAY_ENABLED')
            : putenv('NOTIFICATION_RELAY_ENABLED=' . $previous);
    }

    public function testAllowlistedRequestRendersFixedTemplateAndEscapesHtmlVariables(): void
    {
        $request = $this->request([
            'reference' => "ABC\r\nBcc: injected@example.invalid",
            'summary' => '<script>alert(1)</script>',
        ]);
        $message = NotificationRelayPolicy::render($request, $this->policy);

        self::assertSame('operations@example.invalid', $request['recipient_email']);
        self::assertStringNotContainsString("\r", $message['subject']);
        self::assertStringNotContainsString("\n", $message['subject']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $message['body']);
        self::assertStringNotContainsString('<script>', $message['body']);
        self::assertTrue($message['is_html']);
    }

    public function testCallerCannotSupplyMessageContentOrHeaders(): void
    {
        foreach (['subject', 'html', 'from', 'reply_to', 'attachments', 'cc', 'url'] as $field) {
            try {
                NotificationRelayPolicy::prepareRequest($this->payload() + [$field => 'forbidden'], $this->policy);
                self::fail("Expected {$field} to be rejected");
            } catch (NotificationRelayRequestException $error) {
                self::assertSame('unknown_field', $error->reason);
            }
        }
    }

    public function testActionTemplateRecipientAndVariablesAreStrictlyAllowlisted(): void
    {
        $cases = [
            ['action' => 'other.event', 'reason' => 'action_not_allowed'],
            ['template' => 'other-template', 'reason' => 'template_not_allowed'],
            ['recipient' => 'security', 'reason' => 'recipient_not_allowed'],
            ['variables' => ['reference' => 'ABC', 'summary' => 'ok', 'extra' => 'no'], 'reason' => 'variable_not_allowed'],
        ];
        foreach ($cases as $case) {
            $reason = $case['reason'];
            unset($case['reason']);
            try {
                NotificationRelayPolicy::prepareRequest(array_replace($this->payload(), $case), $this->policy);
                self::fail("Expected {$reason}");
            } catch (NotificationRelayRequestException $error) {
                self::assertSame($reason, $error->reason);
            }
        }
    }

    public function testCanonicalPayloadHashIgnoresVariableOrderButDetectsContentChanges(): void
    {
        $one = $this->request(['summary' => 'ok', 'reference' => 'ABC']);
        $two = $this->request(['reference' => 'ABC', 'summary' => 'ok']);
        $changed = $this->request(['reference' => 'ABC', 'summary' => 'changed']);

        self::assertSame($one['payload_hash'], $two['payload_hash']);
        self::assertNotSame($one['payload_hash'], $changed['payload_hash']);
    }

    public function testFullScopeDoesNotGrantSensitiveRelayScopeWhenExplicitScopeIsRequired(): void
    {
        self::assertFalse(api_key_has_scope('full', 'notifications.enqueue', false));
        self::assertTrue(api_key_has_scope('notifications.enqueue', 'notifications.enqueue', false));
        self::assertTrue(api_key_has_scope('full', 'invoices.read'));
    }

    public function testRelayIpAllowlistRequiresValidExactAddresses(): void
    {
        self::assertSame([], api_parse_exact_ip_allowlist(''));
        self::assertSame([], api_parse_exact_ip_allowlist(', ,'));
        self::assertSame([], api_parse_exact_ip_allowlist('192.0.2.10,not-an-ip'));
        self::assertSame(['192.0.2.10', '2001:db8::10'], api_parse_exact_ip_allowlist('192.0.2.10, 2001:db8::10'));
        self::assertTrue(api_ip_matches_exact_allowlist('2001:0db8:0:0:0:0:0:10', ['2001:db8::10']));
        self::assertFalse(api_ip_matches_exact_allowlist('192.0.2.11', ['192.0.2.10']));
    }

    public function testPolicyTyposFailClosed(): void
    {
        $this->expectException(RuntimeException::class);
        NotificationRelayPolicy::validate([
            'version' => 1,
            'recipients' => ['operations' => 'operations@example.invalid'],
            'templates' => [
                'event' => [
                    'subject' => 'Event',
                    'text' => 'Body',
                    'required_variables' => [],
                    'optional_variables' => [],
                ],
            ],
            'actions' => [
                'service.event' => ['templates' => ['event'], 'recipients' => ['operations']],
            ],
            'limits' => ['per_key_per_mintue' => 1],
        ]);
    }

    public function testWorkerUsesConfiguredProviderOnlyAndMarksSuccess(): void
    {
        $request = $this->request(['reference' => 'ABC', 'summary' => 'ok']);
        $job = $this->job($request);
        $queue = new class implements NotificationRelayQueueContract {
            public array $calls = [];
            public function markSent(array $job): bool { $this->calls[] = 'sent'; return true; }
            public function markPermanentFailure(array $job, string $errorCode): bool { $this->calls[] = $errorCode; return true; }
            public function markDeliveryFailure(array $job, array $policy): string { $this->calls[] = 'retry'; return 'retry'; }
        };
        $sender = static function (string $to, string $subject, string $body, array $options): array {
            self::assertSame('operations@example.invalid', $to);
            self::assertSame('notification', $options['document_type']);
            self::assertStringStartsWith('notification-relay:', $options['message_key']);
            return [true, ''];
        };

        $outcome = (new NotificationRelayWorker($queue))->process($job, $this->policy, $sender);

        self::assertSame('sent', $outcome);
        self::assertSame(['sent'], $queue->calls);
    }

    public function testWorkerRetriesRedactedDeliveryFailureAndDeadLettersPolicyFailure(): void
    {
        $request = $this->request(['reference' => 'ABC', 'summary' => 'ok']);
        $queue = new class implements NotificationRelayQueueContract {
            public array $calls = [];
            public function markSent(array $job): bool { $this->calls[] = 'sent'; return true; }
            public function markPermanentFailure(array $job, string $errorCode): bool { $this->calls[] = $errorCode; return true; }
            public function markDeliveryFailure(array $job, array $policy): string { $this->calls[] = 'delivery_failed'; return 'retry'; }
        };
        $worker = new NotificationRelayWorker($queue);

        self::assertSame('retry', $worker->process($this->job($request), $this->policy, static fn(): array => [false, 'raw provider detail']));
        $invalidJob = $this->job($request);
        $invalidJob['payload_hash'] = str_repeat('0', 64);
        self::assertSame('failed', $worker->process($invalidJob, $this->policy, static fn(): array => [true, '']));
        self::assertSame(['delivery_failed', 'policy_rejected'], $queue->calls);
    }

    public function testRouteSchemaAndWorkerRemainInternalDurableAndAudited(): void
    {
        $root = dirname(__DIR__, 2);
        $front = (string)file_get_contents($root . '/public/index.php');
        $internalEndpoint = (string)file_get_contents($root . '/src/internal/notification_relay_endpoint.php');
        $apiAuth = (string)file_get_contents($root . '/src/utils/api_auth.php');
        $queue = (string)file_get_contents($root . '/src/services/NotificationRelayQueue.php');
        $baseline = (string)file_get_contents($root . '/database/baseline.sql');
        $migration = (string)file_get_contents($root . '/database/migrations/0063_notification_relay.sql');
        $cron = (string)file_get_contents($root . '/cron/crontab');

        self::assertStringNotContainsString('api-notification-relay', $front);
        self::assertStringContainsString("define('PA_STATELESS_API_NO_SESSION', true)", $internalEndpoint);
        self::assertStringContainsString("api_require_key(['notifications.enqueue'], false)", $internalEndpoint);
        self::assertStringContainsString('outside the public document root', $internalEndpoint);
        self::assertStringContainsString("!defined('PA_STATELESS_API_NO_SESSION')", $apiAuth);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $queue);
        self::assertStringContainsString('$atAttemptCap', $queue);
        self::assertStringContainsString('notification_relay_events', $queue);
        self::assertStringContainsString('notification_relay_queue', $baseline);
        self::assertStringContainsString('notification_relay_rate_buckets', $migration);
        self::assertStringContainsString('process_notification_relay.php', $cron);
    }

    public function testWorkerUsesStableDeliveryReservationKey(): void
    {
        $request = $this->request(['reference' => 'ABC', 'summary' => 'ok']);
        $job = $this->job($request);
        $queue = new class implements NotificationRelayQueueContract {
            public function markSent(array $job): bool { return true; }
            public function markPermanentFailure(array $job, string $errorCode): bool { return true; }
            public function markDeliveryFailure(array $job, array $policy): string { return 'retry'; }
        };
        $captured = [];

        (new NotificationRelayWorker($queue))->process(
            $job,
            $this->policy,
            static function (string $to, string $subject, string $body, array $options) use (&$captured): array {
                $captured = $options;
                return [true, ''];
            }
        );

        self::assertSame('notification', $captured['document_type']);
        self::assertSame(
            'notification-relay:1:' . substr($request['payload_hash'], 0, 32),
            $captured['message_key']
        );
    }

    public function testFreshProviderReservationIsInProgressNotAlreadySent(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is unavailable');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(
            'CREATE TABLE email_delivery_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_key TEXT NOT NULL UNIQUE,
                provider_connection_id INTEGER NOT NULL,
                document_type TEXT NOT NULL,
                document_id INTEGER NULL,
                document_revision INTEGER NULL,
                recipient TEXT NOT NULL,
                subject TEXT NOT NULL,
                status TEXT NOT NULL,
                error_message TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $manager = new EmailProviderManager($pdo, []);
        $method = new ReflectionMethod($manager, 'reserveDelivery');
        $message = new EmailMessage('client@example.invalid', 'Subject', 'Body', 'sender@example.invalid');

        $first = $method->invoke($manager, 'relay:test', 1, $message, ['document_type' => 'notification']);
        $fresh = $pdo->prepare('UPDATE email_delivery_log SET updated_at=? WHERE message_key=?');
        $fresh->execute([date('Y-m-d H:i:s'), 'relay:test']);
        $second = $method->invoke($manager, 'relay:test', 1, $message, ['document_type' => 'notification']);
        $pdo->exec("UPDATE email_delivery_log SET status='sent' WHERE message_key='relay:test'");
        $third = $method->invoke($manager, 'relay:test', 1, $message, ['document_type' => 'notification']);

        self::assertSame('reserved', $first['status']);
        self::assertSame('in_progress', $second['status']);
        self::assertSame('sent', $third['status']);
    }

    private function payload(): array
    {
        return [
            'action' => 'service.event',
            'template' => 'event-summary',
            'recipient' => 'operations',
            'variables' => ['reference' => 'ABC', 'summary' => 'ok'],
            'idempotency_key' => 'event:ABC:0001',
        ];
    }

    private function request(array $variables): array
    {
        return NotificationRelayPolicy::prepareRequest(
            array_replace($this->payload(), ['variables' => $variables]),
            $this->policy
        );
    }

    private function job(array $request): array
    {
        return [
            'id' => 1,
            'api_key_id' => 1,
            'action_name' => $request['action'],
            'template_name' => $request['template'],
            'recipient_alias' => $request['recipient_alias'],
            'recipient_hash' => hash('sha256', $request['recipient_email']),
            'variables_json' => $request['variables_json'],
            'idempotency_hash' => hash('sha256', $request['idempotency_key']),
            'payload_hash' => $request['payload_hash'],
            'attempt_count' => 1,
            'lock_token' => str_repeat('a', 32),
        ];
    }
}
