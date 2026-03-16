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

/* partials/document_list/details/long-term-quote-details.twig */
class __TwigTemplate_756fe5081ee5f50fcae593501a2c1dd1 extends Template
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
\t<div class=\"doc-type\" style=\"text-align:center;font-weight:700;font-size:22px;margin-bottom:6px\">Long-term Service Quote</div>
\t<div style=\"text-align:center;color:#6b7280;margin-bottom:16px;font-size:13px\">Recurring Billing Proposal</div>
\t<div style=\"text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px\">Valid for
\t\t";
        // line 5
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "documents_valid_days", [], "any", false, false, false, 5), "html", null, true);
        yield "
\t\tdays
\t</div>

\t<div class=\"no-print\" style=\"display:flex;gap:8px;margin-bottom:8px\">
\t\t<a href=\"javascript:history.back()\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Back</a>
\t\t<a href=\"/?page=quote/long-term-quote-pdf&id=";
        // line 11
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" target=\"_blank\" rel=\"noopener\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">View PDF</a>
\t\t<a href=\"/?page=quote/long-term-quote-pdf&id=";
        // line 12
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
        yield "\" download=\"longterm-quote-";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 12), "html", null, true);
        yield ".pdf\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium; margin-left:4px;\">Download</a>
\t\t";
        // line 13
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 13) !== "rejected")) {
            // line 14
            yield "\t\t\t<form method=\"post\" action=\"/?page=email-send\" style=\"display:inline\">
\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
            // line 15
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"long_term_quote\">
\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
            // line 17
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["id"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<input type=\"hidden\" name=\"redirect_to\" value=\"";
            // line 18
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["requestURI"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;\">Email</button>
\t\t\t</form>
\t\t";
        }
        // line 22
        yield "\t</div>

\t<table style=\"width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:middle;width:70%\">
\t\t\t\t<div style=\"font-weight:700;font-size:20px\">";
        // line 27
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "</div>
\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Long-term Quote Q-";
        // line 28
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 28), "html", null, true);
        yield "</div>
\t\t\t\t";
        // line 29
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 29))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 30
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Job
\t\t\t\t\t\t";
            // line 31
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 31), "html", null, true);
            yield "
\t\t\t\t\t</div>
\t\t\t\t";
        }
        // line 34
        yield "\t\t\t\t";
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 34))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 35
            yield "\t\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Project
\t\t\t\t\t\t";
            // line 36
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 36), "html", null, true);
            yield "
\t\t\t\t\t</div>
\t\t\t\t";
        }
        // line 39
        yield "\t\t\t</td>
\t\t\t<td style=\"vertical-align:middle;width:30%;text-align:right\">
\t\t\t\t<img src=\"";
        // line 41
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["logoPath"] ?? null), "html", null, true);
        yield "\" alt=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "\" style=\"height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px\">
\t\t\t</td>
\t\t</tr>
\t</table>

\t<!-- Quote Details Box -->
\t<table style=\"width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb;background:#f9fafb\">
\t\t<tr>
\t\t\t<td style=\"width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Start Date</div>
\t\t\t\t<div style=\"font-weight:600;color:#111\">";
        // line 51
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "start_date", [], "any", false, false, false, 51), "html", null, true);
        yield "</div>
\t\t\t</td>
\t\t\t<td style=\"width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">End Date</div>
\t\t\t\t<div style=\"font-weight:600;color:#111\">";
        // line 55
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "end_date", [], "any", false, false, false, 55), "html", null, true);
        yield "</div>
\t\t\t</td>
\t\t\t<td style=\"width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Billing Frequency</div>
\t\t\t\t<div style=\"font-weight:600;color:#111\">";
        // line 59
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "billing_interval_count", [], "any", false, false, false, 59), "html", null, true);
        yield "
\t\t\t\t\t";
        // line 60
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "billing_interval_unit", [], "any", false, false, false, 60), "html", null, true);
        yield "</div>
\t\t\t</td>
\t\t\t<td style=\"width:25%;padding:8px;vertical-align:top\">
\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Status</div>
\t\t\t\t<div style=\"font-weight:600;color:#111;text-transform:capitalize\">";
        // line 64
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "status", [], "any", false, false, false, 64), "html", null, true);
        yield "</div>
\t\t\t</td>
\t\t</tr>
\t</table>

\t";
        // line 69
        if ((($tmp = ($context["showDepositInfo"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 70
            yield "\t\t<table style=\"width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #a7f3d0;background:#ecfdf5\">
\t\t\t<tr>
\t\t\t\t<td style=\"padding:8px;vertical-align:top\">
\t\t\t\t\t<div style=\"font-size:11px;color:#065f46\">Initial Deposit Due:
\t\t\t\t\t\t<span style=\"font-weight:700;font-size:14px\">\$";
            // line 74
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "depositCalc", [], "any", false, false, false, 74), "html", null, true);
            yield "</span>
\t\t\t\t\t</div>
\t\t\t\t</td>
\t\t\t</tr>
\t\t</table>
\t";
        }
        // line 80
        yield "
\t<table style=\"width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse\">
\t\t<tr>
\t\t\t<td style=\"vertical-align:top;width:50%;padding-right:12px\">
\t\t\t\t<div style=\"font-weight:600\">From</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 86
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["fromLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 87
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 89
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 92
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 93
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 95
        yield "
\t\t\t\t\t";
        // line 96
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 97
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 99
        yield "\t\t\t\t</div>
\t\t\t</td>

\t\t\t<td style=\"vertical-align:top;width:50%;padding-left:12px\">
\t\t\t\t<div style=\"font-weight:600\">To</div>
\t\t\t\t<div>
\t\t\t\t\t";
        // line 105
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["toLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 106
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 108
        yield "\t\t\t\t</div>

\t\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t\t";
        // line 111
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 112
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 114
        yield "
\t\t\t\t\t";
        // line 115
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 116
            yield "\t\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t\t";
        }
        // line 118
        yield "\t\t\t\t</div>
\t\t\t</td>
\t\t</tr>

\t</table>

\t";
        // line 124
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "scope", [], "any", false, false, false, 124))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 125
            yield "\t\t<div style=\"margin:16px 0;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px\">
\t\t\t<div style=\"font-weight:600;margin-bottom:8px\">Scope of Work</div>
\t\t\t<div style=\"white-space:pre-wrap;font-size:14px;line-height:1.6;color:#374151\">";
            // line 127
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "scope", [], "any", false, false, false, 127), "html", null, true);
            yield "</div>
\t\t</div>
\t";
        }
        // line 130
        yield "
\t<table style=\"width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin-top:16px\">
\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee;background:#f9fafb\">
\t\t\t<th colspan=\"4\" style=\"padding:12px;font-size:15px\">";
        // line 133
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "pricing_type", [], "any", false, false, false, 133) == "per_invoice")) ? ("Recurring Amount (per invoice)") : ("Fixed Total (billed over time)"));
        yield "</th>
\t\t</tr>

\t\t";
        // line 136
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "pricing_type", [], "any", false, false, false, 136) == "per_invoice")) {
            // line 137
            yield "\t\t\t<tr>
\t\t\t\t<td colspan=\"3\" style=\"padding:12px\">Recurring service fee (billed every
\t\t\t\t\t";
            // line 139
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "billing_interval_count", [], "any", false, false, false, 139), "html", null, true);
            yield "
\t\t\t\t\t";
            // line 140
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "billing_interval_unit", [], "any", false, false, false, 140), "html", null, true);
            yield ")</td>
\t\t\t\t<td style=\"padding:12px;text-align:right;font-weight:600\">\$";
            // line 141
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "price_per_invoice", [], "any", false, false, false, 141), "html", null, true);
            yield "</td>
\t\t\t</tr>
\t\t";
        } else {
            // line 144
            yield "\t\t\t";
            if (array_key_exists("items", $context)) {
                // line 145
                yield "\t\t\t\t<tr style=\"border-bottom:1px solid #eee\">
\t\t\t\t\t<th style=\"padding:10px\">Description</th>
\t\t\t\t\t<th style=\"padding:10px\">Qty</th>
\t\t\t\t\t<th style=\"padding:10px\">Unit</th>
\t\t\t\t\t<th style=\"padding:10px\">Line Total</th>
\t\t\t\t</tr>

\t\t\t\t";
                // line 152
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
                foreach ($context['_seq'] as $context["_key"] => $context["it"]) {
                    // line 153
                    yield "\t\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6\">
\t\t\t\t\t\t<td style=\"padding:10px\">";
                    // line 154
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["it"], "description", [], "any", false, false, false, 154), "html", null, true);
                    yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px\">";
                    // line 155
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["it"], "quantity", [], "any", false, false, false, 155), "html", null, true);
                    yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px\">\$";
                    // line 156
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["it"], "unit_price", [], "any", false, false, false, 156), "html", null, true);
                    yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px\">\$";
                    // line 157
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["it"], "line_total", [], "any", false, false, false, 157), "html", null, true);
                    yield "</td>
\t\t\t\t\t</tr>
\t\t\t\t";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['it'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 160
                yield "\t\t\t";
            }
            // line 161
            yield "\t\t\t<tr>
\t\t\t\t<td></td>
\t\t\t\t<td></td>
\t\t\t\t<td style=\"padding:10px;font-weight:600\">Subtotal</td>
\t\t\t\t<td style=\"padding:10px\">\$";
            // line 165
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "subtotal", [], "any", false, false, false, 165), "html", null, true);
            yield "</td>
\t\t\t</tr>

\t\t\t<tr>
\t\t\t\t<td></td>
\t\t\t\t<td></td>
\t\t\t\t<td style=\"padding:10px;font-weight:600\">Discount</td>
\t\t\t\t<td style=\"padding:10px\">";
            // line 172
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 172), "html", null, true);
            yield "</td>
\t\t\t</tr>
\t\t";
        }
        // line 175
        yield "
\t\t<tr>
\t\t\t<td colspan=\"3\" style=\"padding:10px\">Tax</td>
\t\t\t<td style=\"padding:10px;text-align:right\">";
        // line 178
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "tax_percent", [], "any", false, false, false, 178), "html", null, true);
        yield "%</td>
\t\t</tr>

\t\t";
        // line 182
        yield "
\t\t<tr style=\"background:#ecfdf5;border-top:2px solid #10b981\">
\t\t\t";
        // line 184
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "pricing_type", [], "any", false, false, false, 184) != "per_invoice")) {
            // line 185
            yield "\t\t\t\t<td colspan=\"3\" style=\"padding:12px;font-weight:700;font-size:15px;color:#065f46\">Amount Per Invoice</td>
\t\t\t";
        } else {
            // line 187
            yield "\t\t\t\t<td colspan=\"3\" style=\"padding:10px;font-weight:700\">Quote Total</td>
\t\t\t";
        }
        // line 189
        yield "
\t\t\t<td style=\"padding:12px;font-weight:700;font-size:16px;color:#065f46;text-align:right\">\$";
        // line 190
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "total", [], "any", false, false, false, 190), "html", null, true);
        yield "</td>
\t\t</tr>
\t</table>

\t";
        // line 194
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 194)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 195
            yield "\t\t<div style=\"page-break-after:always\"></div>
\t\t<h3>Terms and Conditions</h3>

\t\t";
            // line 198
            if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["terms"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 199
                yield "\t\t\t<div style=\"white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222;\">";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["terms"] ?? null), "html", null, true);
                yield "</div>
\t\t";
            } else {
                // line 201
                yield "\t\t\t<ul>
\t\t\t\t<li>This is a recurring billing proposal. If approved, invoices will be generated automatically every
\t\t\t\t\t";
                // line 203
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "billing_interval_count", [], "any", false, false, false, 203), "html", null, true);
                yield "
\t\t\t\t\t";
                // line 204
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "billing_interval_unit", [], "any", false, false, false, 204), "html", null, true);
                yield ".</li>
\t\t\t\t<li>Service begins on
\t\t\t\t\t";
                // line 206
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "start_date", [], "any", false, false, false, 206), "html", null, true);
                yield "
\t\t\t\t\tand
\t\t\t\t\t";
                // line 208
                yield (((($tmp = ($context["isOngoing"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("continues until terminated by either party") : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(("ends on" . CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "end_date", [], "any", false, false, false, 208)), "html", null, true)));
                yield ".</li>
\t\t\t\t<li>Payment due NET 30 unless otherwise specified.</li>
\t\t\t\t<li>Termination requires written notice.</li>
\t\t\t\t<li>Work product ownership and usage rights per agreement.</li>
\t\t\t</ul>
\t\t";
            }
            // line 214
            yield "\t";
        }
        // line 215
        yield "</section>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/details/long-term-quote-details.twig";
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
        return array (  481 => 215,  478 => 214,  469 => 208,  464 => 206,  459 => 204,  455 => 203,  451 => 201,  445 => 199,  443 => 198,  438 => 195,  436 => 194,  429 => 190,  426 => 189,  422 => 187,  418 => 185,  416 => 184,  412 => 182,  406 => 178,  401 => 175,  395 => 172,  385 => 165,  379 => 161,  376 => 160,  367 => 157,  363 => 156,  359 => 155,  355 => 154,  352 => 153,  348 => 152,  339 => 145,  336 => 144,  330 => 141,  326 => 140,  322 => 139,  318 => 137,  316 => 136,  310 => 133,  305 => 130,  299 => 127,  295 => 125,  293 => 124,  285 => 118,  279 => 116,  277 => 115,  274 => 114,  268 => 112,  266 => 111,  261 => 108,  252 => 106,  248 => 105,  240 => 99,  234 => 97,  232 => 96,  229 => 95,  223 => 93,  221 => 92,  216 => 89,  207 => 87,  203 => 86,  195 => 80,  186 => 74,  180 => 70,  178 => 69,  170 => 64,  163 => 60,  159 => 59,  152 => 55,  145 => 51,  130 => 41,  126 => 39,  120 => 36,  117 => 35,  114 => 34,  108 => 31,  105 => 30,  103 => 29,  99 => 28,  95 => 27,  88 => 22,  81 => 18,  77 => 17,  72 => 15,  69 => 14,  67 => 13,  61 => 12,  57 => 11,  48 => 5,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/details/long-term-quote-details.twig", "/var/www/src/views/partials/document_list/details/long-term-quote-details.twig");
    }
}
