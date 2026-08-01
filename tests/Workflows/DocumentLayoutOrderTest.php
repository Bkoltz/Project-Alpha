<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DocumentLayoutOrderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testInvoiceTermsFollowItemsTotalsAndContentLinks(): void
    {
        $view = $this->view('src/views/pages/invoice/invoice-details.php');

        $this->assertOrdered($view, 'Line Total', 'Subtotal', 'Payment terms:');
        $this->assertOrdered($view, 'invoiceContentLinksHtml =', 'Payment terms:');
    }

    public function testQuoteTermsFollowItemsAndTotals(): void
    {
        $view = $this->view('src/views/pages/quote/quote-details.php');

        $this->assertOrdered($view, 'Line Total', 'Subtotal', 'Terms and Conditions');
    }

    public function testRecurringQuoteTermsPrecedeSignature(): void
    {
        $view = $this->view('src/views/pages/quote/long-term-quote-details.php');

        $this->assertOrdered($view, 'Amount Per Invoice', 'Terms and Conditions', '<!-- Signature block -->');
    }

    public function testContractAcknowledgementAndSignaturePrecedeNewPageTerms(): void
    {
        $view = $this->view('src/views/pages/contract/contract-details.php');

        $this->assertOrdered($view, 'Line Total', '<!-- Totals section', '<!-- Signature section -->', 'signature_agreement', 'Terms and Conditions');
        self::assertMatchesRegularExpression('/page-break-after:always[^>]*><\\/div>\\s*<h3>Terms and Conditions<\\/h3>/', $view);
    }

    public function testRecurringContractAcknowledgementAndSignaturePrecedeNewPageTerms(): void
    {
        $view = $this->view('src/views/pages/contract/long-term-contract-details.php');

        $this->assertOrdered($view, 'Amount Per Invoice', 'signature_agreement', '<!-- Signature block -->', 'Terms and Conditions');
        self::assertMatchesRegularExpression('/page-break-after:always[^>]*><\\/div>\\s*<h3>Terms and Conditions<\\/h3>/', $view);
    }

    private function view(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, 'Template must be readable: ' . $path);

        return $contents;
    }

    private function assertOrdered(string $contents, string ...$needles): void
    {
        $previous = -1;
        foreach ($needles as $needle) {
            $offset = strpos($contents, $needle);
            self::assertNotFalse($offset, 'Expected document marker: ' . $needle);
            self::assertGreaterThan($previous, $offset, 'Document marker must follow the preceding section: ' . $needle);
            $previous = $offset;
        }
    }
}