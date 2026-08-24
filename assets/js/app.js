(() => {
    'use strict';

    const menuButton = document.querySelector('[data-menu]');
    const sidebar = document.getElementById('sidebar');
    if (menuButton && sidebar) {
        menuButton.addEventListener('click', () => {
            const open = document.body.classList.toggle('menu-open');
            menuButton.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('click', (event) => {
            if (window.innerWidth > 900 || !document.body.classList.contains('menu-open')) return;
            if (sidebar.contains(event.target) || menuButton.contains(event.target)) return;
            document.body.classList.remove('menu-open');
            menuButton.setAttribute('aria-expanded', 'false');
        });
    }

    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Bu işleme devam edilsin mi?')) event.preventDefault();
        });
    });

    document.querySelectorAll('.permission-table > div').forEach((row) => {
        const boxes = row.querySelectorAll('input[type="checkbox"]');
        if (boxes.length !== 2) return;
        boxes.forEach((box, index) => {
            box.addEventListener('change', () => {
                if (box.checked) boxes[index === 0 ? 1 : 0].checked = false;
            });
        });
    });

    const reviewSelectAll = document.querySelector('[data-review-select-all]');
    const reviewSelections = Array.from(document.querySelectorAll('[data-review-select]'));
    const reviewSelectionCount = document.querySelector('[data-review-selection-count]');
    const reviewBulkDelete = document.querySelector('[data-review-bulk-delete]');
    if (reviewSelectAll && reviewSelections.length) {
        const syncReviewSelection = () => {
            const selected = reviewSelections.filter((box) => box.checked).length;
            reviewSelectAll.checked = selected === reviewSelections.length;
            reviewSelectAll.indeterminate = selected > 0 && selected < reviewSelections.length;
            if (reviewSelectionCount) reviewSelectionCount.textContent = `${selected} uçuş seçili`;
            if (reviewBulkDelete) reviewBulkDelete.disabled = selected === 0;
        };
        reviewSelectAll.addEventListener('change', () => {
            reviewSelections.forEach((box) => { box.checked = reviewSelectAll.checked; });
            syncReviewSelection();
        });
        reviewSelections.forEach((box) => box.addEventListener('change', syncReviewSelection));
        syncReviewSelection();
    }
})();
