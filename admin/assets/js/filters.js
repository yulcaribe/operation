const pageName = window.pageName || 'active-operations.php';

function loadFilters(targetId = 'filterArea', tableId = 'flightTable') {
  fetch(`components/filters.php?action=get_filters&page=${pageName}`)
    .then(res => res.json())
    .then(filters => {
      const area = document.getElementById(targetId);
      filters.forEach(f => {
        const select = document.createElement('select');
        select.name = f.column_key;
        select.classList.add('form-select', 'form-select-sm');
        select.dataset.label = f.filter_label;

        const label = document.createElement('label');
        label.textContent = f.filter_label;
        label.classList.add('form-label');

        const wrapper = document.createElement('div');
        wrapper.classList.add('d-flex', 'flex-column');
        wrapper.append(label, select);
        area.append(wrapper);

        fetch(`components/filters.php?action=get_filter_options&page=${pageName}&column=${f.column_key}`)
          .then(r => r.json())
          .then(opts => {
            select.innerHTML += '<option value=\"\">T«äm«ä</option>';
            opts.forEach(val => {
              select.innerHTML += `<option value=\"${val}\">${val}</option>`;
            });
          });

        select.addEventListener('change', () => applyFilters(targetId, tableId));
      });
    });
}

function applyFilters(filterAreaId = 'filterArea', tableId = 'flightTable') {
  const filters = {};
  document.querySelectorAll(`#${filterAreaId} select`).forEach(sel => {
    if (sel.value) filters[sel.name] = sel.value;
  });

  fetch(`components/filters.php?action=apply_filters&page=${pageName}&filters=${encodeURIComponent(JSON.stringify(filters))}`)
    .then(res => res.json())
    .then(rows => {
      const tbody = document.querySelector(`#${tableId} tbody`);
      tbody.innerHTML = '';
      rows.forEach(row => {
        const tr = document.createElement('tr');
        for (const key in row) {
          const td = document.createElement('td');
          td.textContent = row[key] ?? '-';
          tr.appendChild(td);
        }
        tbody.appendChild(tr);
      });
    });
}
