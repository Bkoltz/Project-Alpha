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

/* pages/invoice/invoice-create.twig */
class __TwigTemplate_b8107e5d72c64164b8047dd33a12b3f3 extends Template
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
\t<h2>Create Invoice</h2>
\t<div id=\"taxExemptBannerInv\" style=\"display:none;margin:12px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#78350f\">
\t\t<strong>ℹ️ Tax Exempt Organization:</strong>
\t\tThe selected client's organization has a tax-exempt form on file. You can still choose whether to charge taxes.
\t</div>
\t<form id=\"invoiceForm\" method=\"post\" action=\"/?page=invoices-create\" style=\"display:grid;gap:16px;max-width:900px\">
\t\t<input type=\"hidden\" name=\"csrf\" value=\"<?php echo csrf_token(); ?>\">
\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr\">

\t\t\t";
        // line 11
        yield from $this->load("partials/client_components/client-input-autocomplete.twig", 11)->unwrap()->yield($context);
        // line 12
        yield "\t\t\t
\t\t\t<label>
\t\t\t\t<div>Due Date</div>
\t\t\t\t<input type=\"date\" name=\"due_date\" value=\"<?php echo htmlspecialchars(\$defaultDue); ?>\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t</label>
\t\t\t<label>
\t\t\t\t<div>Tax (%)</div>
\t\t\t\t<input id=\"taxPercentInv\" type=\"number\" step=\"0.01\" name=\"tax_percent\" value=\"0\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t</label>
\t\t\t<label>
\t\t\t\t<div>Discount Type</div>
\t\t\t\t<select id=\"discountTypeInv\" name=\"discount_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t<option value=\"none\">None</option>
\t\t\t\t\t<option value=\"percent\">Percent</option>
\t\t\t\t\t<option value=\"fixed\">Fixed \$</option>
\t\t\t\t</select>
\t\t\t</label>
\t\t\t<label>
\t\t\t\t<div>Discount Value</div>
\t\t\t\t<input id=\"discountValueInv\" type=\"number\" step=\"0.01\" name=\"discount_value\" value=\"0\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t</label>
\t\t</div>

\t\t<div id=\"customFieldsContainer\">
\t\t\t";
        // line 36
        yield from $this->load("partials/document_details/input/custom-field-input.twig", 36)->unwrap()->yield(CoreExtension::merge($context, ["fields" => ($context["fields"] ?? null), "columns" => ($context["columns"] ?? null), "idSuffix" => ($context["idSuffix"] ?? null)]));
        // line 37
        yield "\t\t</div>

\t\t<div id=\"projectSectionInv\" style=\"display:none;border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb;margin:12px 0\">
\t\t\t<h3 style=\"margin:0 0 12px 0;color:#374151\">Project Association</h3>
\t\t\t<div style=\"display:grid;gap:12px\">
\t\t\t\t<label>
\t\t\t\t\t<div>Add to Existing Project</div>
\t\t\t\t\t<select id=\"projectSelectInv\" name=\"project_id\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t<option value=\"\">-- Select Project --</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t\t<div style=\"text-align:center;color:#6b7280;font-size:13px\">or</div>
\t\t\t\t<div>
\t\t\t\t\t<button type=\"button\" id=\"createProjectBtnInv\" style=\"padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;width:100%\">Create New Project</button>
\t\t\t\t</div>
\t\t\t</div>
\t\t</div>

\t\t<div>
\t\t\t<div style=\"font-weight:600;margin-bottom:8px\">Items</div>
\t\t\t<div id=\"itemsInv\" style=\"display:grid;gap:8px\"></div>
\t\t\t<button type=\"button\" onclick=\"addItemInv()\" style=\"margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff\">+ Add Item</button>
\t\t</div>

\t\t";
        // line 61
        yield from $this->load("partials/document_details/input/notes-input.twig", 61)->unwrap()->yield($context);
        // line 62
        yield "
\t\t<div id=\"totalsInv\" style=\"margin-top:8px;display:grid;gap:6px;justify-content:end\">
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Subtotal</div>
\t\t\t\t<div id=\"subtotalValInv\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Discount</div>
\t\t\t\t<div id=\"discountValInv\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Tax</div>
\t\t\t\t<div id=\"taxValInv\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end;font-weight:700\">
\t\t\t\t<div style=\"min-width:140px;text-align:right\">Total</div>
\t\t\t\t<div id=\"totalValInv\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t\t</div>
\t\t</div>

\t\t<div>
\t\t\t<button type=\"submit\" style=\"padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600\">Create Invoice</button>
\t\t</div>
\t</form>
</section>

<script src=\"js/invoices-create-logic.js\" defer></script>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages/invoice/invoice-create.twig";
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
        return array (  112 => 62,  110 => 61,  84 => 37,  82 => 36,  56 => 12,  54 => 11,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/invoice/invoice-create.twig", "/var/www/src/views/pages/invoice/invoice-create.twig");
    }
}
