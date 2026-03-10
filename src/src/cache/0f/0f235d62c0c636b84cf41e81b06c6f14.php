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

/* partials/custom_fields/date_field.twig */
class __TwigTemplate_3b04387fd50da5eab63c271c7921c302 extends Template
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
        yield "<input type=\"date\" 
    name=\"";
        // line 2
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_name", [], "any", true, true, false, 2) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_name", [], "any", false, false, false, 2)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_name", [], "any", false, false, false, 2), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_key", [], "any", false, false, false, 2), "html", null, true)));
        yield "\" 
    id=\"";
        // line 3
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_key", [], "any", false, false, false, 3), "html", null, true);
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["idSuffix"] ?? null), "html", null, true);
        yield "\" 
    style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\"
    ";
        // line 5
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "is_required", [], "any", false, false, false, 5)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 6
            yield "        'required '
    ";
        }
        // line 8
        yield ">
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/custom_fields/date_field.twig";
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
        return array (  61 => 8,  57 => 6,  55 => 5,  49 => 3,  45 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/custom_fields/date_field.twig", "/var/www/src/views/partials/custom_fields/date_field.twig");
    }
}
