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
            
        <?php elseif ($type === 'project_autocomplete'): ?>
            <!-- Project Autocomplete -->
            <label style="position:relative">
                <div><?php echo htmlspecialchars($label); ?></div>
                <input 
                    type="text" 
                    name="q" 
                    id="<?php echo $instanceId; ?>_project_input" 
                    value="<?php echo htmlspecialchars($value); ?>" 
                    placeholder="<?php echo htmlspecialchars($placeholder ?: 'Type project name...'); ?>" 
                    style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
                <div id="<?php echo $instanceId; ?>_project_suggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>
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
$hasProjectAutocomplete = false;
foreach ($filters as $name => $config) {
    if (($config['type'] ?? '') === 'client_autocomplete') {
        $hasClientAutocomplete = true;
    }
    if (($config['type'] ?? '') === 'project_autocomplete') {
        $hasProjectAutocomplete = true;
    }
}

if ($hasClientAutocomplete || $hasProjectAutocomplete):
?>
<script>
(function() {
    <?php if ($hasClientAutocomplete): ?>
    // Client autocomplete
    var clientInput = document.getElementById('<?php echo $instanceId; ?>_client_input');
    var clientHid = document.getElementById('<?php echo $instanceId; ?>_client_id');
    var clientSug = document.getElementById('<?php echo $instanceId; ?>_client_suggest');
    
    if (clientInput && clientHid && clientSug) {
        clientInput.addEventListener('input', function() {
            clientHid.value = '';
            var term = this.value.trim();
            
            if (!term) {
                clientSug.style.display = 'none';
                clientSug.innerHTML = '';
                return;
            }
            
            fetch('/?page=clients-search&term=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(list => {
                    if (!Array.isArray(list) || list.length === 0) {
                        clientSug.style.display = 'none';
                        clientSug.innerHTML = '';
                        return;
                    }
                    
                    clientSug.innerHTML = list.map(x => 
                        '<div data-id="' + x.id + '" data-name="' + x.name + '" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6">' + 
                        x.name + 
                        '</div>'
                    ).join('');
                    
                    Array.from(clientSug.children).forEach(el => {
                        el.addEventListener('click', function() {
                            clientInput.value = this.dataset.name;
                            clientHid.value = this.dataset.id;
                            clientSug.style.display = 'none';
                        });
                        
                        el.addEventListener('mouseenter', function() {
                            this.style.background = '#f3f4f6';
                        });
                        
                        el.addEventListener('mouseleave', function() {
                            this.style.background = '#fff';
                        });
                    });
                    
                    clientSug.style.display = 'block';
                })
                .catch(() => {
                    clientSug.style.display = 'none';
                });
        });
    }
    <?php endif; ?>
    
    <?php if ($hasProjectAutocomplete): ?>
    // Project autocomplete
    var projectInput = document.getElementById('<?php echo $instanceId; ?>_project_input');
    var projectSug = document.getElementById('<?php echo $instanceId; ?>_project_suggest');
    
    if (projectInput && projectSug) {
        projectInput.addEventListener('input', function() {
            var term = this.value.trim();
            
            if (!term) {
                projectSug.style.display = 'none';
                projectSug.innerHTML = '';
                return;
            }
            
            fetch('/?page=projects-search-autocomplete&term=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(list => {
                    if (!Array.isArray(list) || list.length === 0) {
                        projectSug.style.display = 'none';
                        projectSug.innerHTML = '';
                        return;
                    }
                    
                    projectSug.innerHTML = list.map(x => 
                        '<div data-name="' + x.name + '" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6">' + 
                        x.name + 
                        (x.client_name ? ' · ' + x.client_name : '') +
                        (x.organization_name ? ' · ' + x.organization_name : '') +
                        '</div>'
                    ).join('');
                    
                    Array.from(projectSug.children).forEach(el => {
                        el.addEventListener('click', function() {
                            projectInput.value = this.dataset.name;
                            projectSug.style.display = 'none';
                        });
                        
                        el.addEventListener('mouseenter', function() {
                            this.style.background = '#f3f4f6';
                        });
                        
                        el.addEventListener('mouseleave', function() {
                            this.style.background = '#fff';
                        });
                    });
                    
                    projectSug.style.display = 'block';
                })
                .catch(() => {
                    projectSug.style.display = 'none';
                });
        });
    }
    <?php endif; ?>
    
    // Close suggestions when clicking outside
    document.addEventListener('click', function(e) {
        <?php if ($hasClientAutocomplete): ?>
        if (clientSug && !clientSug.contains(e.target) && e.target !== clientInput) {
            clientSug.style.display = 'none';
        }
        <?php endif; ?>
        <?php if ($hasProjectAutocomplete): ?>
        if (projectSug && !projectSug.contains(e.target) && e.target !== projectInput) {
            projectSug.style.display = 'none';
        }
        <?php endif; ?>
    });
})();
</script>
<?php endif; ?>
