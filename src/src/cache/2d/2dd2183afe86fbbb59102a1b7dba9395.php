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

/* partials/client_components/client-input-autocomplete.twig */
class __TwigTemplate_b5bf7c094b440f2392cb48a23604069f extends Template
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
        yield "<label style=\"grid-column:1/2;position:relative\">
\t<div>Client</div>
\t<input id=\"clientInput\" type=\"text\" placeholder=\"Type client name...\" autocomplete=\"off\" style=\"width:100%;padding:10px;border-radius:8px;border:1px solid #ddd\" value=";
        // line 3
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "client_name", [], "any", true, true, false, 3) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "client_name", [], "any", false, false, false, 3)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "client_name", [], "any", false, false, false, 3), "html", null, true)) : (""));
        yield " ";
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "client_name", [], "any", true, true, false, 3) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "client_name", [], "any", false, false, false, 3)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "client_name", [], "any", false, false, false, 3), "html", null, true)) : (""));
        yield ">
\t<input id=\"clientId\" type=\"hidden\" name=\"client_id\" value=";
        // line 4
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "client_id", [], "any", false, false, false, 4), "html", null, true);
        yield ">
\t<div id=\"clientSuggest\" style=\"position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto\"></div>
</label>

<script src=\"js/client-selection-dropdown-logic.js\" defer></script>";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/client_components/client-input-autocomplete.twig";
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
        return array (  52 => 4,  46 => 3,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/client_components/client-input-autocomplete.twig", "/var/www/src/views/partials/client_components/client-input-autocomplete.twig");
    }
}
