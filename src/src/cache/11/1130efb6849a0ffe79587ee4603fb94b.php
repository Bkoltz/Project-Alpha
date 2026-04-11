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

/* partials/document_details/display/terms-display.twig */
class __TwigTemplate_c2fa002f166b3d46854da8d9d5d6c23c extends Template
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
        yield "<div style=\"page-break-after:always\"></div>

<h3>Terms and Conditions</h3>
";
        // line 4
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "terms", [], "any", false, false, false, 4)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 5
            yield "\t<div style=\"white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222;\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "terms", [], "any", false, false, false, 5), "html", null, true);
            yield "</div>
";
        } else {
            // line 6
            yield "<!-- <p class=\"lead\">By signing, the client agrees to the scope, timeline, and payment schedule indicated in this document. Additional terms can be customized later.</p> --><ul>
\t\t<li>Payment due NET 30 unless otherwise specified.</li>
\t\t<li>Cancellation requires written notice.</li>
\t\t<li>Work product ownership and usage rights per agreement.</li>
\t</ul>
";
        }
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_details/display/terms-display.twig";
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
        return array (  55 => 6,  49 => 5,  47 => 4,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/terms-display.twig", "/var/www/src/views/partials/document_details/display/terms-display.twig");
    }
}
