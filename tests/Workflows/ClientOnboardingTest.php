<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientOnboardingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testInvitationStoresOnlyTokenAndCodeHashes(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/037_client_onboarding_portal.sql');
        $invite = file_get_contents($this->root . '/src/controllers/client/client_onboarding_invite.php');
        $utility = file_get_contents($this->root . '/src/utils/client_onboarding.php');
        self::assertStringContainsString('token_hash CHAR(64)', (string)$migration);
        self::assertStringContainsString("hash('sha256', \$token)", (string)$invite);
        self::assertStringContainsString('password_hash($code, PASSWORD_DEFAULT)', (string)$utility);
    }

    public function testPortalSupportsStandaloneManualLinksAndReview(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/client/onboarding.php');
        $review = file_get_contents($this->root . '/src/controllers/client/client_onboarding_review.php');
        self::assertStringContainsString('No organization', (string)$view);
        self::assertStringContainsString('Generate Link', (string)$view);
        self::assertStringContainsString("in_array(\$decision, ['approve', 'reject']", (string)$review);
    }

    public function testPublicOnboardingIsRateLimitedAndDoesNotCollectPaymentData(): void
    {
        $submit = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding_submit.php');
        $page = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding.php');
        self::assertStringContainsString("rate_limit_check(\$pdo, 'client_onboarding_submit'", (string)$submit);
        self::assertStringNotContainsString('card_number', (string)$submit . (string)$page);
        self::assertStringNotContainsString('payment_method', (string)$submit . (string)$page);
    }

    public function testReceiptDeliveryHasNoUserToggleOrDuplicateConfirmation(): void
    {
        $notifications = file_get_contents($this->root . '/src/views/pages/settings/notifications.php');
        $payments = file_get_contents($this->root . '/src/controllers/payments_create.php');
        self::assertStringNotContainsString('payment_received_notification', (string)$notifications . (string)$payments);
        self::assertStringContainsString('payment_receipt_issue', (string)$payments);
    }
}
