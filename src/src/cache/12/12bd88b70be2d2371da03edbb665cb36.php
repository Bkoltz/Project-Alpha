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

/* partials/document_details/display/contact-information-display.twig */
class __TwigTemplate_944eacdf72a893bfe1df29749cf50a4c extends Template
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
        yield "<table style=\"width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse\">
\t<tr>
\t\t<td style=\"vertical-align:top;width:50%;padding-right:12px\">
\t\t\t<div style=\"font-weight:600\">From</div>
\t\t\t<div>
\t\t\t\t";
        // line 7
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "from_lines", [], "any", false, false, false, 7));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 8
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 10
        yield "\t\t\t</div>

\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t";
        // line 13
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "from_phone", [], "any", false, false, false, 13))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 14
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "from_phone", [], "any", false, false, false, 14), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 16
        yield "
\t\t\t\t";
        // line 17
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "from_email", [], "any", false, false, false, 17))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 18
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "from_email", [], "any", false, false, false, 18), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 20
        yield "\t\t\t</div>
\t\t</td>

\t\t<td style=\"vertical-align:top;width:50%;padding-left:12px\">
\t\t\t<div style=\"font-weight:600\">To</div>
\t\t\t<div>
\t\t\t\t";
        // line 26
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "to_lines", [], "any", false, false, false, 26));
        foreach ($context['_seq'] as $context["_key"] => $context["line"]) {
            // line 27
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["line"], "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['line'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 29
        yield "\t\t\t</div>

\t\t\t<div style=\"margin-top:6px;color:#4b5563;font-size:13px\">
\t\t\t\t";
        // line 32
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "to_phone", [], "any", false, false, false, 32))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 33
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "to_phone", [], "any", false, false, false, 33), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 35
        yield "
\t\t\t\t";
        // line 36
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "to_email", [], "any", false, false, false, 36))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 37
            yield "\t\t\t\t\t<div>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["contact_information"] ?? null), "to_email", [], "any", false, false, false, 37), "html", null, true);
            yield "</div>
\t\t\t\t";
        }
        // line 39
        yield "\t\t\t</div>
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
        return "partials/document_details/display/contact-information-display.twig";
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
        return array (  131 => 39,  125 => 37,  123 => 36,  120 => 35,  114 => 33,  112 => 32,  107 => 29,  98 => 27,  94 => 26,  86 => 20,  80 => 18,  78 => 17,  75 => 16,  69 => 14,  67 => 13,  62 => 10,  53 => 8,  49 => 7,  42 => 2,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/contact-information-display.twig", "/var/www/src/views/partials/document_details/display/contact-information-display.twig");
    }
}
