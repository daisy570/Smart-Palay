// Sidebar toggle
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('spSidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        document.addEventListener('click', (e) => {
            if (window.innerWidth < 992 &&
                sidebar.classList.contains('show') &&
                !sidebar.contains(e.target) &&
                !toggle.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });
    }
});

// Live total computation for transaction forms
function bindTotalCalculation(weightId, priceId, totalId, paymentId, balanceId) {
    const w = document.getElementById(weightId);
    const p = document.getElementById(priceId);
    const t = document.getElementById(totalId);
    const pay = document.getElementById(paymentId);
    const bal = document.getElementById(balanceId);

    function compute() {
        const weight = parseFloat(w?.value) || 0;
        const price = parseFloat(p?.value) || 0;
        const total = weight * price;
        if (t) t.value = total.toFixed(2);
        const payment = parseFloat(pay?.value) || 0;
        const balance = total - payment;
        if (bal) bal.value = balance.toFixed(2);
    }
    [w, p, pay].forEach(el => el && el.addEventListener('input', compute));
}

// Confirm delete
function confirmAction(msg = 'Are you sure?') {
    return confirm(msg);
}

// Table search
function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;
    input.addEventListener('keyup', () => {
        const term = input.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
    });
}

// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.alert-dismissible').forEach(el => {
        const a = bootstrap.Alert.getOrCreateInstance(el);
        a.close();
    });
}, 4000);

// Print report
function printReport() { window.print(); }