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

/* pages/quote/quotes-edit.twig */
class __TwigTemplate_38c1e3eaa409471e63d0017a9932046d extends Template
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
\t<h2>Edit Quote Q-";
        // line 2
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", true, true, false, 2) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 2)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "doc_number", [], "any", false, false, false, 2), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 2), "html", null, true)));
        yield "
\t\t";
        // line 3
        if (CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", true, true, false, 3)) {
            // line 4
            yield "\t\t\tJob
\t\t\t";
            // line 5
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "project_code", [], "any", false, false, false, 5), "html", null, true);
            yield "
\t\t";
        }
        // line 7
        yield "\t</h2>
\t<form id=\"quoteEditForm\" method=\"post\" action=\"/?page=quote/quotes-edit\" style=\"display:grid;gap:16px;max-width:900px\">
\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
        // line 9
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
        yield "\">
\t\t<input type=\"hidden\" name=\"id\" value=\"";
        // line 10
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "id", [], "any", false, false, false, 10), "html", null, true);
        yield "\">
\t\t<div style=\"display:grid;gap:12px;grid-template-columns:1fr 1fr\">
\t\t\t<label>
\t\t\t\t<!-- Client input -->
\t\t\t\t";
        // line 14
        yield from $this->load("partials/client_components/client_input_autocomplete.twig", 14)->unwrap()->yield(CoreExtension::merge($context, ["clientName" => CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "client_name", [], "any", false, false, false, 14), "clientId" => CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "client_id", [], "any", false, false, false, 14)]));
        // line 15
        yield "\t\t\t</label>
\t\t</label>
\t\t<label>
\t\t\t<div>Tax (%)</div>
\t\t\t<input id=\"taxPercent\" type=\"number\" step=\"0.01\" name=\"tax_percent\" value=\"";
        // line 19
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "tax_percent", [], "any", false, false, false, 19), "html", null, true);
        yield "\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t</label>
\t\t<label>
\t\t\t<div>Discount Type</div>
\t\t\t<select id=\"discountType\" name=\"discount_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t<option value=\"none\" ";
        // line 24
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 24), "html", null, true);
        yield ">None</option>
\t\t\t\t<option value=\"percent\" ";
        // line 25
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 25), "html", null, true);
        yield ">Percent</option>
\t\t\t\t<option value=\"fixed\" ";
        // line 26
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_type", [], "any", false, false, false, 26), "html", null, true);
        yield ">Fixed \$</option>
\t\t\t</select>
\t\t</label>
\t\t<label>
\t\t\t<div>Discount Value</div>
\t\t\t<input id=\"discountValue\" type=\"number\" step=\"0.01\" name=\"discount_value\" value=\"";
        // line 31
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["quote"] ?? null), "discount_value", [], "any", false, false, false, 31), "html", null, true);
        yield "\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t\t</label>
\t</div>

\t";
        // line 36
        yield "\t<div id=\"customFieldsContainer\">
\t\t";
        // line 37
        yield from $this->load("partials/custom_fields/custom_field.twig", 37)->unwrap()->yield(CoreExtension::merge($context, ["fields" => ($context["fields"] ?? null), "columns" => ($context["columns"] ?? null), "idSuffix" => ($context["idSuffix"] ?? null)]));
        // line 38
        yield "\t</div>

\t<div>
\t\t<div style=\"font-weight:600;margin-bottom:8px\">Items</div>
\t\t<div id=\"items\" style=\"display:grid;gap:8px\"></div>
\t\t<button id=\"addItemBtn\" type=\"button\" style=\"margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff\">+ Add Item</button>
\t</div>

\t";
        // line 46
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["appConfig"] ?? null), "quote_scope_enabled", [], "any", false, false, false, 46)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 47
            yield "\t\t<label>
\t\t\t<div>Scope of Work</div>
\t\t\t<textarea name=\"scope\" rows=\"4\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" placeholder=\"Optional: Describe the scope of work and deliverables...\"><?php echo htmlspecialchars(\$quote['scope'] ?? ''); ?></textarea>
\t\t</label>
\t";
        }
        // line 52
        yield "
\t<label>
\t\t<div>Job Notes</div>
\t\t<textarea name=\"project_notes\" rows=\"3\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" placeholder=\"Shared across related docs\">";
        // line 55
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["notes"] ?? null), "html", null, true);
        yield "</textarea>
\t</label>

\t<label>
\t\t<div>Job Terms (override default terms for this job)</div>
\t\t<textarea name=\"project_terms\" rows=\"6\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" placeholder=\"If set, used for all quotes/contracts under this project\">";
        // line 60
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["terms"] ?? null), "html", null, true);
        yield "</textarea>
\t</label>

\t<div id=\"totals\" style=\"margin-top:8px;display:grid;gap:6px;justify-content:end\">
\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Subtotal</div>
\t\t\t<div id=\"subtotalVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t</div>
\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Discount</div>
\t\t\t<div id=\"discountVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t</div>
\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Tax</div>
\t\t\t<div id=\"taxVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t</div>
\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end;font-weight:700\">
\t\t\t<div style=\"min-width:140px;text-align:right\">Total</div>
\t\t\t<div id=\"totalVal\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t</div>
\t</div>

\t<div>
\t\t<button type=\"submit\" style=\"padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600\">Update Quote</button>
\t</div>
</form></section><div id=\"quote-items-data\" type=\"application/json\" style=\"display:none\">";
        // line 85
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["items"] ?? null), "html", null, true);
        yield "</div><!-- Client logic --><script src=\"js/quotes-edit-logic.js\" defer></script>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages/quote/quotes-edit.twig";
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
        return array (  178 => 85,  150 => 60,  142 => 55,  137 => 52,  130 => 47,  128 => 46,  118 => 38,  116 => 37,  113 => 36,  106 => 31,  98 => 26,  94 => 25,  90 => 24,  82 => 19,  76 => 15,  74 => 14,  67 => 10,  63 => 9,  59 => 7,  54 => 5,  51 => 4,  49 => 3,  45 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/quote/quotes-edit.twig", "/var/www/src/views/pages/quote/quotes-edit.twig");
    }
}
