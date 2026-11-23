(() => {
  const mapNode = document.getElementById('calendar-map');
  const form = document.querySelector('[data-booking-form-link]');
  const status = document.getElementById('calendar-map-status');
  const error = form?.querySelector('[data-booking-selection-error]');
  if (!mapNode || !form || !status) return;

  const originName = form.elements.namedItem('origin');
  const originSource = form.elements.namedItem('origin_source');
  const originLat = form.elements.namedItem('origin_lat');
  const originLng = form.elements.namedItem('origin_lng');
  const destinationName = form.elements.namedItem('destination');
  const destinationSource = form.elements.namedItem('destination_source');
  const destinationLat = form.elements.namedItem('destination_lat');
  const destinationLng = form.elements.namedItem('destination_lng');
  const pointInputs = {origin: document.getElementById('calendar-origin-search'), destination: document.getElementById('calendar-destination-search')};
  const resultLists = {origin: document.getElementById('calendar-origin-results'), destination: document.getElementById('calendar-destination-results')};
  const locations = {origin: document.getElementById('calendar-origin-location'), destination: document.getElementById('calendar-destination-location')};
  const searchState = {origin: {timer: null, controller: null, results: [], revision: 0}, destination: {timer: null, controller: null, results: [], revision: 0}};
  if (![originName, originSource, originLat, originLng, destinationName, destinationSource, destinationLat, destinationLng, pointInputs.origin, pointInputs.destination, resultLists.origin, resultLists.destination, locations.origin, locations.destination].every(Boolean)) return;
  if (!window.L || typeof L.map !== 'function') { showMapError('โหลดเครื่องมือแผนที่ไม่สำเร็จ กรุณาตรวจอินเทอร์เน็ตแล้วลองใหม่'); return; }

  const startPoint = {lat: Number(originLat.value), lng: Number(originLng.value), label: String(originName.value)};
  let map, origin = null, destination = null, originMarker = null, destinationMarker = null;
  let lastGeocodeRequest = 0, suppressClickUntil = 0;
  let routeLayer = null, routeRevision = 0, routeController = null, routeTimer = null;
  const coordinates = point => `${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`;
  const icon = kind => L.divIcon({className: `travel-map-marker travel-map-marker-${kind}`, html: '<span class="travel-map-pin" aria-hidden="true"></span>', iconSize: [32, 40], iconAnchor: [16, 38]});
  const hidden = kind => kind === 'origin' ? {name: originName, source: originSource, lat: originLat, lng: originLng} : {name: destinationName, source: destinationSource, lat: destinationLat, lng: destinationLng};
  const hideResults = kind => { resultLists[kind].replaceChildren(); resultLists[kind].hidden = true; searchState[kind].results = []; };
  const cancelSearch = kind => { const state = searchState[kind]; state.revision += 1; state.controller?.abort(); window.clearTimeout(state.timer); hideResults(kind); };

  function clearRoadRoute() {
    routeRevision += 1; routeController?.abort(); window.clearTimeout(routeTimer); routeController = null;
    if (routeLayer && map) map.removeLayer(routeLayer);
    routeLayer = null;
  }

  function scheduleRoadRoute() {
    clearRoadRoute();
    if (!origin || !destination || !map) return;
    const revision = routeRevision, from = {...origin}, to = {...destination};
    routeTimer = window.setTimeout(async () => {
      if (revision !== routeRevision) return;
      const controller = new AbortController(); routeController = controller;
      const timeout = window.setTimeout(() => controller.abort(), 12000);
      const url = new URL('/bookings/calendar/route-geometry', window.location.origin);
      for (const [key, value] of Object.entries({origin_lat: from.lat, origin_lng: from.lng, destination_lat: to.lat, destination_lng: to.lng})) url.searchParams.set(key, String(value));
      status.textContent = 'กำลังแสดงเส้นทางตามถนน…';
      try {
        const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', headers: {Accept: 'application/json'}});
        if (!response.ok) throw Error('ROAD_ROUTE_UNAVAILABLE');
        const data = await response.json(), geometry = data?.geometry;
        if (!geometry || geometry.type !== 'LineString' || !Array.isArray(geometry.coordinates) || geometry.coordinates.length < 2 || geometry.coordinates.length > 5000 || geometry.coordinates.some(point => !Array.isArray(point) || point.length < 2 || !Number.isFinite(point[0]) || !Number.isFinite(point[1]) || Math.abs(point[0]) > 180 || Math.abs(point[1]) > 90)) throw Error('ROAD_ROUTE_INVALID');
        if (revision !== routeRevision || controller.signal.aborted) return;
        routeLayer = L.geoJSON(geometry, {style: {color: '#512d6d', weight: 5, opacity: 0.85}}).addTo(map);
        map.fitBounds(routeLayer.getBounds(), {padding: [24, 24], maxZoom: 15});
        status.textContent = 'แสดงเส้นทางตามถนนระหว่างต้นทางและปลายทางแล้ว';
      } catch (requestError) {
        if (requestError.name !== 'AbortError' && revision === routeRevision) status.textContent = 'ไม่สามารถแสดงเส้นทางได้ กรุณาเลือกตำแหน่งบนถนนแล้วลองอีกครั้ง';
      } finally {
        window.clearTimeout(timeout);
        if (routeController === controller) routeController = null;
      }
    }, 450);
  }

  function showMapError(message) {
    mapNode.replaceChildren(); const panel = document.createElement('div');
    panel.className = 'map-load-error'; panel.textContent = message; mapNode.append(panel); status.textContent = message;
  }

  function setPoint(kind, location, label, pan = false, source = 'map') {
    const lat = Number(location.lat), lng = Number(location.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return;
    cancelSearch(kind);
    const cleanLabel = typeof label === 'string' ? label.trim().slice(0, kind === 'origin' ? 160 : 500) : '';
    const placeName = source === 'map' ? '' : cleanLabel;
    const markerLabel = placeName || `ตำแหน่ง${kind === 'origin' ? 'ต้นทาง' : 'ปลายทาง'}`;
    const point = {lat, lng, label: placeName, markerLabel};
    if (kind === 'origin') origin = point; else destination = point;
    const fields = hidden(kind); fields.name.value = point.label; fields.source.value = source; fields.lat.value = lat.toFixed(6); fields.lng.value = lng.toFixed(6);
    const input = pointInputs[kind]; input.value = point.label;
    const previous = kind === 'origin' ? originMarker : destinationMarker;
    const markerOptions = {title: `${kind === 'origin' ? 'ต้นทาง' : 'ปลายทาง'}: ${point.markerLabel}`, alt: kind === 'origin' ? 'หมุดต้นทาง' : 'หมุดปลายทาง', icon: icon(kind)};
    if (previous) {
      previous.options.title = markerOptions.title; previous.options.alt = markerOptions.alt;
      previous.setLatLng([lat, lng]).setIcon(markerOptions.icon).bindTooltip(markerOptions.title);
      previous.getElement()?.setAttribute('title', markerOptions.title); previous.getElement()?.setAttribute('aria-label', markerOptions.alt);
    } else {
      const marker = L.marker([lat, lng], markerOptions).addTo(map).bindTooltip(markerOptions.title);
      if (kind === 'origin') originMarker = marker; else destinationMarker = marker;
    }
    input.classList.remove('border-red-500', 'ring-2', 'ring-red-200');
    locations[kind].textContent = `พิกัด ${coordinates(point)}`;
    status.textContent = `เลือก${kind === 'origin' ? 'ต้นทาง' : 'ปลายทาง'}แล้ว${placeName ? ` · ${placeName}` : ''}`;
    if (pan) map.setView([lat, lng], Math.max(map.getZoom(), 15));
    scheduleRoadRoute();
  }

  function clearPoint(kind) {
    if (kind === 'origin') { origin = null; if (originMarker) map.removeLayer(originMarker); originMarker = null; }
    else { destination = null; if (destinationMarker) map.removeLayer(destinationMarker); destinationMarker = null; }
    const fields = hidden(kind); fields.name.value = ''; fields.source.value = ''; fields.lat.value = ''; fields.lng.value = '';
    locations[kind].textContent = kind === 'origin' ? 'ยังไม่ได้เลือกต้นทาง' : 'ยังไม่ได้เลือกปลายทาง';
    scheduleRoadRoute();
  }

  function resultLabel(properties) {
    const street = [properties.housenumber, properties.street].filter(Boolean).join(' ');
    const parts = [properties.name, street, properties.district, properties.city, properties.state, properties.country]
      .filter(value => typeof value === 'string' && value.trim());
    return [...new Map(parts.map(value => [value.trim().toLocaleLowerCase(), value.trim()])).values()].join(', ').slice(0, 500);
  }

  function showResults(kind, features) {
    const state = searchState[kind], list = resultLists[kind]; state.results = features;
    list.replaceChildren();
    if (!features.length) {
      const empty = document.createElement('p'); empty.className = 'travel-search-message'; empty.textContent = 'ไม่พบสถานที่ ลองใช้คำค้นอื่น'; list.append(empty); list.hidden = false; return;
    }
    features.forEach((feature, index) => {
      const button = document.createElement('button'); button.type = 'button'; button.role = 'option'; button.setAttribute('aria-selected', 'false');
      button.className = 'travel-search-option'; button.textContent = resultLabel(feature.properties || {}) || 'สถานที่จาก OpenStreetMap';
      button.addEventListener('click', () => {
        const coords = feature.geometry?.coordinates;
        if (!Array.isArray(coords) || coords.length < 2) return;
        const label = resultLabel(feature.properties || {}); hideResults(kind);
        setPoint(kind, {lat: coords[1], lng: coords[0]}, label, true, 'search');
      });
      state.results[index] = feature; list.append(button);
    });
    list.hidden = false;
  }

  async function searchPlaces(kind, query, revision = searchState[kind].revision) {
    const state = searchState[kind];
    if (revision !== state.revision) return;
    state.controller?.abort(); const controller = new AbortController(); state.controller = controller;
    const elapsed = Date.now() - lastGeocodeRequest;
    if (elapsed < 1000) await new Promise(resolve => window.setTimeout(resolve, 1000 - elapsed));
    if (controller.signal.aborted || revision !== state.revision) return;
    lastGeocodeRequest = Date.now();
    const center = map?.getCenter() || {lat: startPoint.lat, lng: startPoint.lng};
    const url = new URL('https://photon.komoot.io/api/');
    url.searchParams.set('q', query); url.searchParams.set('lang', 'default'); url.searchParams.set('limit', '6');
    url.searchParams.set('lat', String(center.lat)); url.searchParams.set('lon', String(center.lng));
    try {
      const response = await fetch(url, {signal: controller.signal, headers: {Accept: 'application/geo+json'}});
      if (!response.ok) throw Error('GEOCODER_UNAVAILABLE');
      const data = await response.json();
      if (controller.signal.aborted || revision !== state.revision) return;
      const features = Array.isArray(data.features) ? data.features : [];
      if (!features.length) {
        const fallback = query.replace(/^สถาบันนวัตกรรมการเรียนรู้\s*/u, '').trim();
        if (fallback && fallback !== query) { await searchPlaces(kind, fallback, revision); return; }
      }
      showResults(kind, features);
    } catch (requestError) {
      if (requestError.name === 'AbortError' || revision !== state.revision) return;
      const list = resultLists[kind]; list.replaceChildren();
      const message = document.createElement('p'); message.className = 'travel-search-message';
      message.textContent = 'ค้นหาสถานที่ไม่สำเร็จ ลองอีกครั้งหรือเลือกจุดบนแผนที่'; list.append(message); list.hidden = false;
    }
  }

  for (const kind of ['origin', 'destination']) {
    const input = pointInputs[kind], state = searchState[kind];
    input.addEventListener('input', () => {
      cancelSearch(kind); clearPoint(kind);
      const query = input.value.trim(); if (query.length < 3) return;
      const wait = Math.max(450, 1000 - (Date.now() - lastGeocodeRequest));
      const revision = state.revision; state.timer = window.setTimeout(() => void searchPlaces(kind, query, revision), wait);
    });
    input.addEventListener('keydown', event => {
      if (event.key === 'Escape') hideResults(kind);
      if (event.key === 'Enter') { const first = resultLists[kind].querySelector('[role=option]'); if (first) { event.preventDefault(); first.click(); } }
    });
    input.addEventListener('focus', () => { if (state.results.length) resultLists[kind].hidden = false; });
  }

  try {
    map = L.map(mapNode, {scrollWheelZoom: true, tapHold: false}).setView([startPoint.lat, startPoint.lng], 14);
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      minZoom: 3, maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'
    }).addTo(map);
    setPoint('origin', {lat: startPoint.lat, lng: startPoint.lng}, startPoint.label, false, 'preset');
    let touchStart = null, holdTimer = null, held = false;
    const cancelHold = () => { window.clearTimeout(holdTimer); holdTimer = null; };
    mapNode.addEventListener('pointerdown', event => {
      if (event.pointerType !== 'touch' && event.pointerType !== 'pen') return;
      if (touchStart) { cancelHold(); touchStart = null; return; }
      held = false; touchStart = {id: event.pointerId, x: event.clientX, y: event.clientY};
      holdTimer = window.setTimeout(() => {
        held = true; suppressClickUntil = Number.POSITIVE_INFINITY;
        const rect = mapNode.getBoundingClientRect();
        setPoint('destination', map.containerPointToLatLng(L.point(event.clientX - rect.left, event.clientY - rect.top)));
      }, 650);
    }, {passive: true});
    mapNode.addEventListener('pointermove', event => {
      if (touchStart && Math.hypot(event.clientX - touchStart.x, event.clientY - touchStart.y) > 10) cancelHold();
    }, {passive: true});
    for (const type of ['pointerup', 'pointercancel', 'pointerleave']) mapNode.addEventListener(type, () => {
      cancelHold(); touchStart = null; if (held) { suppressClickUntil = Date.now() + 1000; held = false; }
    });
    mapNode.addEventListener('click', event => {
      if (Date.now() < suppressClickUntil) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    mapNode.addEventListener('contextmenu', event => event.preventDefault());
    map.on('click', event => { if (Date.now() >= suppressClickUntil) setPoint('origin', event.latlng); });
    map.on('contextmenu', event => { L.DomEvent.preventDefault(event.originalEvent); if (Date.now() >= suppressClickUntil) setPoint('destination', event.latlng); });
    let loadedTiles = 0, failedTiles = 0;
    const initialLoadTimer = window.setTimeout(() => { if (loadedTiles === 0) showMapError('โหลดแผนที่ไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ได้'); }, 12000);
    tiles.on('tileload', () => { loadedTiles += 1; });
    tiles.once('load', () => {
      window.clearTimeout(initialLoadTimer);
      if (loadedTiles > 0) status.textContent = failedTiles > 0 ? 'แผนที่โหลดได้บางส่วน · แตะหรือคลิกเพื่อเลือกจุด' : 'แผนที่พร้อม · เลือกต้นทางหรือปลายทางจากแผนที่ได้';
      else showMapError('โหลดแผนที่ไม่สำเร็จ อาจเกิดจากเครือข่ายหรือบริการแผนที่ไม่พร้อม');
    });
    tiles.on('tileerror', () => { failedTiles += 1; if (loadedTiles > 0) status.textContent = 'แผนที่โหลดได้บางส่วน · อาจมีบางพื้นที่แสดงไม่ครบ'; });
    const invalidateMapSize = () => { if (map) map.invalidateSize({pan: false}); };
    if ('ResizeObserver' in window) { const observer = new ResizeObserver(() => window.requestAnimationFrame(invalidateMapSize)); observer.observe(mapNode); }
    else window.addEventListener('resize', () => window.requestAnimationFrame(invalidateMapSize), {passive: true});
    window.setTimeout(invalidateMapSize, 0);
  } catch (_) { showMapError('เปิดแผนที่ไม่สำเร็จ กรุณาโหลดหน้านี้ใหม่'); }

  form.addEventListener('submit', event => {
    if (!originLat.value || !originLng.value || !destinationLat.value || !destinationLng.value) {
      event.preventDefault();
      if (error) { error.textContent = 'กรุณาเลือกรถ วันเวลา ต้นทาง และปลายทางให้ครบถ้วน'; error.hidden = false; error.classList.remove('hidden'); }
      for (const kind of ['origin', 'destination']) if (!hidden(kind).lat.value || !hidden(kind).lng.value) pointInputs[kind].classList.add('border-red-500', 'ring-2', 'ring-red-200');
      mapNode.classList.add('border-red-500', 'ring-2', 'ring-red-200'); status.scrollIntoView({block: 'nearest'});
    }
  });
})();
