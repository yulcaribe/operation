let mapInstance;
let airplaneMarker;
let mapUpdateInterval;
let fakeMoveInterval;

function getMapThemeTileURL() {
  const theme = document.documentElement.getAttribute('data-bs-theme');
  return theme === 'dark'
    ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
    : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
}

function initLeafletMap(lat, lon, heading = 0) {
  const container = document.getElementById('world-map-markers');
  if (container._leaflet_id) {
    delete container._leaflet_id;
  }

  if (mapInstance) {
    mapInstance.remove();
    mapInstance = null;
  }

  container.style.height = "400px";

  mapInstance = L.map('world-map-markers').setView([lat, lon], 9);

  L.tileLayer(getMapThemeTileURL(), {
    attribution: '© OpenStreetMap contributors © Carto',
    subdomains: 'abcd',
    maxZoom: 19
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

function animateMarkerTo(marker, newLatLng, duration = 15000) {
  const startLatLng = marker.getLatLng();
  const startTime = performance.now();

  function animate(time) {
    const progress = Math.min((time - startTime) / duration, 1);
    const lat = startLatLng.lat + (newLatLng.lat - startLatLng.lat) * progress;
    const lng = startLatLng.lng + (newLatLng.lng - startLatLng.lng) * progress;
    marker.setLatLng([lat, lng]);

    if (progress < 1) {
      requestAnimationFrame(animate);
    }
  }

  requestAnimationFrame(animate);
}

function updateLeafletPosition(lat, lon, heading = 0) {
  if (!airplaneMarker) return;
  const iconHtml = '<i class="ti ti-plane" style="font-size: 24px; display: inline-block; transform: rotate(' + (heading - 90) + 'deg);"></i>';
  animateMarkerTo(airplaneMarker, L.latLng(lat, lon), 15000);
  airplaneMarker.setIcon(L.divIcon({
    className: 'leaflet-airplane-icon',
    html: iconHtml,
    iconSize: [24, 24],
    iconAnchor: [12, 12]
  }));
  mapInstance.panTo([lat, lon]);
}

function startFlightTracking() {
  function fetchAndUpdate() {
    fetch('get_altitude.php')
      .then(response => response.json())
      .then(data => {
        if (data.latitude && data.longitude) {
          updateLeafletPosition(data.latitude, data.longitude, data.heading || 0);
        }
      });
  }

  fetchAndUpdate();
  mapUpdateInterval = setInterval(fetchAndUpdate, 15000);
}

function startFakeMotion(lat, lon, heading = 0) {
  let step = 0;
  fakeMoveInterval = setInterval(() => {
    const offset = 0.0003 * Math.sin(step);
    updateLeafletPosition(lat + offset, lon + offset, heading);
    step += 1;
  }, 1000);
}

document.getElementById('mapModal').addEventListener('shown.bs.modal', function () {
  const modalEl = document.getElementById('mapModal');
  modalEl.removeAttribute('inert');
  modalEl.setAttribute('aria-hidden', 'false');
  modalEl.setAttribute('tabindex', '-1');

  setTimeout(() => {
    const container = document.getElementById('world-map-markers');
    if (!container) return;

    fetch('get_altitude.php')
      .then(response => response.json())
      .then(data => {
        const lat = data.latitude || 0;
        const lon = data.longitude || 0;
        const heading = data.heading || 0;

        initLeafletMap(lat, lon, heading);
        startFakeMotion(lat, lon, heading);

        setTimeout(() => {
          clearInterval(fakeMoveInterval);
          startFlightTracking();
        }, 15000);
      });

    setTimeout(() => {
      if (mapInstance) mapInstance.invalidateSize();
    }, 300);
  }, 200);
});

document.getElementById('mapModal').addEventListener('hidden.bs.modal', function () {
  clearInterval(mapUpdateInterval);
  clearInterval(fakeMoveInterval);
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
