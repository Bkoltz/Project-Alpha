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

/* partials/document_details/display/total-details-display.twig */
class __TwigTemplate_fc8ed77008f272351d69d057581b776c extends Template
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
        yield "<table style=\"width:100%;border-collapse:collapse;margin-top:12px\">
\t<tr>
\t\t<td style=\"width:60%\"></td>
\t\t<td style=\"width:40%\">
\t\t\t<table style=\"width:100%;border-collapse:collapse\">
\t\t\t\t<tr>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Subtotal</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;width:120px\">\$";
        // line 8
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "subtotal", [], "any", false, false, false, 8), "html", null, true);
        yield "</td>
\t\t\t\t</tr>
\t\t\t\t<tr>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Discount</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">
\t\t\t\t\t\t";
        // line 13
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "discount_type", [], "any", false, false, false, 13) == "percent")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "discount_value", [], "any", false, false, false, 13), 2) . "%"), "html", null, true)) : ((((CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "discount_type", [], "any", false, false, false, 13) == "fixed")) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(("\$" . $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "discount_value", [], "any", false, false, false, 13), 2)), "html", null, true)) : ("\$0.00"))));
        yield "
\t\t\t\t\t</td>
\t\t\t\t</tr>
\t\t\t\t<tr>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;color:#6b7280\">Tax</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right\">";
        // line 18
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "tax_percent", [], "any", false, false, false, 18), 2), "html", null, true);
        yield "%</td>
\t\t\t\t</tr>
\t\t\t\t<tr style=\"border-top:2px solid #e5e7eb\">
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700\">Total</td>
\t\t\t\t\t<td style=\"padding:8px 12px;text-align:right;font-weight:700;font-size:16px\">\$";
        // line 22
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "total", [], "any", false, false, false, 22), 2), "html", null, true);
        yield "</td>
\t\t\t\t</tr>
\t\t\t</table>
\t\t</td>
\t</tr>
</table>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_details/display/total-details-display.twig";
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
        return array (  74 => 22,  67 => 18,  59 => 13,  51 => 8,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/total-details-display.twig", "/var/www/src/views/partials/document_details/display/total-details-display.twig");
    }
}
