<?php
/**
 * Reusable Document List Filter Component
 * 
 * Usage:
 * <?php
 * $filterConfig = [
 *     'page' => 'quote/quotes-list',  // Required: form action page
 *     'filters' => [
 *         'client' => ['type' => 'client_autocomplete', 'label' => 'Client', 'value' => $client_name, 'id_value' => $client_id],
 *         'status' => ['type' => 'select', 'label' => 'Status', 'value' => $status, 'options' => ['all' => 'All', 'approved' => 'Approved']],
 *         'start' => ['type' => 'date', 'label' => 'Start Date', 'value' => $start],
 *         'end' => ['type' => 'date', 'label' => 'End Date', 'value' => $end],
 *         'project_code' => ['type' => 'text', 'label' => 'Project ID', 'value' => $project_code, 'placeholder' => 'PA-2025'],
 *         'doc_number' => ['type' => 'number', 'label' => 'Doc #', 'value' => $doc_number],
 *         'min' => ['type' => 'number', 'label' => 'Min Total ($)', 'value' => $min, 'step' => '0.01'],
 *     ],
 *     'columns' => 7  // Optional: number of columns (default: auto)
 * ];
 * require __DIR__ . '/../components/document_list_filter.php';
 * ?>
 */

if (!isset($filterConfig) || !is_array($filterConfig)) {
    throw new Exception('$filterConfig must be defined before including document_list_filter.php');
}

$page = $filterConfig['page'] ?? '';
$filters = $filterConfig['filters'] ?? [];
$columns = $filterConfig['columns'] ?? null;

if (empty($page)) {
    throw new Exception('Filter config must include "page" parameter');
}

// Generate unique ID for this filter instance
$instanceId = 'filter_' . md5($page . microtime());

// Calculate grid columns: each filter + submit + reset buttons
$gridCols = $columns ?? (count($filters) + 2);
?>

<form method="get" action="/" style="display:grid;grid-template-columns:repeat(<?php echo $gridCols; ?>, 1fr);gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="<?php echo htmlspecialchars($page); ?>">
    
    <?php foreach ($filters as $name => $config): ?>
        <?php
        $type = $config['type'] ?? 'text';
        $label = $config['label'] ?? ucfirst($name);
        $value = $config['value'] ?? '';
        $placeholder = $config['placeholder'] ?? '';
        $step = $config['step'] ?? '';
        ?>
        
        <?php if ($type === 'client_autocomplete'): ?>
            <!-- Client Autocomplete -->
            <?php $idValue = $config['id_value'] ?? 0; ?>
            <input type="hidden" name="client_id" id="<?php echo $instanceId; ?>_client_id" value="<?php echo (int)$idValue; ?>">
            <label style="position:relative">
                <div><?php echo htmlspecialchars($label); ?></div>
                <input 
                    type="text" 
                    name="client" 
                    id="<?php echo $instanceId; ?>_client_input" 
                    value="<?php echo htmlspecialchars($value); ?>" 
                    placeholder="<?php echo htmlspecialchars($placeholder ?: 'Type client name...'); ?>" 
                    style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
                <div id="<?php echo $instanceId; ?>_client_suggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>
            </label>
            
        <?php elseif ($type === 'select'): ?>
            <!-- Select Dropdown -->
            <?php $options = $config['options'] ?? []; ?>
            <label>
                <div><?php echo htmlspecialchars($label); ?></div>
                <select name="<?php echo htmlspecialchars($name); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
                    <?php foreach ($options as $optValue => $optLabel): ?>
                        <option value="<?php echo htmlspecialchars($optValue); ?>" <?php echo $value === $optValue ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($optLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            
        <?php elseif ($type === 'date'): ?>
            <!-- Date Input -->
            <label>
                <div><?php echo htmlspecialchars($label); ?></div>
                <input 
                    type="date" 
                    name="<?php echo htmlspecialchars($name); ?>" 
                    value="<?php echo htmlspecialchars($value); ?>" 
                    style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
            </label>
            
        <?php elseif ($type === 'number'): ?>
            <!-- Number Input -->
            <label>
                <div><?php echo htmlspecialchars($label); ?></div>
                <input 
                    type="number" 
                    name="<?php echo htmlspecialchars($name); ?>" 
                    value="<?php echo htmlspecialchars($value); ?>" 
                    placeholder="<?php echo htmlspecialchars($placeholder); ?>"
                    <?php echo $step ? 'step="' . htmlspecialchars($step) . '"' : ''; ?>
                    style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
            </label>
            
        <?php else: ?>
            <!-- Text Input (default) -->
            <label>
                <div><?php echo htmlspecialchars($label); ?></div>
                <input 
                    type="text" 
                    name="<?php echo htmlspecialchars($name); ?>" 
                    value="<?php echo htmlspecialchars($value); ?>" 
                    placeholder="<?php echo htmlspecialchars($placeholder); ?>"
                    style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
            </label>
        <?php endif; ?>
        
    <?php endforeach; ?>
    
    <!-- Submit Button -->
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer;white-space:nowrap">
        Filter
    </button>
    
    <!-- Reset Button -->
    <a href="/?page=<?php echo htmlspecialchars($page); ?>" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block;font-size:small;text-align:center;text-decoration:none;color:inherit;white-space:nowrap">
        Reset
    </a>
</form>

<?php
// Generate JavaScript for client autocomplete if needed
$hasClientAutocomplete = false;
foreach ($filters as $name => $config) {
    if (($config['type'] ?? '') === 'client_autocomplete') {
        $hasClientAutocomplete = true;
        break;
    }
}

if ($hasClientAutocomplete):
?>
<script>
(function() {
    var input = document.getElementById('<?php echo $instanceId; ?>_client_input');
    var hid = document.getElementById('<?php echo $instanceId; ?>_client_id');
    var sug = document.getElementById('<?php echo $instanceId; ?>_client_suggest');
    
    if (!input || !hid || !sug) return;
    
    input.addEventListener('input', function() {
        hid.value = '';
        var term = this.value.trim();
        
        if (!term) {
            sug.style.display = 'none';
            sug.innerHTML = '';
            return;
        }
        
        fetch('/?page=clients-search&term=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) {
                    sug.style.display = 'none';
                    sug.innerHTML = '';
                    return;
                }
                
                sug.innerHTML = list.map(x => 
                    '<div data-id="' + x.id + '" data-name="' + x.name + '" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6">' + 
                    x.name + 
                    '</div>'
                ).join('');
                
                Array.from(sug.children).forEach(el => {
                    el.addEventListener('click', function() {
                        input.value = this.dataset.name;
                        hid.value = this.dataset.id;
                        sug.style.display = 'none';
                    });
                    
                    el.addEventListener('mouseenter', function() {
                        this.style.background = '#f3f4f6';
                    });
                    
                    el.addEventListener('mouseleave', function() {
                        this.style.background = '#fff';
                    });
                });
                
                sug.style.display = 'block';
            })
            .catch(() => {
                sug.style.display = 'none';
            });
    });
    
    // Close suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!sug.contains(e.target) && e.target !== input) {
            sug.style.display = 'none';
        }
    });
})();
</script>
<?php endif; ?>
