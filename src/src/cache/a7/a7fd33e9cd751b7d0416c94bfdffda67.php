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

/* pages/contract/contracts-general-list.twig */
class __TwigTemplate_9899c78bb06b385a9ad7bab1bb876aa5 extends Template
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
        $context["rowTemplates"] = ["regular" => "partials/document_list/rows/contract/regular-contract-rows.twig", "long_term" => "partials/document_list/rows/contract/long-term-contract-rows.twig", "on_demand" => "partials/document_list/rows/contract/on-demand-contract-rows.twig"];
        // line 6
        yield "
<section>
\t<h2>";
        // line 8
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["title"] ?? null), "html", null, true);
        yield "</h2>

\t";
        // line 10
        if ((($tmp = ($context["created"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 11
            yield "\t\t<div style=\"margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0\">On-demand contract created successfully.</div>
\t";
        }
        // line 13
        yield "
\t";
        // line 14
        if ((($tmp = ($context["invoice_generated"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 15
            yield "\t\t<div style=\"margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0\">Invoice generated successfully.</div>
\t";
        }
        // line 17
        yield "
\t";
        // line 18
        if ((($tmp = ($context["activated"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 19
            yield "\t\t<div style=\"margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0\">Contract activated.</div>
\t";
        }
        // line 21
        yield "
\t";
        // line 22
        if (array_key_exists("error", $context)) {
            // line 23
            yield "\t\t<div style=\"margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["error"] ?? null), "html", null, true);
            yield "</div>
\t";
        }
        // line 25
        yield "
\t";
        // line 26
        yield from $this->load("templates/components/document-filter.html.twig", 26)->unwrap()->yield(CoreExtension::merge($context, ($context["filter_config"] ?? null)));
        // line 27
        yield "
    ";
        // line 29
        yield "\t<div style=\"overflow:auto\">
\t\t<table style=\"width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t\t\t";
        // line 31
        $context["template"] = (($_v0 = ($context["rowTemplates"] ?? null)) && is_array($_v0) || $_v0 instanceof ArrayAccess ? ($_v0[($context["document_type"] ?? null)] ?? null) : null);
        // line 32
        yield "
\t\t\t";
        // line 33
        yield from $this->load(($context["template"] ?? null), 33)->unwrap()->yield(CoreExtension::merge($context, ($context["rows"] ?? null)));
        // line 34
        yield "\t\t</table>
\t</div>

\t";
        // line 37
        yield from $this->load("partials/document_list/page-number-control.twig", 37)->unwrap()->yield(CoreExtension::merge($context, ($context["page_number_view"] ?? null)));
        // line 38
        yield "</section>";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "pages/contract/contracts-general-list.twig";
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
        return array (  114 => 38,  112 => 37,  107 => 34,  105 => 33,  102 => 32,  100 => 31,  96 => 29,  93 => 27,  91 => 26,  88 => 25,  82 => 23,  80 => 22,  77 => 21,  73 => 19,  71 => 18,  68 => 17,  64 => 15,  62 => 14,  59 => 13,  55 => 11,  53 => 10,  48 => 8,  44 => 6,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "pages/contract/contracts-general-list.twig", "/var/www/src/views/pages/contract/contracts-general-list.twig");
    }
}
