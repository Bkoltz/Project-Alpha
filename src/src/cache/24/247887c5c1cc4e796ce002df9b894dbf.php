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

/* pages/quote/quotes-details.twig */
class __TwigTemplate_7f5e749b1a51d9b0c6fbad99aa870a25 extends Template
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
        yield "\t\t<div class=\"no-print\" style=\"padding:12px 16px;background:";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "bg", [], "any", false, false, false, 10), "html", null, true);
        yield ";color:";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "text", [], "any", false, false, false, 10), "html", null, true);
        yield ";border-left:4px solid ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["colors"] ?? null), "border", [], "any", false, false, false, 10), "html", null, true);
        yield ";border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px\">
\t\t\tStatus:
\t\t\t";
        // line 12
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 12), "html", null, true);
        yield "
\t\t</div>
\t\t<div class=\"no-print\" style=\"display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap\">
\t\t\t<a href=\"javascript:history.back()\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Back</a>
\t\t\t<a href=\"/?page=quote/quote-pdf&id=";
        // line 16
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" target=\"_blank\" rel=\"noopener\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">View PDF</a>
\t\t\t<a href=\"/?page=quote/quote-pdf&id=";
        // line 17
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" download=\"quote-";
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 17) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 17)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 17), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 17), "html", null, true)));
        yield ".pdf\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Download</a>
\t\t\t
      ";
        // line 19
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 19) === "pending")) {
            // line 20
            yield "\t\t\t\t<a href=\"/?page=quote/quotes-edit&id=";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Edit</a>
\t\t\t";
        }
        // line 22
        yield "
\t\t\t";
        // line 23
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 23)) && (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 23) != "rejected"))) {
            // line 24
            yield "\t\t\t\t<form method=\"post\" action=\"/?page=email-send\" style=\"display:inline\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 25
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"redirect_to\" value=\"";
            // line 28
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["requestUri"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:medium;\">Email</button>
\t\t\t\t</form>
\t\t\t";
        }
        // line 32
        yield "
\t\t\t";
        // line 33
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 33) == "pending")) {
            // line 34
            yield "\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-approve\" style=\"display:inline\" onsubmit=\"return confirm('Approve this quote and generate contract + invoice?');\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 35
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 36
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff;font-size:medium;\">Approve</button>
\t\t\t\t</form>
\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-reject\" style=\"display:inline\" onsubmit=\"return confirm('Deny this quote?');\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 40
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 41
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff;font-size:medium;\">Deny</button>
\t\t\t\t</form>
\t\t\t";
        }
        // line 45
        yield "
\t\t\t";
        // line 46
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 46)) && (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 46) == "rejected"))) {
            // line 47
            yield "\t\t\t\t<form method=\"post\" action=\"/?page=document-reenable\" style=\"display:inline\" onsubmit=\"return confirm('Re-enable this quote? It will be set back to pending status.');\">
\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 48
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 50
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fef3c7;color:#92400e;font-size:medium;\">Re-enable</button>
\t\t\t\t</form>
\t\t\t";
        }
        // line 54
        yield "
\t\t\t<form method=\"post\" action=\"/?page=document-date-update\" style=\"display:inline\" onsubmit=\"return confirm('Update document date to today? This will refresh the date shown on the PDF.');\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
        // line 56
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrf_token"] ?? null), "html", null, true);
        yield "\">
\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
        // line 58
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#dbeafe;color:#1e40af;font-size:medium;\">Update Document Date</button>
\t\t\t</form>
\t\t</div>
";
        // line 74
        yield "
\t\t<div class=\"no-print\" style=\"padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151\">
\t\t\t<strong>Created:</strong>
\t\t\t";
        // line 77
        yield (((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "created_at", [], "any", false, false, false, 77))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "created_at", [], "any", false, false, false, 77), "M j, Y g:i A"), "html", null, true)) : ("N/A"));
        yield "

\t\t\t<span style=\"margin:0 8px\">|</span>

\t\t\t<strong>Document Date:</strong>
\t\t\t";
        // line 82
        yield (((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date", [], "any", false, false, false, 82))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date", [], "any", false, false, false, 82), "M j, Y g:i A"), "html", null, true)) : ("N/A"));
        yield "

\t\t\t";
        // line 84
        if (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date_updated_at", [], "any", true, true, false, 84)) {
            // line 85
            yield "\t\t\t\t<span style=\"margin-left:8px;color:#6b7280;font-size:12px\">(Updated:
\t\t\t\t\t";
            // line 86
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "document_date_updated_at", [], "any", false, false, false, 86), "M j, Y g:i A"), "html", null, true);
            yield ")</span>
\t\t\t";
        }
        // line 88
        yield "\t\t</div>

  ";
        // line 91
        yield "\t<table style=\"width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:middle;width:70%\">
\t\t\t\t<div style=\"font-weight:700;font-size:20px\">";
        // line 94
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "</div>
\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Quote Q-";
        // line 95
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 95) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 95)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 95), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 95), "html", null, true)));
        yield "</div>
\t\t\t\t";
        // line 96
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 96))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 97
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Job
\t\t\t\t\t\t";
            // line 98
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 98), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 100
        yield "\t\t\t\t";
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 100))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 101
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Project
\t\t\t\t\t\t";
            // line 102
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 102), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 104
        yield "\t\t\t</td>
\t\t\t<td style=\"vertical-align:middle;width:30%;text-align:right\">
\t\t\t\t<img src=\"";
        // line 106
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["logoPath"] ?? null), "html", null, true);
        yield "\" alt=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "\" style=\"height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px\">
\t\t\t</td>
\t\t</tr>
\t</table>

  ";
        // line 112
        yield "\t";
        if (((($context["showDepositInfo"] ?? null) || ($context["showFulfillmentDate"] ?? null)) || ($context["hasCustomFields"] ?? null))) {
            // line 113
            yield "\t\t<table style=\"width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb\">
\t\t\t<tr>
\t\t\t\t";
            // line 115
            if ((($tmp = ($context["showDepositInfo"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 116
                yield "\t\t\t\t\t<td style=\"padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Deposit Due:
\t\t\t\t\t\t\t<span style=\"font-weight:600;color:#059669\">\$";
                // line 118
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(($context["depositCalc"] ?? null), 2), "html", null, true);
                yield "</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t</td>
\t\t\t\t";
            }
            // line 122
            yield "\t\t\t\t";
            if ((($tmp = ($context["showFulfillmentDate"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 123
                yield "\t\t\t\t\t<td style=\"padding:8px;";
                yield (((($tmp = ($context["hasCustomFields"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("border-right:1px solid #e5e7eb;") : (""));
                yield "vertical-align:top\">
\t\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Fulfillment Date:
\t\t\t\t\t\t\t<span style=\"font-weight:600;color:#2563eb\">";
                // line 125
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(($context["fulfillmentDate"] ?? null), "M j, Y"), "html", null, true);
                yield "</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t</td>
\t\t\t\t";
            }
            // line 129
            yield "\t\t\t\t";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["displayCustomFields"] ?? null));
            foreach ($context['_seq'] as $context["idx"] => $context["customField"]) {
                // line 130
                yield "\t\t\t\t\t<td style=\"padding:8px;";
                yield ((($context["idx"] < (Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["displayCustomFields"] ?? null)) - 1))) ? ("border-right:1px solid #e5e7eb;") : (""));
                yield "vertical-align:top\">
\t\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">";
                // line 131
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "label", [], "any", false, false, false, 131));
                yield ":
\t\t\t\t\t\t\t<span style=\"font-weight:600;color:#374151\">";
                // line 132
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "value", [], "any", false, false, false, 132));
                yield "</span>
\t\t\t\t\t\t</div>
\t\t\t\t\t</td>
\t\t\t\t";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['idx'], $context['customField'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 136
            yield "\t\t\t</tr>
\t\t</table>
\t";
        }
        // line 139
        yield "
  ";
        // line 141
        yield "\t<table style=\"width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:top;width:50%;padding-right:12px\">
\t\t\t\t<div style=\"font-weight:600\">From</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 146
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["fromLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 147
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 149
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 152
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 153
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 155
        yield "
\t\t\t\t\t";
        // line 156
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 157
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 159
        yield "\t\t\t\t</div>
\t\t\t</td>

\t\t\t<td style=\"vertical-align:top;width:50%;padding-left:12px\">
\t\t\t\t<div style=\"font-weight:600\">To</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 165
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["toLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 166
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 168
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 171
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 172
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 174
        yield "
\t\t\t\t\t";
        // line 175
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 176
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 178
        yield "\t\t\t\t</div>
\t\t\t</td>
\t\t</tr>
\t</table>

\t";
        // line 184
        yield "\t";
        if ((($tmp = ($context["scope_enabled"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 185
            yield "\t\t<div style=\"page-break-before:auto;margin-top:20px\">
\t\t\t<h3 style=\"font-size:18px;font-weight:700;margin-bottom:12px;color:#111\">Scope of Project</h3>
\t\t\t<div style=\"white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px\"><?php echo nl2br(htmlspecialchars(\$scopeText)); ?></div>
\t\t</div>
\t\t<div style=\"page-break-after:always\"></div>
\t";
        }
        // line 191
        yield "
\t";
        // line 193
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
        // line 204
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
            // line 205
            yield "\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6\">
\t\t\t\t\t<td style=\"padding:10px;font-weight:600;vertical-align:top;text-align:center\">";
            // line 206
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", true, true, false, 206) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 206)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 206), "html", null, true)) : (""));
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;color:#6b7280;font-size:13px;vertical-align:top\">";
            // line 207
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", true, true, false, 207) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 207)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 207), "html", null, true)) : (""));
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">";
            // line 208
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "quantity", [], "any", false, false, false, 208), 2), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
            // line 209
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "unit_price", [], "any", false, false, false, 209), 2), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
            // line 210
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "line_total", [], "any", false, false, false, 210), 2), "html", null, true);
            yield "</td>
\t\t\t\t</tr>
\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 213
        yield "\t\t</tbody>
\t</table>

  ";
        // line 217
        yield "\t<table style=\"width:100%;border-collapse:collapse;margin-top:12px\">
\t\t<tr>
\t\t\t<td style=\"width:60%\"></td>
\t\t\t<td style=\"width:40%\">
\t\t\t\t<table style=\"width:100%;border-collapse:collapse\">
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Subtotal</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;width:120px\">\$";
        // line 224
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "subtotal", [], "any", false, false, false, 224), "html", null, true);
        yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Discount</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">
\t\t\t\t\t\t\t";
        // line 229
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 229) == "percent")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 229), 2) . "%"), "html", null, true)) : ((((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 229) == "fixed")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(("\$" . $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 229), 2)), "html", null, true)) : ("\$0.00"))));
        yield "
\t\t\t\t\t\t</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Tax</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">";
        // line 234
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "tax_percent", [], "any", false, false, false, 234), 2), "html", null, true);
        yield "%</td>
\t\t\t\t\t</tr>
\t\t\t\t\t<tr style=\"border-top:2px solid #e5e7eb\">
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700\">Total</td>
\t\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700;font-size:16px\">\$";
        // line 238
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "total", [], "any", false, false, false, 238), 2), "html", null, true);
        yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t</table>
\t\t\t</td>
\t\t</tr>
\t</table>

  ";
        // line 246
        yield "\t";
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 246)) || (CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 246) == 1))) {
            // line 247
            yield "\t\t<div style=\"page-break-after:always\"></div>
\t\t<h3>Terms and Conditions</h3>
\t\t<div style=\"white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222\">
\t\t\t";
            // line 250
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["termsText"] ?? null), "html", null, true);
            yield "
\t\t</div>
\t";
        }
        // line 253
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
        return "pages/quote/quotes-details.twig";
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
        return array (  545 => 253,  539 => 250,  534 => 247,  531 => 246,  521 => 238,  514 => 234,  506 => 229,  498 => 224,  489 => 217,  484 => 213,  475 => 210,  471 => 209,  467 => 208,  463 => 207,  459 => 206,  456 => 205,  452 => 204,  439 => 193,  436 => 191,  428 => 185,  425 => 184,  418 => 178,  412 => 176,  410 => 175,  407 => 174,  401 => 172,  399 => 171,  394 => 168,  385 => 166,  381 => 165,  373 => 159,  367 => 157,  365 => 156,  362 => 155,  356 => 153,  354 => 152,  349 => 149,  340 => 147,  336 => 146,  329 => 141,  326 => 139,  321 => 136,  311 => 132,  307 => 131,  302 => 130,  297 => 129,  290 => 125,  284 => 123,  281 => 122,  274 => 118,  270 => 116,  268 => 115,  264 => 113,  261 => 112,  251 => 106,  247 => 104,  242 => 102,  239 => 101,  236 => 100,  231 => 98,  228 => 97,  226 => 96,  222 => 95,  218 => 94,  213 => 91,  209 => 88,  204 => 86,  201 => 85,  199 => 84,  194 => 82,  186 => 77,  181 => 74,  174 => 58,  169 => 56,  165 => 54,  158 => 50,  153 => 48,  150 => 47,  148 => 46,  145 => 45,  138 => 41,  134 => 40,  127 => 36,  123 => 35,  120 => 34,  118 => 33,  115 => 32,  108 => 28,  104 => 27,  99 => 25,  96 => 24,  94 => 23,  91 => 22,  85 => 20,  83 => 19,  76 => 17,  72 => 16,  65 => 12,  55 => 10,  48 => 5,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/quote/quotes-details.twig", "/var/www/src/views/pages/quote/quotes-details.twig");
    }
}
