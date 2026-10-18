// UMPCSL front-end helpers.
(function () {
    'use strict';

    // Confirm before destructive submissions.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    // Auto-dismiss alerts after a few seconds.
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            if (window.bootstrap && window.bootstrap.Alert) {
                window.bootstrap.Alert.getOrCreateInstance(alert).close();
            }
        }, 6000);
    });

    // Live calculation of loan total repayable on the application form.
    var amountInput = document.getElementById('loan-amount');
    var rateInput = document.getElementById('loan-rate');
    var termInput = document.getElementById('loan-term');
    var preview = document.getElementById('loan-preview');

    function recalc() {
        if (!amountInput || !preview) return;
        var amount = parseFloat(amountInput.value) || 0;
        var rate = parseFloat(rateInput && rateInput.value) || 0;
        var term = parseInt(termInput && termInput.value, 10) || 1;
        var total = amount + (amount * rate / 100);
        var monthly = term > 0 ? total / term : total;
        preview.textContent =
            'Total repayable: ' + total.toLocaleString(undefined, { minimumFractionDigits: 2 }) +
            '  |  Monthly: ' + monthly.toLocaleString(undefined, { minimumFractionDigits: 2 });
    }

    [amountInput, rateInput, termInput].forEach(function (el) {
        if (el) el.addEventListener('input', recalc);
    });
    recalc();

    // Repeatable guarantor rows on the loan application page.
    var addGuarantor = document.getElementById('add-guarantor');
    var guarantorBox = document.getElementById('guarantor-rows');
    if (addGuarantor && guarantorBox) {
        addGuarantor.addEventListener('click', function () {
            var index = guarantorBox.querySelectorAll('.guarantor-row').length;
            var row = document.createElement('div');
            row.className = 'row g-2 mb-2 guarantor-row';
            row.innerHTML =
                '<div class="col-md-4"><input class="form-control" name="guarantors[' + index + '][name]" placeholder="Full name" required></div>' +
                '<div class="col-md-3"><input class="form-control" name="guarantors[' + index + '][phone]" placeholder="Phone"></div>' +
                '<div class="col-md-3"><input class="form-control" name="guarantors[' + index + '][relationship]" placeholder="Relationship"></div>' +
                '<div class="col-md-2"><input class="form-control" name="guarantors[' + index + '][amount]" placeholder="Amount"></div>';
            guarantorBox.appendChild(row);
        });
    }
})();
