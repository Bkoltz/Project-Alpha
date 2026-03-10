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

/* templates/components/document-filter.html.twig */
class __TwigTemplate_8357fdf09ffc136c0d197a87a0fdceda extends Template
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
        // line 17
        yield "
";
        // line 18
        $context["gridCols"] = ((array_key_exists("columns", $context)) ? (Twig\Extension\CoreExtension::default(($context["columns"] ?? null), (Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["filters"] ?? null)) + 2))) : ((Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["filters"] ?? null)) + 2)));
        // line 19
        $context["instanceId"] = ("filter_" . Twig\Extension\CoreExtension::slice($this->env->getCharset(), Twig\Extension\CoreExtension::replace(($context["page"] ?? null), ["/" => "_"]), 0, 8));
        // line 20
        yield "
<form method=\"get\" action=\"/\" style=\"display:grid;grid-template-columns:repeat(";
        // line 21
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["gridCols"] ?? null), "html", null, true);
        yield ", 1fr);gap:8px;align-items:end;margin:12px 0;position:relative\">
    <input type=\"hidden\" name=\"page\" value=\"";
        // line 22
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["page"] ?? null), "html", null, true);
        yield "\">
    
    ";
        // line 24
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["filters"] ?? null));
        foreach ($context['_seq'] as $context["filterName"] => $context["config"]) {
            // line 25
            yield "        ";
            $context["type"] = ((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "type", [], "any", true, true, false, 25)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "type", [], "any", false, false, false, 25), "text")) : ("text"));
            // line 26
            yield "        ";
            $context["label"] = ((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "label", [], "any", true, true, false, 26)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "label", [], "any", false, false, false, 26), Twig\Extension\CoreExtension::capitalize($this->env->getCharset(), $context["filterName"]))) : (Twig\Extension\CoreExtension::capitalize($this->env->getCharset(), $context["filterName"])));
            // line 27
            yield "        ";
            $context["value"] = ((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "value", [], "any", true, true, false, 27)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "value", [], "any", false, false, false, 27), "")) : (""));
            // line 28
            yield "        ";
            $context["idValue"] = ((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "id_value", [], "any", true, true, false, 28)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "id_value", [], "any", false, false, false, 28), 0)) : (0));
            // line 29
            yield "        
        <div style=\"display:flex;flex-direction:column;gap:4px\">
            <label for=\"";
            // line 31
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
            yield "_";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
            yield "\" style=\"font-size:13px;font-weight:600;color:#374151\">
                ";
            // line 32
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["label"] ?? null), "html", null, true);
            yield "
            </label>
            
            ";
            // line 35
            if ((($context["type"] ?? null) == "text")) {
                // line 36
                yield "                <input 
                    type=\"text\" 
                    id=\"";
                // line 38
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\"
                    name=\"";
                // line 39
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\" 
                    value=\"";
                // line 40
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["value"] ?? null), "html", null, true);
                yield "\"
                    placeholder=\"";
                // line 41
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "placeholder", [], "any", true, true, false, 41)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "placeholder", [], "any", false, false, false, 41), "")) : ("")), "html", null, true);
                yield "\"
                    style=\"padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;background:#fff\"
                >
            
            ";
            } elseif ((            // line 45
($context["type"] ?? null) == "date")) {
                // line 46
                yield "                <input 
                    type=\"date\" 
                    id=\"";
                // line 48
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\"
                    name=\"";
                // line 49
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\" 
                    value=\"";
                // line 50
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["value"] ?? null), "html", null, true);
                yield "\"
                    style=\"padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;background:#fff\"
                >
            
            ";
            } elseif ((            // line 54
($context["type"] ?? null) == "number")) {
                // line 55
                yield "                <input 
                    type=\"number\" 
                    id=\"";
                // line 57
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\"
                    name=\"";
                // line 58
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\" 
                    value=\"";
                // line 59
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["value"] ?? null), "html", null, true);
                yield "\"
                    placeholder=\"";
                // line 60
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "placeholder", [], "any", true, true, false, 60)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "placeholder", [], "any", false, false, false, 60), "")) : ("")), "html", null, true);
                yield "\"
                    step=\"";
                // line 61
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "step", [], "any", true, true, false, 61)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "step", [], "any", false, false, false, 61), "1")) : ("1")), "html", null, true);
                yield "\"
                    style=\"padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;background:#fff\"
                >
            
            ";
            } elseif ((            // line 65
($context["type"] ?? null) == "select")) {
                // line 66
                yield "                <select 
                    id=\"";
                // line 67
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\"
                    name=\"";
                // line 68
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\"
                    style=\"padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;background:#fff\"
                >
                    ";
                // line 71
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["config"], "options", [], "any", false, false, false, 71)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 72
                    yield "                        ";
                    $context['_parent'] = $context;
                    $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "options", [], "any", false, false, false, 72));
                    foreach ($context['_seq'] as $context["_key"] => $context["option"]) {
                        // line 73
                        yield "                            <option value=\"";
                        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(((CoreExtension::getAttribute($this->env, $this->source, $context["option"], "value", [], "any", true, true, false, 73)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["option"], "value", [], "any", false, false, false, 73), "")) : ("")), "html", null, true);
                        yield "\" ";
                        if ((((CoreExtension::getAttribute($this->env, $this->source, $context["option"], "value", [], "any", true, true, false, 73)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["option"], "value", [], "any", false, false, false, 73), "")) : ("")) == ($context["value"] ?? null))) {
                            yield "selected";
                        }
                        yield ">
                                ";
                        // line 74
                        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["option"], "label", [], "any", false, false, false, 74), "html", null, true);
                        yield "
                            </option>
                        ";
                    }
                    $_parent = $context['_parent'];
                    unset($context['_seq'], $context['_key'], $context['option'], $context['_parent']);
                    $context = array_intersect_key($context, $_parent) + $_parent;
                    // line 77
                    yield "                    ";
                }
                // line 78
                yield "                </select>
            
            ";
            } elseif ((            // line 80
($context["type"] ?? null) == "client_autocomplete")) {
                // line 81
                yield "                <input 
                    type=\"hidden\" 
                    id=\"";
                // line 83
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "_id\"
                    name=\"";
                // line 84
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "_id\"
                    value=\"";
                // line 85
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["idValue"] ?? null), "html", null, true);
                yield "\"
                >
                <input 
                    type=\"text\" 
                    id=\"";
                // line 89
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\"
                    name=\"";
                // line 90
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "\"
                    value=\"";
                // line 91
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["value"] ?? null), "html", null, true);
                yield "\"
                    placeholder=\"";
                // line 92
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(((CoreExtension::getAttribute($this->env, $this->source, $context["config"], "placeholder", [], "any", true, true, false, 92)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["config"], "placeholder", [], "any", false, false, false, 92), "Search client...")) : ("Search client...")), "html", null, true);
                yield "\"
                    style=\"padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;background:#fff;position:relative;z-index:1\"
                    data-autocomplete=\"client\"
                    data-hidden-id=\"";
                // line 95
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "_id\"
                >
                <div id=\"";
                // line 97
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["instanceId"] ?? null), "html", null, true);
                yield "_";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["filterName"], "html", null, true);
                yield "_suggestions\" style=\"position:absolute;display:none;background:#fff;border:1px solid #ddd;border-radius:6px;max-height:200px;overflow-y:auto;width:100%;z-index:10;margin-top:-4px\"></div>
            
            ";
            }
            // line 100
            yield "        </div>
    ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['filterName'], $context['config'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 102
        yield "    
    ";
        // line 104
        yield "    <div style=\"display:flex;flex-direction:column;gap:4px\">
        <label style=\"font-size:13px;font-weight:600;color:#374151;height:24px\"></label>
        <button 
            type=\"submit\" 
            style=\"padding:8px 14px;background:#2563eb;color:#fff;border:0;border-radius:6px;font-weight:600;cursor:pointer;font-size:14px\"
        >
            Filter
        </button>
    </div>
    
    <div style=\"display:flex;flex-direction:column;gap:4px\">
        <label style=\"font-size:13px;font-weight:600;color:#374151;height:24px\"></label>
        <a 
            href=\"/?page=";
        // line 117
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["page"] ?? null), "html", null, true);
        yield "\" 
            style=\"padding:8px 14px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:6px;font-weight:600;cursor:pointer;font-size:14px;text-decoration:none;text-align:center;display:inline-block\"
        >
            Reset
        </a>
    </div>
</form>

<script>
// Client autocomplete functionality
document.querySelectorAll('[data-autocomplete=\"client\"]').forEach(input => {
    const hiddenId = input.getAttribute('data-hidden-id');
    const suggestionsDiv = document.getElementById(input.id + '_suggestions');
    
    // Fetch clients on input
    input.addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length < 2) {
            suggestionsDiv.style.display = 'none';
            return;
        }
        
        fetch('/?page=clients-search&term=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) {
                    suggestionsDiv.style.display = 'none';
                    return;
                }
                suggestionsDiv.innerHTML = list.map(x => 
                    `<div data-id=\"\${x.id}\" data-name=\"\${x.name}\" style=\"padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6\">\${x.name}</div>`
                ).join('');
                Array.from(suggestionsDiv.children).forEach(el => {
                    el.addEventListener('click', function() {
                        input.value = this.dataset.name;
                        document.getElementById(hiddenId).value = this.dataset.id;
                        suggestionsDiv.style.display = 'none';
                    });
                });
                suggestionsDiv.style.display = 'block';
            })
            .catch(() => {
                suggestionsDiv.style.display = 'none';
            });
    });
    
    input.addEventListener('blur', function() {
        setTimeout(() => {
            suggestionsDiv.style.display = 'none';
        }, 200);
    });
});
</script>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "templates/components/document-filter.html.twig";
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
        return array (  306 => 117,  291 => 104,  288 => 102,  281 => 100,  273 => 97,  266 => 95,  260 => 92,  256 => 91,  252 => 90,  246 => 89,  239 => 85,  235 => 84,  229 => 83,  225 => 81,  223 => 80,  219 => 78,  216 => 77,  207 => 74,  198 => 73,  193 => 72,  191 => 71,  185 => 68,  179 => 67,  176 => 66,  174 => 65,  167 => 61,  163 => 60,  159 => 59,  155 => 58,  149 => 57,  145 => 55,  143 => 54,  136 => 50,  132 => 49,  126 => 48,  122 => 46,  120 => 45,  113 => 41,  109 => 40,  105 => 39,  99 => 38,  95 => 36,  93 => 35,  87 => 32,  81 => 31,  77 => 29,  74 => 28,  71 => 27,  68 => 26,  65 => 25,  61 => 24,  56 => 22,  52 => 21,  49 => 20,  47 => 19,  45 => 18,  42 => 17,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "templates/components/document-filter.html.twig", "/var/www/src/views/templates/components/document-filter.html.twig");
    }
}
