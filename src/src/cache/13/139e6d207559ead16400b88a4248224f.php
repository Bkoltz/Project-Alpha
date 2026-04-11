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

/* partials/document_details/display/project-details-display.twig */
class __TwigTemplate_801bfd60953da90d00ff9662128ca2fd extends Template
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
        yield "<table style=\"width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse\">
\t<tr>
\t\t<td style=\"vertical-align:middle;width:70%\">
\t\t\t<div style=\"font-weight:700;font-size:20px\">";
        // line 4
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["branding"] ?? null), "name", [], "any", false, false, false, 4), "html", null, true);
        yield "</div>

\t\t\t";
        // line 6
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "project_code", [], "any", false, false, false, 6))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 7
            yield "\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Job
\t\t\t\t\t";
            // line 8
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "project_code", [], "any", false, false, false, 8), "html", null, true);
            yield "</div>
\t\t\t";
        }
        // line 10
        yield "
\t\t\t";
        // line 11
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "project_id", [], "any", false, false, false, 11))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 12
            yield "\t\t\t\t<div style=\"color:#374151;font-size:13px;margin-top:2px\">Project
\t\t\t\t\t";
            // line 13
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "project_id", [], "any", false, false, false, 13), "html", null, true);
            yield "</div>
\t\t\t";
        }
        // line 15
        yield "\t\t</td>

\t\t<td style=\"vertical-align:middle;width:30%;text-align:right\">
\t\t\t<img src=\"";
        // line 18
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["branding"] ?? null), "logo_path", [], "any", false, false, false, 18), "html", null, true);
        yield "\" alt=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["branding"] ?? null), "name", [], "any", false, false, false, 18), "html", null, true);
        yield "\" style=\"height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px\">
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
        return "partials/document_details/display/project-details-display.twig";
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
        return array (  80 => 18,  75 => 15,  70 => 13,  67 => 12,  65 => 11,  62 => 10,  57 => 8,  54 => 7,  52 => 6,  47 => 4,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/project-details-display.twig", "/var/www/src/views/partials/document_details/display/project-details-display.twig");
    }
}
