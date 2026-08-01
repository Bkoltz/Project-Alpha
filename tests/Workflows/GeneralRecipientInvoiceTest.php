<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/utils/general_recipient_invoices.php';

use PHPUnit\Framework\TestCase;

final class GeneralRecipientInvoiceTest extends TestCase
{
    public function testGeneralRecipientIsAnExplicitPresentationModeOnly(): void
    {
        self::assertTrue(pa_invoice_is_general_recipient(['recipient_presentation_mode' => 'general']));
        self::assertFalse(pa_invoice_is_general_recipient(['recipient_presentation_mode' => 'named']));
        self::assertFalse(pa_invoice_is_general_recipient([]));
        self::assertTrue(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'regular', 'collection_mode' => 'direct',
        ]));
        self::assertFalse(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'long_term', 'collection_mode' => 'direct',
        ]));
        self::assertFalse(pa_general_recipient_invoice_is_eligible([
            'recipient_presentation_mode' => 'general', 'invoice_type' => 'regular', 'collection_mode' => 'project_aggregate',
        ]));
    }

    public function testPaidGeneralRecipientReceiptExpiresSevenDaysAfterRecordedPayment(): void
    {
        $paidAt = '2026-07-01 12:00:00';
        self::assertTrue(pa_general_recipient_public_receipt_window_open([
            'recipient_presentation_mode' => 'general', 'status' => 'paid', 'paid_at' => $paidAt,
        ], strtotime('2026-07-08 11:59:59')));
        self::assertFalse(pa_general_recipient_public_receipt_window_open([
            'recipient_presentation_mode' => 'general', 'status' => 'paid', 'paid_at' => $paidAt,
        ], strtotime('2026-07-08 12:00:00')));
        self::assertFalse(pa_general_recipient_public_receipt_window_open([
            'recipient_presentation_mode' => 'general', 'status' => 'unpaid', 'paid_at' => $paidAt,
        ]));
    }

    public function testIntegrationKeepsAccountingClientAndBlocksUnsafeAutomation(): void
    {
        $root = dirname(__DIR__, 2);
        $create = (string)file_get_contents($root . '/src/controllers/invoice/invoices_create.php');
        $lifecycle = (string)file_get_contents($root . '/src/utils/invoice_lifecycle.php');
        $reminders = (string)file_get_contents($root . '/src/utils/invoice_notifications.php');
        $mail = (string)file_get_contents($root . '/src/controllers/email_send.php');
        $public = (string)file_get_contents($root . '/src/controllers/public_view/public_doc.php');
        $links = (string)file_get_contents($root . '/src/utils/public_links.php');
        $pdf = (string)file_get_contents($root . '/src/views/pages/invoice/invoice-details.php');

        self::assertStringContainsString('(client_id,recipient_presentation_mode,project_id', $create);
        self::assertStringContainsString('General-recipient invoices must be one-off direct invoices', $create);
        self::assertStringContainsString('General-recipient invoices cannot be emailed automatically', $create);
        self::assertStringContainsString('pa_invoice_is_general_recipient($invoice)', $lifecycle);
        self::assertStringContainsString('recipient_presentation_mode, "named") <> "general"', $reminders);
        self::assertStringContainsString('General-recipient invoices are shared manually', $mail);
        self::assertStringContainsString('pa_general_recipient_public_receipt_window_open', $public);
        self::assertStringContainsString('A paid general-recipient invoice remains an active, non-payable receipt', $links);
        self::assertStringContainsString("['General Recipient']", $pdf);
    }

    public function testFinalizeAndLinkFlowIsAtomicAndIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $lifecycle = (string)file_get_contents($root . '/src/utils/invoice_lifecycle.php');
        $create = (string)file_get_contents($root . '/src/controllers/invoice/invoices_create.php');
        $finalize = (string)file_get_contents($root . '/src/controllers/invoice/invoice_finalize.php');

        self::assertStringContainsString('function invoice_finalize_and_create_general_recipient_link', $lifecycle);
        self::assertStringContainsString('invoice_finalize($pdo, $invoiceId, $appConfig, \'general_recipient_manual_link\'', $lifecycle);
        self::assertStringContainsString('FOR UPDATE', $lifecycle);
        self::assertStringContainsString('bin2hex(random_bytes(32))', $lifecycle);
        self::assertStringContainsString('expire_when_paid=1 AND expires_at IS NULL', $lifecycle);
        self::assertStringContainsString('invoice_finalize_and_create_general_recipient_link($pdo, $invoice_id', $create);
        self::assertStringContainsString('invoice_finalize_and_create_general_recipient_link($pdo, $id', $finalize);
        self::assertStringNotContainsString('invoice_send_finalized($pdo, $id', substr($finalize, 0, strpos($finalize, '} else {') ?: 0));
    }

    public function testReceiptWindowIsNotRewrittenAsLegacyExpiryAndPdfAllowsIt(): void
    {
        $root = dirname(__DIR__, 2);
        $public = (string)file_get_contents($root . '/src/controllers/public_view/public_doc.php');
        $pdf = (string)file_get_contents($root . '/src/controllers/public_view/public_doc_pdf.php');
        $links = (string)file_get_contents($root . '/src/utils/public_links.php');

        self::assertStringContainsString('&& empty($row[\'expires_at\'])', $public);
        self::assertStringContainsString('&& empty($row[\'expires_at\'])', $pdf);
        self::assertStringContainsString('pa_general_recipient_public_receipt_window_open($invoice)', $pdf);
        self::assertStringContainsString('AND revoked=0 AND expires_at IS NULL', $links);
        self::assertFileExists($root . '/database/migrations/0061_general_recipient_invoices.sql');
        self::assertFileDoesNotExist($root . '/database/migrations/0060_general_recipient_invoices.sql');
    }

    public function testCreateScreenRestoresNormalProjectSelectionAndNeverOffersEmailForGeneralMode(): void
    {
        $root = dirname(__DIR__, 2);
        $createView = (string)file_get_contents($root . '/src/views/pages/invoice/invoices-create.php');
        $detailView = (string)file_get_contents($root . '/src/views/pages/invoice/invoice-details.php');

        self::assertStringContainsString('loadProjectsForClientInv(clientId.value)', $createView);
        self::assertStringContainsString('Finalize &amp; Create Link', $createView);
        self::assertStringContainsString('Finalize &amp; Create Link', $detailView);
        self::assertStringContainsString('!$isGeneralRecipientInvoice', $detailView);
    }
}
