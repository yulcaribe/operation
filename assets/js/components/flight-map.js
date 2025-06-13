// flight-map.js (Leaflet + CartoDB + ti-plane + heading +90 düzeltildi + mobil uyumlu)
let mapInstance;
let airplaneMarker;
let mapUpdateInterval;

function initLeafletMap(lat, lon, heading = 0) {
  const mapContainer = document.getElementById('world-map-markers');
  mapContainer.innerHTML = '';
  mapContainer.style.height = "400px";

  mapInstance = L.map('world-map-markers').setView([lat, lon], 6);

  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap & CartoDB contributors'
  }).addTo(mapInstance);

  const airplaneIcon = L.divIcon({
    className: 'leaflet-airplane-icon',
    html: '<i class="ti ti-plane" style="font-size: 24px; display: inline-block; transform: rotate(' + (heading - 90) + 'deg);"></i>',
    iconSize: [24, 24],
    iconAnchor: [12, 12]
  });

  airplaneMarker = L.marker([lat, lon], {
    icon: airplaneIcon
  }).addTo(mapInstance);

  setTimeout(() => {
    mapInstance.invalidateSize();
  }, 300);
}

function updateLeafletPosition(lat, lon, heading = 0) {
  if (!airplaneMarker) return;
  const iconHtml = '<i class="ti ti-plane" style="font-size: 24px; display: inline-block; transform: rotate(' + (heading - 90) + 'deg);"></i>';
  airplaneMarker.setLatLng([lat, lon]);
  airplaneMarker.setIcon(L.divIcon({
    className: 'leaflet-airplane-icon',
    html: iconHtml,
    iconSize: [24, 24],
    iconAnchor: [12, 12]
  }));
}

function startFlightTracking() {
  mapUpdateInterval = setInterval(() => {
    fetch('get_altitude.php')
      .then(response => response.json())
      .then(data => {
        if (data.latitude && data.longitude) {
          updateLeafletPosition(data.latitude, data.longitude, data.heading || 0);
        }
      });
  }, 15000);
}

document.getElementById('mapModal').addEventListener('shown.bs.modal', function () {
  const modalEl = document.getElementById('mapModal');
  modalEl.removeAttribute('inert');
  modalEl.setAttribute('aria-hidden', 'false');
  modalEl.setAttribute('tabindex', '-1');

  fetch('get_altitude.php')
    .then(response => response.json())
    .then(data => {
      const lat = data.latitude || 0;
      const lon = data.longitude || 0;
      const heading = data.heading || 0;

      initLeafletMap(lat, lon, heading);
      startFlightTracking();
    });

  setTimeout(() => {
    if (mapInstance) mapInstance.invalidateSize();
  }, 300);
});

document.getElementById('mapModal').addEventListener('hidden.bs.modal', function () {
  clearInterval(mapUpdateInterval);
  if (mapInstance) mapInstance.remove();
  mapInstance = null;
  airplaneMarker = null;

  const modalEl = document.getElementById('mapModal');
  modalEl.setAttribute('inert', 'true');
  modalEl.setAttribute('aria-hidden', 'true');
  modalEl.removeAttribute('tabindex');

  const active = document.activeElement;
  if (active && modalEl.contains(active)) {
    active.blur();
  }

  setTimeout(() => {
    const btn = document.querySelector('[data-bs-target="#mapModal"]');
    if (btn) btn.focus();
  }, 100);
});
