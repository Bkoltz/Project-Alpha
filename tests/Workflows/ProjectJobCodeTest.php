<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/src/utils/project_id.php';

use PHPUnit\Framework\TestCase;

final class ProjectJobCodeTest extends TestCase
{
    public function testOrganizationContactJobPrefixIsReadable(): void
    {
        self::assertSame('PA-JD', project_organization_job_prefix('Project Alpha LLC', 'John Doe'));
        self::assertSame('LT-AS', project_organization_job_prefix('LedgeTop Technologies', 'Alex Morgan Smith'));
    }

    public function testLegalSuffixDoesNotBecomeTheOrganizationIdentity(): void
    {
        self::assertSame('VP', project_organization_initials('vPaulTech LLC'));
        self::assertSame('AC', project_organization_initials('Acme Corporation'));
    }

    public function testJobsListUsesFirstClassJobCreationDateNewestFirst(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/src/views/pages/jobs/jobs-list.php');
        self::assertStringContainsString('FROM jobs j JOIN clients c ON c.id=j.client_id',$source);
        self::assertStringContainsString('ORDER BY j.created_at DESC,j.id DESC',$source);
        self::assertStringContainsString('WHERE q.job_id=j.id',$source);
        self::assertStringNotContainsString('UNION ALL SELECT co.project_code',$source);
    }

    public function testExistingStandaloneFormatRemainsAvailable(): void
    {
        self::assertSame('JD',project_client_initials('John Doe'));
        self::assertSame('AC',project_client_initials('Acme'));
    }
}
