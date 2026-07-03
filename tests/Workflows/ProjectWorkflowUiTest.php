<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProjectWorkflowUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProjectCreateUsesDynamicClientTagPickers(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/project/projects-create.php');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $edit = file_get_contents($this->root . '/src/views/pages/project/projects-edit.php');
        $script = file_get_contents($this->root . '/public/assets/js/project-form.js');
        $settingsScript = file_get_contents($this->root . '/public/assets/js/project-settings.js');
        $controller = file_get_contents($this->root . '/src/controllers/project/projects_create.php');

        self::assertStringContainsString('data-project-client-picker="project"', (string)$view);
        self::assertStringContainsString('data-project-client-picker="invoice"', (string)$view);
        self::assertStringContainsString('$activeOrgName', (string)$view);
        self::assertStringContainsString('value="<?php echo $activeOrgId > 0 ? (int)$activeOrgId : \'\'; ?>"', (string)$view);
        self::assertStringNotContainsString('name="project_client_ids[]" multiple', (string)$view);
        self::assertStringContainsString('/?page=project/projects-edit&amp;id=', (string)$details);
        self::assertStringContainsString('data-legacy-project-settings-panel style="display:none"', (string)$details);
        self::assertStringContainsString('data-project-settings-contact-manager', (string)$edit);
        self::assertStringContainsString('data-project-settings-picker="project"', (string)$edit);
        self::assertStringContainsString('data-project-settings-picker="invoice"', (string)$edit);
        self::assertStringContainsString('data-project-settings-picker="links"', (string)$edit);
        self::assertStringContainsString('/assets/js/project-settings.js', (string)$edit);
        self::assertStringContainsString('/?page=project/client-options', (string)$script);
        self::assertStringContainsString('is_primary_department_contact', (string)$script);
        self::assertStringContainsString('selected.project.add(id)', (string)$script);
        self::assertStringContainsString('initializedNewPicker', (string)$script);
        self::assertStringContainsString('project_invoice_link_client_ids[]', (string)$settingsScript);
        self::assertStringContainsString('$projectInvoiceRecipientIds ?? []', (string)$controller);
    }

    public function testProjectFilesAreSeparateFromFormsAndDocs(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0008_project_file_uploads.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $handler = file_get_contents($this->root . '/src/controllers/project/project_files_handler.php');
        $download = file_get_contents($this->root . '/src/controllers/project/project_file_download.php');
        $router = file_get_contents($this->root . '/public/index.php');
        $acl = file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $helpers = file_get_contents($this->root . '/src/utils/project_files.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_file_folders', (string)$migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_files', (string)$migration);
        self::assertStringContainsString('client_upload_enabled', (string)$migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_file_folders', (string)$baseline);
        self::assertStringContainsString('id="project-files"', (string)$details);
        self::assertStringContainsString('These are separate from Forms &amp; Docs.', (string)$details);
        self::assertStringContainsString('name="project_file"', (string)$details);
        self::assertStringContainsString('/?page=project/project-file-download&id=', (string)$details);
        self::assertStringContainsString('move_uploaded_file', (string)$handler);
        self::assertStringContainsString('project_files_folder_dir($projectId, $folderId)', (string)$handler);
        self::assertStringContainsString('require_record_ownership($pdo, \'projects\', $projectId)', (string)$download);
        self::assertStringContainsString('project/project-files', (string)$router);
        self::assertStringContainsString('project/project-file-download', (string)$router);
        self::assertStringContainsString("'project/project-files'           => 'projects.edit'", (string)$acl);
        self::assertStringContainsString("'project/project-file-download'   => 'projects.view'", (string)$acl);
        self::assertStringContainsString('src/uploads/projects', (string)$helpers);
    }

    public function testDepartmentPrimaryContactsCanBeManaged(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/organization/organization-view.php');
        $handler = file_get_contents($this->root . '/src/controllers/organization/organization_departments.php');
        $endpoint = file_get_contents($this->root . '/src/controllers/project/project_client_options.php');

        self::assertStringContainsString('name="is_primary"', (string)$view);
        self::assertStringContainsString('set_primary_contact', (string)$view);
        self::assertStringContainsString('SET is_primary = 0 WHERE department_id = ?', (string)$handler);
        self::assertStringContainsString('is_primary_department_contact', (string)$endpoint);
    }

    public function testTimeTrackingFormIsSplitIntoExpectedSections(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/time-tracking.php');
        $script = file_get_contents($this->root . '/public/assets/js/time-tracking.js');

        self::assertStringContainsString('Date / Time', (string)$view);
        self::assertStringContainsString('Bill To', (string)$view);
        self::assertStringContainsString('Details', (string)$view);
        self::assertStringContainsString('Use start and end time, or enter manual hours. Do not use both.', (string)$view);
        self::assertStringContainsString('function syncFromJob(formConfig)', (string)$script);
        self::assertStringContainsString('function syncFromContract(formConfig)', (string)$script);
        self::assertStringContainsString('selectInvoiceForContract(formConfig.invoiceSelect', (string)$script);
    }

    public function testPdfFacingDocumentCopyUsesWorkAndJobWording(): void
    {
        $quoteDetails = file_get_contents($this->root . '/src/views/pages/quote/quote-details.php');
        $contractDetails = file_get_contents($this->root . '/src/views/pages/contract/contract-details.php');
        $contractEdit = file_get_contents($this->root . '/src/views/pages/contract/contracts-edit.php');

        self::assertStringContainsString('Scope of Work', (string)$quoteDetails);
        self::assertStringContainsString('Scope of Work', (string)$contractDetails);
        self::assertStringNotContainsString('Scope of Project', (string)$quoteDetails);
        self::assertStringNotContainsString('Scope of Project', (string)$contractDetails);
        self::assertStringContainsString("' (Job '", (string)$contractEdit);
    }

    public function testInvoiceCreateCanSaveWithoutSendingOrSaveAndSend(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/invoice/invoices-create.php');
        $controller = file_get_contents($this->root . '/src/controllers/invoice/invoices_create.php');

        self::assertStringContainsString('value="save" class="btn">Save Invoice</button>', (string)$view);
        self::assertStringContainsString('value="finalize_send" class="btn btn-primary">Save &amp; Send</button>', (string)$view);
        self::assertStringNotContainsString('Save Draft</button>', (string)$view);
        self::assertStringContainsString("\$invoiceAction = (string)(\$_POST['invoice_action'] ?? 'save');", (string)$controller);
        self::assertStringContainsString("['save', 'draft', 'finalize_send']", (string)$controller);
        self::assertStringContainsString('$finalizeAndSend = $invoiceAction === \'finalize_send\';', (string)$controller);
    }

    public function testDocumentsCanAttachToActiveOrNotStartedProjects(): void
    {
        $projectSelection = file_get_contents($this->root . '/src/utils/project_selection.php');
        $contractCreate = file_get_contents($this->root . '/src/views/pages/contract/contracts-create.php');
        $contractEdit = file_get_contents($this->root . '/src/views/pages/contract/contracts-edit.php');
        $contractUpdate = file_get_contents($this->root . '/src/controllers/contract/contracts_update.php');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');

        self::assertStringContainsString('p.status IN ("active","not_started")', (string)$projectSelection);
        self::assertStringContainsString('p.status IN ("active","not_started")', (string)$contractCreate);
        self::assertStringContainsString('name="project_id"', (string)$contractEdit);
        self::assertStringContainsString('pa_project_is_active_for_client($pdo, $project_id, $client_id', (string)$contractUpdate);
        self::assertStringContainsString('project_documents WHERE document_type="contract" AND document_id=?', (string)$contractUpdate);
        self::assertStringContainsString('foreach ($projectClients as $projectClient)', (string)$details);
        self::assertStringContainsString('client_id IN (SELECT id FROM clients WHERE organization_id = ?)', (string)$details);
    }

    public function testLongTermAndOnDemandBillingBehaviorsAreExplicit(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $migration = file_get_contents($this->root . '/database/migrations/0010_long_term_billing_start_mode.sql');
        $createView = file_get_contents($this->root . '/src/views/pages/contract/contracts-create.php');
        $ltCreate = file_get_contents($this->root . '/src/controllers/contract/long_term_contracts_create.php');
        $sign = file_get_contents($this->root . '/src/controllers/public_view/public_contract_sign.php');
        $start = file_get_contents($this->root . '/src/controllers/contract/long_term_contract_start_billing.php');
        $odcList = file_get_contents($this->root . '/src/views/pages/contract/on-demand-contracts-list.php');

        self::assertStringContainsString("billing_start_mode ENUM('on_upload','manual')", (string)$baseline);
        self::assertStringContainsString('ADD COLUMN billing_start_mode', (string)$migration);
        self::assertStringContainsString('name="billing_start_mode" value="on_upload" checked', (string)$createView);
        self::assertStringContainsString('$next_invoice_date = null;', (string)$ltCreate);
        self::assertStringContainsString('pa_long_term_starts_billing_on_upload($contract)', (string)$sign);
        self::assertStringContainsString('generate_recurring_invoice($pdo, $contract, $appConfig)', (string)$start);
        self::assertStringContainsString('$billingText = \'Manual invoices\';', (string)$odcList);
    }

    public function testInvoiceAndContractNumbersArePerDocumentType(): void
    {
        $helper = file_get_contents($this->root . '/src/utils/invoice_numbers.php');
        $invoiceCreate = file_get_contents($this->root . '/src/controllers/invoice/invoices_create.php');
        $contractCreate = file_get_contents($this->root . '/src/controllers/contract/contracts_create.php');
        $odcInvoice = file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');

        self::assertStringContainsString('invoice_type = "regular" OR invoice_type IS NULL', (string)$helper);
        self::assertStringContainsString("pa_next_invoice_doc_number($pdo, 'regular')", (string)$invoiceCreate);
        self::assertStringContainsString('contracts WHERE contract_type = "regular"', (string)$contractCreate);
        self::assertStringContainsString("pa_next_invoice_doc_number($pdo, 'on_demand')", (string)$odcInvoice);
    }

    public function testCreatePageScriptsInitializeAfterAjaxNavigation(): void
    {
        $navigation = file_get_contents($this->root . '/public/assets/navigation.js');
        $clientCreate = file_get_contents($this->root . '/public/assets/js/clients-create-logic.js');
        $clientEdit = file_get_contents($this->root . '/public/assets/js/clients-edit-logic.js');
        $projectCreate = file_get_contents($this->root . '/public/assets/js/project-form.js');
        $accounts = file_get_contents($this->root . '/src/views/pages/auth/accounts.php');
        $accountsCreate = file_get_contents($this->root . '/src/controllers/accounts/accounts_create.php');

        self::assertStringContainsString('function registerPageInitializer', (string)$navigation);
        self::assertStringContainsString('runPageCleanups();', (string)$navigation);
        self::assertStringContainsString('runPageInitializers(page, mainContent);', (string)$navigation);
        self::assertStringContainsString('form[action="/?page=client/clients-create"]', (string)$clientCreate);
        self::assertStringContainsString('dataset.orgCreateReady', (string)$clientCreate);
        self::assertStringContainsString("window.ProjectAlpha.registerPage(['client/clients-create', 'clients-create']", (string)$clientCreate);
        self::assertStringNotContainsString('setTimeout(initializeOrgCreate', (string)$clientCreate);
        self::assertStringContainsString("window.ProjectAlpha.registerPage(['client/clients-edit', 'clients-edit']", (string)$clientEdit);
        self::assertStringContainsString("window.ProjectAlpha.registerPage('project/projects-create'", (string)$projectCreate);
        self::assertStringContainsString('function initAccountCreateForm()', (string)$accounts);
        self::assertStringContainsString("window.ProjectAlpha.registerPage('accounts', initAccountCreateForm)", (string)$accounts);
        self::assertStringContainsString("header('Location: /?page=accounts&created=1')", (string)$accountsCreate);
    }
}
