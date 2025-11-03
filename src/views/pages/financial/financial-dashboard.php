<?php
// src/views/pages/financial/financial-dashboard.php
require_once __DIR__ . '/../../../config/db.php';

// Get all clients for filter dropdown
$clientsStmt = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC");
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Default date range: current year (Jan 1 - Dec 31)
$defaultStartDate = date('Y') . '-01-01';
$defaultEndDate = date('Y') . '-12-31';
?>
<section style="padding: 24px;">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h2 style="margin: 0;">Financial Dashboard</h2>
  </div>

  <!-- Filters -->
  <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
      <label>
        <div style="margin-bottom: 6px; font-weight: 500; color: #333;">Start Date</div>
        <input type="date" id="startDate" value="<?php echo htmlspecialchars($defaultStartDate); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
      </label>
      <label>
        <div style="margin-bottom: 6px; font-weight: 500; color: #333;">End Date</div>
        <input type="date" id="endDate" value="<?php echo htmlspecialchars($defaultEndDate); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
      </label>
      <label>
        <div style="margin-bottom: 6px; font-weight: 500; color: #333;">Client</div>
        <select id="clientFilter" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
          <option value="">All Clients</option>
          <?php foreach ($clients as $client): ?>
            <option value="<?php echo (int)$client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <div style="margin-bottom: 6px; font-weight: 500; color: #333;">Group By</div>
        <select id="groupBy" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
          <option value="month">Month</option>
          <option value="week">Week</option>
          <option value="day">Day</option>
        </select>
      </label>
      <button id="applyFilters" style="padding: 10px 20px; background: var(--nav-accent); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
        Apply Filters
      </button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
      <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">Total Income</div>
      <div id="totalIncome" style="font-size: 32px; font-weight: 700;">$0.00</div>
    </div>
    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
      <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">Invoices Paid</div>
      <div id="invoiceCount" style="font-size: 32px; font-weight: 700;">0</div>
    </div>
    <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
      <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">Average Payment</div>
      <div id="avgPayment" style="font-size: 32px; font-weight: 700;">$0.00</div>
    </div>
  </div>

  <!-- Chart -->
  <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <h3 style="margin: 0 0 20px 0; color: #333;">Income Over Time</h3>
    <canvas id="incomeChart" style="max-height: 400px;"></canvas>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
  let chart = null;

  function formatCurrency(value) {
    return '$' + parseFloat(value).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
  }

  function loadData() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const clientId = document.getElementById('clientFilter').value;
    const groupBy = document.getElementById('groupBy').value;

    const params = new URLSearchParams({
      start_date: startDate,
      end_date: endDate,
      group_by: groupBy
    });

    if (clientId) {
      params.append('client_id', clientId);
    }

    fetch(`/?page=financial/financial-api&${params.toString()}`)
      .then(res => res.json())
      .then(response => {
        if (!response.success) {
          console.error('Failed to load data');
          return;
        }

        const data = response.data;
        
        // Update summary cards
        const totalIncome = data.reduce((sum, d) => sum + d.income, 0);
        const totalInvoices = data.reduce((sum, d) => sum + d.invoice_count, 0);
        const avgPayment = totalInvoices > 0 ? totalIncome / totalInvoices : 0;

        document.getElementById('totalIncome').textContent = formatCurrency(totalIncome);
        document.getElementById('invoiceCount').textContent = totalInvoices;
        document.getElementById('avgPayment').textContent = formatCurrency(avgPayment);

        // Update chart
        const labels = data.map(d => d.period);
        const values = data.map(d => d.income);

        if (chart) {
          chart.destroy();
        }

        const ctx = document.getElementById('incomeChart').getContext('2d');
        chart = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [{
              label: 'Income',
              data: values,
              backgroundColor: 'rgba(102, 126, 234, 0.6)',
              borderColor: 'rgba(102, 126, 234, 1)',
              borderWidth: 2,
              borderRadius: 8
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
              legend: {
                display: false
              },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    return 'Income: ' + formatCurrency(context.parsed.y);
                  }
                }
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  callback: function(value) {
                    return formatCurrency(value);
                  }
                }
              }
            }
          }
        });
      })
      .catch(err => {
        console.error('Error loading financial data:', err);
      });
  }

  // Load data on page load
  loadData();

  // Apply filters button
  document.getElementById('applyFilters').addEventListener('click', loadData);

  // Also allow Enter key in date inputs
  ['startDate', 'endDate'].forEach(id => {
    document.getElementById(id).addEventListener('keypress', function(e) {
      if (e.key === 'Enter') loadData();
    });
  });
})();
</script>
