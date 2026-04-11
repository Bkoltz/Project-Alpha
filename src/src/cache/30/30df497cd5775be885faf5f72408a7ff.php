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

/* partials/document_list/rows/contract/long-term-contract-rows.twig */
class __TwigTemplate_37daa84cb6a25da2fc08b3d9183376a8 extends Template
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
        yield "<div style=\"overflow:auto\">
\t<table style=\"width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t\t<thead>
\t\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t\t\t<th style=\"padding:10px\">No.</th>
\t\t\t\t<th style=\"padding:10px\">Project</th>
\t\t\t\t<th style=\"padding:10px\">Client</th>
\t\t\t\t<th style=\"padding:10px\">Status</th>
\t\t\t\t<th style=\"padding:10px\">Billing</th>
\t\t\t\t<th style=\"padding:10px\">Amount</th>
\t\t\t\t<th style=\"padding:10px\">Next Invoice</th>
\t\t\t\t<th style=\"padding:10px\">Actions</th>
\t\t\t</tr>
\t\t</thead>

\t\t<tbody>
\t\t\t";
        // line 17
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
            // line 18
            yield "\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6;";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "style", [], "any", false, false, false, 18), "html", null, true);
            yield "\">
\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t<a href=\"/?page=contract/long-term-contract-details&id=";
            // line 20
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 20), "html", null, true);
            yield "\" style=\"text-decoration:none;color:inherit\">LTC-";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "documentId", [], "any", false, false, false, 20), "html", null, true);
            yield "</a>
\t\t\t\t\t</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 22
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "projectCode", [], "any", false, false, false, 22), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t<a href=\"/?page=client/clients-list&selected_client_id=";
            // line 24
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "clientId", [], "any", false, false, false, 24), "html", null, true);
            yield "\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "clientName", [], "any", false, false, false, 24), "html", null, true);
            yield "</a>
\t\t\t\t\t</td>
\t\t\t\t\t<td style=\"padding:10px;text-transform:capitalize\">";
            // line 26
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 26), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "billingIntervalCount", [], "any", false, false, false, 27), "html", null, true);
            yield "
\t\t\t\t\t\t";
            // line 28
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "billingIntervalUnit", [], "any", false, false, false, 28), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 29
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "pricingText", [], "any", false, false, false, 29), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 30
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "nextInvoiceDate", [], "any", false, false, false, 30), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center\">
\t\t\t\t\t\t<a href=\"/?page=contract/long-term-contract-details&id=";
            // line 32
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 32), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">View</a>
\t\t\t\t\t\t";
            // line 33
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 33) == "pending")) {
                // line 34
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=long-term-contract-activate\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 35
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 36
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 36), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;\">Activate</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 40
            yield "\t\t\t\t\t\t";
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 40) == "active")) {
                // line 41
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=long-term-contract-pause\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 42
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 43
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 43), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#f59e0b;color:#fff; font-size: small;\">Pause</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            } elseif ((CoreExtension::getAttribute($this->env, $this->source,             // line 46
$context["row"], "status", [], "any", false, false, false, 46) == "paused")) {
                // line 47
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=long-term-contract-resume\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 48
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 49
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 49), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;\">Resume</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 53
            yield "\t\t\t\t\t\t";
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 53) != "rejected")) {
                // line 54
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=long-term-contract-terminate\" style=\"display:inline\" onsubmit=\"return confirm('Terminate this long-term contract? This will stop future invoicing.')\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 55
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 56
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 56), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#dc2626;color:#fff; font-size: small;\">Terminate</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 60
            yield "\t\t\t\t\t</td>
\t\t\t\t</tr>
\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 63
        yield "\t\t</tbody>
\t</table>
</div>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/rows/contract/long-term-contract-rows.twig";
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
        return array (  187 => 63,  179 => 60,  172 => 56,  168 => 55,  165 => 54,  162 => 53,  155 => 49,  151 => 48,  148 => 47,  146 => 46,  140 => 43,  136 => 42,  133 => 41,  130 => 40,  123 => 36,  119 => 35,  116 => 34,  114 => 33,  110 => 32,  105 => 30,  101 => 29,  97 => 28,  93 => 27,  89 => 26,  82 => 24,  77 => 22,  70 => 20,  64 => 18,  60 => 17,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/rows/contract/long-term-contract-rows.twig", "/var/www/src/views/partials/document_list/rows/contract/long-term-contract-rows.twig");
    }
}
