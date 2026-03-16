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

/* partials/document_list/rows/long-term-quote-rows.twig */
class __TwigTemplate_321846b58450dbf2dcce5fb46b64f4d4 extends Template
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
        yield "<thead>
\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t<th style=\"padding:10px\">No.</th>
\t\t<th style=\"padding:10px\">Project</th>
\t\t<th style=\"padding:10px\">Client</th>
\t\t<th style=\"padding:10px\">Status</th>
\t\t<th style=\"padding:10px\">Start Date</th>
\t\t<th style=\"padding:10px\">Billing</th>
\t\t<th style=\"padding:10px\">Total</th>
\t\t<th style=\"padding:10px\">Created</th>
\t\t<th style=\"padding:10px\">Actions</th>
\t</tr>
</thead>

<tbody>
\t";
        // line 16
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
            // line 17
            yield "\t\t<tr style=\"border-top:1px solid #f3f4f6;";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "style", [], "any", false, false, false, 17), "html", null, true);
            yield "\">
\t\t\t<td style=\"padding:10px\">LQ-";
            // line 18
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", false, false, false, 18), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">";
            // line 19
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "project_code", [], "any", false, false, false, 19), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">
\t\t\t\t<a href=\"/?page=client/clients-list&selected_client_id=";
            // line 21
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_id", [], "any", false, false, false, 21), "html", null, true);
            yield "; ?>\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_name", [], "any", false, false, false, 21), "html", null, true);
            yield "</a>
\t\t\t</td>
\t\t\t<td style=\"padding:10px;text-transform:capitalize\">";
            // line 23
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 23), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">";
            // line 24
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "start_date", [], "any", false, false, false, 24), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">";
            // line 25
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "billing_interval_count", [], "any", false, false, false, 25), "html", null, true);
            yield " ";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "billing_interval_unit", [], "any", false, false, false, 25), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">\$";
            // line 26
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "total", [], "any", false, false, false, 26), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "created_at", [], "any", false, false, false, 27), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px;display:flex;gap:8px\">
\t\t\t\t<a href=\"/?page=quote/quote-details&id=";
            // line 29
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 29), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">View</a>

\t\t\t\t";
            // line 31
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 31) == "pending")) {
                // line 32
                yield "\t\t\t\t\t<a href=\"/?page=quote/quote-edit&id=";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 32), "html", null, true);
                yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Edit</a>
\t\t\t\t";
            }
            // line 34
            yield "
\t\t\t\t";
            // line 35
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 35) !== "rejected")) {
                // line 36
                yield "\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/email-send\" style=\"display:inline\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"long_term_quote\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 38
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 38), "html", null, true);
                yield "\">
\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Email</button>
\t\t\t\t\t</form>
\t\t\t\t";
            }
            // line 42
            yield "
\t\t\t\t";
            // line 43
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 43) == "pending")) {
                // line 44
                yield "\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-approve\" onsubmit=\"return confirm('Approve this long-term quote and generate long-term contract?')\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 45
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 45), "html", null, true);
                yield "\">
\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff; font-size: small;\">Approve</button>
\t\t\t\t\t</form>

\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-reject\" onsubmit=\"return confirm('Deny this quote?')\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 50
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 50), "html", null, true);
                yield "\">
\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff; font-size: small;\">Deny</button>
\t\t\t\t\t</form>
\t\t\t\t";
            }
            // line 54
            yield "
\t\t\t</td>
\t\t</tr>
\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 58
        yield "</tbody>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/rows/long-term-quote-rows.twig";
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
        return array (  168 => 58,  159 => 54,  152 => 50,  144 => 45,  141 => 44,  139 => 43,  136 => 42,  129 => 38,  125 => 36,  123 => 35,  120 => 34,  114 => 32,  112 => 31,  107 => 29,  102 => 27,  98 => 26,  92 => 25,  88 => 24,  84 => 23,  77 => 21,  72 => 19,  68 => 18,  63 => 17,  59 => 16,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/rows/long-term-quote-rows.twig", "/var/www/src/views/partials/document_list/rows/long-term-quote-rows.twig");
    }
}
