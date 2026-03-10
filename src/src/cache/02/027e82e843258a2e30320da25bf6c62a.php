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

/* pages/quote/quote-details.twig */
class __TwigTemplate_23290e8ec3c4f01fbdc78e610d9c3559 extends Template
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
\t<div class=\"doc-type\" style=\"text-align:center;font-weight:700;font-size:22px;margin-bottom:6px\">Quote</div>
\t<div style=\"text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px\">
\t\tValid for
\t\t";
        // line 5
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "documents_valid_days", [], "any", true, true, false, 5) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "documents_valid_days", [], "any", false, false, false, 5)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "documents_valid_days", [], "any", false, false, false, 5), "html", null, true)) : (14));
        yield "
\t\tdays
\t</div>

  ";
        // line 10
        yield "\t";
        if ((($tmp =  !($context["isPDF"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 11
            yield "\t\t<div class=\"no-print\" style=\"padding:12px 16px;background:";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "bg", [], "any", false, false, false, 11), "html", null, true);
            yield ";color:";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "text", [], "any", false, false, false, 11), "html", null, true);
            yield ";border-left:4px solid ";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "border", [], "any", false, false, false, 11), "html", null, true);
            yield ";border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px\">
\t\t\tStatus:
\t\t\t";
            // line 13
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 13), "html", null, true);
            yield "
\t\t</div>
\t\t<div class=\"no-print\" style=\"display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap\">
\t\t\t<a href=\"javascript:history.back()\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Back</a>
\t\t\t<a href=\"/?page=quote/quote-pdf&id=";
            // line 17
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\" target=\"_blank\" rel=\"noopener\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">View PDF</a>
\t\t\t<a href=\"/?page=quote/quote-pdf&id=";
            // line 18
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\" download=\"quote-";
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 18) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 18)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 18), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 18), "html", null, true)));
            yield ".pdf\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Download</a>
\t\t\t
      ";
            // line 20
            if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 20) === "pending")) {
                // line 21
                yield "\t\t\t\t<a href=\"/?page=quote/quotes-edit&id=";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
                yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Edit</a>
\t\t\t";
            }
            // line 23
            yield "
\t\t\t";
            // line 24
            if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 24)) && (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 24) != "rejected"))) {
                // line 25
                yield "\t\t\t\t<form method=\"post\" action=\"/?page=email-send\" style=\"display:inline\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 26
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 28
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"redirect_to\" value=\"";
                // line 29
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["requestUri"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:medium;\">Email</button>
\t\t\t\t</form>
\t\t\t";
            }
            // line 33
            yield "
\t\t\t";
            // line 34
            if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 34) == "pending")) {
                // line 35
                yield "\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-approve\" style=\"display:inline\" onsubmit=\"return confirm('Approve this quote and generate contract + invoice?');\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 36
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 37
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff;font-size:medium;\">Approve</button>
\t\t\t\t</form>
\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-reject\" style=\"display:inline\" onsubmit=\"return confirm('Deny this quote?');\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 41
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 42
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff;font-size:medium;\">Deny</button>
\t\t\t\t</form>
\t\t\t";
            }
            // line 46
            yield "
\t\t\t";
            // line 47
            if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 47)) && (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 47) == "rejected"))) {
                // line 48
                yield "\t\t\t\t<form method=\"post\" action=\"/?page=document-reenable\" style=\"display:inline\" onsubmit=\"return confirm('Re-enable this quote? It will be set back to pending status.');\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 49
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 51
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fef3c7;color:#92400e;font-size:medium;\">Re-enable</button>
\t\t\t\t</form>
\t\t\t";
            }
            // line 55
            yield "
\t\t\t<form method=\"post\" action=\"/?page=document-date-update\" style=\"display:inline\" onsubmit=\"return confirm('Update document date to today? This will refresh the date shown on the PDF.');\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 57
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 59
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#dbeafe;color:#1e40af;font-size:medium;\">Update Document Date</button>
\t\t\t</form>
\t\t</div>
";
            // line 75
            yield "
\t\t<div class=\"no-print\" style=\"padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151\">
\t\t\t<strong>Created:</strong>
\t\t\t";
            // line 78
            yield (((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "created_at", [], "any", false, false, false, 78))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "created_at", [], "any", false, false, false, 78), "M j, Y g:i A"), "html", null, true)) : ("N/A"));
            yield "

\t\t\t<span style=\"margin:0 8px\">|</span>

\t\t\t<strong>Document Date:</strong>
\t\t\t";
            // line 83
            yield (((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date", [], "any", false, false, false, 83))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date", [], "any", false, false, false, 83), "M j, Y g:i A"), "html", null, true)) : ("N/A"));
            yield "

\t\t\t";
            // line 85
            if (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date_updated_at", [], "any", true, true, false, 85)) {
                // line 86
                yield "\t\t\t\t<span style=\"margin-left:8px;color:#6b7280;font-size:12px\">(Updated:
\t\t\t\t\t";
                // line 87
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date_updated_at", [], "any", false, false, false, 87), "M j, Y g:i A"), "html", null, true);
                yield ")</span>
\t\t\t";
            }
            // line 89
            yield "\t\t</div>
\t";
        }
        // line 91
        yield "
  ";
        // line 93
        yield "\t<table style=\"width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:middle;width:70%\">
\t\t\t\t<div style=\"font-weight:700;font-size:20px\">";
        // line 96
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "</div>
\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Quote Q-";
        // line 97
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 97) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 97)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 97), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 97), "html", null, true)));
        yield "</div>
\t\t\t\t";
        // line 98
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 98))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 99
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Job
\t\t\t\t\t\t";
            // line 100
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 100), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 102
        yield "\t\t\t\t";
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 102))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 103
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Project
\t\t\t\t\t\t";
            // line 104
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 104), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 106
        yield "\t\t\t</td>
\t\t\t<td style=\"vertical-align:middle;width:30%;text-align:right\">
\t\t\t\t<img src=\"";
        // line 108
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["imageSrc"] ?? null), "html", null, true);
        yield "\" alt=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "\" style=\"height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px\">
\t\t\t</td>
\t\t</tr>
\t</table>

  ";
        // line 114
        yield "\t";
        if (((($context["showDepositInfo"] ?? null) || ($context["showFulfillmentDate"] ?? null)) || ($context["hasCustomFields"] ?? null))) {
            // line 115
            yield "\t\t<table style=\"width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb\">
\t\t\t<tr>
\t\t\t\t";
            // line 117
            if ((($tmp = ($context["showDepositInfo"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 118
                yield "\t\t\t\t\t<td style=\"padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Deposit Due:
\t\t\t\t\t\t\t<span style=\"font-weight:600;color:#059669\">\$";
                // line 120
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(($context["depositCalc"] ?? null), 2), "html", null, true);
                yield "</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t</td>
\t\t\t\t";
            }
            // line 124
            yield "\t\t\t\t";
            if ((($tmp = ($context["showFulfillmentDate"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 125
                yield "\t\t\t\t\t<td style=\"padding:8px;";
                yield (((($tmp = ($context["hasCustomFields"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("border-right:1px solid #e5e7eb;") : (""));
                yield "vertical-align:top\">
\t\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Fulfillment Date:
\t\t\t\t\t\t\t<span style=\"font-weight:600;color:#2563eb\">";
                // line 127
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(($context["fulfillmentDate"] ?? null), "M j, Y"), "html", null, true);
                yield "</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t</td>
\t\t\t\t";
            }
            // line 131
            yield "\t\t\t\t";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["displayCustomFields"] ?? null));
            foreach ($context['_seq'] as $context["idx"] => $context["customField"]) {
                // line 132
                yield "\t\t\t\t\t<td style=\"padding:8px;";
                yield ((($context["idx"] < (Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["displayCustomFields"] ?? null)) - 1))) ? ("border-right:1px solid #e5e7eb;") : (""));
                yield "vertical-align:top\">
\t\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">";
                // line 133
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "label", [], "any", false, false, false, 133));
                yield ":
\t\t\t\t\t\t\t<span style=\"font-weight:600;color:#374151\">";
                // line 134
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "value", [], "any", false, false, false, 134));
                yield "</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t</td>
\t\t\t\t";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['idx'], $context['customField'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 138
            yield "\t\t\t</tr>
\t\t</table>
\t";
        }
        // line 141
        yield "
  ";
        // line 143
        yield "\t<table style=\"width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:top;width:50%;padding-right:12px\">
\t\t\t\t<div style=\"font-weight:600\">From</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 148
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["fromLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 149
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 151
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 154
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 155
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 157
        yield "
\t\t\t\t\t";
        // line 158
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 159
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 161
        yield "\t\t\t\t</div>
\t\t\t</td>

\t\t\t<td style=\"vertical-align:top;width:50%;padding-left:12px\">
\t\t\t\t<div style=\"font-weight:600\">To</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 167
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["toLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 168
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 170
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 173
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 174
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 176
        yield "
\t\t\t\t\t";
        // line 177
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 178
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 180
        yield "\t\t\t\t</div>
\t\t\t</td>
\t\t</tr>
\t</table>

\t";
        // line 186
        yield "\t";
        if ((($tmp = ($context["scope_enabled"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 187
            yield "\t\t<div style=\"page-break-before:auto;margin-top:20px\">
\t\t\t<h3 style=\"font-size:18px;font-weight:700;margin-bottom:12px;color:#111\">Scope of Project</h3>
\t\t\t<div style=\"white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px\"><?php echo nl2br(htmlspecialchars(\$scopeText)); ?></div>
\t\t</div>
\t\t<div style=\"page-break-after:always\"></div>
\t";
        }
        // line 193
        yield "
\t";
        // line 195
        yield "\t<table style=\"width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t\t<thead>
\t\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t\t\t<th style=\"padding:10px;width:25%;vertical-align:top;text-align:center\">Item</th>
\t\t\t\t<th style=\"padding:10px;width:35%;vertical-align:top\">Description</th>
\t\t\t\t<th style=\"padding:10px;width:10%;text-align:right;vertical-align:top\">Qty</th>
\t\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Unit Price</th>
\t\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Line Total</th>
\t\t\t</tr>
\t\t</thead>
\t\t<tbody>
\t\t\t";
        // line 206
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
            // line 207
            yield "\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6\">
\t\t\t\t\t<td style=\"padding:10px;font-weight:600;vertical-align:top;text-align:center\">";
            // line 208
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", true, true, false, 208) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 208)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 208), "html", null, true)) : (""));
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;color:#6b7280;font-size:13px;vertical-align:top\">";
            // line 209
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", true, true, false, 209) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 209)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 209), "html", null, true)) : (""));
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">";
            // line 210
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "quantity", [], "any", false, false, false, 210), 2), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
            // line 211
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "unit_price", [], "any", false, false, false, 211), 2), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
            // line 212
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "line_total", [], "any", false, false, false, 212), 2), "html", null, true);
            yield "</td>
\t\t\t\t</tr>
\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 215
        yield "\t\t</tbody>
\t</table>

  ";
        // line 219
        yield "\t<table style=\"width:100%;border-collapse:collapse;margin-top:12px\">
\t\t<tr>
\t\t\t<td style=\"width:60%\"></td>
\t\t\t<td style=\"width:40%\">
\t\t\t\t<table style=\"width:100%;border-collapse:collapse\">
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Subtotal</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;width:120px\">\$";
        // line 226
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "subtotal", [], "any", false, false, false, 226), "html", null, true);
        yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Discount</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">
\t\t\t\t\t\t\t";
        // line 231
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 231) == "percent")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 231), 2) . "%"), "html", null, true)) : ((((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 231) == "fixed")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(("\$" . $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 231), 2)), "html", null, true)) : ("\$0.00"))));
        yield "
\t\t\t\t\t\t</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Tax</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">";
        // line 236
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "tax_percent", [], "any", false, false, false, 236), 2), "html", null, true);
        yield "%</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr style=\"border-top:2px solid #e5e7eb\">
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700\">Total</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700;font-size:16px\">\$";
        // line 240
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "total", [], "any", false, false, false, 240), 2), "html", null, true);
        yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t</table>
\t\t\t</td>
\t\t</tr>
\t</table>

  ";
        // line 248
        yield "\t";
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 248)) || (CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 248) == 1))) {
            // line 249
            yield "\t\t<div style=\"page-break-after:always\"></div>
\t\t<h3>Terms and Conditions</h3>
\t\t<div style=\"white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222\">
\t\t\t";
            // line 252
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["termsText"] ?? null), "html", null, true);
            yield "
\t\t</div>
\t";
        }
        // line 255
        yield "</section>
<style>
\t.no-print {
\t\t\t\t\t\t\t\t\tdisplay: flex
\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t.print-footer {
\t\t\t\t\t\t\t\t\tdisplay: none
\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t@media print {
\t\t\t\t\t\t\t\t\t.no-print {
\t\t\t\t\t\t\t\t\t\tdisplay: none !important
\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t.side-nav,
\t\t\t\t\t\t\t\t\t.nav-footer {
\t\t\t\t\t\t\t\t\t\tdisplay: none
\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t.main-content {
\t\t\t\t\t\t\t\t\t\tmargin-left: 0
\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\tbody {
\t\t\t\t\t\t\t\t\t\tbackground: #fff
\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t\t.print-footer {
\t\t\t\t\t\t\t\t\t\tdisplay: block;
\t\t\t\t\t\t\t\t\t\tposition: fixed;
\t\t\t\t\t\t\t\t\t\tbottom: 6px;
\t\t\t\t\t\t\t\t\t\tleft: 12px;
\t\t\t\t\t\t\t\t\t\tcolor: #374151;
\t\t\t\t\t\t\t\t\t\tfont-size: 12px
\t\t\t\t\t\t\t\t\t}
\t\t\t\t\t\t\t\t}
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages/quote/quote-details.twig";
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
        return array (  551 => 255,  545 => 252,  540 => 249,  537 => 248,  527 => 240,  520 => 236,  512 => 231,  504 => 226,  495 => 219,  490 => 215,  481 => 212,  477 => 211,  473 => 210,  469 => 209,  465 => 208,  462 => 207,  458 => 206,  445 => 195,  442 => 193,  434 => 187,  431 => 186,  424 => 180,  418 => 178,  416 => 177,  413 => 176,  407 => 174,  405 => 173,  400 => 170,  391 => 168,  387 => 167,  379 => 161,  373 => 159,  371 => 158,  368 => 157,  362 => 155,  360 => 154,  355 => 151,  346 => 149,  342 => 148,  335 => 143,  332 => 141,  327 => 138,  317 => 134,  313 => 133,  308 => 132,  303 => 131,  296 => 127,  290 => 125,  287 => 124,  280 => 120,  276 => 118,  274 => 117,  270 => 115,  267 => 114,  257 => 108,  253 => 106,  248 => 104,  245 => 103,  242 => 102,  237 => 100,  234 => 99,  232 => 98,  228 => 97,  224 => 96,  219 => 93,  216 => 91,  212 => 89,  207 => 87,  204 => 86,  202 => 85,  197 => 83,  189 => 78,  184 => 75,  177 => 59,  172 => 57,  168 => 55,  161 => 51,  156 => 49,  153 => 48,  151 => 47,  148 => 46,  141 => 42,  137 => 41,  130 => 37,  126 => 36,  123 => 35,  121 => 34,  118 => 33,  111 => 29,  107 => 28,  102 => 26,  99 => 25,  97 => 24,  94 => 23,  88 => 21,  86 => 20,  79 => 18,  75 => 17,  68 => 13,  58 => 11,  55 => 10,  48 => 5,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/quote/quote-details.twig", "/var/www/src/views/pages/quote/quote-details.twig");
    }
}
