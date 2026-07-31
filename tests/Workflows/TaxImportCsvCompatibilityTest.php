<?php

declare(strict_types=1);

namespace Tests\Workflows;

use PHPUnit\Framework\TestCase;

final class TaxImportCsvCompatibilityTest extends TestCase
{
    public function testParserKeepsCurrentQuoteAndBackslashSemanticsExplicit(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, "\"Acme \"\"County\"\"\",\"C:\\Tax\\Imports\"\n");
        rewind($stream);

        $row = fgetcsv($stream, 0, ',', '"', '\\');
        fclose($stream);

        self::assertSame(['Acme "County"', 'C:\\Tax\\Imports'], $row);

        $handler = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/controllers/settings/tax-import-handler.php'
        );
        self::assertStringContainsString(
            'return fgetcsv($handle, 0, \',\', \'"\', \'\\\\\');',
            $handler
        );
        self::assertStringNotContainsString('fgetcsv($handle)', $handler);
    }
}
