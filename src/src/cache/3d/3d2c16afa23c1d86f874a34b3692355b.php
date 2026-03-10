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

/* partials/custom_fields/custom_field_row.twig */
class __TwigTemplate_7f2c826d9f6b698448ce833e51b773b2 extends Template
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
        yield "<label id=\"";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_key", [], "any", false, false, false, 1), "html", null, true);
        yield "Label";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["idSuffix"] ?? null), "html", null, true);
        yield "\">
\t<div>
\t\t";
        // line 3
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_label", [], "any", false, false, false, 3), "html", null, true);
        yield "
\t\t";
        // line 4
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "is_required", [], "any", false, false, false, 4)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 5
            yield "\t\t\t<span style=\"color:#dc2626\">*</span>
\t\t";
        }
        // line 7
        yield "\t</div>

\t";
        // line 9
        $context["templates"] = ["deposit" => "partials/custom_fields/deposit_field.twig", "date" => "partials/custom_fields/date_field.twig", "number" => "partials/custom_fields/number_field.twig", "select" => "partials/custom_fields/select_field.twig", "text" => "partials/custom_fields/text_field.twig"];
        // line 16
        yield "
\t";
        // line 17
        $context["template"] = ((CoreExtension::getAttribute($this->env, $this->source, ($context["templates"] ?? null), CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_type", [], "any", false, false, false, 17), [], "array", true, true, false, 17)) ? (Twig\Extension\CoreExtension::default((($_v0 = ($context["templates"] ?? null)) && is_array($_v0) || $_v0 instanceof ArrayAccess ? ($_v0[CoreExtension::getAttribute($this->env, $this->source, ($context["field"] ?? null), "field_type", [], "any", false, false, false, 17)] ?? null) : null), "partials/custom_fields/default_field.twig")) : ("partials/custom_fields/default_field.twig"));
        // line 18
        yield "
\t";
        // line 19
        yield from $this->load(($context["template"] ?? null), 19)->unwrap()->yield(CoreExtension::merge($context, ["field" => ($context["field"] ?? null), "idSuffix" => ($context["idSuffix"] ?? null)]));
        // line 20
        yield "</label>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/custom_fields/custom_field_row.twig";
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
        return array (  76 => 20,  74 => 19,  71 => 18,  69 => 17,  66 => 16,  64 => 9,  60 => 7,  56 => 5,  54 => 4,  50 => 3,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/custom_fields/custom_field_row.twig", "/var/www/src/views/partials/custom_fields/custom_field_row.twig");
    }
}
