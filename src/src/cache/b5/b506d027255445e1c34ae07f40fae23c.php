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

/* pages/quote/quote-general-list.twig */
class __TwigTemplate_9ccb424b519291752fed8de52d9930e1 extends Template
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
        $context["rowTemplates"] = ["regular" => "partials/document_list/rows/quote/regular-quote-rows.twig", "long_term" => "partials/document_list/rows/quote/long-term-quote-rows.twig", "on_demand" => "partials/document_list/rows/quote/on-demand-quote-rows.twig"];
        // line 6
        yield "
<section>
\t<h2>";
        // line 8
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["title"] ?? null), "html", null, true);
        yield "</h2>
\t";
        // line 10
        yield "\t";
        yield from $this->load("templates/components/document-filter.html.twig", 10)->unwrap()->yield(CoreExtension::merge($context, ($context["filterConfig"] ?? null)));
        // line 11
        yield "
\t";
        // line 13
        yield "\t<div style=\"overflow:auto\">
\t\t<table style=\"width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t\t\t";
        // line 15
        $context["template"] = (($_v0 = ($context["rowTemplates"] ?? null)) && is_array($_v0) || $_v0 instanceof ArrayAccess ? ($_v0[($context["documentType"] ?? null)] ?? null) : null);
        // line 16
        yield "
\t\t\t";
        // line 17
        yield from $this->load(($context["template"] ?? null), 17)->unwrap()->yield(CoreExtension::merge($context, ($context["rows"] ?? null)));
        // line 18
        yield "\t\t</table>
\t</div>

\t";
        // line 22
        yield "\t<div style=\"margin-top:12px;display:flex;justify-content:space-between;align-items:center\">
\t\t<div>
\t\t\t<form method=\"get\" action=\"/\">
\t\t\t\t<input type=\"hidden\" name=\"page\" value=\"quote/regular-quote-list\">
\t\t\t\t<label>Per page
\t\t\t\t\t<select name=\"per_page\" onchange=\"this.form.submit()\" style=\"padding:6px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t\t<option value=\"50\">50</option>
\t\t\t\t\t\t<option value=\"100\">100</option>
\t\t\t\t\t</select>
\t\t\t\t</label>
\t\t\t</form>
\t\t</div>

\t\t";
        // line 36
        yield "\t\t";
        yield from $this->load("partials/document_list/page-number-control.twig", 36)->unwrap()->yield(CoreExtension::merge($context, ($context["page_number_view"] ?? null)));
        // line 37
        yield "\t</div>
</section>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages/quote/quote-general-list.twig";
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
        return array (  92 => 37,  89 => 36,  74 => 22,  69 => 18,  67 => 17,  64 => 16,  62 => 15,  58 => 13,  55 => 11,  52 => 10,  48 => 8,  44 => 6,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/quote/quote-general-list.twig", "/var/www/src/views/pages/quote/quote-general-list.twig");
    }
}
