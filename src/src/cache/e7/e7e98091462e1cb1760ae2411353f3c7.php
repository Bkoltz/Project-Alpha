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

/* partials/document_list/rows/contract/on-demand-contract-rows.twig */
class __TwigTemplate_36c1377fefd7f5f231042d571792516a extends Template
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
\t\t\t\t<th style=\"padding:10px\">Price/Invoice</th>
\t\t\t\t<th style=\"padding:10px\">Invoices</th>
\t\t\t\t<th style=\"padding:10px\">Last Invoice</th>
\t\t\t\t<th style=\"padding:10px\">Actions</th>
\t\t\t</tr>
\t\t</thead>
        
\t\t<tbody>
\t\t\t";
        // line 18
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
            // line 19
            yield "\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6;";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "style", [], "any", false, false, false, 19), "html", null, true);
            yield ">\">
\t\t\t\t\t<td style=\"padding:10px\">ODC-";
            // line 20
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 20), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 21
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "projectCode", [], "any", false, false, false, 21), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t<a href=\"/?page=client/clients-list&selected_client_id=";
            // line 23
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "clientId", [], "any", false, false, false, 23), "html", null, true);
            yield "\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "clientName", [], "any", false, false, false, 23), "html", null, true);
            yield "</a>
\t\t\t\t\t</td>
\t\t\t\t\t<td style=\"padding:10px;text-transform:capitalize\">";
            // line 25
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 25), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 26
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "billingIntervalCount", [], "any", false, false, false, 26), "html", null, true);
            yield " ";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "billingIntervalUnit", [], "any", false, false, false, 26), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">\$";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "pricePerInvoice", [], "any", false, false, false, 27), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 28
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "invoiceCount", [], "any", false, false, false, 28), "html", null, true);
            yield " ";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "totalInvoiced", [], "any", false, false, false, 28), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 29
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "lastInvoiced", [], "any", false, false, false, 29), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center\">
\t\t\t\t\t\t<a href=\"/?page=contract/on-demand-invoices-list&contract_id=";
            // line 31
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 31), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Invoices</a>
\t\t\t\t\t\t";
            // line 32
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 32) == "pending")) {
                // line 33
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=on-demand-contract-activate\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"<?php echo htmlspecialchars(csrf_token()); ?>\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 35
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 35), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;\">Activate</button>
\t\t\t\t\t\t\t</form>

\t\t\t\t\t\t\t";
                // line 39
                if ((($tmp = ($context["canGenerate"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 40
                    yield "\t\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=on-demand-invoice-generate\" style=\"display:inline\" onsubmit=\"return confirm('Generate invoice for this contract?')\">
\t\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"<?php echo htmlspecialchars(csrf_token()); ?>\">
\t\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                    // line 42
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 42), "html", null, true);
                    yield "\">
\t\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#3b82f6;color:#fff; font-size: small;\">Generate Invoice</button>
\t\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t\t";
                }
                // line 46
                yield "                            
\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=on-demand-contract-pause\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"<?php echo htmlspecialchars(csrf_token()); ?>\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 49
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 49), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#f59e0b;color:#fff; font-size: small;\">Pause</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            } elseif ((CoreExtension::getAttribute($this->env, $this->source,             // line 52
$context["row"], "status", [], "any", false, false, false, 52) == "paused")) {
                // line 53
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=on-demand-contract-resume\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"<?php echo htmlspecialchars(csrf_token()); ?>\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 55
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 55), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;\">Resume</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 59
            yield "
\t\t\t\t\t\t";
            // line 60
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 60) != "rejected")) {
                // line 61
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=on-demand-contract-terminate\" style=\"display:inline\" onsubmit=\"return confirm('Terminate this on-demand contract?')\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"<?php echo htmlspecialchars(csrf_token()); ?>\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 63
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 63), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#dc2626;color:#fff; font-size: small;\">Terminate</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 67
            yield "\t\t\t\t\t</td>
\t\t\t\t</tr>
\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 70
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
        return "partials/document_list/rows/contract/on-demand-contract-rows.twig";
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
        return array (  189 => 70,  181 => 67,  174 => 63,  170 => 61,  168 => 60,  165 => 59,  158 => 55,  154 => 53,  152 => 52,  146 => 49,  141 => 46,  134 => 42,  130 => 40,  128 => 39,  121 => 35,  117 => 33,  115 => 32,  111 => 31,  106 => 29,  100 => 28,  96 => 27,  90 => 26,  86 => 25,  79 => 23,  74 => 21,  70 => 20,  65 => 19,  61 => 18,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/rows/contract/on-demand-contract-rows.twig", "/var/www/src/views/partials/document_list/rows/contract/on-demand-contract-rows.twig");
    }
}
