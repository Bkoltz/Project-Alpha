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

/* pages/quote/quotes-list.twig */
class __TwigTemplate_a268091ce760bbfd01d04ab2cdb2ee57 extends Template
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
\t<h2>Quotes</h2>
\t";
        // line 9
        yield "
\t";
        // line 10
        yield from $this->load("templates/components/document-filter.html.twig", 10)->unwrap()->yield(CoreExtension::merge($context, ($context["filterConfig"] ?? null)));
        // line 11
        yield "
\t<div style=\"overflow:auto\">
\t\t<table style=\"width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t\t\t<thead>
\t\t\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t\t\t\t<th style=\"padding:10px\">
                        ";
        // line 17
        yield (((($tmp = ($context["hasDocument"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("No.") : ("ID"));
        yield "
\t\t\t\t\t</th>
\t\t\t\t\t";
        // line 19
        if ((($tmp = ($context["hasProject"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 20
            yield "\t\t\t\t\t\t<th style=\"padding:10px\">Project</th>
\t\t\t\t\t";
        }
        // line 22
        yield "\t\t\t\t\t<th style=\"padding:10px\">Client</th>
\t\t\t\t\t<th style=\"padding:10px\">Status</th>
\t\t\t\t\t<th style=\"padding:10px\">Total</th>
\t\t\t\t\t<th style=\"padding:10px\">Created</th>
\t\t\t\t\t<th style=\"padding:10px;text-align:right\">Actions</th>
\t\t\t\t</tr>
\t\t\t</thead>
\t\t\t<tbody>
\t\t\t\t";
        // line 30
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
            // line 31
            yield "\t\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6;";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "style", [], "any", false, false, false, 31), "html", null, true);
            yield "\">
\t\t\t\t\t\t<td style=\"padding:10px\">Q-";
            // line 32
            yield (((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", true, true, false, 32) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", false, false, false, 32)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "doc_number", [], "any", false, false, false, 32), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 32), "html", null, true)));
            yield "</td>
\t\t\t\t\t\t";
            // line 33
            if ((($tmp = ($context["hasProject"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 34
                yield "\t\t\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t\t\t";
                // line 35
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "project_code", [], "any", false, false, false, 35), "html", null, true);
                yield "
\t\t\t\t\t\t\t</td>
\t\t\t\t\t\t";
            }
            // line 38
            yield "\t\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t\t<a href=\"/?page=client/clients-list&selected_client_id=";
            // line 39
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_id", [], "any", false, false, false, 39), "html", null, true);
            yield " ?>\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_name", [], "any", false, false, false, 39), "html", null, true);
            yield "</a>
\t\t\t\t\t\t</td>
\t\t\t\t\t\t<td style=\"padding:10px;text-transform:capitalize\">";
            // line 41
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 41), "html", null, true);
            yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px\">\$";
            // line 42
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "total", [], "any", false, false, false, 42), "html", null, true);
            yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px\">";
            // line 43
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "created_at", [], "any", false, false, false, 43), "html", null, true);
            yield "</td>
\t\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t\t<div style=\"display:flex;gap:6px;justify-content:flex-end\">
\t\t\t\t\t\t\t\t<a href=\"/?page=quote/quotes-details&id=";
            // line 46
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 46), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">View</a>

\t\t\t\t\t\t\t\t";
            // line 48
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 48) == "pending")) {
                // line 49
                yield "\t\t\t\t\t\t\t\t\t<a href=\"/?page=quote/quotes-edit&id=";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 49), "html", null, true);
                yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Edit</a>
\t\t\t\t\t\t\t\t";
            }
            // line 51
            yield "
\t\t\t\t\t\t\t\t";
            // line 52
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 52) == "rejected")) {
                // line 53
                yield "\t\t\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/email-send\" style=\"display:inline\">
\t\t\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 54
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"quote\">
\t\t\t\t\t\t\t\t\t\t<input
\t\t\t\t\t\t\t\t\t\ttype=\"hidden\" name=\"id\" value=\"";
                // line 57
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape((($_v0 = $context["row"]) && is_array($_v0) || $_v0 instanceof ArrayAccess ? ($_v0["id"] ?? null) : null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t\t\t";
                // line 59
                yield "\t\t\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Email</button>
\t\t\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t\t\t";
            }
            // line 62
            yield "
\t\t\t\t\t\t\t\t";
            // line 63
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 63) == "pending")) {
                // line 64
                yield "\t\t\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-approve\" onsubmit=\"return confirm('Approve this quote and generate contract + invoice?')\">
\t\t\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 65
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 65), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff; font-size: small;\">Approve</button>
\t\t\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=quote/quote-reject\" onsubmit=\"return confirm('Deny this quote?')\">
\t\t\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 69
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 69), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff; font-size: small;\">Deny</button>
\t\t\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t\t\t";
            }
            // line 73
            yield "
\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t</td>
\t\t\t\t\t</tr>
\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 78
        yield "\t\t\t</tbody>
\t\t</table>
\t</div>

\t<div style=\"margin-top:12px;display:flex;justify-content:space-between;align-items:center\">
\t\t<div>
\t\t\t<form method=\"get\" action=\"/\">
\t\t\t\t";
        // line 88
        yield "
\t\t\t\t<input type=\"hidden\" name=\"page\" value=\"quote/quotes-list\">
\t\t\t\t<label>Per page
\t\t\t\t\t<select name=\"per_page\" onchange=\"this.form.submit()\" style=\"padding:6px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t<option value=\"50\">50</option>
\t\t\t\t\t\t<option value=\"100\">100</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</form>
\t\t</div>

\t\t";
        // line 100
        yield "\t\t<div style=\"display:flex;gap:8px\">
\t\t\t";
        // line 101
        if ((($context["page"] ?? null) > 1)) {
            // line 102
            yield "\t\t\t\t<a href=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["previousPagePath"] ?? null), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff\">Prev</a>
\t\t\t";
        }
        // line 104
        yield "\t\t\t<div style=\"padding:6px 10px;color:var(--muted)\">Page
\t\t\t\t";
        // line 105
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["page"] ?? null), "html", null, true);
        yield " / ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["pageCount"] ?? null), "html", null, true);
        yield "
\t\t\t</div>
\t\t\t";
        // line 107
        if ((($context["page"] ?? null) < ($context["pageCount"] ?? null))) {
            // line 108
            yield "\t\t\t\t<a href=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["nextPagePath"] ?? null), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff\">Next</a>
\t\t\t";
        }
        // line 110
        yield "\t\t</div>
\t</div>
</section>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages/quote/quotes-list.twig";
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
        return array (  247 => 110,  241 => 108,  239 => 107,  232 => 105,  229 => 104,  223 => 102,  221 => 101,  218 => 100,  205 => 88,  196 => 78,  186 => 73,  179 => 69,  172 => 65,  169 => 64,  167 => 63,  164 => 62,  159 => 59,  155 => 57,  149 => 54,  146 => 53,  144 => 52,  141 => 51,  135 => 49,  133 => 48,  128 => 46,  122 => 43,  118 => 42,  114 => 41,  107 => 39,  104 => 38,  98 => 35,  95 => 34,  93 => 33,  89 => 32,  84 => 31,  80 => 30,  70 => 22,  66 => 20,  64 => 19,  59 => 17,  51 => 11,  49 => 10,  46 => 9,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/quote/quotes-list.twig", "/var/www/src/views/pages/quote/quotes-list.twig");
    }
}
