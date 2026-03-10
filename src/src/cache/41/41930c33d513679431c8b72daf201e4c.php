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

/* partials/custom_fields/select_field.twig */
class __TwigTemplate_efdc83e594f1b7ab2cf7bba22110f30b extends Template
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
        yield "<select name=\"";
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_name", [], "any", true, true, false, 1) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_name", [], "any", false, false, false, 1)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_name", [], "any", false, false, false, 1), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_key", [], "any", false, false, false, 1), "html", null, true)));
        yield "\" id=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_key", [], "any", false, false, false, 1), "html", null, true);
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["idSuffix"] ?? null), "html", null, true);
        yield "\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "is_required", [], "any", false, false, false, 1)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            yield " required ";
        }
        yield ">
\t";
        // line 2
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable($this->env->getFilter('json_decode')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_options", [], "any", false, false, false, 2)));
        foreach ($context['_seq'] as $context["_key"] => $context["option"]) {
            // line 3
            yield "\t\t<option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["option"], "html", null, true);
            yield "\">
\t\t\t";
            // line 4
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["option"], "html", null, true);
            yield "
\t\t</option>
\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['option'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 7
        yield "</select>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/custom_fields/select_field.twig";
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
        return array (  72 => 7,  63 => 4,  58 => 3,  54 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/custom_fields/select_field.twig", "/var/www/src/views/partials/custom_fields/select_field.twig");
    }
}
