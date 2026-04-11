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

/* partials/document_list/page-number-control.twig */
class __TwigTemplate_7b051571d9df11589354df9310a06462 extends Template
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
        yield "<div style=\"margin-top:12px;display:flex;justify-content:space-between;align-items:center\">
\t<div>
\t\t<form method=\"get\" action=\"/\">
\t\t\t<input type=\"hidden\" name=\"page\" value=\"quote/quote-list\">
\t\t\t<label>Per page
\t\t\t\t<select name=\"per_page\" onchange=\"this.form.submit()\" style=\"padding:6px;border-radius:8px;border:1px solid #ddd\">
\t\t\t\t\t<option value=\"50\">50</option>
\t\t\t\t\t<option value=\"100\">100</option>
\t\t\t\t</select>
\t\t\t</label>
\t\t</form>
\t</div>

\t";
        // line 15
        yield "\t<div style=\"display:flex;gap:8px\">
\t\t";
        // line 16
        if ((($context["page"] ?? null) > 1)) {
            // line 17
            yield "\t\t\t<a href=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["previous_page_path"] ?? null), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff\">Prev</a>
\t\t";
        }
        // line 19
        yield "\t\t<div style=\"padding:6px 10px;color:var(--muted)\">Page
\t\t\t";
        // line 20
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["page"] ?? null), "html", null, true);
        yield "
\t\t\t/
\t\t\t";
        // line 22
        yield (((array_key_exists("page_count", $context) &&  !(null === $context["page_count"]))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["page_count"], "html", null, true)) : (1));
        yield "
\t\t</div>
\t\t";
        // line 24
        if ((($context["page"] ?? null) < ($context["page_count"] ?? null))) {
            // line 25
            yield "\t\t\t<a href=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["next_page_path"] ?? null), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff\">Next</a>
\t\t";
        }
        // line 27
        yield "\t</div>
</div>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/page-number-control.twig";
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
        return array (  89 => 27,  83 => 25,  81 => 24,  76 => 22,  71 => 20,  68 => 19,  62 => 17,  60 => 16,  57 => 15,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/page-number-control.twig", "/var/www/src/views/partials/document_list/page-number-control.twig");
    }
}
