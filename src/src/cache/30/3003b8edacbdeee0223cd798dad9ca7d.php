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

/* partials/document_details/display/custom-field-display.twig */
class __TwigTemplate_1d303ac7b002e57e3b089415032dd622 extends Template
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
        yield "<table style=\"width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb\">
\t<tr>
\t\t";
        // line 3
        if ((($tmp = ($context["show_deposit_info"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 4
            yield "\t\t\t<td style=\"padding:8px;border-right:1px solid #e5e7eb;vertical-align:top\">
\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Deposit Due:
\t\t\t\t\t<span style=\"font-weight:600;color:#059669\">\$";
            // line 6
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatNumber(($context["deposit_due"] ?? null), 2), "html", null, true);
            yield "</span>
\t\t\t\t</div>
\t\t\t</td>
\t\t";
        }
        // line 10
        yield "\t\t";
        if ((($tmp = ($context["show_fulfillment_date"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 11
            yield "\t\t\t<td style=\"padding:8px;";
            yield (((($tmp = ($context["hasCustomFields"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("border-right:1px solid #e5e7eb;") : (""));
            yield "vertical-align:top\">
\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">Fulfillment Date:
\t\t\t\t\t<span style=\"font-weight:600;color:#2563eb\">";
            // line 13
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(($context["fulfillment_date"] ?? null), "M j, Y"), "html", null, true);
            yield "</span>
\t\t\t\t</div>
\t\t\t</td>
\t\t";
        }
        // line 17
        yield "\t\t";
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["custom_fields"] ?? null));
        foreach ($context['_seq'] as $context["idx"] => $context["custom_field"]) {
            // line 18
            yield "\t\t\t<td style=\"padding:8px;";
            yield ((($context["idx"] < (Twig\Extension\CoreExtension::length($this->env->getCharset(), $context["custom_field"]) - 1))) ? ("border-right:1px solid #e5e7eb;") : (""));
            yield "vertical-align:top\">
\t\t\t\t<div style=\"font-size:11px;color:#6b7280\">";
            // line 19
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["custom_field"], "label", [], "any", false, false, false, 19));
            yield ":
\t\t\t\t\t<span style=\"font-weight:600;color:#374151\">";
            // line 20
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["custom_field"], "value", [], "any", false, false, false, 20));
            yield "</span>
\t\t\t\t</div>
\t\t\t</td>
\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['idx'], $context['custom_field'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 24
        yield "\t</tr>
</table>

";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_details/display/custom-field-display.twig";
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
        return array (  99 => 24,  89 => 20,  85 => 19,  80 => 18,  75 => 17,  68 => 13,  62 => 11,  59 => 10,  52 => 6,  48 => 4,  46 => 3,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/custom-field-display.twig", "/var/www/src/views/partials/document_details/display/custom-field-display.twig");
    }
}
