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

/* partials/custom_fields/deposit_field.twig */
class __TwigTemplate_32adf4bdbe20b25262273d84ba8db9f5 extends Template
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
        yield "<select id=\"depositType";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["idSuffix"] ?? null), "html", null, true);
        yield "\" name=\"deposit_type\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\">
\t<option value=\"none\" ";
        // line 2
        yield (((($context["deposit_type"] ?? null) == "none")) ? ("selected") : (""));
        yield ">None</option>
\t<option value=\"percent\" ";
        // line 3
        yield (((($context["deposit_type"] ?? null) == "percent")) ? ("selected") : (""));
        yield ">Percent</option>
\t<option value=\"fixed\" ";
        // line 4
        yield (((($context["deposit_type"] ?? null) == "fixed")) ? ("selected") : (""));
        yield ">Fixed \$</option>
</select>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/custom_fields/deposit_field.twig";
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
        return array (  55 => 4,  51 => 3,  47 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/custom_fields/deposit_field.twig", "/var/www/src/views/partials/custom_fields/deposit_field.twig");
    }
}
