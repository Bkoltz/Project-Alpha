<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* pages\contract\contract-create.twig */
class __TwigTemplate_c58a5b89e3fb7f15e3f1e4422720afdc extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 1
        yield "<section>
\t<h2>Create Contract</h2>
\t<div id=\"taxExemptBannerCo\" style=\"display:none;margin:12px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#78350f\">
\t\t<strong>ℹ️ Tax Exempt Organization:</strong>
\t\tThe selected client's organization has a tax-exempt form on file. You can still choose whether to charge taxes.
\t</div>
\t<form id=\"coCreateForm\" method=\"post\" action=\"/?page=contract/contracts-create\" style=\"display:grid;gap:16px;max-width:900px\">
\t\t<input type=\"hidden\" name=\"_token\" value=\"";
        // line 8
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["view"] ?? null), "csrf", [], "any", false, false, false, 8), "html", null, true);
        yield "\">
\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
        // line 9
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["view"] ?? null), "token", [], "any", false, false, false, 9), "html", null, true);
        yield "\">
\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">

\t\t\t";
        // line 12
        yield from $this->load("partials/client_components/client-input-autocomplete.twig", 12)->unwrap()->yield(CoreExtension::merge($context, ["document" => null]));
        // line 13
        yield "
\t\t\t<label>
\t\t\t\t<div>Tax (%)</div>
\t\t\t\t<input id=\"taxPercentCo\" type=\"number\" step=\"0.01\" name=\"tax_percent\" value=\"0\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t</label>
\t\t\t<label>
\t\t\t\t<div>Discount Type</div>
\t\t\t\t<select id=\"discountTypeCo\" name=\"discount_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t<option value=\"none\">None</option>
\t\t\t\t\t<option value=\"percent\">Percent</option>
\t\t\t\t\t<option value=\"fixed\">Fixed \$</option>
\t\t\t\t</select>
\t\t\t</label>
\t\t\t<label>
\t\t\t\t<div>Discount Value</div>
\t\t\t\t<input id=\"discountValueCo\" type=\"number\" step=\"0.01\" name=\"discount_value\" value=\"0\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t</label>
\t\t</div>

\t\t<div id=\"projectSectionCo\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;margin:12px 0\">
\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">Project Association</h3>
\t\t\t<div style=\"display:grid;gap:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Add to Existing Project</div>
\t\t\t\t\t<select id=\"projectSelectCo\" name=\"project_id\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t<option value=\"\">-- Select Project --</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t\t<div style=\"text-align:center;color:#6b7280;font-size:13px\">or</div>
\t\t\t\t<div>
\t\t\t\t\t<button type=\"button\" id=\"createProjectBtnCo\" style=\"padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;width:100%\">Create New Project</button>
\t\t\t\t</div>
\t\t\t</div>
\t\t</div>

\t\t<div id=\"customFieldsContainer\">
\t\t\t";
        // line 49
        yield from $this->load("partials/document_details/input/custom-field-input.twig", 49)->unwrap()->yield(CoreExtension::merge($context, ["custom_fields" => ($context["custom_fields"] ?? null)]));
        // line 50
        yield "\t\t</div>

\t\t<div style=\"margin:12px 0\">
\t\t\t<div style=\"font-weight:600;margin-bottom:8px\">Document Type</div>
\t\t\t<div style=\"display:flex;gap:24px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb\">
\t\t\t\t<label style=\"display:flex;align-items:center;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"doc_type\" value=\"regular\" checked onchange=\"toggleDocTypeFields()\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600\">Regular</div>
\t\t\t\t\t\t<div style=\"font-size:12px;color:#6b7280\">One-time contract</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t\t<label style=\"display:flex;align-items:center;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"doc_type\" value=\"long_term\" onchange=\"toggleDocTypeFields()\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600\">Long Term</div>
\t\t\t\t\t\t<div style=\"font-size:12px;color:#6b7280\">Recurring billing</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t\t<label style=\"display:flex;align-items:center;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"doc_type\" value=\"on_demand\" onchange=\"toggleDocTypeFields()\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600\">On-Demand</div>
\t\t\t\t\t\t<div style=\"font-size:12px;color:#6b7280\">Manual invoicing</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t</div>
\t\t</div>

\t\t<div id=\"longTermFields\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb\">
\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">Recurring Billing Settings</h3>

\t\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">
\t\t\t\t<label>
\t\t\t\t\t<div>Start Date *</div>
\t\t\t\t\t<input id=\"startDateFieldCo\" type=\"date\" name=\"start_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t\t<label>
\t\t\t\t\t<div>Contract Duration *</div>
\t\t\t\t\t<select id=\"endDateTypeCo\" name=\"end_date_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" onchange=\"toggleEndDate()\">
\t\t\t\t\t\t<option value=\"ongoing\">Ongoing (Until Terminated)</option>
\t\t\t\t\t\t<option value=\"fixed\">Fixed End Date</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"endDateFieldCo\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>End Date *</div>
\t\t\t\t\t<input type=\"date\" name=\"end_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Bill Every *</div>
\t\t\t\t\t<select id=\"billingIntervalCount\" name=\"billing_interval_count\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t<option value=\"1\">1</option>
\t\t\t\t\t\t<option value=\"2\">2</option>
\t\t\t\t\t\t<option value=\"3\">3</option>
\t\t\t\t\t\t<option value=\"6\">6</option>
\t\t\t\t\t\t<option value=\"12\">12</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t\t<label>
\t\t\t\t\t<div>Period *</div>
\t\t\t\t\t<select id=\"billingIntervalUnit\" name=\"billing_interval_unit\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t<option value=\"day\">Day(s)</option>
\t\t\t\t\t\t<option value=\"week\">Week(s)</option>
\t\t\t\t\t\t<option value=\"month\" selected>Month(s)</option>
\t\t\t\t\t\t<option value=\"year\">Year(s)</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"margin-top:16px;padding:12px;background:#fef3c7;border-radius:8px;border:1px solid #fbbf24\">
\t\t\t\t<div style=\"font-weight:600;margin-bottom:8px;color:#92400e\">How should the client be billed?</div>
\t\t\t\t<label style=\"display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" id=\"recurringPerInvoice\" name=\"pricing_type\" value=\"per_invoice\" checked onchange=\"togglePricingFields()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Recurring Amount</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Client pays the same amount on each invoice (e.g., \$20/month)</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t\t<label id=\"fixedTotalOption\" style=\"display:flex;align-items:start;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" id=\"recurringFixedTotal\" name=\"pricing_type\" value=\"fixed_total\" onchange=\"togglePricingFields()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Fixed Total (Billed Over Time)</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Total contract amount is divided across invoices until paid in full</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"perInvoiceField\" style=\"margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Amount Per Invoice *
\t\t\t\t\t\t<span style=\"font-size:13px;color:#6b7280;font-weight:normal\">(before tax & discount)</span>
\t\t\t\t\t</div>
\t\t\t\t\t<input id=\"pricePerInvoiceInput\" type=\"number\" step=\"0.01\" name=\"price_per_invoice\" placeholder=\"20.00\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" oninput=\"recalcCo()\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"fixedTotalFieldsCo\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Number of Invoices *
\t\t\t\t\t\t<span style=\"font-size:13px;color:#6b7280;font-weight:normal\">(how many invoices to divide the total across)</span>
\t\t\t\t\t</div>
\t\t\t\t\t<input id=\"invoiceCountInputCo\" type=\"number\" step=\"1\" min=\"1\" name=\"invoice_count\" placeholder=\"4\" value=\"4\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" oninput=\"recalcCo()\">
\t\t\t\t</label>
\t\t\t\t<div id=\"calculatedPricePerInvoiceCo\" style=\"margin-top:8px;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px\">
\t\t\t\t\t<div style=\"display:flex;justify-content:space-between;align-items:center\">
\t\t\t\t\t\t<div style=\"font-weight:600;color:#065f46\">Price Per Invoice:</div>
\t\t\t\t\t\t<div id=\"calcPriceValCo\" style=\"font-size:16px;font-weight:700;color:#065f46\">\$0.00</div>
\t\t\t\t\t</div>
\t\t\t\t</div>
\t\t\t</div>

\t\t\t<div id=\"discountWarning\" style=\"display:none;margin-top:12px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:13px\">
\t\t\t\t<strong>Note:</strong>
\t\t\t\tFor ongoing contracts, discounts apply to each invoice, not the total contract value.
\t\t\t</div>
\t\t</div>

\t\t<div id=\"onDemandFieldsCo\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb\">
\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">On-Demand Contract Settings</h3>

\t\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">
\t\t\t\t<label>
\t\t\t\t\t<div>Start Date</div>
\t\t\t\t\t<input id=\"onDemandStartDateCo\" type=\"date\" name=\"od_start_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t\t<label>
\t\t\t\t\t<div>Contract Duration</div>
\t\t\t\t\t<select id=\"onDemandEndDateTypeCo\" name=\"od_end_date_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" onchange=\"toggleOnDemandEndDateCo()\">
\t\t\t\t\t\t<option value=\"ongoing\">Ongoing (Until Terminated)</option>
\t\t\t\t\t\t<option value=\"fixed\">Fixed End Date</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"onDemandEndDateFieldCo\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>End Date</div>
\t\t\t\t\t<input type=\"date\" name=\"od_end_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"margin-top:16px;padding:12px;background:#e0f2fe;border-radius:8px;border:1px solid #7dd3fc\">
\t\t\t\t<div style=\"font-weight:600;margin-bottom:8px;color:#0369a1\">How do you want to specify pricing?</div>
\t\t\t\t<label style=\"display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"od_pricing_mode\" value=\"items\" checked onchange=\"toggleOnDemandPricingModeCo()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Use Line Items</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Add individual items with quantities and prices</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t\t<label style=\"display:flex;align-items:start;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"od_pricing_mode\" value=\"flat\" onchange=\"toggleOnDemandPricingModeCo()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Flat Amount</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Enter a single amount without itemized details</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"onDemandFlatAmountCo\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Contract Amount *
\t\t\t\t\t\t<span style=\"font-size:13px;color:#6b7280;font-weight:normal\">(before tax & discount)</span>
\t\t\t\t\t</div>
\t\t\t\t\t<input id=\"onDemandAmountInputCo\" type=\"number\" step=\"0.01\" name=\"od_flat_amount\" placeholder=\"0.00\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" oninput=\"recalcCo()\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"margin-top:12px;padding:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:13px\">
\t\t\t\t<strong>ℹ️ Note:</strong>
\t\t\t\tOn-demand contracts allow you to generate invoices manually as needed. No recurring billing schedule is set.
\t\t\t</div>
\t\t</div>

\t\t<div>
\t\t\t<div style=\"font-weight:600;margin-bottom:8px\">Items</div>
\t\t\t<div id=\"itemsCo\" style=\"display:grid;gap:8px\"></div>
\t\t\t<button type=\"button\" onclick=\"addItemCo()\" style=\"margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff\">+ Add Item</button>
\t\t</div>

\t\t";
        // line 236
        yield from $this->load("partials/document_details/input/scope-input.twig", 236)->unwrap()->yield(CoreExtension::merge($context, ["app_config" => ($context["app_config"] ?? null), "document" => null]));
        // line 237
        yield "
\t\t";
        // line 238
        yield from $this->load("partials/document_details/input/notes-input.twig", 238)->unwrap()->yield(CoreExtension::merge($context, ["document" => null]));
        // line 239
        yield "
\t\t<div id=\"invoiceAmountRow\" style=\"display:none;margin-top:8px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px\">
\t\t\t<div style=\"display:flex;justify-content:space-between;align-items:center\">
\t\t\t\t<div style=\"font-weight:600;color:#065f46\">Amount Per Invoice:</div>
\t\t\t\t<div id=\"invoiceAmountVal\" style=\"font-size:18px;font-weight:700;color:#065f46\">\$0.00</div>
\t\t\t</div>
\t\t</div>

\t\t";
        // line 247
        yield from $this->load("partials/document_details/input/total-input.twig", 247)->unwrap()->yield(CoreExtension::merge($context, ["document" => null]));
        // line 248
        yield "
\t\t<!-- Signatures Section -->
\t\t<div style=\"border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb\">
\t\t\t<h3 style=\"margin:0 0 8px 0;font-size:15px\">Contract Signatures</h3>
\t\t\t<p style=\"margin:0 0 12px 0;font-size:13px;color:var(--muted)\">Add up to 5 signatures for this contract</p>

\t\t\t<div id=\"signaturesList\" style=\"display:grid;gap:12px\"></div>

\t\t\t<button type=\"button\" onclick=\"addSignature()\" id=\"addSigBtn\" style=\"margin-top:12px;padding:8px 14px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px\">
\t\t\t\t+ Add Signature
\t\t\t</button>
\t\t</div>

\t\t<div>
\t\t\t<button type=\"submit\" style=\"padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600\">Create Contract</button>
\t\t</div>
\t</form>
</section>
<script src=\"js/contracts-create-logic.js\"></script>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages\\contract\\contract-create.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  310 => 248,  308 => 247,  298 => 239,  296 => 238,  293 => 237,  291 => 236,  103 => 50,  101 => 49,  63 => 13,  61 => 12,  55 => 9,  51 => 8,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages\\contract\\contract-create.twig", "/var/www/src/views/pages/contract/contract-create.twig");
    }
}
