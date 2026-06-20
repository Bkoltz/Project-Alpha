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
                console.error('Failed to load data:', response.error);
                document.getElementById('noDataMessage').style.display = 'block';
                document.getElementById('incomeChart').style.display = 'none';
                return;
            }

            const data = response.data;

            // Handle empty data
            if (!data || data.length === 0) {
                document.getElementById('noDataMessage').style.display = 'block';
                document.getElementById('incomeChart').style.display = 'none';
                document.getElementById('totalIncome').textContent = '$0.00';
                document.getElementById('invoiceCount').textContent = '0';
                document.getElementById('avgPayment').textContent = '$0.00';
                return;
            }

            document.getElementById('noDataMessage').style.display = 'none';
            document.getElementById('incomeChart').style.display = 'block';

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
                                label: function (context) {
                                    return 'Income: ' + formatCurrency(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (value) {
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
            document.getElementById('noDataMessage').style.display = 'block';
            document.getElementById('noDataMessage').innerHTML = '<p style="color: #dc2626; margin: 0;">Error loading data. Please check the console.</p>';
        });
}

// Load data on page load
loadData();

// Apply filters button
document.getElementById('applyFilters').addEventListener('click', loadData);

// Also allow Enter key in date inputs
['startDate', 'endDate'].forEach(id => {
    document.getElementById(id).addEventListener('keypress', function (e) {
        if (e.key === 'Enter') loadData();
    });
});