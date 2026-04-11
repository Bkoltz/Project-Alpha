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

/* partials/document_details/input/total-input.twig */
class __TwigTemplate_9df8980d2abb5034412b54f1a23152a3 extends Template
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
        yield "
<div id=\"totalsCo\" style=\"margin-top:8px;display:grid;gap:6px;justify-content:end\">
\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Subtotal</div>
\t\t<div id=\"subtotalValCo\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t</div>
\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Discount</div>
\t\t<div id=\"discountValCo\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t</div>
\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t<div style=\"min-width:140px;text-align:right;color:var(--muted)\">Tax</div>
\t\t<div id=\"taxValCo\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t</div>

\t";
        // line 17
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "is_ongoing", [], "any", false, false, false, 17) ||  !CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "is_ongoing", [], "any", true, true, false, 17))) {
            // line 18
            yield "\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end;font-weight:700\">
\t\t\t<div style=\"min-width:140px;text-align:right\">Total</div>
\t\t\t<div id=\"totalValCo\" style=\"min-width:120px;text-align:right\">\$0.00</div>
\t\t</div>
\t";
        }
        // line 23
        yield "
\t";
        // line 24
        if (CoreExtension::getAttribute($this->env, $this->source, ($context["document"] ?? null), "deposit_due", [], "any", true, true, false, 24)) {
            // line 25
            yield "\t\t<div id=\"depositRowCo\" style=\"display:none;border-top:1px solid #e5e7eb;padding-top:6px;margin-top:6px\">
\t\t\t<div style=\"display:flex;gap:16px;justify-content:flex-end\">
\t\t\t\t<div style=\"min-width:140px;text-align:right;color:#059669;font-weight:700;font-size:15px\">Deposit Due</div>
\t\t\t\t<div id=\"depositValCo\" style=\"min-width:120px;text-align:right;color:#059669;font-weight:700;font-size:15px\">\$0.00</div>
\t\t\t</div>
\t\t</div>
\t";
        }
        // line 32
        yield "</div>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_details/input/total-input.twig";
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
        return array (  82 => 32,  73 => 25,  71 => 24,  68 => 23,  61 => 18,  59 => 17,  42 => 2,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/input/total-input.twig", "/var/www/src/views/partials/document_details/input/total-input.twig");
    }
}
