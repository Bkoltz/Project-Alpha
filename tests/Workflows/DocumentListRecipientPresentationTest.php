<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DocumentListRecipientPresentationTest extends TestCase
{
    /** @dataProvider coreDocumentLists */
    public function testCoreListsShowOrganizationCustomerAndSeparateContact(string $relativePath, string $documentAlias): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../' . $relativePath);

        self::assertStringContainsString('<th style="padding:10px">Customer</th>', $source);
        self::assertStringContainsString('<th style="padding:10px">Contact</th>', $source);
        self::assertStringContainsString(
            'LEFT JOIN organizations o ON o.id=COALESCE(' . $documentAlias . '.organization_id,c.organization_id)',
            $source
        );
        self::assertStringContainsString('(c.name LIKE ? OR o.name LIKE ?)', $source);
        self::assertStringContainsString("['organization_name'] ?: \$r['client", $source);
        self::assertStringContainsString("if (!empty(\$r['organization_name']))", $source);
    }

    public static function coreDocumentLists(): array
    {
        return [
            ['src/views/pages/invoice/invoices-list.php', 'i'],
            ['src/views/pages/quote/quotes-list.php', 'q'],
            ['src/views/pages/contract/contracts-list.php', 'co'],
        ];
    }
}
