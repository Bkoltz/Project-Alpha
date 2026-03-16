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

/* partials/document_list/rows/on-demand-quote-rows.twig */
class __TwigTemplate_2ec996510f21ced1137ab2bb0f881e34 extends Template
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
\t\t<th style=\"padding:10px\">Price/Invoice</th>
\t\t<th style=\"padding:10px\">Start Date</th>
\t\t<th style=\"padding:10px\">Created</th>
\t\t<th style=\"padding:10px\">Actions</th>
\t</tr>
</thead>

<tbody>
\t";
        // line 15
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
            // line 16
            yield "\t\t<tr style=\"border-top:1px solid #f3f4f6;";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "style", [], "any", false, false, false, 16), "html", null, true);
            yield "\">
\t\t\t<td style=\"padding:10px\">
\t\t\t\t<a href=\"/?page=quote/quote-details&id=";
            // line 18
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 18), "html", null, true);
            yield "\" style=\"text-decoration:none;color:inherit\">ODQ-";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", false, false, false, 18), "html", null, true);
            yield "</a>
\t\t\t</td>
\t\t\t<td style=\"padding:10px\">";
            // line 20
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "project_code", [], "any", false, false, false, 20), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">
\t\t\t\t<a href=\"/?page=client/clients-list&selected_client_id=";
            // line 22
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_id", [], "any", false, false, false, 22), "html", null, true);
            yield "\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_name", [], "any", false, false, false, 22), "html", null, true);
            yield "</a>
\t\t\t</td>
\t\t\t<td style=\"padding:10px;text-transform:capitalize\">";
            // line 24
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 24), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">\$";
            // line 25
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "price_per_invoice", [], "any", false, false, false, 25), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">";
            // line 26
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "start_date", [], "any", false, false, false, 26), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "created_at", [], "any", false, false, false, 27), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center\">
\t\t\t\t<a href=\"/?page=quote/quote-details&id=";
            // line 29
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 29), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">View</a>

\t\t\t\t";
            // line 31
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 31) == "pending")) {
                // line 32
                yield "\t\t\t\t\t<form method=\"post\" action=\"/?page=email-send\" style=\"display:inline\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 33
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"";
                // line 34
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["quote"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 35
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 35), "html", null, true);
                yield "\">
\t\t\t\t\t\t";
                // line 37
                yield "\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Email</button>
\t\t\t\t\t</form>

\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-approve\" onsubmit=\"return confirm('Approve this on-demand quote and generate on-demand contract?')\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 41
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 41), "html", null, true);
                yield "\">
\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff; font-size: small;\">Approve</button>
\t\t\t\t\t</form>

\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-reject\" onsubmit=\"return confirm('Deny this quote?')\">
\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 46
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 46), "html", null, true);
                yield "\">
\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff; font-size: small;\">Deny</button>
\t\t\t\t\t</form>
\t\t\t\t";
            }
            // line 50
            yield "
\t\t\t</td>
\t\t</tr>
\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 54
        yield "</tbody>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/rows/on-demand-quote-rows.twig";
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
        return array (  156 => 54,  147 => 50,  140 => 46,  132 => 41,  126 => 37,  122 => 35,  118 => 34,  114 => 33,  111 => 32,  109 => 31,  104 => 29,  99 => 27,  95 => 26,  91 => 25,  87 => 24,  80 => 22,  75 => 20,  68 => 18,  62 => 16,  58 => 15,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/rows/on-demand-quote-rows.twig", "/var/www/src/views/partials/document_list/rows/on-demand-quote-rows.twig");
    }
}
