<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/utils/document_recipient.php';

final class DocumentRecipientPresentationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testIndividualClientRemainsTheRenderedRecipient(): void
    {
        $recipient = pa_document_recipient([
            'client_name' => 'Kevin Smith',
            'client_address_line1' => '123 Example Street',
            'client_city' => 'Green Bay',
            'client_state' => 'WI',
            'client_postal_code' => '54301',
            'client_email' => 'kevin@example.test',
        ]);

        self::assertSame(['Kevin Smith', '123 Example Street', 'Green Bay, WI 54301'], $recipient['lines']);
        self::assertSame('kevin@example.test', $recipient['email']);
        self::assertFalse($recipient['organization_addressed']);
    }

    public function testOrganizationHidesContactByDefaultAndUsesOrganizationAddress(): void
    {
        $recipient = pa_document_recipient($this->organizationDocument());

        self::assertSame(['Company ABC', '456 Company Avenue', 'Appleton, WI 54911'], $recipient['lines']);
        self::assertNull($recipient['email']);
        self::assertNull($recipient['phone']);
        self::assertTrue($recipient['organization_addressed']);
        self::assertFalse($recipient['contact_included']);
    }

    public function testOrganizationCanIncludeContactAsAttentionLine(): void
    {
        $document = $this->organizationDocument();
        $document['show_contact_on_document'] = 1;
        $recipient = pa_document_recipient($document);

        self::assertSame(
            ['Company ABC', 'Attn: Kevin Smith', '456 Company Avenue', 'Appleton, WI 54911'],
            $recipient['lines']
        );
        self::assertSame('kevin@example.test', $recipient['email']);
        self::assertTrue($recipient['contact_included']);
    }

    public function testLegacyOrganizationFallsBackToClientAddressWithoutShowingContact(): void
    {
        $document = $this->organizationDocument();
        unset($document['organization_id']);
        foreach (['organization_address_line1', 'organization_city', 'organization_state', 'organization_postal_code'] as $key) {
            $document[$key] = null;
        }

        $recipient = pa_document_recipient($document);
        self::assertSame(['Company ABC', '123 Example Street', 'Green Bay, WI 54301'], $recipient['lines']);
        self::assertNotContains('Kevin Smith', $recipient['lines']);
    }

    public function testGeneralRecipientModeStillSuppressesAllStoredIdentity(): void
    {
        $recipient = pa_document_recipient($this->organizationDocument(), true);
        self::assertSame(['General Recipient'], $recipient['lines']);
        self::assertNull($recipient['email']);
        self::assertNull($recipient['phone']);
    }

    public function testPresentationFlagPropagatesWithoutReplacingClientOwnership(): void
    {
        foreach ([
            'src/controllers/quote/quote_approve.php',
            'src/controllers/public_view/public_quote_action.php',
            'src/controllers/contract/contracts_create.php',
            'src/controllers/contract/on_demand_invoice_generate.php',
            'src/utils/recurring_billing.php',
        ] as $path) {
            $source = (string) file_get_contents($this->root . '/' . $path);
            self::assertStringContainsString('client_id', $source, $path);
            self::assertStringContainsString('organization_id', $source, $path);
            self::assertStringContainsString('show_contact_on_document', $source, $path);
        }
    }

    public function testRevisionSnapshotsPreferOrganizationBillingAddress(): void
    {
        $source = (string) file_get_contents($this->root . '/src/services/DocumentRevisionService.php');
        self::assertStringContainsString("address_book_default_for_entity(\$pdo, 'organization', \$organizationId, 'billing')", $source);
        self::assertStringContainsString("address_book_default_for_entity(\$pdo, 'client'", $source);
    }

    private function organizationDocument(): array
    {
        return [
            'organization_id' => 7,
            'organization_name' => 'Company ABC',
            'organization_address_line1' => '456 Company Avenue',
            'organization_city' => 'Appleton',
            'organization_state' => 'WI',
            'organization_postal_code' => '54911',
            'client_name' => 'Kevin Smith',
            'client_address_line1' => '123 Example Street',
            'client_city' => 'Green Bay',
            'client_state' => 'WI',
            'client_postal_code' => '54301',
            'client_email' => 'kevin@example.test',
            'client_phone' => '9205550100',
        ];
    }
}
