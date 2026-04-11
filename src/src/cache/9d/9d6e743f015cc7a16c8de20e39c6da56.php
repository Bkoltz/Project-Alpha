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

/* pages\contract\regular-contract-details.twig */
class __TwigTemplate_fb0d04057bbeb1e77766baa8f1de9f82 extends Template
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
\t<div class=\"doc-type\" style=\"text-align:center;font-weight:700;font-size:22px;margin-bottom:6px\">Contract</div>
\t<div style=\"text-align:center;color:#6b7280;margin-bottom:16px;font-size:13px\">Valid for
\t\t";
        // line 4
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["app_config"] ?? null), "documents_valid_days", [], "any", false, false, false, 4), "html", null, true);
        yield "
\t\tdays</div>
\t<div class=\"no-print\" style=\"padding:12px 16px;background:";
        // line 6
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "bg", [], "any", false, false, false, 6), "html", null, true);
        yield ";color:";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "text", [], "any", false, false, false, 6), "html", null, true);
        yield ";border-left:4px solid ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "border", [], "any", false, false, false, 6), "html", null, true);
        yield ";border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px\">
\t\tStatus:
\t\t";
        // line 8
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 8), "html", null, true);
        yield "
\t</div>
\t<div class=\"no-print\" style=\"display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap\">
\t\t<a href=\"javascript:history.back()\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Back</a>
\t\t<a href=\"/?page=contract/contract-pdf&id=";
        // line 12
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" target=\"_blank\" rel=\"noopener\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">View PDF</a>
\t\t<a href=\"/?page=contract/contract-pdf&id=";
        // line 13
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" download=\"contract-";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "document_id", [], "any", false, false, false, 13), "html", null, true);
        yield ".pdf\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Download</a>

\t\t";
        // line 15
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 15) == "pending")) {
            // line 16
            yield "\t\t\t<a href=\"/?page=contract/contracts-edit&id=";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Edit</a>
\t\t";
        }
        // line 18
        yield "
\t\t";
        // line 19
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 19) !== "cancelled")) {
            // line 20
            yield "\t\t\t<form method=\"post\" action=\"/?page=email-send\" style=\"display:inline\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 21
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"contract\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 23
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"redirect_to\" value=\"<?php echo htmlspecialchars(\$_SERVER['REQUEST_URI']); ?>\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Email</button>
\t\t\t</form>

\t\t\t<form method=\"post\" action=\"/?page=contract/contract-sign\" enctype=\"multipart/form-data\" style=\"display:inline-flex;gap:6px;align-items:center\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 29
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 30
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input id=\"upload-signed\" type=\"file\" name=\"signed_pdf\" accept=\"application/pdf\" style=\"display:none\" onchange=\"this.form.submit()\">
\t\t\t\t<button type=\"button\" onclick=\"document.getElementById('upload-signed').click()\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">
\t\t\t\t\t";
            // line 33
            yield (((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "signed_pdf_path", [], "any", false, false, false, 33)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("Upload Signed PDF") : ("Replace Signed PDF"));
            yield "
\t\t\t\t</button>
\t\t\t</form>
\t\t";
        }
        // line 37
        yield "
\t\t";
        // line 38
        if (CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "signed_pdf_path", [], "any", true, true, false, 38)) {
            // line 39
            yield "\t\t\t<a href=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "signed_pdf_path", [], "any", false, false, false, 39), "html", null, true);
            yield "\" target=\"_blank\" rel=\"noopener\" style=\"padding:6px 10px;border:1px solid #10b981;border-radius:8px;background:#ecfdf5;color:#065f46; font-size: medium;\">View Signed PDF</a>
\t\t";
        }
        // line 41
        yield "
\t\t";
        // line 42
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "needs_deposit", [], "any", false, false, false, 42) && (CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 42) == "pending"))) {
            // line 43
            yield "\t\t\t<form method=\"post\" action=\"/?page=contract/contract-deposit-received\" style=\"display:inline\" onsubmit=\"return confirm('Mark deposit as received (\$";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "received_deposit", [], "any", false, false, false, 43), "html", null, true);
            yield ")\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 44
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 45
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46; font-size: medium;\">Deposit Received (\$";
            // line 46
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "received_deposit", [], "any", false, false, false, 46), "html", null, true);
            yield ")</button>
\t\t\t</form>
\t\t";
        }
        // line 49
        yield "
\t\t";
        // line 50
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 50) != "active")) {
            // line 51
            yield "\t\t\t<form method=\"post\" action=\"/?page=contract/contract-complete\" style=\"display:inline\" onsubmit=\"return confirm('Mark this contract as completed and set invoice due date?');\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 52
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 53
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: medium;\">Complete</button>
\t\t\t</form>
\t\t";
        }
        // line 57
        yield "
\t\t";
        // line 58
        if (((CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 58) != "cancelled") && (CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 58) != "completed"))) {
            // line 59
            yield "\t\t\t<form method=\"post\" action=\"/?page=contract/contract-void\" onsubmit=\"return confirm('Void this contract and linked invoices?')\" style=\"display:inline\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 60
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 61
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#6b7280;color:#fff; font-size: medium;\">Void</button>
\t\t\t</form>
\t\t";
        }
        // line 65
        yield "
\t\t";
        // line 66
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "status", [], "any", false, false, false, 66) != "cancelled")) {
            // line 67
            yield "\t\t\t<form method=\"post\" action=\"/?page=document-reenable\" style=\"display:inline\" onsubmit=\"return confirm('Re-enable this contract? It will be set back to pending status and related invoices will be restored.');\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 68
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"contract\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 70
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fef3c7;color:#92400e; font-size: medium;\">Re-enable</button>
\t\t\t</form>
\t\t";
        }
        // line 74
        yield "
\t\t<form method=\"post\" action=\"/?page=document-date-update\" style=\"display:inline\" onsubmit=\"return confirm('Update document date to today? This will refresh the date shown on the PDF.');\">
\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
        // line 76
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
        yield "\">
\t\t\t<input type=\"hidden\" name=\"type\" value=\"contract\">
\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
        // line 78
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\">
\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#dbeafe;color:#1e40af; font-size: medium;\">Update Document Date</button>
\t\t</form>
\t</div>

\t";
        // line 83
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "reenabled", [], "any", false, false, false, 83))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 84
            yield "\t\t<div class=\"no-print\" style=\"padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px\">✓ Contract re-enabled successfully</div>
\t";
        }
        // line 86
        yield "
\t";
        // line 87
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["contract"] ?? null), "updatedAt", [], "any", false, false, false, 87))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 88
            yield "\t\t<div class=\"no-print\" style=\"padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px\">✓ Document date updated successfully</div>
\t";
        }
        // line 90
        yield "
\t";
        // line 91
        yield from $this->load("partials/document_details/display/date-details-display.twig", 91)->unwrap()->yield(CoreExtension::merge($context, ["document" => ($context["contract"] ?? null)]));
        // line 92
        yield "
\t";
        // line 93
        yield from $this->load("partials/document_details/display/project-details-display.twig", 93)->unwrap()->yield(CoreExtension::merge($context, ["document" => ($context["contract"] ?? null), "branding" => ($context["branding"] ?? null)]));
        // line 94
        yield "\t
\t";
        // line 95
        yield from $this->load("partials/document_details/display/custom-field-display.twig", 95)->unwrap()->yield(CoreExtension::merge($context, ["custom_fields" => ($context["custom_fields"] ?? null)]));
        // line 96
        yield "
\t";
        // line 97
        yield from $this->load("partials/document_details/display/contact-information-display.twig", 97)->unwrap()->yield(CoreExtension::merge($context, ["contact_information" => ($context["contact_information"] ?? null)]));
        // line 98
        yield "
\t";
        // line 99
        yield from $this->load("partials/document_details/display/scope-display.twig", 99)->unwrap()->yield(CoreExtension::merge($context, ["document" => ($context["contract"] ?? null)]));
        // line 100
        yield "
\t";
        // line 101
        yield from $this->load("partials/document_details/display/items-table-display.twig", 101)->unwrap()->yield(CoreExtension::merge($context, ["items" => ($context["items"] ?? null)]));
        // line 102
        yield "
\t";
        // line 103
        yield from $this->load("partials/document_details/display/total-details-display.twig", 103)->unwrap()->yield(CoreExtension::merge($context, ["document" => ($context["contract"] ?? null)]));
        // line 104
        yield "
\t";
        // line 105
        yield from $this->load("partials/document_details/display/signature-display.twig", 105)->unwrap()->yield(CoreExtension::merge($context, ["signatures" => ($context["signatures"] ?? null)]));
        // line 106
        yield "
\t";
        // line 107
        yield from $this->load("partials/document_details/display/terms-display.twig", 107)->unwrap()->yield(CoreExtension::merge($context, ["document" => ($context["contract"] ?? null)]));
        // line 108
        yield "
</section>
<style>
\t.no-print {
\t\tdisplay: flex
\t}

\t.print-footer {
\t\tdisplay: none
\t}

\t@media print {
\t\t.no-print {
\t\t\tdisplay: none !important
\t\t}

\t\t.side-nav,
\t\t.nav-footer {
\t\t\tdisplay: none
\t\t}

\t\t.main-content {
\t\t\tmargin-left: 0
\t\t}

\t\tbody {
\t\t\tbackground: #fff
\t\t}

\t\t.print-footer {
\t\t\tdisplay: block;
\t\t\tposition: fixed;
\t\t\tbottom: 6px;
\t\t\tleft: 12px;
\t\t\tcolor: #374151;
\t\t\tfont-size: 12px
\t\t}
\t}
</style>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages\\contract\\regular-contract-details.twig";
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
        return array (  296 => 108,  294 => 107,  291 => 106,  289 => 105,  286 => 104,  284 => 103,  281 => 102,  279 => 101,  276 => 100,  274 => 99,  271 => 98,  269 => 97,  266 => 96,  264 => 95,  261 => 94,  259 => 93,  256 => 92,  254 => 91,  251 => 90,  247 => 88,  245 => 87,  242 => 86,  238 => 84,  236 => 83,  228 => 78,  223 => 76,  219 => 74,  212 => 70,  207 => 68,  204 => 67,  202 => 66,  199 => 65,  192 => 61,  188 => 60,  185 => 59,  183 => 58,  180 => 57,  173 => 53,  169 => 52,  166 => 51,  164 => 50,  161 => 49,  155 => 46,  151 => 45,  147 => 44,  142 => 43,  140 => 42,  137 => 41,  131 => 39,  129 => 38,  126 => 37,  119 => 33,  113 => 30,  109 => 29,  100 => 23,  95 => 21,  92 => 20,  90 => 19,  87 => 18,  81 => 16,  79 => 15,  72 => 13,  68 => 12,  61 => 8,  52 => 6,  47 => 4,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages\\contract\\regular-contract-details.twig", "/var/www/src/views/pages/contract/regular-contract-details.twig");
    }
}
