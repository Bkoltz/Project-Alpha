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

/* partials/document_list/rows/contract/regular-contract-rows.twig */
class __TwigTemplate_f0ae80dfce1f75a064001e019b5e9a45 extends Template
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
        yield "<div style=\"overflow:auto\">
\t<table style=\"width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)\">
\t\t<thead>
\t\t\t<tr style=\"text-align:left;border-bottom:1px solid #eee\">
\t\t\t\t<th style=\"padding:10px\">No.</th>
\t\t\t\t<th style=\"padding:10px\">Project</th>
\t\t\t\t<th style=\"padding:10px\">Client</th>
\t\t\t\t<th style=\"padding:10px\">Status</th>
\t\t\t\t<th style=\"padding:10px\">Total</th>
\t\t\t\t<th style=\"padding:10px\">Actions</th>
\t\t\t\t<th style=\"padding:10px\">Edit</th>
\t\t\t</tr>
\t\t</thead>

\t\t<tbody>
\t\t\t";
        // line 16
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
            // line 17
            yield "\t\t\t\t<tr style=\"border-top:1px solid #f3f4f6;";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "style", [], "any", false, false, false, 17), "html", null, true);
            yield "\">
\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t<a href=\"/?page=contract/contract-details&id=";
            // line 19
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 19), "html", null, true);
            yield "\" style=\"text-decoration:none;color:inherit\">C-";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 19), "html", null, true);
            yield "</a>
\t\t\t\t\t</td>
\t\t\t\t\t<td style=\"padding:10px\">";
            // line 21
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "project_code", [], "any", false, false, false, 21), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t<a href=\"/?page=client/clients-list&selected_client_id=";
            // line 23
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_id", [], "any", false, false, false, 23), "html", null, true);
            yield "\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "client_name", [], "any", false, false, false, 23), "html", null, true);
            yield "</a>
\t\t\t\t\t</td>
\t\t\t\t\t<td style=\"padding:10px;text-transform:capitalize\">";
            // line 25
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 25), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px\">\$";
            // line 26
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "total", [], "any", false, false, false, 26), "html", null, true);
            yield "</td>
\t\t\t\t\t<td style=\"padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center\">
\t\t\t\t\t\t<a href=\"/?page=contract/regular-contract-details&id=";
            // line 28
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 28), "html", null, true);
            yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">View</a>

\t\t\t\t\t\t";
            // line 30
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 30) !== "cancelled")) {
                // line 31
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=email-send\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 32
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"type\" value=\"contract\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 34
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 34), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"redirect_to\" value=\"<?php echo htmlspecialchars(\$_SERVER['REQUEST_URI']); ?>\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Email</button>
\t\t\t\t\t\t\t</form>

\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=contract/contract-sign\" enctype=\"multipart/form-data\" style=\"display:inline-flex;gap:6px;align-items:center\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 40
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 41
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 41), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input id=\"upload-";
                // line 42
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 42), "html", null, true);
                yield "\" type=\"file\" name=\"signed_pdf\" accept=\"application/pdf\" style=\"display:none\" onchange=\"this.form.submit()\">
\t\t\t\t\t\t\t\t<button type=\"button\" onclick=\"document.getElementById('upload-";
                // line 43
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 43), "html", null, true);
                yield "').click()\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">";
                yield ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "signed_pdf_path", [], "any", true, true, false, 43)) ? ("Upload") : ("New Upload"));
                yield "</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 46
            yield "
\t\t\t\t\t\t";
            // line 47
            if (CoreExtension::getAttribute($this->env, $this->source, $context["row"], "signed_pdf_path", [], "any", true, true, false, 47)) {
                // line 48
                yield "\t\t\t\t\t\t\t<a href=\"";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "signedPdfPath", [], "any", false, false, false, 48), "html", null, true);
                yield "\" target=\"_blank\" rel=\"noopener\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Signed PDF</a>
\t\t\t\t\t\t";
            }
            // line 50
            yield "
\t\t\t\t\t\t";
            // line 51
            if ((($context["needsDeposit"] ?? null) && (CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 51) == "pending"))) {
                // line 52
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=contract/contract-deposit-received\" style=\"display:inline\" onsubmit=\"return confirm('Mark deposit as received (\$<?php echo number_format(\$depositCalc, 2); ?>)?')\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 53
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 54
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 54), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46; font-size: small;\">Deposit Received</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 58
            yield "
\t\t\t\t\t\t";
            // line 59
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 59) == "active")) {
                // line 60
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=contract/contract-complete\" style=\"display:inline\" onsubmit=\"return confirm('Mark this contract as completed and set invoice due date?')\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 61
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 62
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 62), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;\">Complete</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 66
            yield "
\t\t\t\t\t\t";
            // line 67
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 67) != "cancelled")) {
                // line 68
                yield "\t\t\t\t\t\t\t<form method=\"post\" action=\"/?page=contract/contract-void\" onsubmit=\"return confirm('Void this contract and linked invoices?')\" style=\"display:inline\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"csrf\" value=\"";
                // line 69
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["token"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"id\" value=\"";
                // line 70
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 70), "html", null, true);
                yield "\">
\t\t\t\t\t\t\t\t<button type=\"submit\" style=\"padding:6px 10px;border:0;border-radius:8px;background:#6b7280;color:#fff; font-size: small;\">Void</button>
\t\t\t\t\t\t\t</form>
\t\t\t\t\t\t";
            }
            // line 74
            yield "\t\t\t\t\t</td>

\t\t\t\t\t<td style=\"padding:10px\">
\t\t\t\t\t\t";
            // line 77
            if ((CoreExtension::getAttribute($this->env, $this->source, $context["row"], "status", [], "any", false, false, false, 77) == "pending")) {
                // line 78
                yield "\t\t\t\t\t\t\t<a href=\"/?page=contract/contracts-edit&id=";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "id", [], "any", false, false, false, 78), "html", null, true);
                yield "\" style=\"padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;\">Edit</a>
\t\t\t\t\t\t";
            } else {
                // line 80
                yield "\t\t\t\t\t\t\t<span style=\"color:#9ca3af;font-size:small\">—</span>
\t\t\t\t\t\t";
            }
            // line 82
            yield "\t\t\t\t\t</td>
\t\t\t\t</tr>


\t\t\t";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 87
        yield "\t\t</tbody>
\t</table>
</div>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "partials/document_list/rows/contract/regular-contract-rows.twig";
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
        return array (  236 => 87,  226 => 82,  222 => 80,  216 => 78,  214 => 77,  209 => 74,  202 => 70,  198 => 69,  195 => 68,  193 => 67,  190 => 66,  183 => 62,  179 => 61,  176 => 60,  174 => 59,  171 => 58,  164 => 54,  160 => 53,  157 => 52,  155 => 51,  152 => 50,  146 => 48,  144 => 47,  141 => 46,  133 => 43,  129 => 42,  125 => 41,  121 => 40,  112 => 34,  107 => 32,  104 => 31,  102 => 30,  97 => 28,  92 => 26,  88 => 25,  81 => 23,  76 => 21,  69 => 19,  63 => 17,  59 => 16,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "partials/document_list/rows/contract/regular-contract-rows.twig", "/var/www/src/views/partials/document_list/rows/contract/regular-contract-rows.twig");
    }
}
