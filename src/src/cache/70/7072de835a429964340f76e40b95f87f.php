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

/* partials/document_details/display/scope-display.twig */
class __TwigTemplate_a6e2496e7642eeaec3809695d7d0710d extends Template
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
        yield "<div style=\"page-break-before:auto;margin-top:20px\">
\t<h3 style=\"font-size:18px;font-weight:700;margin-bottom:12px;color:#111\">Scope of Project</h3>
\t<div style=\"white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px\">";
        // line 3
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "scope", [], "any", false, false, false, 3), "html", null, true);
        yield "</div>
</div>
<div style=\"page-break-after:always\"></div>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_details/display/scope-display.twig";
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
        return array (  46 => 3,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/scope-display.twig", "/var/www/src/views/partials/document_details/display/scope-display.twig");
    }
}
