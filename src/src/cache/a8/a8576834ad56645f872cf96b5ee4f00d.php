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

/* pages/quote/quotes-pdf.twig */
class __TwigTemplate_95043064ffcc68c57d5ab57e4a0bf4cc extends Template
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
        yield "<table style=\"width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse\">
\t<tr>
\t\t<td style=\"vertical-align:middle;width:70%\">
\t\t\t<div style=\"font-weight:700;font-size:20px\">";
        // line 4
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "</div>
\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Quote Q-";
        // line 5
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 5) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 5)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 5), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 5), "html", null, true)));
        yield "</div>
\t\t\t";
        // line 6
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 6))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 7
            yield "\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Job
\t\t\t\t\t";
            // line 8
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 8), "html", null, true);
            yield "</div>
\t\t\t";
        }
        // line 10
        yield "\t\t\t";
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 10))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 11
            yield "\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Project
\t\t\t\t\t";
            // line 12
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_id", [], "any", false, false, false, 12), "html", null, true);
            yield "</div>
\t\t\t";
        }
        // line 14
        yield "\t\t</td>
\t\t<td style=\"vertical-align:middle;width:30%;text-align:right\">
\t\t\t<img src=\"";
        // line 16
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["logoPath"] ?? null), "html", null, true);
        yield "\" alt=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["brand"] ?? null), "html", null, true);
        yield "\" style=\"height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px\">
\t\t</td>
\t</tr>
</table>

";
        // line 22
        if (((($context["showDepositInfo"] ?? null) || ($context["showFulfillmentDate"] ?? null)) || ($context["hasCustomFields"] ?? null))) {
            // line 23
            yield "\t<table style=\"width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb\">
\t\t<tr>
\t\t\t";
            // line 25
            if ((($tmp = ($context["showDepositInfo"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 26
                yield "\t\t\t\t<td style=\"padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Deposit Due:
\t\t\t\t\t\t<span style=\"font-weight:600;color:#059669\">\$";
                // line 28
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(($context["depositCalc"] ?? null), 2), "html", null, true);
                yield "</span>
\t\t\t\t\t</div>
\t\t\t\t</td>
\t\t\t";
            }
            // line 32
            yield "\t\t\t";
            if ((($tmp = ($context["showFulfillmentDate"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 33
                yield "\t\t\t\t<td style=\"padding:8px;";
                yield (((($tmp = ($context["hasCustomFields"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("border-right:1px solid #e5e7eb;") : (""));
                yield "vertical-align:top\">
\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Fulfillment Date:
\t\t\t\t\t\t<span style=\"font-weight:600;color:#2563eb\">";
                // line 35
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(($context["fulfillmentDate"] ?? null), "M j, Y"), "html", null, true);
                yield "</span>
\t\t\t\t\t</div>
\t\t\t\t</td>
\t\t\t";
            }
            // line 39
            yield "\t\t\t";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["displayCustomFields"] ?? null));
            foreach ($context['_seq'] as $context["idx"] => $context["customField"]) {
                // line 40
                yield "\t\t\t\t<td style=\"padding:8px;";
                yield ((($context["idx"] < (Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["displayCustomFields"] ?? null)) - 1))) ? ("border-right:1px solid #e5e7eb;") : (""));
                yield "vertical-align:top\">
\t\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">";
                // line 41
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "label", [], "any", false, false, false, 41));
                yield ":
\t\t\t\t\t\t<span style=\"font-weight:600;color:#374151\">";
                // line 42
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["customField"], "value", [], "any", false, false, false, 42));
                yield "</span>
\t\t\t\t\t</div>
\t\t\t\t</td>
\t\t\t";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['idx'], $context['customField'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 46
            yield "\t\t</tr>
\t</table>
";
        }
        // line 49
        yield "
";
        // line 51
        yield "<table style=\"width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse\">
\t<tr>
\t\t<td style=\"vertical-align:top;width:50%;padding-right:12px\">
\t\t\t<div style=\"font-weight:600\">From</div>
\t\t\t<div>
\t\t\t\t";
        // line 56
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["fromLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 57
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 59
        yield "\t\t\t</div>

\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t";
        // line 62
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 63
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 65
        yield "
\t\t\t\t";
        // line 66
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["fromEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 67
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["fromEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 69
        yield "\t\t\t</div>
\t\t</td>

\t\t<td style=\"vertical-align:top;width:50%;padding-left:12px\">
\t\t\t<div style=\"font-weight:600\">To</div>
\t\t\t<div>
\t\t\t\t";
        // line 75
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["toLines"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 76
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 78
        yield "\t\t\t</div>

\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t";
        // line 81
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toPhone"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 82
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toPhone"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 84
        yield "
\t\t\t\t";
        // line 85
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["toEmail"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 86
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["toEmail"] ?? null), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 88
        yield "\t\t\t</div>
\t\t</td>
\t</tr>
</table>

";
        // line 94
        if ((($tmp = ($context["scope_enabled"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 95
            yield "\t<div style=\"page-break-before:auto;margin-top:20px\">
\t\t<h3 style=\"font-size:18px;font-weight:700;margin-bottom:12px;color:#111\">Scope of Project</h3>
\t\t<div style=\"white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px\"><?php echo nl2br(htmlspecialchars(\$scopeText)); ?></div>
\t</div>
\t<div style=\"page-break-after:always\"></div>
";
        }
        // line 101
        yield "
";
        // line 103
        yield "<table style=\"width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t<thead>
\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t\t<th style=\"padding:10px;width:25%;vertical-align:top;text-align:center\">Item</th>
\t\t\t<th style=\"padding:10px;width:35%;vertical-align:top\">Description</th>
\t\t\t<th style=\"padding:10px;width:10%;text-align:right;vertical-align:top\">Qty</th>
\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Unit Price</th>
\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Line Total</th>
\t\t</tr>
\t</thead>
\t<tbody>
\t\t";
        // line 114
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
            // line 115
            yield "\t\t\t<tr style=\"border-top:1px solid #f3f4f6\">
\t\t\t\t<td style=\"padding:10px;font-weight:600;vertical-align:top;text-align:center\">";
            // line 116
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", true, true, false, 116) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 116)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "item", [], "any", false, false, false, 116), "html", null, true)) : (""));
            yield "</td>
\t\t\t\t<td style=\"padding:10px;color:#6b7280;font-size:13px;vertical-align:top\">";
            // line 117
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", true, true, false, 117) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 117)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 117), "html", null, true)) : (""));
            yield "</td>
\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">";
            // line 118
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "quantity", [], "any", false, false, false, 118), 2), "html", null, true);
            yield "</td>
\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
            // line 119
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "unit_price", [], "any", false, false, false, 119), 2), "html", null, true);
            yield "</td>
\t\t\t\t<td style=\"padding:10px;text-align:right;vertical-align:top\">\$";
            // line 120
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "line_total", [], "any", false, false, false, 120), 2), "html", null, true);
            yield "</td>
\t\t\t</tr>
\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 123
        yield "\t</tbody>
</table>

";
        // line 127
        yield "<table style=\"width:100%;border-collapse:collapse;margin-top:12px\">
\t<tr>
\t\t<td style=\"width:60%\"></td>
\t\t<td style=\"width:40%\">
\t\t\t<table style=\"width:100%;border-collapse:collapse\">
\t\t\t\t<tr>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Subtotal</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;width:120px\">\$";
        // line 134
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "subtotal", [], "any", false, false, false, 134), "html", null, true);
        yield "</td>
\t\t\t\t</tr>
\t\t\t\t<tr>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Discount</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">
\t\t\t\t\t\t";
        // line 139
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 139) == "percent")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 139), 2) . "%"), "html", null, true)) : ((((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 139) == "fixed")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(("\$" . $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 139), 2)), "html", null, true)) : ("\$0.00"))));
        yield "
\t\t\t\t\t</td>
\t\t\t\t</tr>
\t\t\t\t<tr>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Tax</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">";
        // line 144
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "tax_percent", [], "any", false, false, false, 144), 2), "html", null, true);
        yield "%</td>
\t\t\t\t</tr>
\t\t\t\t<tr style=\"border-top:2px solid #e5e7eb\">
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700\">Total</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700;font-size:16px\">\$";
        // line 148
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "total", [], "any", false, false, false, 148), 2), "html", null, true);
        yield "</td>
\t\t\t\t</tr>
\t\t\t</table>
\t\t</td>
\t</tr>
</table>

";
        // line 156
        if (( !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 156)) || (CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quotes_show_terms", [], "any", false, false, false, 156) == 1))) {
            // line 157
            yield "\t<div style=\"page-break-after:always\"></div>
\t<h3>Terms and Conditions</h3>
\t<div style=\"white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222\">
\t\t";
            // line 160
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["termsText"] ?? null), "html", null, true);
            yield "
\t</div>
";
        }
        // line 162
        yield "</section><style>
.no-print {
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
        return "pages/quote/quotes-pdf.twig";
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
        return array (  371 => 162,  365 => 160,  360 => 157,  358 => 156,  348 => 148,  341 => 144,  333 => 139,  325 => 134,  316 => 127,  311 => 123,  302 => 120,  298 => 119,  294 => 118,  290 => 117,  286 => 116,  283 => 115,  279 => 114,  266 => 103,  263 => 101,  255 => 95,  253 => 94,  246 => 88,  240 => 86,  238 => 85,  235 => 84,  229 => 82,  227 => 81,  222 => 78,  213 => 76,  209 => 75,  201 => 69,  195 => 67,  193 => 66,  190 => 65,  184 => 63,  182 => 62,  177 => 59,  168 => 57,  164 => 56,  157 => 51,  154 => 49,  149 => 46,  139 => 42,  135 => 41,  130 => 40,  125 => 39,  118 => 35,  112 => 33,  109 => 32,  102 => 28,  98 => 26,  96 => 25,  92 => 23,  90 => 22,  80 => 16,  76 => 14,  71 => 12,  68 => 11,  65 => 10,  60 => 8,  57 => 7,  55 => 6,  51 => 5,  47 => 4,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/quote/quotes-pdf.twig", "/var/www/src/views/pages/quote/quotes-pdf.twig");
    }
}
