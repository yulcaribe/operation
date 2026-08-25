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

    const taskTabs = Array.from(document.querySelectorAll('[data-task-tab]'));
    const taskPanels = Array.from(document.querySelectorAll('[data-task-panel]'));
    if (taskTabs.length && taskPanels.length) {
        const activateTaskTab = (name) => {
            taskTabs.forEach((tab) => {
                const active = tab.dataset.taskTab === name;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', String(active));
            });
            taskPanels.forEach((panel) => { panel.hidden = panel.dataset.taskPanel !== name; });
        };
        taskTabs.forEach((tab) => tab.addEventListener('click', () => activateTaskTab(tab.dataset.taskTab)));
        const requestedTaskTab = new URLSearchParams(window.location.search).get('tab');
        activateTaskTab(requestedTaskTab === 'completed' ? 'completed' : 'assigned');
    }

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(form.dataset.confirm || 'Bu işleme devam edilsin mi?')) event.preventDefault();
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-process-form]');
        if (!form || event.defaultPrevented) return;
        event.preventDefault();

        const formData = new FormData(form);
        const processId = String(formData.get('process_type_id') || '');
        const currentCard = form.closest('[data-process-id]');
        const buttons = Array.from(form.querySelectorAll('button'));
        buttons.forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });

        const showFeedback = (message, isError = false) => {
            const grid = document.querySelector('.process-grid');
            if (!grid) return;
            let feedback = document.querySelector('[data-process-feedback]');
            if (!feedback) {
                feedback = document.createElement('section');
                feedback.dataset.processFeedback = '';
                grid.before(feedback);
            }
            feedback.className = isError ? 'panel danger-zone text-danger' : 'panel notice';
            feedback.textContent = message;
        };

        try {
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const html = await response.text();
            const page = new DOMParser().parseFromString(html, 'text/html');
            const flash = page.querySelector('.flash');
            const isError = !response.ok || Boolean(flash && flash.classList.contains('error'));

            if (isError) {
                showFeedback(flash ? flash.textContent.trim() : 'Süreç güncellenemedi.', true);
                return;
            }

            const nextCard = Array.from(page.querySelectorAll('[data-process-id]'))
                .find((card) => card.dataset.processId === processId);
            if (!currentCard || !nextCard) throw new Error('Güncel süreç kartı alınamadı.');
            currentCard.replaceWith(nextCard);
            showFeedback(flash ? flash.textContent.trim() : 'Süreç güncellendi.');
        } catch (error) {
            showFeedback('Süreç güncellenemedi; bağlantıyı kontrol edip tekrar deneyin.', true);
        } finally {
            buttons.forEach((button) => {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            });
        }
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
