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

/* partials/document_details/display/signature-display.twig */
class __TwigTemplate_c662b61a75e8c99348c37dbd0d913720 extends Template
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
        yield "<div style=\"margin-top:24px;padding:12px 10px;color:#374151;font-size:13px;line-height:1.4\">
\t<strong>By signing below</strong>, I acknowledge that this is a multi-page document and that I have read and agree to the terms and conditions.
</div>

<table style=\"width:100%;border-collapse:collapse\">
\t";
        // line 6
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["signatures"] ?? null), "signature_titles", [], "any", false, false, false, 6));
        $context['loop'] = [
          'parent' => $context['_parent'],
          'index0' => 0,
          'index'  => 1,
          'first'  => true,
        ];
        if (is_array($context['_seq']) || (is_object($context['_seq']) && $context['_seq'] instanceof \Countable)) {
            $length = count($context['_seq']);
            $context['loop']['revindex0'] = $length - 1;
            $context['loop']['revindex'] = $length;
            $context['loop']['length'] = $length;
            $context['loop']['last'] = 1 === $length;
        }
        foreach ($context['_seq'] as $context["_key"] => $context["signature_title"]) {
            // line 7
            yield "\t\t";
            $context["is_required"] = (($_v0 = CoreExtension::getAttribute($this->env, $this->source, ($context["signatures"] ?? null), "signature_required", [], "any", false, false, false, 7)) && is_array($_v0) || $_v0 instanceof ArrayAccess ? ($_v0[CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index0", [], "any", false, false, false, 7)] ?? null) : null);
            // line 8
            yield "\t\t<tr>
\t\t\t<td style=\"width:50%;vertical-align:top;padding:10px 16px 16px 10px\">
\t\t\t\t<table style=\"width:100%;border-collapse:collapse;margin-top:20px\">
\t\t\t\t\t<tr>
\t\t\t\t\t\t<td style=\"width:65%;vertical-align:bottom;padding-right:12px\">
\t\t\t\t\t\t\t<div style=\"border-top:2px solid #111\"></div>
\t\t\t\t\t\t\t<div style=\"margin-top:4px;color:#4b5563;font-size:12px\">
\t\t\t\t\t\t\t\t";
            // line 15
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["signature_title"], "html", null, true);
            yield "

\t\t\t\t\t\t\t\t";
            // line 17
            if ((($tmp = ($context["is_required"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 18
                yield "\t\t\t\t\t\t\t\t\t<span style=\"color:#dc2626\">*</span>
\t\t\t\t\t\t\t\t";
            }
            // line 20
            yield "\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t</td>
\t\t\t\t\t\t<td style=\"width:35%;vertical-align:bottom\">
\t\t\t\t\t\t\t<div style=\"border-top:2px solid #111\"></div>
\t\t\t\t\t\t\t<div style=\"margin-top:4px;color:#4b5563;font-size:12px\">Date</div>
\t\t\t\t\t\t</td>
\t\t\t\t\t</tr>
\t\t\t\t</table>
\t\t\t</td>
\t\t</tr>
\t";
            ++$context['loop']['index0'];
            ++$context['loop']['index'];
            $context['loop']['first'] = false;
            if (isset($context['loop']['revindex0'], $context['loop']['revindex'])) {
                --$context['loop']['revindex0'];
                --$context['loop']['revindex'];
                $context['loop']['last'] = 0 === $context['loop']['revindex0'];
            }
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['signature_title'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 31
        yield "</table>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_details/display/signature-display.twig";
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
        return array (  113 => 31,  89 => 20,  85 => 18,  83 => 17,  78 => 15,  69 => 8,  66 => 7,  49 => 6,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_details/display/signature-display.twig", "/var/www/src/views/partials/document_details/display/signature-display.twig");
    }
}
