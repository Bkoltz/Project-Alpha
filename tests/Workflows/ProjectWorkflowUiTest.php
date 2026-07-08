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

    public function testProjectPublicLinksHavePasswordAndCapabilityControls(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0021_project_public_links.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $edit = file_get_contents($this->root . '/src/views/pages/project/projects-edit.php');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');
        $update = file_get_contents($this->root . '/src/controllers/project/projects_update.php');
        $portal = file_get_contents($this->root . '/src/controllers/public_view/public_project.php');
        $upload = file_get_contents($this->root . '/src/controllers/public_view/public_project_upload.php');
        $download = file_get_contents($this->root . '/src/controllers/public_view/public_project_file.php');
        $router = file_get_contents($this->root . '/public/index.php');
        $acl = file_get_contents($this->root . '/src/utils/acl_middleware.php');
        $helper = file_get_contents($this->root . '/src/utils/public_project_links.php');

        foreach (['public_project_enabled', 'public_project_token', 'public_project_password_hash', 'public_project_can_upload', 'public_project_can_request_changes'] as $column) {
            self::assertStringContainsString($column, (string)$migration);
            self::assertStringContainsString($column, (string)$baseline);
            self::assertStringContainsString($column, (string)$edit);
            self::assertStringContainsString($column, (string)$update);
        }
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS project_public_events', (string)$migration);
        self::assertStringContainsString('Public Project Link', (string)$edit);
        self::assertStringContainsString('pa_project_public_badge_html', (string)$details);
        self::assertStringContainsString('Public Link Activity', (string)$details);
        self::assertStringContainsString('password_hash($publicProjectPassword', (string)$update);
        self::assertStringContainsString('password_verify($code, $hash)', (string)$portal);
        self::assertStringContainsString('pa_project_public_document_url', (string)$portal);
        self::assertStringContainsString('validate_and_store_upload', (string)$upload);
        self::assertStringContainsString('reject_pdf_active_content', (string)$upload);
        self::assertStringContainsString('pa_project_public_is_unlocked($project, $token)', (string)$download);
        self::assertStringContainsString('public-project-upload', (string)$router);
        self::assertStringContainsString('public-project-file', (string)$router);
        self::assertStringContainsString("'public-project'      => null", (string)$acl);
        self::assertStringContainsString('function pa_project_public_resolve', (string)$helper);
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

    public function testOrganizationLinksSwitchFromOverallFolderToDepartmentLinks(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $migration = file_get_contents($this->root . '/database/migrations/0022_dynamic_org_link_strategy.sql');
        $view = file_get_contents($this->root . '/src/views/pages/organization/organization-view.php');
        $handler = file_get_contents($this->root . '/src/controllers/organization/organization_departments.php');
        $resolver = file_get_contents($this->root . '/src/services/LinkResolverService.php');

        self::assertStringContainsString("DEFAULT 'overall_folder'", (string)$baseline);
        self::assertStringContainsString("DEFAULT 'overall_folder'", (string)$migration);
        self::assertStringContainsString('PA uses the overall organization folder until the first department is added.', (string)$view);
        self::assertStringContainsString('When a first department is created, PA switches this organization to department links only', (string)$view);
        self::assertStringContainsString('$existingDepartmentCount === 0', (string)$handler);
        self::assertStringContainsString('link_strategy = "department_links_only"', (string)$handler);
        self::assertStringContainsString('removeResolverOrganizationLinks($organizationId)', (string)$handler);
        self::assertStringContainsString('autoGenerateForDepartment($departmentId)', (string)$handler);
        self::assertStringContainsString('function removeResolverOrganizationLinks', (string)$resolver);
    }

    public function testClientAndOrganizationSuiteAddressesFlowToDocuments(): void
    {
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $migration = file_get_contents($this->root . '/database/migrations/0013_ensure_client_and_org_address_line2.sql');
        $clientCreate = file_get_contents($this->root . '/src/views/pages/client/clients-create.php');
        $clientEdit = file_get_contents($this->root . '/src/views/pages/client/clients-edit.php');
        $clientCreateController = file_get_contents($this->root . '/src/controllers/client/clients_create.php');
        $clientUpdateController = file_get_contents($this->root . '/src/controllers/client/clients_update.php');
        $clientOnboarding = file_get_contents($this->root . '/src/controllers/public_view/client_onboarding.php');
        $orgCreate = file_get_contents($this->root . '/src/views/pages/organization/organizations-create.php');
        $orgEdit = file_get_contents($this->root . '/src/views/pages/organization/organizations-edit.php');
        $orgCreateController = file_get_contents($this->root . '/src/controllers/organization/organizations_create.php');
        $orgUpdateController = file_get_contents($this->root . '/src/controllers/organization/organizations_update.php');
        $orgSearchController = file_get_contents($this->root . '/src/controllers/organization/org_search.php');
        $clientCreateScript = file_get_contents($this->root . '/public/assets/js/clients-create-logic.js');
        $quoteDetail = file_get_contents($this->root . '/src/views/pages/quote/quote-details.php');
        $longQuoteDetail = file_get_contents($this->root . '/src/views/pages/quote/long-term-quote-details.php');
        $contractDetail = file_get_contents($this->root . '/src/views/pages/contract/contract-details.php');
        $longContractDetail = file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php');
        $invoiceDetail = file_get_contents($this->root . '/src/views/pages/invoice/invoice-details.php');
        $projectInvoiceDetail = file_get_contents($this->root . '/src/views/pages/project/project-invoice-details.php');
        $accountEdit = file_get_contents($this->root . '/src/views/pages/auth/account-edit.php');
        $systemSettings = file_get_contents($this->root . '/src/views/pages/settings/system.php');

        self::assertStringContainsString('address_line2 VARCHAR(255) NULL', (string)$baseline);
        self::assertStringContainsString('ALTER TABLE clients ADD COLUMN address_line2', (string)$migration);
        self::assertStringContainsString('ALTER TABLE organizations ADD COLUMN address_line2', (string)$migration);
        foreach ([$clientCreate, $clientEdit, $clientOnboarding, $orgCreate, $orgEdit, $accountEdit, $systemSettings] as $view) {
            self::assertStringContainsString('Apartment / Suite', (string)$view);
        }
        self::assertStringContainsString('$client[\'postal_code\'] ?? \'\'', (string)$clientEdit);
        foreach ([$clientCreateController, $clientUpdateController, $orgCreateController, $orgUpdateController] as $controller) {
            self::assertStringContainsString('address_line2', (string)$controller);
        }
        self::assertStringContainsString('pa_organization_address_select($pdo)', (string)$orgSearchController);
        self::assertStringContainsString('dataset.clientAddressDirty', (string)$clientCreateScript);
        self::assertStringContainsString('fillAddressFromOrg(item)', (string)$clientCreateScript);
        self::assertStringContainsString("\$appConfig['primary_state']", (string)$orgCreate);
        self::assertStringContainsString("\$org['state'] ?: (\$appConfig['primary_state'] ?? '')", (string)$orgEdit);
        foreach ([$quoteDetail, $longQuoteDetail, $contractDetail, $longContractDetail, $invoiceDetail] as $documentView) {
            self::assertStringContainsString('address_line2', (string)$documentView);
            self::assertStringContainsString('$toLines[] = (string)', (string)$documentView);
        }
        self::assertStringContainsString('organization_address_line2', (string)$projectInvoiceDetail);
        self::assertStringContainsString('$orgLines[] = (string)$pi[\'organization_address_line2\'];', (string)$projectInvoiceDetail);
    }

    public function testTimeTrackingFormIsSplitIntoExpectedSections(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/time-tracking.php');
        $script = file_get_contents($this->root . '/public/assets/js/time-tracking.js');
        $createController = file_get_contents($this->root . '/src/controllers/time-tracking/time_entry_create.php');
        $updateController = file_get_contents($this->root . '/src/controllers/time-tracking/time_entry_update.php');
        $startController = file_get_contents($this->root . '/src/controllers/time-tracking/time_entry_start_timer.php');
        $deleteController = file_get_contents($this->root . '/src/controllers/time-tracking/time_entry_delete.php');
        $optionsController = file_get_contents($this->root . '/src/controllers/time-tracking/time_entry_options.php');
        $schema = file_get_contents($this->root . '/src/utils/time_tracking_schema.php');
        $migration = file_get_contents($this->root . '/database/migrations/0019_repair_time_entries_schema.sql');

        self::assertStringContainsString('Date / Time', (string)$view);
        self::assertStringContainsString('Bill To', (string)$view);
        self::assertStringContainsString('Details', (string)$view);
        self::assertStringContainsString('Use start and end time, or enter manual hours. Do not use both.', (string)$view);
        self::assertStringContainsString('pa_time_tracking_ensure_schema($pdo)', (string)$view);
        self::assertStringContainsString('function syncFromJob(formConfig)', (string)$script);
        self::assertStringContainsString('function syncFromContract(formConfig)', (string)$script);
        self::assertStringContainsString('selectInvoiceForContract(formConfig.invoiceSelect', (string)$script);
        foreach ([$createController, $updateController, $startController, $deleteController, $optionsController] as $controller) {
            self::assertStringContainsString("__DIR__ . '/../../config/db.php'", (string)$controller);
        }
        foreach ([$createController, $updateController] as $controller) {
            self::assertStringContainsString('pa_time_tracking_ensure_schema($pdo)', (string)$controller);
            self::assertStringContainsString('Failed to save time entry', (string)$controller);
        }
        self::assertStringContainsString('invoice_item_id=NULL', (string)$updateController);
        self::assertStringContainsString('function pa_time_tracking_ensure_schema', (string)$schema);
        self::assertStringContainsString('service_item_id', (string)$migration);
        self::assertStringContainsString('project_code', (string)$migration);
        self::assertStringContainsString('updated_at', (string)$migration);
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
        $updateController = file_get_contents($this->root . '/src/controllers/invoice/invoices_update.php');
        $unbilledTime = file_get_contents($this->root . '/src/controllers/time-tracking/time_entries_unbilled.php');

        self::assertStringContainsString('value="save" class="btn">Save Invoice</button>', (string)$view);
        self::assertStringContainsString('value="finalize_send" class="btn btn-primary">Save &amp; Send</button>', (string)$view);
        self::assertStringContainsString('display:flex;gap:8px;flex-wrap:wrap;padding-top:8px', (string)$view);
        self::assertStringNotContainsString('Save Draft</button>', (string)$view);
        self::assertStringContainsString("\$invoiceAction = (string)(\$_POST['invoice_action'] ?? 'save');", (string)$controller);
        self::assertStringContainsString("['save', 'draft', 'finalize_send']", (string)$controller);
        self::assertStringContainsString('$finalizeAndSend = $invoiceAction === \'finalize_send\';', (string)$controller);
        self::assertStringContainsString('client_id = ? OR client_id IS NULL OR client_id = 0', (string)$controller);
        self::assertStringContainsString('CASE WHEN client_id IS NULL OR client_id = 0 THEN ? ELSE client_id END', (string)$controller);
        self::assertStringContainsString('UPDATE time_entries te', (string)$updateController);
        self::assertStringContainsString('SET te.client_id = ?, te.invoice_id = ?', (string)$updateController);
        self::assertStringContainsString('SET te.billed = 0, te.invoice_item_id = NULL, te.invoice_id = NULL', (string)$updateController);
        self::assertStringContainsString('te.client_id = ? OR te.client_id IS NULL OR te.client_id = 0', (string)$unbilledTime);
    }

    public function testDocumentsCanAttachToActiveOrNotStartedProjects(): void
    {
        $projectSelection = file_get_contents($this->root . '/src/utils/project_selection.php');
        $contractCreate = file_get_contents($this->root . '/src/views/pages/contract/contracts-create.php');
        $contractEdit = file_get_contents($this->root . '/src/views/pages/contract/contracts-edit.php');
        $contractEditScript = file_get_contents($this->root . '/public/assets/js/contracts-edit-logic.js');
        $contractUpdate = file_get_contents($this->root . '/src/controllers/contract/contracts_update.php');
        $details = file_get_contents($this->root . '/src/views/pages/project/projects-details.php');

        self::assertStringContainsString('p.status IN ("active","not_started")', (string)$projectSelection);
        self::assertStringContainsString('p.status IN ("active","not_started")', (string)$contractCreate);
        self::assertStringContainsString('name="project_id"', (string)$contractEdit);
        self::assertStringContainsString('id="contractEditClientSearch"', (string)$contractEdit);
        self::assertStringContainsString('id="contractEditClientId"', (string)$contractEdit);
        self::assertStringNotContainsString('SELECT id, name FROM clients ORDER BY name ASC', (string)$contractEdit);
        self::assertStringContainsString('/?page=clients-search&term=', (string)$contractEditScript);
        self::assertStringContainsString('/?page=projects-search&client_id=', (string)$contractEditScript);
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
        $editView = file_get_contents($this->root . '/src/views/pages/contract/contracts-edit.php');
        $editScript = file_get_contents($this->root . '/public/assets/js/contracts-edit-logic.js');
        $update = file_get_contents($this->root . '/src/controllers/contract/contracts_update.php');
        $ltDetails = file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php');
        $ltList = file_get_contents($this->root . '/src/views/pages/contract/long-term-contracts-list.php');
        $recurringList = file_get_contents($this->root . '/src/views/pages/invoice/recurring-invoices-list.php');

        self::assertStringContainsString("billing_start_mode ENUM('on_upload','manual')", (string)$baseline);
        self::assertStringContainsString('ADD COLUMN billing_start_mode', (string)$migration);
        self::assertStringContainsString('name="billing_start_mode" value="on_upload" checked', (string)$createView);
        self::assertStringContainsString('$next_invoice_date = null;', (string)$ltCreate);
        self::assertStringContainsString('pa_long_term_starts_billing_on_upload($contract)', (string)$sign);
        self::assertStringContainsString('generate_recurring_invoice($pdo, $contract, $appConfig)', (string)$start);
        self::assertStringContainsString('$billingText = \'Manual invoices\';', (string)$odcList);
        self::assertStringContainsString('Recurring Billing Settings', (string)$editView);
        self::assertStringContainsString('name="billing_interval_unit"', (string)$editView);
        self::assertStringContainsString('name="next_invoice_date"', (string)$editView);
        self::assertStringContainsString('id="pricePerInvoiceEditCo"', (string)$editView);
        self::assertStringContainsString('function initLongTermEditFieldsCo()', (string)$editScript);
        self::assertStringContainsString('$isLongTermContract = $contractType === \'long_term\';', (string)$update);
        self::assertStringContainsString('next_invoice_date=?', (string)$update);
        self::assertStringContainsString('Long-term recurring invoices are historical billing records and must not be rewritten.', (string)$update);
        self::assertStringContainsString('if (!$isLongTermContract)', (string)$update);
        self::assertStringContainsString("['pending', 'active', 'paused']", (string)$ltDetails);
        self::assertStringContainsString('Edit Billing', (string)$ltList);
        self::assertStringContainsString('Edit billing', (string)$recurringList);
    }

    public function testInvoiceAndContractNumbersArePerDocumentType(): void
    {
        $helper = file_get_contents($this->root . '/src/utils/invoice_numbers.php');
        $invoiceCreate = file_get_contents($this->root . '/src/controllers/invoice/invoices_create.php');
        $contractCreate = file_get_contents($this->root . '/src/controllers/contract/contracts_create.php');
        $odcInvoice = file_get_contents($this->root . '/src/controllers/contract/on_demand_invoice_generate.php');

        self::assertStringContainsString('invoice_type = "regular" OR invoice_type IS NULL', (string)$helper);
        self::assertStringContainsString('pa_next_invoice_doc_number($pdo, \'regular\')', (string)$invoiceCreate);
        self::assertStringContainsString('contracts WHERE contract_type = "regular"', (string)$contractCreate);
        self::assertStringContainsString('pa_next_invoice_doc_number($pdo, \'on_demand\')', (string)$odcInvoice);
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

    public function testVersionedAssetsPreventStalePageScripts(): void
    {
        $appVersion = file_get_contents($this->root . '/src/utils/app_version.php');
        $header = file_get_contents($this->root . '/src/views/partials/header.php');
        $footer = file_get_contents($this->root . '/src/views/partials/footer.php');
        $payments = file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        $projectCreate = file_get_contents($this->root . '/src/views/pages/project/projects-create.php');

        self::assertStringContainsString('function asset_url(string $path): string', (string)$appVersion);
        self::assertStringContainsString("filemtime(\$filePath)", (string)$appVersion);
        self::assertStringContainsString("asset_url('/assets/styles.css')", (string)$header);
        self::assertStringContainsString("asset_url('/assets/js/csrf-auto-link.js')", (string)$footer);
        self::assertStringContainsString("asset_url('/assets/js/payments-create-logic.js')", (string)$payments);
        self::assertStringContainsString("asset_url('/assets/js/project-form.js')", (string)$projectCreate);
        self::assertStringNotContainsString('<script src="/assets/js/payments-create-logic.js"', (string)$payments);
    }

    public function testFormsDocsRemainSeparateFromProjectDocuments(): void
    {
        $formsList = file_get_contents($this->root . '/src/views/pages/financial/forms-list.php');
        $formDetail = file_get_contents($this->root . '/src/views/pages/financial/form-detail.php');
        $folderDetail = file_get_contents($this->root . '/src/views/pages/financial/folder-detail.php');
        $handler = file_get_contents($this->root . '/src/controllers/forms_handler.php');
        $formScript = file_get_contents($this->root . '/public/assets/js/form-detail-logic.js');
        $folderScript = file_get_contents($this->root . '/public/assets/js/folder-detail-logic.js');
        $pickerScript = file_get_contents($this->root . '/public/assets/js/forms-email-recipient-picker.js');

        self::assertStringContainsString('request_client_org_id()', (string)$formsList);
        self::assertStringContainsString('request_client_org_id()', (string)$handler);
        self::assertStringContainsString('WHERE project_id IS NULL OR project_id = 0', (string)$formsList);
        self::assertStringContainsString('fd.project_id IS NULL OR fd.project_id = 0', (string)$formDetail);
        self::assertStringContainsString('fd.project_id IS NULL OR fd.project_id = 0', (string)$folderDetail);
        self::assertStringNotContainsString('name="project_id"', (string)$formsList);
        self::assertStringNotContainsString('Project (Optional)', (string)$formDetail);
        foreach ([$formDetail, $folderDetail] as $detailView) {
            self::assertStringContainsString('data-forms-email-client-search', (string)$detailView);
            self::assertStringContainsString('data-forms-email-org-search', (string)$detailView);
            self::assertStringContainsString('/assets/js/forms-email-recipient-picker.js', (string)$detailView);
            self::assertStringNotContainsString('name="client_ids[]" value="<?php echo $client[\'id\']; ?>"', (string)$detailView);
            self::assertStringNotContainsString('-- Select a client --', (string)$detailView);
            self::assertStringNotContainsString('-- Select an organization --', (string)$detailView);
        }
        self::assertStringContainsString('FormsEmailRecipientPicker', (string)$formScript);
        self::assertStringContainsString('FormsEmailRecipientPicker', (string)$folderScript);
        self::assertStringContainsString('/?page=clients-search&term=', (string)$pickerScript);
        self::assertStringContainsString('/?page=organization/org-search&term=', (string)$pickerScript);
        self::assertStringContainsString('/?page=organization/organization-departments-options&organization_id=', (string)$pickerScript);
        self::assertStringContainsString('name="department_ids[]"', (string)$pickerScript);
        self::assertStringContainsString('function forms_email_recipients', (string)$handler);
        self::assertStringContainsString('organization_department_contacts', (string)$handler);
    }

    public function testContractSignatureLabelsAndPaymentReferencesArePreserved(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0011_contract_signature_labels.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $helper = file_get_contents($this->root . '/src/utils/contract_signatures.php');
        $contractCreate = file_get_contents($this->root . '/src/controllers/contract/contracts_create.php');
        $longTermDetails = file_get_contents($this->root . '/src/views/pages/contract/long-term-contract-details.php');
        $paymentView = file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        $paymentController = file_get_contents($this->root . '/src/controllers/payments_create.php');
        $paymentScript = file_get_contents($this->root . '/public/assets/js/payments-create-logic.js');

        self::assertStringContainsString('ADD COLUMN signer_title', (string)$migration);
        self::assertStringContainsString('signer_title VARCHAR(190) NULL', (string)$baseline);
        self::assertStringContainsString('pa_save_contract_signatures', (string)$helper);
        self::assertStringContainsString('pa_save_contract_signatures($pdo, $co_id', (string)$contractCreate);
        self::assertStringContainsString('$sig[\'signer_title\'] ?? \'Client Signature\'', (string)$longTermDetails);
        self::assertStringContainsString('data-remaining=', (string)$paymentView);
        self::assertStringContainsString('name="reference_number"', (string)$paymentView);
        self::assertStringContainsString('id="manualClientSearch"', (string)$paymentView);
        self::assertStringContainsString('id="manualClientId"', (string)$paymentView);
        self::assertStringNotContainsString('id="manualClientSelect"', (string)$paymentView);
        self::assertStringContainsString('reference_number', (string)$paymentController);
        self::assertStringContainsString('referenceLabel.textContent', (string)$paymentScript);
        self::assertStringContainsString('/?page=clients-search&term=', (string)$paymentScript);
        self::assertStringContainsString('Choose a client from the search results.', (string)$paymentScript);
    }

    public function testLegacyServerSchemaRepairsProtectAjaxPages(): void
    {
        $migration = file_get_contents($this->root . '/database/migrations/0012_activity_log_and_legacy_schema_repairs.sql');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $apiKeys = file_get_contents($this->root . '/src/views/pages/api-keys.php');
        $apiKeysSchema = file_get_contents($this->root . '/src/utils/api_keys_schema.php');
        $clientsList = file_get_contents($this->root . '/src/controllers/api/clients_list.php');
        $notifications = file_get_contents($this->root . '/src/utils/notifications.php');
        $dropbox = file_get_contents($this->root . '/src/controllers/settings/dropbox_oauth.php');
        $publicSign = file_get_contents($this->root . '/src/controllers/public_view/public_contract_sign.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS activity_log', (string)$migration);
        self::assertStringContainsString('ALTER TABLE api_keys ADD COLUMN name', (string)$migration);
        self::assertStringContainsString('ALTER TABLE payments ADD COLUMN reference_number', (string)$migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS activity_log', (string)$baseline);
        self::assertStringContainsString('pa_api_keys_existing_columns($pdo)', (string)$apiKeys);
        self::assertStringContainsString('schema repair incomplete', (string)$apiKeysSchema);
        self::assertStringContainsString('api_clients_table_has_column', (string)$clientsList);
        self::assertStringContainsString('bindValue(count($params) + 1, $limit, PDO::PARAM_INT)', (string)$clientsList);
        self::assertStringContainsString('ensure_activity_log_table($pdo)', (string)$notifications);
        self::assertStringContainsString('PHP_VERSION_ID < 80500', (string)$dropbox);
        self::assertStringContainsString('validate_and_store_upload', (string)$publicSign);
        self::assertStringNotContainsString('finfo_close', (string)$publicSign);
    }

    public function testPaymentsExpensesAndPdfPreviewsHandleLegacyServers(): void
    {
        $paymentController = file_get_contents($this->root . '/src/controllers/payments_create.php');
        $invoiceLifecycle = file_get_contents($this->root . '/src/utils/invoice_lifecycle.php');
        $expenseHandler = file_get_contents($this->root . '/src/controllers/financial/expense_handler.php');
        $expenseCreate = file_get_contents($this->root . '/src/views/pages/financial/expense-create.php');
        $formsList = file_get_contents($this->root . '/src/views/pages/financial/forms-list.php');
        $formDetail = file_get_contents($this->root . '/src/views/pages/financial/form-detail.php');
        $securityHeaders = file_get_contents($this->root . '/src/utils/security_headers.php');

        self::assertStringContainsString('invoice_ensure_payments_schema($pdo)', (string)$paymentController);
        self::assertStringContainsString("'organization_id' => $organization_id", (string)$paymentController);
        self::assertStringContainsString('function invoice_ensure_payments_schema', (string)$invoiceLifecycle);
        self::assertStringContainsString('ALTER TABLE payments ADD COLUMN {$column}', (string)$invoiceLifecycle);
        self::assertStringContainsString('$taxAmount = null;', (string)$expenseHandler);
        self::assertStringContainsString('$paymentMethod = null;', (string)$expenseHandler);
        self::assertStringNotContainsString('id="taxInput"', (string)$expenseCreate);
        self::assertStringNotContainsString('name="payment_method"', (string)$expenseCreate);
        self::assertStringNotContainsString('fetch(form.action', (string)$expenseCreate);
        self::assertStringContainsString('$_GET[\'error\']', (string)$expenseCreate);
        self::assertStringContainsString('#toolbar=0&navpanes=0&scrollbar=0&view=FitH', (string)$formsList);
        self::assertStringContainsString('Open PDF in new tab', (string)$formDetail);
        self::assertStringContainsString('X-Frame-Options: SAMEORIGIN', (string)$securityHeaders);
        self::assertStringContainsString("frame-src 'self'", (string)$securityHeaders);
        self::assertStringContainsString("frame-ancestors 'self'", (string)$securityHeaders);
    }

    public function testAdminPaymentAndContractNotificationsAreConfigurable(): void
    {
        $notifications = file_get_contents($this->root . '/src/utils/notifications.php');
        $settingsView = file_get_contents($this->root . '/src/views/pages/settings/notifications.php');
        $settingsHandler = file_get_contents($this->root . '/src/controllers/settings_handler.php');
        $appConfig = file_get_contents($this->root . '/src/config/app.php');
        $baseline = file_get_contents($this->root . '/database/baseline.sql');
        $projectBilling = file_get_contents($this->root . '/src/utils/project_invoice_billing.php');
        $legacyWebhook = file_get_contents($this->root . '/src/controllers/stripe/stripe_webhook.php');
        $manualPayments = file_get_contents($this->root . '/src/controllers/payments_create.php');

        foreach ([
            'notify_signed_contract_uploaded',
            'notify_invoice_paid',
            'notify_invoice_paid_regular',
            'notify_invoice_paid_on_demand',
            'notify_invoice_paid_long_term',
            'notify_invoice_paid_project',
        ] as $key) {
            self::assertStringContainsString($key, (string)$settingsView);
            self::assertStringContainsString($key, (string)$settingsHandler);
            self::assertStringContainsString($key, (string)$appConfig);
            self::assertStringContainsString($key, (string)$baseline);
        }

        self::assertStringContainsString("LOWER(email) <> 'admin@project-alpha.local'", (string)$notifications);
        self::assertStringContainsString("role IN ('admin','owner')", (string)$notifications);
        self::assertStringContainsString('foreach ($adminEmails as $adminEmail)', (string)$notifications);
        self::assertStringContainsString("notification_setting_enabled(\$appConfig, 'notify_signed_contract_uploaded', true)", (string)$notifications);
        self::assertStringContainsString('admin_invoice_paid_notification_enabled($appConfig, $invoice)', (string)$notifications);
        self::assertStringContainsString('function notify_admin_project_invoice_paid', (string)$notifications);
        self::assertStringContainsString('notify_admin_project_invoice_paid($pdo, $GLOBALS[\'appConfig\'] ?? [], $projectInvoiceId, $amount', (string)$projectBilling);
        self::assertStringContainsString('notify_admin_invoice_paid($pdo, $GLOBALS[\'appConfig\'] ?? [], $invoiceId, $paymentAmount, $status)', (string)$legacyWebhook);
        self::assertStringNotContainsString('notify_admin_invoice_paid', (string)$manualPayments);
    }
}
