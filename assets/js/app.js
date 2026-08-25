(() => {
    'use strict';

    const menuButton = document.querySelector('[data-menu]');
    const sidebar = document.getElementById('sidebar');
    if (menuButton && sidebar) {
        menuButton.setAttribute('aria-expanded', String(window.innerWidth > 900));
        menuButton.addEventListener('click', () => {
            const desktop = window.innerWidth > 900;
            const open = desktop
                ? !document.body.classList.toggle('sidebar-collapsed')
                : document.body.classList.toggle('menu-open');
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
        const focusButton = document.querySelector('[data-timeline-focus]');
        const flightStatusFilter = document.querySelector('[data-timeline-flight-status]');
        const processStatusFilter = document.querySelector('[data-timeline-process-status]');
        const filterCount = document.querySelector('[data-timeline-filter-count]');
        const drawerLayer = document.querySelector('[data-timeline-drawer-layer]');
        const drawer = document.querySelector('[data-timeline-drawer]');
        const drawerTitle = document.querySelector('[data-timeline-drawer-title]');
        const drawerMeta = document.querySelector('[data-timeline-drawer-meta]');
        const drawerFeedback = document.querySelector('[data-timeline-drawer-feedback]');
        const drawerProcesses = document.querySelector('[data-timeline-drawer-processes]');
        const flightForm = document.querySelector('[data-timeline-flight-form]');
        const assignForm = document.querySelector('[data-timeline-assign-form]');
        const statusForm = document.querySelector('[data-timeline-status-form]');
        const zoomFactors = [1, 1.35, 1.8, 2.4];
        const zoomLabels = ['Sığdır', '100%', '150%', '200%'];
        const zoomBarHeights = [34, 44, 56, 70];
        const zoomLaneGaps = [3, 4, 5, 6];
        const zoomProcessSlots = [17, 19, 21, 23];
        const stateLabels = { not_started: 'Başlamadı', started: 'Devam ediyor', finished: 'Tamamlandı', not_used: 'Kullanılmadı' };
        const stateMarks = { not_started: '○', started: '▶', finished: '✓', not_used: '╱' };
        const flightStatusLabels = { scheduled: 'Planlanan', active: 'Devam ediyor', completed: 'Tamamlanan', cancelled: 'İptal' };
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
        let zoomIndex = 0;
        let currentData = null;
        let selectedFlightId = 0;
        let drawerDirty = false;
        let loading = false;
        let initialLoad = true;
        let resizeTimer = 0;
        let renderedMinuteWidth = 0;

        const makeElement = (tag, className = '', text = '') => {
            const element = document.createElement(tag);
            if (className) element.className = className;
            if (text !== '') element.textContent = text;
            return element;
        };
        const labelWidth = () => (window.innerWidth <= 680 ? 142 : 190);
        const availableTimelineWidth = () => Math.max(180, scrollArea.clientWidth - labelWidth());
        const minuteWidth = () => {
            let width = Math.max(.55, availableTimelineWidth() / 1440) * zoomFactors[zoomIndex];
            if (zoomIndex !== zoomFactors.length - 1 || !currentData) return width;
            const slotWidth = zoomProcessSlots[zoomIndex];
            allFlights(currentData).filter(flightMatchesFilters).forEach((flight) => {
                const duration = Number(flight.duration_minutes);
                const processCount = (flight.processes || []).length;
                if (!Number.isFinite(duration) || duration <= 0 || processCount === 0) return;
                width = Math.max(width, ((processCount * slotWidth) + 18) / duration);
            });
            return width;
        };
        const compactDateTime = (value) => {
            if (!value) return '-';
            const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
            return match ? `${match[3]}.${match[2]} ${match[4]}:${match[5]}` : String(value);
        };
        const dateTimeLocal = (value) => {
            const match = String(value || '').match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
            return match ? `${match[1]}T${match[2]}` : '';
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
            button.setAttribute('aria-label', button.dataset.tooltip.split('\n').join(', '));
            button.append(makeElement('span', 'timeline-process-mark', stateMarks[process.state] || '○'));
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                document.querySelectorAll('.timeline-process.is-tooltip-open').forEach((item) => { if (item !== button) item.classList.remove('is-tooltip-open'); });
                button.classList.toggle('is-tooltip-open');
            });
            return button;
        };
        const flightTitle = (flight) => `${flight.icao_code} · ${flight.arrival_flight_number || '-'} / ${flight.departure_flight_number || '-'}`;
        const flightMeta = (flight) => {
            const values = [];
            if (flight.stand) values.push(`Park ${flight.stand}`);
            if (flight.tail_number) values.push(flight.tail_number);
            if (flight.aircraft_type) values.push(flight.aircraft_type);
            return values.join(' · ');
        };
        const allFlights = (data) => (data.rows || []).flatMap((row) => row.flights).concat(data.missing || []);
        const findFlight = (flightId) => currentData ? allFlights(currentData).find((flight) => Number(flight.id) === Number(flightId)) : null;
        const flightMatchesFilters = (flight) => {
            const selectedFlightStatus = flightStatusFilter ? flightStatusFilter.value : '';
            const selectedProcessStatus = processStatusFilter ? processStatusFilter.value : '';
            if (selectedFlightStatus && flight.status !== selectedFlightStatus) return false;
            if (selectedProcessStatus && !(flight.processes || []).some((process) => process.state === selectedProcessStatus)) return false;
            return true;
        };
        const filteredTimelineData = (data) => {
            const sourceRows = data.rows || [];
            const sourceMissing = data.missing || [];
            const rows = sourceRows
                .map((row) => ({ ...row, flights: (row.flights || []).filter(flightMatchesFilters) }))
                .filter((row) => row.flights.length > 0);
            const missing = sourceMissing.filter(flightMatchesFilters);
            const visibleCount = rows.reduce((total, row) => total + row.flights.length, 0) + missing.length;
            const totalCount = sourceRows.reduce((total, row) => total + (row.flights || []).length, 0) + sourceMissing.length;
            if (filterCount) filterCount.textContent = `${visibleCount} / ${totalCount} uçuş`;
            return { ...data, rows, missing };
        };

        const setFormValue = (form, name, value) => {
            const field = form ? form.elements.namedItem(name) : null;
            if (field) field.value = value === null || value === undefined ? '' : String(value);
        };
        const showDrawerFeedback = (message, isError = false) => {
            if (!drawerFeedback) return;
            drawerFeedback.hidden = false;
            drawerFeedback.className = `timeline-drawer-feedback ${isError ? 'error' : 'success'}`;
            drawerFeedback.textContent = message;
        };
        const renderDrawerProcesses = (processes) => {
            drawerProcesses.replaceChildren();
            processes.forEach((process) => {
                const item = makeElement('article', `timeline-drawer-process state-${process.state}`);
                item.append(processIcon(process));
                const copy = makeElement('div');
                copy.append(makeElement('strong', '', process.name));
                copy.append(makeElement('span', '', stateLabels[process.state] || process.state));
                const times = [process.started_at ? `Başlangıç ${compactDateTime(process.started_at)}` : '', process.finished_at ? `Bitiş ${compactDateTime(process.finished_at)}` : ''].filter(Boolean);
                if (times.length) copy.append(makeElement('small', '', times.join(' · ')));
                item.append(copy);
                drawerProcesses.append(item);
            });
            if (!processes.length) drawerProcesses.append(makeElement('div', 'empty', 'Bu uçuş tipi için süreç bulunmuyor.'));
        };
        const openDrawer = (flight, preserveForm = false) => {
            if (!drawerLayer || !drawer || !flight) return;
            if (selectedFlightId !== Number(flight.id)) drawerDirty = false;
            selectedFlightId = Number(flight.id);
            drawerTitle.textContent = flightTitle(flight);
            drawerMeta.textContent = `${flightStatusLabels[flight.status] || flight.status} · ${flight.assignee_name || 'Atanmamış'} · ${flight.start_label || 'Zaman eksik'}`;
            renderDrawerProcesses(flight.processes || []);
            drawerFeedback.hidden = true;

            const values = {
                flight_id: flight.id, airline_id: flight.airline_id, status: flight.status, flight_type_id: flight.flight_type_id,
                arrival_flight_number: flight.arrival_flight_number, departure_flight_number: flight.departure_flight_number,
                arrival_origin: flight.arrival_origin, arrival_destination: flight.arrival_destination,
                departure_origin: flight.departure_origin, departure_destination: flight.departure_destination,
                scheduled_arrival_at: dateTimeLocal(flight.scheduled_arrival_at), estimated_arrival_at: dateTimeLocal(flight.estimated_arrival_at),
                scheduled_departure_at: dateTimeLocal(flight.scheduled_departure_at), estimated_departure_at: dateTimeLocal(flight.estimated_departure_at),
                tail_number: flight.tail_number, aircraft_type: flight.aircraft_type, stand: flight.stand, note: flight.note,
            };
            if (!preserveForm || !drawerDirty) Object.entries(values).forEach(([name, value]) => setFormValue(flightForm, name, value));
            flightForm.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field) => { field.disabled = !flight.can_edit; });
            const saveButton = flightForm.querySelector('[data-timeline-flight-save]');
            const readonlyMessage = flightForm.querySelector('[data-timeline-drawer-readonly]');
            saveButton.hidden = !flight.can_edit;
            readonlyMessage.hidden = Boolean(flight.can_edit);

            if (assignForm) {
                setFormValue(assignForm, 'flight_id', flight.id);
                setFormValue(assignForm, 'user_id', flight.assignee_user_id || 0);
                const select = assignForm.elements.namedItem('user_id');
                const button = assignForm.querySelector('button');
                const note = assignForm.querySelector('[data-timeline-assign-note]');
                select.disabled = !flight.can_assign;
                button.hidden = !flight.can_assign;
                note.textContent = flight.can_assign ? 'Bu uçuş için aktif sorumluyu değiştirebilirsiniz.' : 'Atama yalnızca yetkili olduğunuz planlanan uçuşlarda değiştirilebilir.';
            }
            if (statusForm) {
                const targets = flight.status === 'completed'
                    ? [['scheduled', 'Planlanan'], ['active', 'Devam ediyor'], ['cancelled', 'İptal']]
                    : (flight.status === 'active' ? [['scheduled', 'Planlanan']] : []);
                setFormValue(statusForm, 'flight_id', flight.id);
                const select = statusForm.elements.namedItem('target_status');
                select.replaceChildren(...targets.map(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    return option;
                }));
                statusForm.hidden = !flight.can_change_status || targets.length === 0;
            }
            drawerLayer.hidden = false;
            drawer.setAttribute('aria-hidden', 'false');
            document.body.classList.add('timeline-drawer-open');
            requestAnimationFrame(() => drawerLayer.classList.add('is-open'));
        };
        const closeDrawer = () => {
            if (!drawerLayer || !drawer) return;
            selectedFlightId = 0;
            drawerDirty = false;
            drawerLayer.classList.remove('is-open');
            drawer.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('timeline-drawer-open');
            window.setTimeout(() => { if (!drawerLayer.classList.contains('is-open')) drawerLayer.hidden = true; }, 180);
        };

        const renderMissing = (flights) => {
            missingList.replaceChildren();
            missingSection.hidden = flights.length === 0;
            flights.forEach((flight) => {
                const button = makeElement('button', 'timeline-missing-card');
                button.type = 'button';
                button.append(makeElement('strong', '', flightTitle(flight)));
                button.append(makeElement('span', '', `${flightMeta(flight)} · ${flight.assignee_name || 'Atanmamış'}`));
                button.append(makeElement('small', '', flight.missing_reason || 'Zaman bilgisi eksik.'));
                button.addEventListener('click', () => openDrawer(flight));
                missingList.append(button);
            });
        };
        const layoutLanes = (flights) => {
            const laneEnds = [];
            return flights.map((flight) => {
                const start = Number(flight.start_minute);
                const end = start + Number(flight.duration_minutes);
                let lane = laneEnds.findIndex((laneEnd) => start >= laneEnd);
                if (lane < 0) lane = laneEnds.length;
                laneEnds[lane] = end;
                return { flight, lane };
            });
        };
        const appendCompactProcesses = (list, flight, barWidth, slotWidth) => {
            const priority = { started: 0, not_started: 1, finished: 2, not_used: 3 };
            const processes = flight.processes || [];
            if (zoomIndex === zoomFactors.length - 1) {
                processes.forEach((process) => list.append(processIcon(process)));
                return;
            }
            const ordered = processes.map((process, index) => ({ process, index }))
                .sort((left, right) => (priority[left.process.state] ?? 4) - (priority[right.process.state] ?? 4) || left.index - right.index)
                .map((item) => item.process);
            const slots = Math.max(1, Math.floor(Math.max(slotWidth, barWidth - 6) / slotWidth));
            const visibleCount = ordered.length > slots && slots > 1 ? slots - 1 : Math.min(ordered.length, slots);
            ordered.slice(0, visibleCount).forEach((process) => list.append(processIcon(process)));
            const hiddenCount = ordered.length - visibleCount;
            if (hiddenCount > 0 && slots > 1) {
                const more = makeElement('button', 'timeline-process-more', `+${hiddenCount}`);
                more.type = 'button';
                more.dataset.tooltip = ordered.slice(visibleCount).map((process) => `${process.name}: ${stateLabels[process.state] || process.state}`).join('\n');
                more.setAttribute('aria-label', more.dataset.tooltip.split('\n').join(', '));
                more.addEventListener('click', (event) => {
                    event.stopPropagation();
                    document.querySelectorAll('.timeline-process-more.is-tooltip-open').forEach((item) => { if (item !== more) item.classList.remove('is-tooltip-open'); });
                    more.classList.toggle('is-tooltip-open');
                });
                list.append(more);
            }
        };

        const renderTimeline = (data, scrollTarget = null) => {
            const viewData = filteredTimelineData(data);
            const previousScroll = scrollArea.scrollLeft;
            const currentMinuteWidth = minuteWidth();
            const previousMinuteWidth = renderedMinuteWidth || currentMinuteWidth;
            const axisWidth = labelWidth();
            const timeWidth = 1440 * currentMinuteWidth;
            const canvasWidth = axisWidth + timeWidth;
            const barHeight = zoomBarHeights[zoomIndex];
            const laneGap = zoomLaneGaps[zoomIndex];
            const rowPadding = 8;
            canvas.replaceChildren();
            canvas.classList.remove('timeline-density-0', 'timeline-density-1', 'timeline-density-2', 'timeline-density-3');
            canvas.classList.add(`timeline-density-${zoomIndex}`);
            canvas.style.width = `${canvasWidth}px`;
            canvas.style.setProperty('--timeline-axis-width', `${axisWidth}px`);
            canvas.style.setProperty('--quarter-width', `${15 * currentMinuteWidth}px`);
            canvas.style.setProperty('--hour-width', `${60 * currentMinuteWidth}px`);

            const header = makeElement('div', 'timeline-board-header');
            header.append(makeElement('div', 'timeline-axis-title', 'Operasyon Memuru'));
            const hours = makeElement('div', 'timeline-hours');
            hours.style.left = `${axisWidth}px`;
            hours.style.width = `${timeWidth}px`;
            for (let hour = 0; hour < 24; hour += 1) {
                const label = makeElement('span', 'timeline-hour-label', `${String(hour).padStart(2, '0')}:00`);
                label.style.left = `${hour * 60 * currentMinuteWidth}px`;
                hours.append(label);
            }
            header.append(hours);
            canvas.append(header);

            (viewData.rows || []).forEach((staffRow) => {
                const laidOut = layoutLanes(staffRow.flights || []);
                const laneCount = Math.max(1, ...laidOut.map((item) => item.lane + 1));
                const rowHeight = rowPadding + (laneCount * barHeight) + ((laneCount - 1) * laneGap);
                const row = makeElement('section', 'timeline-staff-row');
                row.style.height = `${rowHeight}px`;
                const staff = makeElement('div', 'timeline-staff-label');
                staff.style.height = `${Math.min(rowHeight, barHeight + rowPadding)}px`;
                staff.append(makeElement('strong', '', staffRow.assignee_name));
                staff.append(makeElement('small', '', `${staffRow.flights.length} uçuş${laneCount > 1 ? ` · ${laneCount} şerit` : ''}`));
                row.append(staff);
                const track = makeElement('div', 'timeline-staff-track');
                track.style.left = `${axisWidth}px`;
                track.style.width = `${timeWidth}px`;

                laidOut.forEach(({ flight, lane }) => {
                    const width = Math.max(1, Number(flight.duration_minutes) * currentMinuteWidth);
                    const bar = makeElement('div', `timeline-flight-bar status-${flight.status}`);
                    bar.style.left = `${Number(flight.start_minute) * currentMinuteWidth}px`;
                    bar.style.top = `${4 + (lane * (barHeight + laneGap))}px`;
                    bar.style.width = `${width}px`;
                    bar.style.height = `${barHeight}px`;
                    bar.tabIndex = 0;
                    bar.setAttribute('role', 'button');
                    bar.setAttribute('aria-label', `${flightTitle(flight)}, ${flight.start_label} - ${flight.end_label}, ${flightMeta(flight)}`);
                    if (width < 110) bar.classList.add('timeline-flight-narrow');
                    if (flight.continues_before) bar.classList.add('continues-before');
                    if (flight.continues_after) bar.classList.add('continues-after');
                    bar.addEventListener('click', () => openDrawer(flight));
                    bar.addEventListener('keydown', (event) => {
                        if (event.target !== bar || (event.key !== 'Enter' && event.key !== ' ')) return;
                        event.preventDefault();
                        openDrawer(flight);
                    });
                    const heading = makeElement('div', 'timeline-flight-heading');
                    heading.append(makeElement('strong', '', flightTitle(flight)));
                    heading.append(makeElement('span', '', `${flight.start_label}–${flight.end_label}`));
                    bar.append(heading);
                    const meta = makeElement('div', 'timeline-flight-meta', flightMeta(flight));
                    meta.title = flightMeta(flight);
                    bar.append(meta);
                    const processList = makeElement('div', 'timeline-process-list');
                    appendCompactProcesses(processList, flight, width, zoomProcessSlots[zoomIndex]);
                    bar.append(processList);
                    track.append(bar);
                });
                row.append(track);
                canvas.append(row);
            });

            if (!(viewData.rows || []).length) {
                const hasActiveFilter = Boolean((flightStatusFilter && flightStatusFilter.value) || (processStatusFilter && processStatusFilter.value));
                canvas.append(makeElement('div', 'timeline-board-empty', hasActiveFilter ? 'Seçilen filtrelere uygun zaman bilgili uçuş bulunmuyor.' : 'Seçili günde yetki kapsamınıza giren uçuş bulunmuyor.'));
            }
            if (viewData.now_minute !== null && Number(viewData.now_minute) >= 0 && Number(viewData.now_minute) <= 1440) {
                const nowLine = makeElement('div', 'timeline-now-line');
                nowLine.style.left = `${axisWidth + (Number(viewData.now_minute) * currentMinuteWidth)}px`;
                nowLine.innerHTML = '<span>Şimdi</span>';
                canvas.append(nowLine);
            }
            renderMissing(viewData.missing || []);
            feedback.className = 'panel timeline-feedback';
            feedback.hidden = true;
            updatedLabel.textContent = `Son güncelleme ${compactDateTime(data.generated_at)}`;
            zoomLabel.textContent = zoomLabels[zoomIndex];
            zoomOut.disabled = zoomIndex === 0;
            zoomIn.disabled = zoomIndex === zoomFactors.length - 1;
            nowButton.disabled = data.now_minute === null;
            renderedMinuteWidth = currentMinuteWidth;
            if (selectedFlightId) {
                const selected = findFlight(selectedFlightId);
                if (selected) openDrawer(selected, true); else closeDrawer();
            }
            requestAnimationFrame(() => {
                if (scrollTarget !== null) {
                    scrollArea.scrollLeft = Math.max(0, (scrollTarget * currentMinuteWidth) - (availableTimelineWidth() / 2));
                    return;
                }
                const centerMinute = (previousScroll + (availableTimelineWidth() / 2)) / previousMinuteWidth;
                scrollArea.scrollLeft = Math.max(0, (centerMinute * currentMinuteWidth) - (availableTimelineWidth() / 2));
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
                const firstRow = (data.rows || [])[0];
                const firstFlight = firstRow && firstRow.flights ? firstRow.flights[0] : null;
                const initialTarget = initialLoad ? (data.now_minute !== null ? Number(data.now_minute) : Number(firstFlight ? firstFlight.start_minute : 0)) : null;
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
        const submitDrawerForm = async (event) => {
            const form = event.target.closest('[data-timeline-flight-form], [data-timeline-assign-form], [data-timeline-status-form]');
            if (!form || event.defaultPrevented) return;
            event.preventDefault();
            const buttons = Array.from(form.querySelectorAll('button'));
            buttons.forEach((button) => { button.disabled = true; });
            try {
                const endpoint = form.getAttribute('action');
                if (!endpoint) throw new Error('Timeline işlem adresi bulunamadı.');
                const response = await fetch(endpoint, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    const redirectedToLogin = response.redirected && new URL(response.url, window.location.origin).pathname.endsWith('/login');
                    throw new Error(redirectedToLogin ? 'Oturumunuz sona ermiş. Yeniden giriş yapın.' : `Timeline işlem servisi JSON yanıtı vermedi (HTTP ${response.status}).`);
                }
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'İşlem tamamlanamadı.');
                drawerDirty = false;
                await loadTimeline(true);
                showDrawerFeedback(result.message || 'Değişiklik kaydedildi.');
            } catch (error) {
                showDrawerFeedback(error instanceof Error ? error.message : 'İşlem tamamlanamadı.', true);
            } finally {
                buttons.forEach((button) => { button.disabled = false; });
            }
        };
        const changeZoom = (direction) => {
            if (!currentData) return;
            const oldWidth = minuteWidth();
            const centerMinute = (scrollArea.scrollLeft + (availableTimelineWidth() / 2)) / oldWidth;
            zoomIndex = Math.max(0, Math.min(zoomFactors.length - 1, zoomIndex + direction));
            renderTimeline(currentData, centerMinute);
        };
        const setFocusMode = (enabled) => {
            document.body.classList.toggle('timeline-focus-mode', enabled);
            focusButton.setAttribute('aria-pressed', String(enabled));
            focusButton.textContent = enabled ? '✕ Tam Ekrandan Çık' : '⛶ Tam Ekran';
            try { window.localStorage.setItem('operation.timelineFocus', enabled ? '1' : '0'); } catch (error) { /* storage kapalı olabilir */ }
        };

        document.querySelectorAll('[data-timeline-drawer-close]').forEach((button) => button.addEventListener('click', closeDrawer));
        if (flightForm) flightForm.addEventListener('input', () => { drawerDirty = true; });
        if (flightForm) flightForm.addEventListener('submit', submitDrawerForm);
        if (assignForm) assignForm.addEventListener('submit', submitDrawerForm);
        if (statusForm) statusForm.addEventListener('submit', submitDrawerForm);
        const applyTimelineFilters = () => { if (currentData) renderTimeline(currentData); };
        if (flightStatusFilter) flightStatusFilter.addEventListener('change', applyTimelineFilters);
        if (processStatusFilter) processStatusFilter.addEventListener('change', applyTimelineFilters);
        zoomOut.addEventListener('click', () => changeZoom(-1));
        zoomIn.addEventListener('click', () => changeZoom(1));
        refreshButton.addEventListener('click', () => loadTimeline(true));
        focusButton.addEventListener('click', () => {
            const currentMinuteWidth = minuteWidth();
            const centerMinute = currentData ? (scrollArea.scrollLeft + (availableTimelineWidth() / 2)) / currentMinuteWidth : null;
            setFocusMode(!document.body.classList.contains('timeline-focus-mode'));
            if (currentData) requestAnimationFrame(() => renderTimeline(currentData, centerMinute));
        });
        nowButton.addEventListener('click', () => {
            if (!currentData || currentData.now_minute === null) return;
            const currentMinuteWidth = minuteWidth();
            scrollArea.scrollTo({ left: Math.max(0, (Number(currentData.now_minute) * currentMinuteWidth) - (availableTimelineWidth() / 2)), behavior: 'smooth' });
        });
        document.addEventListener('click', (event) => {
            if (event.target.closest('.timeline-process, .timeline-process-more')) return;
            document.querySelectorAll('.timeline-process.is-tooltip-open, .timeline-process-more.is-tooltip-open').forEach((item) => item.classList.remove('is-tooltip-open'));
        });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && selectedFlightId) closeDrawer(); });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) loadTimeline(true); });
        window.addEventListener('resize', () => {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(() => { if (currentData) renderTimeline(currentData); }, 120);
        });
        window.setInterval(() => { if (!document.hidden) loadTimeline(); }, 15000);
        let savedFocus = false;
        try { savedFocus = window.localStorage.getItem('operation.timelineFocus') === '1'; } catch (error) { /* storage kapalı olabilir */ }
        setFocusMode(savedFocus);
        loadTimeline();
    }
})();
