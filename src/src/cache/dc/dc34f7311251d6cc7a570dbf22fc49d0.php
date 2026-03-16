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

/* partials/document_list/regular-quote-rows.twig */
class __TwigTemplate_78091986599c77cb9d1e7b0ccd35b1f5 extends Template
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
        // line 2
        yield "<thead>
\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t<th style=\"padding:10px\">No.</th>
\t\t<th style=\"padding:10px\">Project</th>
\t\t<th style=\"padding:10px\">Client</th>
\t\t<th style=\"padding:10px\">Status</th>
\t\t<th style=\"padding:10px\">Total</th>
\t\t<th style=\"padding:10px\">Created</th>
\t\t<th style=\"padding:10px;text-align:right\">Actions</th>
\t</tr>
</thead>

";
        // line 15
        yield "<tbody>
\t";
        // line 16
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
            // line 17
            yield "\t\t<tr style=\"border-top:1px solid #f3f4f6;";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "style", [], "any", false, false, false, 17), "html", null, true);
            yield "\">

\t\t\t<td style=\"padding:10px\">Q-";
            // line 19
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", true, true, false, 19) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", false, false, false, 19)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", false, false, false, 19), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 19), "html", null, true)));
            yield "</td>
\t\t\t<td style=\"padding:10px\">
\t\t\t\t";
            // line 21
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "project_code", [], "any", false, false, false, 21), "html", null, true);
            yield "
\t\t\t</td>
\t\t\t<td style=\"padding:10px\">
\t\t\t\t<a href=\"/?page=client/clients-list&selected_client_id=";
            // line 24
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_id", [], "any", false, false, false, 24), "html", null, true);
            yield " ?>\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_name", [], "any", false, false, false, 24), "html", null, true);
            yield "</a>
\t\t\t</td>
\t\t\t<td style=\"padding:10px;text-transform:capitalize\">";
            // line 26
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 26), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">\$";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "total", [], "any", false, false, false, 27), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">";
            // line 28
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "created_at", [], "any", false, false, false, 28), "html", null, true);
            yield "</td>
\t\t\t<td style=\"padding:10px\">
\t\t\t\t<div style=\"display:flex;gap:6px;justify-content:flex-end\">
\t\t\t\t\t<a href=\"/?page=quote/quotes-details&id=";
            // line 31
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 31), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">View</a>

\t\t\t\t\t";
            // line 33
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 33) == "pending")) {
                // line 34
                yield "\t\t\t\t\t\t<a href=\"/?page=quote/quotes-edit&id=";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 34), "html", null, true);
                yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Edit</a>
\t\t\t\t\t";
            }
            // line 36
            yield "
\t\t\t\t\t";
            // line 37
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 37) == "rejected")) {
                // line 38
                yield "\t\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/email-send\" style=\"display:inline\">
\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 39
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"";
                // line 40
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["quote"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 41
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 41), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Email</button>
\t\t\t\t\t\t</form>
\t\t\t\t\t";
            }
            // line 45
            yield "
\t\t\t\t\t";
            // line 46
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 46) == "pending")) {
                // line 47
                yield "\t\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-approve\" onsubmit=\"return confirm('Approve this quote and generate contract + invoice?')\">
\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 48
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 48), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff; font-size: small;\">Approve</button>
\t\t\t\t\t\t</form>
\t\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-reject\" onsubmit=\"return confirm('Deny this quote?')\">
\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 52
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 52), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff; font-size: small;\">Deny</button>
\t\t\t\t\t\t</form>
\t\t\t\t\t";
            }
            // line 56
            yield "
\t\t\t\t</div>
\t\t\t</td>
\t\t</tr>
\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 61
        yield "</tbody>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/regular-quote-rows.twig";
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
        return array (  169 => 61,  159 => 56,  152 => 52,  145 => 48,  142 => 47,  140 => 46,  137 => 45,  130 => 41,  126 => 40,  122 => 39,  119 => 38,  117 => 37,  114 => 36,  108 => 34,  106 => 33,  101 => 31,  95 => 28,  91 => 27,  87 => 26,  80 => 24,  74 => 21,  69 => 19,  63 => 17,  59 => 16,  56 => 15,  42 => 2,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/regular-quote-rows.twig", "/var/www/src/views/partials/document_list/regular-quote-rows.twig");
    }
}
