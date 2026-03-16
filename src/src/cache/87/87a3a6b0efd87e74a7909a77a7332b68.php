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

/* pages/quote/quotes-create.twig */
class __TwigTemplate_c8300555d8932182dfb110e350d5af45 extends Template
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
\t<h2>Create Quote</h2>
\t<div id=\"taxExemptBanner\" style=\"display:none;margin:12px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#78350f\">
\t\t<strong>ℹ️ Tax Exempt Organization:</strong>
\t\tThe selected client's organization has a tax-exempt form on file. You can still choose whether to charge taxes.
\t</div>
\t<form id=\"quoteForm\" method=\"post\" action=\"/?page=quote/quotes-create\" style=\"display:grid;gap:16px;max-width:900px\">
\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
        // line 8
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrfToken"] ?? null), "html", null, true);
        yield "\">
\t\t<div
\t\t\tstyle=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">

\t\t\t<!-- Client input -->
\t\t\t<label style=\"grid-column:1/2;position:relative\">
\t\t\t\t<div>Client</div>
\t\t\t\t<input id=\"clientInput\" type=\"text\" placeholder=\"Type client name...\" autocomplete=\"off\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t<input id=\"clientId\" type=\"hidden\" name=\"client_id\">
\t\t\t\t<div id=\"clientSuggest\" style=\"position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto\"></div>
\t\t\t</label>

\t\t\t<!-- Tax input -->
\t\t\t<label style=\"grid-column:2/3\">
\t\t\t\t<div>Tax (%)</div>
\t\t\t\t<input id=\"taxPercent\" type=\"number\" step=\"0.01\" name=\"tax_percent\" value=\"0\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t</label>

\t\t\t<!-- Project selection -->
\t\t\t<label>
\t\t\t\t<div id=\"projectSection\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;margin:12px 0\">
\t\t\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">Project Association</h3>
\t\t\t\t\t<div style=\"display:grid;gap:12px\">
\t\t\t\t\t\t<label>
\t\t\t\t\t\t\t<div>Add to Existing Project</div>
\t\t\t\t\t\t\t<select id=\"projectSelect\" name=\"project_id\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t\t\t<option value=\"\">-- Select Project --</option>
\t\t\t\t\t\t\t</select>
\t\t\t\t\t\t</label>
\t\t\t\t\t\t<div style=\"text-align:center;color:#6b7280;font-size:13px\">or</div>
\t\t\t\t\t\t<div>
\t\t\t\t\t\t\t<button type=\"button\" id=\"createProjectBtn\" style=\"padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;width:100%\">Create New Project</button>
\t\t\t\t\t\t</div>
\t\t\t\t\t</div>
\t\t\t\t</div>

\t\t\t\t<!-- Discount inputs -->
\t\t\t\t<div>Discount Type</div>
\t\t\t\t<select id=\"discountType\" name=\"discount_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t<option value=\"none\">None</option>
\t\t\t\t\t<option value=\"percent\">Percent</option>
\t\t\t\t\t<option value=\"fixed\">Fixed \$</option>
\t\t\t\t</select>
\t\t\t</label>
\t\t\t<label>
\t\t\t\t<div>Discount Value</div>
\t\t\t\t<input id=\"discountValue\" type=\"number\" step=\"0.01\" name=\"discount_value\" value=\"0\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t</label>
\t\t</div>

\t\t<div id=\"customFieldsContainer\">
\t\t\t";
        // line 59
        yield from $this->load("partials/custom_fields/custom_field.twig", 59)->unwrap()->yield(CoreExtension::merge($context, ["fields" => ($context["fields"] ?? null), "columns" => ($context["columns"] ?? null), "idSuffix" => ($context["idSuffix"] ?? null)]));
        // line 60
        yield "\t\t</div>
\t\t
\t\t<!-- Billing type -->
\t\t<div style=\"margin:12px 0\">
\t\t\t<div style=\"font-weight:600;margin-bottom:8px\">Document Type</div>
\t\t\t<div style=\"display:flex;gap:24px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb\">
\t\t\t\t<label style=\"display:flex;align-items:center;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"doc_type\" value=\"regular\" checked onchange=\"toggleDocTypeFields()\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600\">Regular</div>
\t\t\t\t\t\t<div style=\"font-size:12px;color:#6b7280\">One-time quote</div>
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

\t\t<!-- Recurring billing -->
\t\t<div id=\"longTermFields\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb\">
\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">Recurring Billing Settings</h3>

\t\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">
\t\t\t\t<label>
\t\t\t\t\t<div>Start Date *</div>
\t\t\t\t\t<input id=\"startDateField\" type=\"date\" name=\"lt_start_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t\t<label>
\t\t\t\t\t<div>Contract Duration *</div>
\t\t\t\t\t<select id=\"endDateType\" name=\"lt_end_date_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" onchange=\"toggleEndDate()\">
\t\t\t\t\t\t<option value=\"ongoing\">Ongoing (Until Terminated)</option>
\t\t\t\t\t\t<option value=\"fixed\">Fixed End Date</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"endDateField\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>End Date *</div>
\t\t\t\t\t<input type=\"date\" name=\"lt_end_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"billingIntervalFields\" style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Bill Every *</div>
\t\t\t\t\t<select id=\"billingIntervalCount\" name=\"lt_billing_interval_count\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t<option value=\"1\">1</option>
\t\t\t\t\t\t<option value=\"2\">2</option>
\t\t\t\t\t\t<option value=\"3\">3</option>
\t\t\t\t\t\t<option value=\"6\">6</option>
\t\t\t\t\t\t<option value=\"12\">12</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t\t<label>
\t\t\t\t\t<div>Period *</div>
\t\t\t\t\t<select id=\"billingIntervalUnit\" name=\"lt_billing_interval_unit\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
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
\t\t\t\t\t<input type=\"radio\" id=\"recurringPerInvoice\" name=\"lt_pricing_type\" value=\"per_invoice\" checked onchange=\"togglePricingFields()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Recurring Amount</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Client pays the same amount on each invoice (e.g., \$20/month)</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t\t<label id=\"fixedTotalOption\" style=\"display:flex;align-items:start;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" id=\"recurringFixedTotal\" name=\"lt_pricing_type\" value=\"fixed_total\" onchange=\"togglePricingFields()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Fixed Total (Billed Over Time)</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Total quote amount is divided across invoices until paid in full</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"perInvoiceField\" style=\"margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Amount Per Invoice *
\t\t\t\t\t\t<span style=\"font-size:13px;color:#6b7280;font-weight:normal\">(before tax & discount)</span>
\t\t\t\t\t</div>
\t\t\t\t\t<input id=\"pricePerInvoiceInput\" type=\"number\" step=\"0.01\" name=\"lt_price_per_invoice\" placeholder=\"20.00\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" oninput=\"recalc()\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"fixedTotalFields\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Number of Invoices *
\t\t\t\t\t\t<span style=\"font-size:13px;color:#6b7280;font-weight:normal\">(how many invoices to divide the total across)</span>
\t\t\t\t\t</div>
\t\t\t\t\t<input id=\"invoiceCountInput\" type=\"number\" step=\"1\" min=\"1\" name=\"invoice_count\" placeholder=\"4\" value=\"4\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" oninput=\"recalc()\">
\t\t\t\t</label>
\t\t\t\t<div id=\"calculatedPricePerInvoice\" style=\"margin-top:8px;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px\">
\t\t\t\t\t<div style=\"display:flex;justify-content:space-between;align-items:center\">
\t\t\t\t\t\t<div style=\"font-weight:600;color:#065f46\">Price Per Invoice:</div>
\t\t\t\t\t\t<div id=\"calcPriceVal\" style=\"font-size:16px;font-weight:700;color:#065f46\">\$0.00</div>
\t\t\t\t\t</div>
\t\t\t\t</div>
\t\t\t</div>

\t\t\t<div id=\"discountWarning\" style=\"display:none;margin-top:12px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:13px\">
\t\t\t\t<strong>Note:</strong>
\t\t\t\tFor ongoing quotes, discounts apply to each invoice, not the total contract value.
\t\t\t</div>
\t\t</div>

\t\t<div id=\"onDemandFields\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb\">
\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">On-Demand Quote Settings</h3>

\t\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">
\t\t\t\t<label>
\t\t\t\t\t<div>Start Date</div>
\t\t\t\t\t<input id=\"onDemandStartDate\" type=\"date\" name=\"od_start_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t\t<label>
\t\t\t\t\t<div>Contract Duration</div>
\t\t\t\t\t<select id=\"onDemandEndDateType\" name=\"od_end_date_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" onchange=\"toggleOnDemandEndDate()\">
\t\t\t\t\t\t<option value=\"ongoing\">Ongoing (Until Terminated)</option>
\t\t\t\t\t\t<option value=\"fixed\">Fixed End Date</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"onDemandEndDateField\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>End Date</div>
\t\t\t\t\t<input type=\"date\" name=\"od_end_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"margin-top:16px;padding:12px;background:#e0f2fe;border-radius:8px;border:1px solid #7dd3fc\">
\t\t\t\t<div style=\"font-weight:600;margin-bottom:8px;color:#0369a1\">How do you want to specify pricing?</div>
\t\t\t\t<label style=\"display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"od_pricing_mode\" value=\"items\" checked onchange=\"toggleOnDemandPricingMode()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Use Line Items</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Add individual items with quantities and prices</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t\t<label style=\"display:flex;align-items:start;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"od_pricing_mode\" value=\"flat\" onchange=\"toggleOnDemandPricingMode()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Flat Amount</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Enter a single amount without itemized details</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"onDemandFlatAmount\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Quote Amount *
\t\t\t\t\t\t<span style=\"font-size:13px;color:#6b7280;font-weight:normal\">(before tax & discount)</span>
\t\t\t\t\t</div>
\t\t\t\t\t<input id=\"onDemandAmountInput\" type=\"number\" step=\"0.01\" name=\"od_flat_amount\" placeholder=\"0.00\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" oninput=\"recalc()\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"margin-top:12px;padding:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:13px\">
\t\t\t\t<strong>ℹ️ Note:</strong>
\t\t\t\tOn-demand quotes allow you to generate invoices manually as needed. No recurring billing schedule is set.
\t\t\t</div>
\t\t</div>

\t\t<div id=\"onDemandFields\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb\">
\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">On-Demand Quote Settings</h3>

\t\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">
\t\t\t\t<label>
\t\t\t\t\t<div>Start Date</div>
\t\t\t\t\t<input id=\"onDemandStartDate\" type=\"date\" name=\"od_start_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t\t<label>
\t\t\t\t\t<div>Contract Duration</div>
\t\t\t\t\t<select id=\"onDemandEndDateType\" name=\"od_end_date_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" onchange=\"toggleOnDemandEndDate()\">
\t\t\t\t\t\t<option value=\"ongoing\">Ongoing (Until Terminated)</option>
\t\t\t\t\t\t<option value=\"fixed\">Fixed End Date</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"onDemandEndDateField\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>End Date</div>
\t\t\t\t\t<input type=\"date\" name=\"od_end_date\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"margin-top:16px;padding:12px;background:#e0f2fe;border-radius:8px;border:1px solid #7dd3fc\">
\t\t\t\t<div style=\"font-weight:600;margin-bottom:8px;color:#0369a1\">How do you want to specify pricing?</div>
\t\t\t\t<label style=\"display:flex;align-items:start;gap:8px;margin-bottom:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"od_pricing_mode\" value=\"items\" checked onchange=\"toggleOnDemandPricingMode()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Use Line Items</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Add individual items with quantities and prices</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t\t<label style=\"display:flex;align-items:start;gap:8px;cursor:pointer\">
\t\t\t\t\t<input type=\"radio\" name=\"od_pricing_mode\" value=\"flat\" onchange=\"toggleOnDemandPricingMode()\" style=\"margin-top:3px\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div style=\"font-weight:600;color:#374151\">Flat Amount</div>
\t\t\t\t\t\t<div style=\"font-size:13px;color:#6b7280\">Enter a single amount without itemized details</div>
\t\t\t\t\t</div>
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div id=\"onDemandFlatAmount\" style=\"display:none;margin-top:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Quote Amount *
\t\t\t\t\t\t<span style=\"font-size:13px;color:#6b7280;font-weight:normal\">(before tax & discount)</span>
\t\t\t\t\t</div>
\t\t\t\t\t<input id=\"onDemandAmountInput\" type=\"number\" step=\"0.01\" name=\"od_flat_amount\" placeholder=\"0.00\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" oninput=\"recalc()\">
\t\t\t\t</label>
\t\t\t</div>

\t\t\t<div style=\"margin-top:12px;padding:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:13px\">
\t\t\t\t<strong>ℹ️ Note:</strong>
\t\t\t\tOn-demand quotes allow you to generate invoices manually as needed. No recurring billing schedule is set.
\t\t\t</div>
\t\t</div>

\t\t<!-- Items input -->
\t\t<div>
\t\t\t<div style=\"font-weight:600;margin-bottom:8px\">Items</div>
\t\t\t<div id=\"items\" style=\"display:grid;gap:8px\"></div>
\t\t\t<button type=\"button\" onclick=\"addItem()\" style=\"margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff\">+ Add Item</button>
\t\t</div>

\t\t<!-- Scope -->
\t\t";
        // line 308
        yield "\t\t<label>
\t\t\t<div>Scope of Work</div>
\t\t\t<textarea name=\"scope\" rows=\"4\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" placeholder=\"Optional: Describe the scope of work and deliverables...\"></textarea>
\t\t</label>
\t\t";
        // line 313
        yield "
\t\t<label>
\t\t\t<div>Job Notes (shared across related docs)</div>
\t\t\t<textarea name=\"project_notes\" rows=\"3\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" placeholder=\"Notes visible to you (not the client PDF)\"></textarea>
\t\t</label>

\t\t<div id=\"invoiceAmountRow\" style=\"display:none;margin-top:8px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px\">
\t\t\t<div style=\"display:flex;justify-content:space-between;align-items:center\">
\t\t\t\t<div style=\"font-weight:600;color:#065f46\">Amount Per Invoice:</div>
\t\t\t\t<div id=\"invoiceAmountVal\" style=\"font-size:18px;font-weight:700;color:#065f46\">\$0.00</div>
\t\t\t</div>
\t\t</div>

\t\t<!-- Totals -->
\t\t<div id=\"totals\" style=\"margin-top:8px;display:grid;gap:6px;justify-content:end\">
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Subtotal</div>
\t\t\t\t<div id=\"subtotalVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Discount</div>
\t\t\t\t<div id=\"discountVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Tax</div>
\t\t\t\t<div id=\"taxVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end;font-weight:700\">
\t\t\t\t<div style=\"min-width:140px;text-align:right\">Total</div>
\t\t\t\t<div id=\"totalVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t\t<div id=\"depositRow\" style=\"display:flex;border-top:1px solid #e5e7eb;padding-top:6px;margin-top:6px\">
\t\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t\t<div style=\"min-width:140px;text-align:right;color:#059669;font-weight:700;font-size:15px\">Deposit Due</div>
\t\t\t\t\t<div id=\"depositVal\" style=\"min-width:120px;text-align:right;color:#059669;font-weight:700;font-size:15px\">\$0.00</div>
\t\t\t\t</div>
\t\t\t</div>
\t\t</div>

\t\t<div>
\t\t\t<button type=\"submit\" style=\"padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600\">Create Quote</button>
\t\t</div>
\t</form>
</section>

<script src=\"js/quotes-create-logic.js\" defer></script>
<script src=\"js/client-selection-dropdown-logic.js\" defer></script>";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages/quote/quotes-create.twig";
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
        return array (  362 => 313,  356 => 308,  107 => 60,  105 => 59,  51 => 8,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/quote/quotes-create.twig", "/var/www/src/views/pages/quote/quotes-create.twig");
    }
}
