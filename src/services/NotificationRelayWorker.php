<?php

declare(strict_types=1);

require_once __DIR__ . '/NotificationRelayPolicy.php';
require_once __DIR__ . '/NotificationRelayQueue.php';

final class NotificationRelayWorker
{
    public function __construct(private readonly NotificationRelayQueueContract $queue)
    {
    }

    /**
     * Process a claimed job using an injectable sender. The sender receives the
     * same four arguments as EmailService::sendEmail and must return [bool, string].
     */
    public function process(array $job, array $policy, callable $sender): string
    {
        try {
            $variables = json_decode((string)$job['variables_json'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($variables)) {
                throw new RuntimeException('Stored variables are invalid');
            }
            $request = NotificationRelayPolicy::prepareRequest([
                'action' => (string)$job['action_name'],
                'template' => (string)$job['template_name'],
                'recipient' => (string)$job['recipient_alias'],
                'variables' => $variables,
                // Idempotency is not part of the payload hash; only its digest is retained.
                'idempotency_key' => 'worker:' . substr((string)$job['idempotency_hash'], 0, 32),
            ], $policy);
            if (!hash_equals((string)$job['payload_hash'], (string)$request['payload_hash'])) {
                throw new RuntimeException('Stored payload integrity check failed');
            }
            $message = NotificationRelayPolicy::render($request, $policy);
        } catch (Throwable $error) {
            $this->queue->markPermanentFailure($job, 'policy_rejected');
            return 'failed';
        }

        try {
            $result = $sender(
                $request['recipient_email'],
                $message['subject'],
                $message['body'],
                [
                    'is_html' => $message['is_html'],
                    'require_configured_provider' => true,
                    'allow_transport_fallback' => false,
                    // Leave time for the terminal DB transition before the lease
                    // can be considered stale by another worker.
                    'transport_timeout_seconds' => max(5, (int)$policy['limits']['lease_seconds'] - 10),
                    'message_id' => '<pa-relay-' . (int)$job['id'] . '-'
                        . substr((string)$job['payload_hash'], 0, 24) . '@project-alpha.invalid>',
                ]
            );
            $sent = is_array($result) && ($result[0] ?? false) === true;
        } catch (Throwable $error) {
            $sent = false;
        }

        if ($sent) {
            return $this->queue->markSent($job) ? 'sent' : 'lost_lease';
        }
        return $this->queue->markDeliveryFailure($job, $policy);
    }
}
