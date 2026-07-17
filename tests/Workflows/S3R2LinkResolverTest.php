<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/link_resolvers/auto_resolver/s3_link_resolver.php';
require_once dirname(__DIR__, 2) . '/src/link_resolvers/auto_resolver/r2_link_resolver.php';

use PHPUnit\Framework\TestCase;

final class S3R2LinkResolverTest extends TestCase
{
    public function testR2UsesAccountEndpointAndFindsNestedDepartmentPrefix(): void
    {
        $requests = [];
        $resolver = new R2LinkResolver([
            'account_id' => str_repeat('a', 32),
            'access_key' => 'r2-access-key',
            'secret_key' => 'r2-secret-key',
            'bucket' => 'client-data',
            'root_path' => 'Clients',
            'public_base_url' => 'https://files.example.test',
        ], $this->successfulListRequester($requests));

        $result = $resolver->searchFolder('Football', ['West High School']);

        self::assertTrue($result['success'], json_encode($result));
        self::assertSame('Clients/West High School/Football/', $result['folder_id']);
        self::assertSame('West High School', $result['parent_name']);
        self::assertCount(1, $requests);
        self::assertStringStartsWith(
            'https://' . str_repeat('a', 32) . '.r2.cloudflarestorage.com/client-data/?',
            $requests[0]['url']
        );

        $query = [];
        parse_str((string)parse_url($requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('Clients/West High School/Football/', $query['prefix']);
        self::assertSame('2', $query['list-type']);
        self::assertStringContainsString('Credential=r2-access-key/', implode("\n", $requests[0]['headers']));

        $url = $resolver->generatePublicLink((string)$result['folder_id']);
        self::assertSame('https://files.example.test/Clients/West%20High%20School/Football/', $url);
    }

    public function testR2PublicUrlCanUsePrefixTemplate(): void
    {
        $requests = [];
        $resolver = new R2LinkResolver([
            'account_id' => str_repeat('b', 32),
            'access_key' => 'access',
            'secret_key' => 'secret',
            'bucket' => 'client-data',
            'public_base_url' => 'https://files.example.test/browse/{prefix}',
        ], $this->successfulListRequester($requests));

        self::assertSame(
            'https://files.example.test/browse/Acme/Photos/',
            $resolver->generatePublicLink('Acme/Photos/')
        );
    }

    public function testR2RequiresCustomerFacingWorkerUrlForGeneratedLinks(): void
    {
        $requests = [];
        $resolver = new R2LinkResolver([
            'account_id' => str_repeat('c', 32),
            'access_key' => 'access',
            'secret_key' => 'secret',
            'bucket' => 'client-data',
        ], $this->successfulListRequester($requests));

        self::assertNull($resolver->generatePublicLink('Acme/'));
        self::assertSame(
            'S3/R2 storage is connected, but no public Worker/base URL is configured.',
            $resolver->getLastError()['message'] ?? null
        );
    }

    public function testAmazonS3KeepsNativeEndpointAndRegion(): void
    {
        $requests = [];
        $resolver = new S3LinkResolver([
            'access_key' => 'aws-access',
            'secret_key' => 'aws-secret',
            'bucket' => 'customer-files',
            'region' => 'us-west-2',
        ], $this->successfulListRequester($requests));

        $result = $resolver->searchFolder('Acme');

        self::assertTrue($result['success'], json_encode($result));
        self::assertStringStartsWith('https://customer-files.s3.us-west-2.amazonaws.com/?', $requests[0]['url']);
        self::assertSame(
            'https://customer-files.s3.us-west-2.amazonaws.com/Acme/',
            $resolver->generatePublicLink('Acme/')
        );
    }

    /**
     * @param array<int,array{url:string,headers:list<string>}> $requests
     */
    private function successfulListRequester(array &$requests): callable
    {
        return static function (string $url, array $headers) use (&$requests): array {
            $requests[] = ['url' => $url, 'headers' => $headers];
            return [
                'response' => '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Contents><Key>matched/file.txt</Key></Contents></ListBucketResult>',
                'curl_error' => '',
                'http_code' => 200,
            ];
        };
    }
}
