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

    public function testInvitationStoresTokenHashAndEncryptedRecoveryToken(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $migration = file_get_contents($this->root . '/database/migrations/0020_client_onboarding_recoverable_links.sql');
        $invite = file_get_contents($this->root . '/src/controllers/client/client_onboarding_invite.php');
        $utility = file_get_contents($this->root . '/src/utils/client_onboarding.php');
        self::assertStringContainsString('token_hash CHAR(64)', (string)$baseline);
        self::assertStringContainsString('token_enc TEXT NULL', (string)$baseline);
        self::assertStringContainsString('ADD COLUMN token_enc TEXT NULL', (string)$migration);
        self::assertStringContainsString('created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)', (string)$migration);
        self::assertStringContainsString('organization_id INT NULL', (string)$baseline);
        self::assertStringContainsString("hash('sha256', \$token)", (string)$invite);
        self::assertStringContainsString('client_onboarding_store_token($token)', (string)$invite);
        self::assertStringNotContainsString('client_onboarding_send_code', (string)$utility);
        self::assertStringNotContainsString('password_hash($code, PASSWORD_DEFAULT)', (string)$utility);
    }

    public function testPortalSupportsStandaloneManualLinksAndReview(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/client/onboarding.php');
        $invite = file_get_contents($this->root . '/src/controllers/client/client_onboarding_invite.php');
        $review = file_get_contents($this->root . '/src/controllers/client/client_onboarding_review.php');
        self::assertStringContainsString('No organization', (string)$view);
        self::assertStringContainsString('Generate Link', (string)$view);
        self::assertStringContainsString('Copy Link', (string)$view);
        self::assertStringContainsString('Regenerate Link', (string)$view);
        self::assertStringContainsString('onboarding-link-row', (string)$view);
        self::assertStringContainsString('client_onboarding_link_for_invitation($appConfig, $invitation)', (string)$view);
        self::assertStringContainsString('<option value="336" selected>14 days</option>', (string)$view);
        self::assertStringNotContainsString('Select an organization before creating an invitation.', (string)$invite);
        self::assertStringContainsString('$ownerOrganizationId = $organizationId > 0 ? $organizationId : null', (string)$invite);
        self::assertStringContainsString("regenerate_link", (string)$invite);
        self::assertStringContainsString("status=\"pending\", expires_at=?", (string)$invite);
        self::assertStringContainsString("min(336, (int)(\$_POST['expires_hours'] ?? 336))", (string)$invite);
        self::assertStringContainsString("in_array(\$decision, ['approve', 'reject']", (string)$review);
        self::assertStringContainsString('c.email AS current_client_email', (string)$review);
        self::assertStringContainsString('$emailValue = !empty($submission[\'invited_email\'])', (string)$review);
    }

    public function testPublicOnboardingIsRateLimitedAndDoesNotCollectPaymentData(): void
    {
        $submit = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding_submit.php');
        $page = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding.php');
        self::assertStringContainsString("rate_limit_check(\$pdo, 'client_onboarding_submit'", (string)$submit);
        self::assertStringNotContainsString('card_number', (string)$submit . (string)$page);
        self::assertStringNotContainsString('payment_method', (string)$submit . (string)$page);
    }

    public function testPublicOnboardingLinkOpensFormDirectlyAndConsumesOnSubmit(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $invite = file_get_contents($this->root . '/src/controllers/client/client_onboarding_invite.php');
        $page = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding.php');
        $submit = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding_submit.php');
        $front = file_get_contents($this->root . '/public/index.php');
        $acl = file_get_contents($this->root . '/src/utils/acl_middleware.php');

        self::assertStringContainsString('invited_email VARCHAR(255) NULL', (string)$baseline);
        self::assertStringContainsString('$delivery === \'email\'', (string)$invite);
        self::assertStringContainsString('$email !== \'\' ? $email : null', (string)$invite);
        self::assertStringContainsString('SELECT * FROM client_onboarding_invitations WHERE id=? AND status="pending"', (string)$page);
        self::assertStringContainsString('name="token"', (string)$page);
        self::assertStringNotContainsString('Verify your email', (string)$page);
        self::assertStringNotContainsString('Send Verification Code', (string)$page);
        self::assertStringNotContainsString('client-onboarding-send-code', (string)$front);
        self::assertStringNotContainsString('client-onboarding-verify', (string)$front);
        self::assertStringNotContainsString('client-onboarding-send-code', (string)$acl);
        self::assertStringNotContainsString('client-onboarding-verify', (string)$acl);
        self::assertStringContainsString('client_onboarding_find_invitation($pdo, $token, true)', (string)$submit);
        self::assertStringContainsString('status="submitted", consumed_at=NOW()', (string)$submit);
    }

    public function testReceiptDeliveryHasNoUserToggleOrDuplicateConfirmation(): void
    {
        $notifications = file_get_contents($this->root . '/src/views/pages/settings/notifications.php');
        $payments = file_get_contents($this->root . '/src/controllers/payments_create.php');
        self::assertStringNotContainsString('payment_received_notification', (string)$notifications . (string)$payments);
        self::assertStringContainsString('payment_receipt_issue', (string)$payments);
    }
}
