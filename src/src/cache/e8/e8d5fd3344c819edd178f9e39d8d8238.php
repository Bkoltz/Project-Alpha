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

/* partials/document_list/details/on-demand-quote-details.twig */
class __TwigTemplate_f2d211677f8cd824f2cdbe5a510cc9dd extends Template
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
\t<div class=\"doc-type\" style=\"text-align:center;font-weight:700;font-size:22px;margin-bottom:6px\">On-Demand Quote</div>
\t<div style=\"text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px\">
\t\tValid for
\t\t";
        // line 5
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "documents_valid_days", [], "any", true, true, false, 5) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "documents_valid_days", [], "any", false, false, false, 5)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "documents_valid_days", [], "any", false, false, false, 5), "html", null, true)) : (14));
        yield "
\t\tdays
\t</div>

\t";
        // line 10
        yield "\t<div class=\"no-print\" style=\"padding:12px 16px;background:";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "bg", [], "any", false, false, false, 10), "html", null, true);
        yield ";color:";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "text", [], "any", false, false, false, 10), "html", null, true);
        yield ";border-left:4px solid ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "border", [], "any", false, false, false, 10), "html", null, true);
        yield ";border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px\">
\t\tStatus:
\t\t";
        // line 12
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 12), "html", null, true);
        yield "
\t</div>
\t<div class=\"no-print\" style=\"display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap\">
\t\t<a href=\"javascript:history.back()\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Back</a>
\t\t<a href=\"/?page=quote/quote-pdf&id=";
        // line 16
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" target=\"_blank\" rel=\"noopener\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">View PDF</a>
\t\t<a href=\"/?page=quote/quote-pdf&id=";
        // line 17
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" download=\"quote-";
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 17) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 17)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 17), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 17), "html", null, true)));
        yield ".pdf\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Download</a>

\t\t";
        // line 19
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 19) === "pending")) {
            // line 20
            yield "\t\t\t<a href=\"/?page=quote/quote-edit&id=";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Edit</a>
\t\t";
        }
        // line 22
        yield "
\t\t";
        // line 23
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 23)) && (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 23) != "rejected"))) {
            // line 24
            yield "\t\t\t<form method=\"post\" action=\"/?page=email-send\" style=\"display:inline\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 25
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"redirect_to\" value=\"";
            // line 28
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["requestUri"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:medium;\">Email</button>
\t\t\t</form>
\t\t";
        }
        // line 32
        yield "
\t\t";
        // line 33
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 33) == "pending")) {
            // line 34
            yield "\t\t\t<form method=\"post\" action=\"/?page=quote/quote-approve\" style=\"display:inline\" onsubmit=\"return confirm('Approve this quote and generate contract + invoice?');\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 35
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 36
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff;font-size:medium;\">Approve</button>
\t\t\t</form>
\t\t\t<form method=\"post\" action=\"/?page=quote/quote-reject\" style=\"display:inline\" onsubmit=\"return confirm('Deny this quote?');\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 40
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 41
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff;font-size:medium;\">Deny</button>
\t\t\t</form>
\t\t";
        }
        // line 45
        yield "
\t\t";
        // line 46
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 46)) && (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 46) == "rejected"))) {
            // line 47
            yield "\t\t\t<form method=\"post\" action=\"/?page=document-reenable\" style=\"display:inline\" onsubmit=\"return confirm('Re-enable this quote? It will be set back to pending status.');\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 48
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 50
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fef3c7;color:#92400e;font-size:medium;\">Re-enable</button>
\t\t\t</form>
\t\t";
        }
        // line 54
        yield "
\t\t<form method=\"post\" action=\"/?page=document-date-update\" style=\"display:inline\" onsubmit=\"return confirm('Update document date to today? This will refresh the date shown on the PDF.');\">
\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
        // line 56
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
        yield "\">
\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
        // line 58
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\">
\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#dbeafe;color:#1e40af;font-size:medium;\">Update Document Date</button>
\t\t</form>
\t</div>

\t";
        // line 63
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["getParams"] ?? null), "error", [], "any", false, false, false, 63))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 64
            yield "\t\t<div class=\"no-print\" style=\"padding:8px 12px;background:#fee2e2;color:#991b1b;border-radius:6px;margin-bottom:8px;font-size:14px\">⚠ Error:
\t\t\t";
            // line 65
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["getParams"] ?? null), "error", [], "any", false, false, false, 65), "html", null, true);
            yield "</div>
\t";
        }
        // line 67
        yield "
\t";
        // line 68
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["getParams"] ?? null), "reenabled", [], "any", false, false, false, 68))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 69
            yield "\t\t<div class=\"no-print\" style=\"padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px\">✓ Quote re-enabled successfully</div>
\t";
        }
        // line 71
        yield "
\t";
        // line 72
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["getParams"] ?? null), "date_updated", [], "any", false, false, false, 72))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 73
            yield "\t\t<div class=\"no-print\" style=\"padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px\">✓ Document date updated successfully</div>
\t";
        }
        // line 75
        yield "
\t<div class=\"no-print\" style=\"padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151\">
\t\t<strong>Created:</strong>
\t\t";
        // line 78
        yield (((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "created_at", [], "any", false, false, false, 78))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "created_at", [], "any", false, false, false, 78), "M j, Y g:i A"), "html", null, true)) : ("N/A"));
        yield "

\t\t<span style=\"margin:0 8px\">|</span>

\t\t<strong>Document Date:</strong>
\t\t";
        // line 83
        yield (((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date", [], "any", false, false, false, 83))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date", [], "any", false, false, false, 83), "M j, Y g:i A"), "html", null, true)) : ("N/A"));
        yield "

\t\t";
        // line 85
        if (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date_updated_at", [], "any", true, true, false, 85)) {
            // line 86
            yield "\t\t\t<span style=\"margin-left:8px;color:#6b7280;font-size:12px\">(Updated:
\t\t\t\t";
            // line 87
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date_updated_at", [], "any", false, false, false, 87), "M j, Y g:i A"), "html", null, true);
            yield ")</span>
\t\t";
        }
        // line 89
        yield "\t</div>

\t";
        // line 92
        yield "\t<table style=\"width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:middle;width:70%\">
\t\t\t\t<div style=\"font-weight:700;font-size:20px\">";
        // line 95
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "</div>
\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Quote ODQ-";
        // line 96
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 96) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 96)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 96), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 96), "html", null, true)));
        yield "</div>
\t\t\t\t";
        // line 97
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 97))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 98
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Job
\t\t\t\t\t\t";
            // line 99
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 99), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 101
        yield "\t\t\t\t";
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 101))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 102
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Project
\t\t\t\t\t\t";
            // line 103
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 103), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 105
        yield "\t\t\t</td>
\t\t\t<td style=\"vertical-align:middle;width:30%;text-align:right\">
\t\t\t\t<img src=\"";
        // line 107
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["logoPath"] ?? null), "html", null, true);
        yield "\" alt=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "\" style=\"height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px\">
\t\t\t</td>
\t\t</tr>
\t</table>

\t";
        // line 113
        yield "\t";
        // line 114
        yield "\t<table style=\"width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb\">
\t\t<tr>
\t\t\t";
        // line 116
        if ((($tmp = ($context["showDepositInfo"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 117
            yield "\t\t\t\t<td style=\"padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Deposit Due:
\t\t\t\t\t\t<span style=\"font-weight:600;color:#059669\">\$";
            // line 119
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(($context["depositCalc"] ?? null), 2), "html", null, true);
            yield "</span>
\t\t\t\t\t</div>
\t\t\t\t</td>
\t\t\t";
        }
        // line 123
        yield "\t\t\t";
        if ((($tmp = ($context["showFulfillmentDate"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 124
            yield "\t\t\t\t<td style=\"padding:8px;";
            yield (((($tmp = ($context["hasCustomFields"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("border-right:1px solid #e5e7eb;") : (""));
            yield "vertical-align:top\">
\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Fulfillment Date:
\t\t\t\t\t\t<span style=\"font-weight:600;color:#2563eb\">";
            // line 126
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(($context["fulfillmentDate"] ?? null), "M j, Y"), "html", null, true);
            yield "</span>
\t\t\t\t\t</div>
\t\t\t\t</td>
\t\t\t";
        }
        // line 130
        yield "\t\t\t";
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["customFields"] ?? null));
        foreach ($context['_seq'] as $context["idx"] => $context["customField"]) {
            // line 131
            yield "\t\t\t\t<td style=\"padding:8px;";
            yield ((($context["idx"] < (Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["customFields"] ?? null)) - 1))) ? ("border-right:1px solid #e5e7eb;") : (""));
            yield "vertical-align:top\">
\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">";
            // line 132
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "label", [], "any", false, false, false, 132));
            yield ":
\t\t\t\t\t\t<span style=\"font-weight:600;color:#374151\">";
            // line 133
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "value", [], "any", false, false, false, 133));
            yield "</span>
\t\t\t\t\t</div>
\t\t\t\t</td>
\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['idx'], $context['customField'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 137
        yield "\t\t</tr>
\t</table>
\t";
        // line 140
        yield "
\t";
        // line 142
        yield "\t<table style=\"width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:top;width:50%;padding-right:12px\">
\t\t\t\t<div style=\"font-weight:600\">From</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 147
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["fromLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 148
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 150
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 153
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 154
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 156
        yield "
\t\t\t\t\t";
        // line 157
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 158
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 160
        yield "\t\t\t\t</div>
\t\t\t</td>

\t\t\t<td style=\"vertical-align:top;width:50%;padding-left:12px\">
\t\t\t\t<div style=\"font-weight:600\">To</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 166
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["toLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 167
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 169
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 172
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 173
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 175
        yield "
\t\t\t\t\t";
        // line 176
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 177
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 179
        yield "\t\t\t\t</div>
\t\t\t</td>
\t\t</tr>
\t</table>

\t";
        // line 185
        yield "\t";
        if ((($tmp = ($context["scope_enabled"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 186
            yield "\t\t<div style=\"page-break-before:auto;margin-top:20px\">
\t\t\t<h3 style=\"font-size:18px;font-weight:700;margin-bottom:12px;color:#111\">Scope of Project</h3>
\t\t\t<div style=\"white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px\"><?php echo nl2br(htmlspecialchars(\$scopeText)); ?></div>
\t\t</div>
\t\t<div style=\"page-break-after:always\"></div>
\t";
        }
        // line 192
        yield "
\t";
        // line 194
        yield "\t";
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["items"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 195
            yield "\t\t<table style=\"width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t\t\t<thead>
\t\t\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t\t\t\t<th style=\"padding:10px;width:25%;vertical-align:top;text-align:center\">Item</th>
\t\t\t\t\t<th style=\"padding:10px;width:35%;vertical-align:top\">Description</th>
\t\t\t\t\t<th style=\"padding:10px;width:10%;text-align:right;vertical-align:top\">Qty</th>
\t\t\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Unit Price</th>
\t\t\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Line Total</th>
\t\t\t\t</tr>
\t\t\t</thead>
\t\t\t<tbody>
\t\t\t\t";
            // line 206
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
                // line 207
                yield "\t\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6\">
\t\t\t\t\t\t<td style=\"padding:10px;font-weight:600;vertical-align:top;text-align:center\">";
                // line 208
                yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", true, true, false, 208) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 208)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 208), "html", null, true)) : (""));
                yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px;color:#6b7280;font-size:13px;vertical-align:top\">";
                // line 209
                yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", true, true, false, 209) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 209)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 209), "html", null, true)) : (""));
                yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">";
                // line 210
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "quantity", [], "any", false, false, false, 210), 2), "html", null, true);
                yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
                // line 211
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "unit_price", [], "any", false, false, false, 211), 2), "html", null, true);
                yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
                // line 212
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "line_total", [], "any", false, false, false, 212), 2), "html", null, true);
                yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 215
            yield "\t\t\t</tbody>
\t\t</table>
\t";
        }
        // line 218
        yield "
\t";
        // line 220
        yield "\t<table style=\"width:100%;border-collapse:collapse;margin-top:12px\">
\t\t<tr>
\t\t\t<td style=\"width:60%\"></td>
\t\t\t<td style=\"width:40%\">
\t\t\t\t<table style=\"width:100%;border-collapse:collapse\">
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Subtotal</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;width:120px\">\$";
        // line 227
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "subtotal", [], "any", false, false, false, 227), "html", null, true);
        yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Discount</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">
\t\t\t\t\t\t\t";
        // line 232
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 232) == "percent")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 232), 2) . "%"), "html", null, true)) : ((((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 232) == "fixed")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(("\$" . $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 232), 2)), "html", null, true)) : ("\$0.00"))));
        yield "
\t\t\t\t\t\t</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Tax</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">";
        // line 237
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "tax_percent", [], "any", false, false, false, 237), 2), "html", null, true);
        yield "%</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr style=\"border-top:2px solid #e5e7eb\">
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700\">Total</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700;font-size:16px\">\$";
        // line 241
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "total", [], "any", false, false, false, 241), 2), "html", null, true);
        yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t</table>
\t\t\t</td>
\t\t</tr>
\t</table>

\t";
        // line 249
        yield "\t";
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 249)) || (CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 249) == 1))) {
            // line 250
            yield "\t\t<div style=\"page-break-after:always\"></div>
\t\t<h3>Terms and Conditions</h3>
\t\t<div style=\"white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222\">
\t\t\t";
            // line 253
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["termsText"] ?? null), "html", null, true);
            yield "
\t\t</div>
\t";
        }
        // line 256
        yield "</section>
<style>
\t.no-print {
\t\t\t\t\t\t\t\t\t\t\t\tdisplay: flex
\t\t\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t\t\t.print-footer {
\t\t\t\t\t\t\t\t\t\t\t\tdisplay: none
\t\t\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t\t\t@media print {
\t\t\t\t\t\t\t\t\t\t\t\t.no-print {
\t\t\t\t\t\t\t\t\t\t\t\t\tdisplay: none !important
\t\t\t\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t\t\t\t.side-nav,
\t\t\t\t\t\t\t\t\t\t\t\t.nav-footer {
\t\t\t\t\t\t\t\t\t\t\t\t\tdisplay: none
\t\t\t\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t\t\t\t.main-content {
\t\t\t\t\t\t\t\t\t\t\t\t\tmargin-left: 0
\t\t\t\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t\t\t\tbody {
\t\t\t\t\t\t\t\t\t\t\t\t\tbackground: #fff
\t\t\t\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t\t\t\t.print-footer {
\t\t\t\t\t\t\t\t\t\t\t\t\tdisplay: block;
\t\t\t\t\t\t\t\t\t\t\t\t\tposition: fixed;
\t\t\t\t\t\t\t\t\t\t\t\t\tbottom: 6px;
\t\t\t\t\t\t\t\t\t\t\t\t\tleft: 12px;
\t\t\t\t\t\t\t\t\t\t\t\t\tcolor: #374151;
\t\t\t\t\t\t\t\t\t\t\t\t\tfont-size: 12px
\t\t\t\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t\t\t}
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/details/on-demand-quote-details.twig";
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
        return array (  578 => 256,  572 => 253,  567 => 250,  564 => 249,  554 => 241,  547 => 237,  539 => 232,  531 => 227,  522 => 220,  519 => 218,  514 => 215,  505 => 212,  501 => 211,  497 => 210,  493 => 209,  489 => 208,  486 => 207,  482 => 206,  469 => 195,  466 => 194,  463 => 192,  455 => 186,  452 => 185,  445 => 179,  439 => 177,  437 => 176,  434 => 175,  428 => 173,  426 => 172,  421 => 169,  412 => 167,  408 => 166,  400 => 160,  394 => 158,  392 => 157,  389 => 156,  383 => 154,  381 => 153,  376 => 150,  367 => 148,  363 => 147,  356 => 142,  353 => 140,  349 => 137,  339 => 133,  335 => 132,  330 => 131,  325 => 130,  318 => 126,  312 => 124,  309 => 123,  302 => 119,  298 => 117,  296 => 116,  292 => 114,  290 => 113,  280 => 107,  276 => 105,  271 => 103,  268 => 102,  265 => 101,  260 => 99,  257 => 98,  255 => 97,  251 => 96,  247 => 95,  242 => 92,  238 => 89,  233 => 87,  230 => 86,  228 => 85,  223 => 83,  215 => 78,  210 => 75,  206 => 73,  204 => 72,  201 => 71,  197 => 69,  195 => 68,  192 => 67,  187 => 65,  184 => 64,  182 => 63,  174 => 58,  169 => 56,  165 => 54,  158 => 50,  153 => 48,  150 => 47,  148 => 46,  145 => 45,  138 => 41,  134 => 40,  127 => 36,  123 => 35,  120 => 34,  118 => 33,  115 => 32,  108 => 28,  104 => 27,  99 => 25,  96 => 24,  94 => 23,  91 => 22,  85 => 20,  83 => 19,  76 => 17,  72 => 16,  65 => 12,  55 => 10,  48 => 5,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/details/on-demand-quote-details.twig", "/var/www/src/views/partials/document_list/details/on-demand-quote-details.twig");
    }
}
