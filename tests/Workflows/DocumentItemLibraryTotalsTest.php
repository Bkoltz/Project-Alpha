<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentItemLibraryTotalsTest extends TestCase
{
    public function testSharedAutocompleteNotifiesThePriceCalculatorWithoutClearingTheCatalogId(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/item-autocomplete.js');

        self::assertMatchesRegularExpression(
            '/this\.priceField\.value\s*=\s*parseFloat\(item\.unit_price\)\.toFixed\(2\);\s*'
            . 'this\.priceField\.dispatchEvent\(new Event\(\'input\', \{ bubbles: true \}\)\);/',
            $source
        );
        self::assertStringNotContainsString('this.input.dispatchEvent', $source);
    }

    public static function createControllers(): array
    {
        return [
            'invoice' => ['src/controllers/invoice/invoices_create.php', 'item_library_id', 'invoice_items', '$items = [];', 'line_total'],
            'quote' => ['src/controllers/quote/quotes_create.php', 'item_library_id', 'quote_items', '// Process items for regular quotes or fixed_total long-term quotes', 'line_total'],
            'contract' => ['src/controllers/contract/contracts_create.php', 'item_library_id', 'contract_items', '$items=[];$subtotal=0.0;', 't'],
        ];
    }

    #[DataProvider('createControllers')]
    public function testCreateControllerCalculatesAndPersistsEveryPostedCatalogRow(
        string $relativePath,
        string $catalogPostField,
        string $itemTable,
        string $loopAnchor,
        string $lineTotalKey
    ): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

        self::assertStringContainsString("\$catalogIds = \$_POST['{$catalogPostField}'] ?? [];", $source);
        self::assertMatchesRegularExpression('/for\s*\(\s*\$i\s*=\s*0\s*;\s*\$i\s*<\s*count\(\$item\)\s*;\s*\$i\+\+\s*\)/', $source);
        self::assertMatchesRegularExpression('/\$line\s*=\s*\$q\s*\*\s*\$p\s*;/', $source);
        self::assertMatchesRegularExpression('/\$subtotal\s*\+=\s*\$line\s*;/', $source);
        self::assertStringContainsString("INSERT INTO {$itemTable}", $source);
        self::assertMatchesRegularExpression('/item_library_id[^\n]+line_total/', $source);
        self::assertMatchesRegularExpression('/foreach\s*\(\s*\$items\s+as\s+(?:\$idx\s*=>\s*)?\$it\s*\)/', $source);
        self::assertMatchesRegularExpression(
            '/->execute\(\[\$[a-zA-Z_]+,\$catalog\[\'item_library_id\'\].*?\$it\[\'(?:line_total|t)\'\]/s',
            $source
        );

        $item = ['Premium Promotional Video', 'Basic Photo Shoot'];
        $desc = ['', ''];
        $qty = ['1', '1'];
        $price = ['350', '150'];
        $billingUnits = ['each', 'each'];
        $catalogIds = ['101', '202'];
        $timeEntryIdsByRow = [];
        $legacyTimeEntryIds = [];
        $mileageAllocationIdsByRow = [];
        $billing_mode = 'hourly';
        $items = [];
        $subtotal = 0.0;

        eval(self::extractForLoop($source, $loopAnchor));

        $tax = 5.5 * $subtotal / 100;
        self::assertCount(2, $items);
        self::assertSame([350.0, 150.0], array_column($items, $lineTotalKey));
        self::assertSame([101, 202], array_map(static fn(array $row): int => (int) $row['catalog_id'], $items));
        self::assertSame(500.0, $subtotal);
        self::assertSame(27.5, $tax);
        self::assertSame(527.5, $subtotal + $tax);
    }

    private static function extractForLoop(string $source, string $anchor): string
    {
        $anchorOffset = strpos($source, $anchor);
        self::assertNotFalse($anchorOffset, 'Expected production item-loop anchor');
        self::assertSame(1, preg_match('/for\s*\(/', $source, $match, PREG_OFFSET_CAPTURE, $anchorOffset));
        $start = $match[0][1];
        $open = strpos($source, '{', $start);
        self::assertNotFalse($open, 'Expected production item-loop body');

        $depth = 0;
        $length = strlen($source);
        for ($offset = $open; $offset < $length; $offset++) {
            if ($source[$offset] === '{') {
                $depth++;
            } elseif ($source[$offset] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $offset - $start + 1);
                }
            }
        }

        self::fail('Production item loop was not balanced');
    }
}
