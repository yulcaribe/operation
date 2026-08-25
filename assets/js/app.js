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

    const timelineRoot = document.querySelector('[data-timeline-root]');
    if (timelineRoot) {
        const canvas = timelineRoot.querySelector('[data-timeline-canvas]');
        const scrollArea = timelineRoot.querySelector('[data-timeline-scroll]');
        const feedback = timelineRoot.querySelector('[data-timeline-feedback]');
        const missingSection = timelineRoot.querySelector('[data-timeline-missing]');
        const missingList = timelineRoot.querySelector('[data-timeline-missing-list]');
        const updatedLabel = document.querySelector('[data-timeline-updated]');
        const zoomLabel = document.querySelector('[data-timeline-zoom-label]');
        const zoomOut = document.querySelector('[data-timeline-zoom-out]');
        const zoomIn = document.querySelector('[data-timeline-zoom-in]');
        const nowButton = document.querySelector('[data-timeline-now]');
        const refreshButton = document.querySelector('[data-timeline-refresh]');
        const zoomLevels = [1.5, 2, 3, 4];
        const zoomLabels = ['75%', '100%', '150%', '200%'];
        const stateLabels = { not_started: 'Başlamadı', started: 'Devam ediyor', finished: 'Tamamlandı', not_used: 'Kullanılmadı' };
        const stateMarks = { not_started: '○', started: '▶', finished: '✓', not_used: '╱' };
        const iconSvgs = {
            inblock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17h18M7 14l5-9 5 9M9 14h6M6 20h12"/></svg>',
            'door-open': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21h16M6 21V4l11-2v19M17 12h3M13 12h.01"/></svg>',
            deboarding: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="14" cy="5" r="2"/><path d="M14 7.5v5l3 2M14 10l-3 3-2 5M14 12l1 7M8 9H2m3-3L2 9l3 3"/></svg>',
            cleaning: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3l-5 12M8 14l7 3M6 14l10 4-2 3H5l-2-4z"/></svg>',
            catering: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 18h18M5 15h14a7 7 0 0 0-14 0zM12 5v3M9 5h6"/></svg>',
            fueling: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V4h10v17M4 9h10M7 6h4M14 8h3l3 3v7a2 2 0 0 1-4 0v-4h-2"/></svg>',
            boarding: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="5" r="2"/><path d="M10 7.5v5l-3 2M10 10l3 3 2 5M10 12l-1 7M16 9h6m-3-3 3 3-3 3"/></svg>',
            'door-closed': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21h14M7 21V3h10v18M13 12h.01"/><rect x="9" y="7" width="6" height="10" rx="1"/></svg>',
            offblock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 16h20M6 13l6-9 2 9M10 16l-3 5M15 16l3 5M17 7h5m-2-2 2 2-2 2"/></svg>',
            note: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18H5zM8 8h8M8 12h8M8 16h5"/></svg>',
        };
        let zoomIndex = 1;
        let currentData = null;
        let loading = false;
        let initialLoad = true;

        const makeElement = (tag, className = '', text = '') => {
            const element = document.createElement(tag);
            if (className) element.className = className;
            if (text !== '') element.textContent = text;
            return element;
        };

        const compactDateTime = (value) => {
            if (!value) return '-';
            const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
            return match ? `${match[3]}.${match[2]} ${match[4]}:${match[5]}` : String(value);
        };

        const processTooltip = (process) => {
            const lines = [process.name, stateLabels[process.state] || process.state];
            if (process.started_at) lines.push(`Başlangıç: ${compactDateTime(process.started_at)}`);
            if (process.finished_at) lines.push(`Bitiş: ${compactDateTime(process.finished_at)}`);
            if (process.value_datetime) lines.push(`Süreç saati: ${compactDateTime(process.value_datetime)}`);
            if (process.recorded_at) lines.push(`Kayıt: ${compactDateTime(process.recorded_at)}`);
            if (process.has_text) lines.push('Operasyon notu kaydedildi');
            return lines.join('\n');
        };

        const processIcon = (process) => {
            const button = makeElement('button', `timeline-process state-${process.state}`);
            button.type = 'button';
            button.innerHTML = iconSvgs[process.icon] || iconSvgs.note;
            button.dataset.tooltip = processTooltip(process);
            button.setAttribute('aria-label', button.dataset.tooltip.replaceAll('\n', ', '));
            const marker = makeElement('span', 'timeline-process-mark', stateMarks[process.state] || '○');
            button.append(marker);
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                document.querySelectorAll('.timeline-process.is-tooltip-open').forEach((item) => { if (item !== button) item.classList.remove('is-tooltip-open'); });
                button.classList.toggle('is-tooltip-open');
            });
            return button;
        };

        const flightTitle = (flight) => {
            const arrival = flight.arrival_flight_number || '-';
            const departure = flight.departure_flight_number || '-';
            return `${flight.icao_code} · ${arrival} / ${departure}`;
        };

        const flightMeta = (flight) => {
            const values = [];
            if (flight.stand) values.push(`Park ${flight.stand}`);
            if (flight.tail_number) values.push(flight.tail_number);
            if (flight.aircraft_type) values.push(flight.aircraft_type);
            values.push(flight.assignee_name || 'Atanmamış');
            return values.join(' · ');
        };

        const renderMissing = (flights) => {
            missingList.replaceChildren();
            missingSection.hidden = flights.length === 0;
            flights.forEach((flight) => {
                const link = makeElement('a', 'timeline-missing-card');
                link.href = `${timelineRoot.dataset.timelineFlightUrl}?id=${flight.id}`;
                link.append(makeElement('strong', '', flightTitle(flight)));
                link.append(makeElement('span', '', flightMeta(flight)));
                link.append(makeElement('small', '', flight.missing_reason || 'Zaman bilgisi eksik.'));
                missingList.append(link);
            });
        };

        const renderTimeline = (data, scrollTarget = null) => {
            const previousScroll = scrollArea.scrollLeft;
            const minuteWidth = zoomLevels[zoomIndex];
            const canvasWidth = 1440 * minuteWidth;
            canvas.replaceChildren();
            canvas.style.width = `${canvasWidth}px`;
            canvas.style.setProperty('--quarter-width', `${15 * minuteWidth}px`);
            canvas.style.setProperty('--hour-width', `${60 * minuteWidth}px`);

            const header = makeElement('div', 'timeline-hours');
            for (let hour = 0; hour < 24; hour += 1) {
                const label = makeElement('span', 'timeline-hour-label', `${String(hour).padStart(2, '0')}:00`);
                label.style.left = `${hour * 60 * minuteWidth}px`;
                header.append(label);
            }
            canvas.append(header);

            data.groups.forEach((group) => {
                const groupElement = makeElement('section', 'timeline-group');
                const groupHeader = makeElement('div', 'timeline-group-header');
                const groupTitle = makeElement('span', 'timeline-group-title');
                groupTitle.append(makeElement('strong', '', group.icao_code));
                groupTitle.append(makeElement('small', '', `${group.airline_name} · ${group.flights.length} uçuş`));
                groupHeader.append(groupTitle);
                groupElement.append(groupHeader);

                group.flights.forEach((flight) => {
                    const width = Math.max(1, Number(flight.duration_minutes) * minuteWidth);
                    const iconsPerLine = Math.max(1, Math.floor(Math.max(20, width - 16) / 31));
                    const iconLines = Math.max(1, Math.ceil(flight.processes.length / iconsPerLine));
                    const rowHeight = Math.max(88, 57 + (iconLines * 31));
                    const row = makeElement('div', 'timeline-row');
                    row.style.height = `${rowHeight}px`;
                    const bar = makeElement('div', `timeline-flight-bar status-${flight.status}`);
                    bar.style.left = `${Number(flight.start_minute) * minuteWidth}px`;
                    bar.style.width = `${width}px`;
                    bar.style.height = `${rowHeight - 14}px`;
                    bar.tabIndex = 0;
                    bar.setAttribute('role', 'link');
                    bar.setAttribute('aria-label', `${flightTitle(flight)}, ${flight.start_label} - ${flight.end_label}, ${flightMeta(flight)}`);
                    if (flight.continues_before) bar.classList.add('continues-before');
                    if (flight.continues_after) bar.classList.add('continues-after');
                    const openFlight = () => { window.location.href = `${timelineRoot.dataset.timelineFlightUrl}?id=${flight.id}`; };
                    bar.addEventListener('click', openFlight);
                    bar.addEventListener('keydown', (event) => {
                        if (event.target !== bar || (event.key !== 'Enter' && event.key !== ' ')) return;
                        event.preventDefault();
                        openFlight();
                    });

                    const heading = makeElement('div', 'timeline-flight-heading');
                    const title = makeElement('strong', '', flightTitle(flight));
                    title.title = `${flight.start_label}–${flight.end_label}`;
                    heading.append(title);
                    heading.append(makeElement('span', '', `${flight.start_label}–${flight.end_label}`));
                    bar.append(heading);
                    const meta = makeElement('div', 'timeline-flight-meta', flightMeta(flight));
                    meta.title = flightMeta(flight);
                    bar.append(meta);
                    const processList = makeElement('div', 'timeline-process-list');
                    flight.processes.forEach((process) => processList.append(processIcon(process)));
                    bar.append(processList);
                    row.append(bar);
                    groupElement.append(row);
                });
                canvas.append(groupElement);
            });

            if (!data.groups.length) {
                const empty = makeElement('div', 'timeline-board-empty', 'Seçili günde yetki kapsamınıza giren uçuş bulunmuyor.');
                canvas.append(empty);
            }
            if (data.now_minute !== null && Number(data.now_minute) >= 0 && Number(data.now_minute) <= 1440) {
                const nowLine = makeElement('div', 'timeline-now-line');
                nowLine.style.left = `${Number(data.now_minute) * minuteWidth}px`;
                nowLine.innerHTML = '<span>Şimdi</span>';
                canvas.append(nowLine);
            }
            renderMissing(data.missing || []);
            feedback.className = 'panel timeline-feedback';
            feedback.hidden = true;
            updatedLabel.textContent = `Son güncelleme ${compactDateTime(data.generated_at)}`;
            zoomLabel.textContent = zoomLabels[zoomIndex];
            zoomOut.disabled = zoomIndex === 0;
            zoomIn.disabled = zoomIndex === zoomLevels.length - 1;
            nowButton.disabled = data.now_minute === null;

            requestAnimationFrame(() => {
                if (scrollTarget !== null) {
                    scrollArea.scrollLeft = Math.max(0, (scrollTarget * minuteWidth) - (scrollArea.clientWidth / 2));
                } else {
                    scrollArea.scrollLeft = previousScroll;
                }
            });
        };

        const loadTimeline = async (manual = false) => {
            if (loading) return;
            loading = true;
            if (manual) updatedLabel.textContent = 'Yenileniyor…';
            try {
                const url = new URL(timelineRoot.dataset.timelineDataUrl, window.location.origin);
                url.searchParams.set('date', timelineRoot.dataset.timelineDate);
                const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || data.error) throw new Error(data.error || 'Zaman çizelgesi alınamadı.');
                currentData = data;
                const firstFlight = data.groups.flatMap((group) => group.flights)[0];
                const initialTarget = initialLoad ? (data.now_minute !== null ? Number(data.now_minute) : Number(firstFlight?.start_minute || 0)) : null;
                renderTimeline(data, initialTarget);
                initialLoad = false;
            } catch (error) {
                feedback.hidden = false;
                feedback.className = 'panel timeline-feedback timeline-feedback-error';
                feedback.textContent = error instanceof Error ? error.message : 'Zaman çizelgesi alınamadı.';
                updatedLabel.textContent = 'Güncelleme başarısız';
            } finally {
                loading = false;
            }
        };

        const changeZoom = (direction) => {
            if (!currentData) return;
            const oldWidth = zoomLevels[zoomIndex];
            const centerMinute = (scrollArea.scrollLeft + (scrollArea.clientWidth / 2)) / oldWidth;
            zoomIndex = Math.max(0, Math.min(zoomLevels.length - 1, zoomIndex + direction));
            renderTimeline(currentData, centerMinute);
        };

        zoomOut.addEventListener('click', () => changeZoom(-1));
        zoomIn.addEventListener('click', () => changeZoom(1));
        refreshButton.addEventListener('click', () => loadTimeline(true));
        nowButton.addEventListener('click', () => {
            if (!currentData || currentData.now_minute === null) return;
            const minuteWidth = zoomLevels[zoomIndex];
            scrollArea.scrollTo({ left: Math.max(0, (Number(currentData.now_minute) * minuteWidth) - (scrollArea.clientWidth / 2)), behavior: 'smooth' });
        });
        document.addEventListener('click', (event) => {
            if (event.target.closest('.timeline-process')) return;
            document.querySelectorAll('.timeline-process.is-tooltip-open').forEach((item) => item.classList.remove('is-tooltip-open'));
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) loadTimeline(true); });
        window.setInterval(() => { if (!document.hidden) loadTimeline(); }, 15000);
        loadTimeline();
    }
})();
