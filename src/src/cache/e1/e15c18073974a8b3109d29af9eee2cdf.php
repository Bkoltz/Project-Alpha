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

/* partials/document_details/display/items-table-display.twig */
class __TwigTemplate_7405b92940999bd9110287956a20558f extends Template
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
        yield "<table style=\"width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t<thead>
\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t\t<th style=\"padding:10px;width:25%;vertical-align:top;text-align:center\">Item</th>
\t\t\t<th style=\"padding:10px;width:35%;vertical-align:top\">Description</th>
\t\t\t<th style=\"padding:10px;width:10%;text-align:right;vertical-align:top\">Quantity</th>
\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Unit Price</th>
\t\t\t<th style=\"padding:10px;width:15%;text-align:right;vertical-align:top\">Line Total</th>
\t\t</tr>
\t</thead>
\t<tbody>
\t\t";
        // line 12
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["view"] ?? null), "items", [], "any", false, false, false, 12));
        foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
            // line 13
            yield "\t\t\t<tr style=\"border-top:1px solid #f3f4f6\">
\t\t\t\t<td style=\"padding:10px;font-weight:600;text-align:center\">
\t\t\t\t\t";
            // line 15
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "name", [], "any", false, false, false, 15), "html", null, true);
            yield "
\t\t\t\t</td>
\t\t\t\t<td style=\"padding:10px;color:#6b7280;font-size:13px\">
\t\t\t\t\t";
            // line 18
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "description", [], "any", false, false, false, 18), "html", null, true);
            yield "
\t\t\t\t</td>
\t\t\t\t<td style=\"padding:10px;text-align:right\">
\t\t\t\t\t";
            // line 21
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "formattedQuantity", [], "any", false, false, false, 21), "html", null, true);
            yield "
\t\t\t\t</td>
\t\t\t\t<td style=\"padding:10px;text-align:right\">
\t\t\t\t\t";
            // line 24
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "formattedUnitPrice", [], "any", false, false, false, 24), "html", null, true);
            yield "
\t\t\t\t</td>
\t\t\t\t<td style=\"padding:10px;text-align:right\">
\t\t\t\t\t";
            // line 27
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "formattedLineTotal", [], "any", false, false, false, 27), "html", null, true);
            yield "
\t\t\t\t</td>
\t\t\t</tr>
\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 31
        yield "\t</tbody>
</table>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_details/display/items-table-display.twig";
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
        return array (  97 => 31,  87 => 27,  81 => 24,  75 => 21,  69 => 18,  63 => 15,  59 => 13,  55 => 12,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/items-table-display.twig", "/var/www/src/views/partials/document_details/display/items-table-display.twig");
    }
}
