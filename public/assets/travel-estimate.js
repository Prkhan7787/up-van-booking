'use strict';
function travelNumbers(outbound, returning, duration, efficiency, price) {
  if (![outbound, returning, duration].every(x => Number.isFinite(x) && x >= 0)) throw Error('ROUTE_MEASURE_INVALID');
  const totalKm = (outbound + returning) / 1000;
  const litres = Number.isFinite(efficiency) && efficiency > 0 ? totalKm / efficiency : null;
  return {outboundKm: outbound / 1000, totalKm, durationMinutes: duration / 60000,
    litres, cost: litres !== null && Number.isFinite(price) && price > 0 ? litres * price : null};
}
function readOsrmRoute(data) {
  if (data?.code !== 'Ok') throw Error('OSRM_' + (['NoRoute','NoSegment','TooBig','InvalidQuery','InvalidValue'].includes(data?.code) ? data.code : 'INVALID_RESPONSE'));
  const route = data.routes?.[0];
  if (!route || !Number.isFinite(route.distance) || route.distance < 0 || !Number.isFinite(route.duration) || route.duration < 0 || route.geometry?.type !== 'LineString' || !Array.isArray(route.geometry.coordinates) || route.geometry.coordinates.length < 2 || route.geometry.coordinates.some(p => !Array.isArray(p) || p.length < 2 || !Number.isFinite(p[0]) || !Number.isFinite(p[1]) || Math.abs(p[0]) > 180 || Math.abs(p[1]) > 90) || data.waypoints?.length !== 2 || data.waypoints.some(p => !Number.isFinite(p.distance) || p.distance < 0 || p.distance > 1000)) throw Error('OSRM_INVALID_RESPONSE');
  return {distance: route.distance, duration: route.duration, geometry: route.geometry, snaps: data.waypoints.map(p => p.distance)};
}
function fuelPriceFor(feed, fuelType) {
  if (!fuelType || feed?.status !== 'PASS' || !Object.prototype.hasOwnProperty.call(feed.prices || {}, fuelType)) return null;
  const price = feed.prices[fuelType];
  return Number.isFinite(price) && price > 0 ? price : null;
}
function referenceFuelEstimate(outbound, returning, price, reference, fuelType) {
  if (!reference || reference.usable !== true || reference.fuel_type !== fuelType || reference.unit !== 'กม./ลิตร' || !Array.isArray(reference.samples) || reference.samples.length !== 3 || !reference.samples.every(n => Number.isFinite(n) && n > 0) || !Number.isFinite(price) || price <= 0 || ![outbound, returning].every(n => Number.isFinite(n) && n > 0)) return null;
  const mean = reference.samples.reduce((sum, n) => sum + n, 0) / reference.samples.length;
  const min = Math.min(...reference.samples), max = Math.max(...reference.samples);
  const numbers = travelNumbers(outbound, returning, 0, mean, price);
  const litresMin = numbers.totalKm / max, litresMax = numbers.totalKm / min;
  return {mean, min, max, litres: numbers.litres, cost: numbers.cost, litresMin, litresMax, costMin: litresMin * price, costMax: litresMax * price};
}
if (typeof module !== 'undefined') module.exports = {travelNumbers, readOsrmRoute, fuelPriceFor, referenceFuelEstimate};

if (typeof document !== 'undefined') {
  const root = document.getElementById('travel-estimate');
  if (root) {
    const form = document.getElementById('travel-next-form');
    const status = document.getElementById('travel-map-status');
    const mapNode = document.getElementById('travel-map');
    const originInput = document.getElementById('travel-origin-search');
    const destinationInput = document.getElementById('travel-destination-search');
    const originLabel = root.dataset.originLabel || 'สถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา';
    const startPoint = {lat: Number(root.dataset.originLat), lng: Number(root.dataset.originLng), label: originLabel};
    const values = key => root.querySelector(`[data-travel-value="${key}"]`);
    const field = key => document.getElementById('travel-' + key.replaceAll('_', '-'));
    const nextButton = form.querySelector('button[type=submit]');
    const syncNextButton = () => {
      nextButton.disabled = form.dataset.canOpenForm !== 'true' || !origin || !destination || !field('route_outbound_m').value || !field('route_return_m').value || field('route_source').value !== 'osrm';
    };
    const put = (key, text) => { values(key).textContent = text; };
    const fmt = (n, digits = 1) => new Intl.NumberFormat('th-TH', {minimumFractionDigits: digits, maximumFractionDigits: digits}).format(n);
    const fuelType = root.dataset.fuelType.trim();
    let verifiedRoute = null, priceExpires = 0, priceTimer = null;
    let fuelReference = null; try { fuelReference = JSON.parse(root.dataset.fuelReference || 'null'); } catch (_) {}
    const fuelEstimateStatus = document.getElementById('travel-fuel-estimate-status');
    const sourceDetails = root.querySelector('.travel-source-panel');
    const sourceSummary = sourceDetails?.querySelector('summary');
    if (sourceDetails && sourceSummary) {
      sourceSummary.setAttribute('aria-expanded', String(sourceDetails.open));
      sourceDetails.addEventListener('toggle', () => sourceSummary.setAttribute('aria-expanded', String(sourceDetails.open)));
    }
    let price = null, priceDate = '', origin = null, destination = null, originMarker = null, destinationMarker = null, map = null;
    let destinationName = '';
    let lastGeocodeRequest = 0;
    const routeStatus = document.getElementById('travel-route-status');
    const clearRouteStatus = () => { routeStatus.textContent = ''; routeStatus.hidden = true; };
    const showRouteStatus = message => { routeStatus.textContent = message; routeStatus.hidden = false; };
    const localRouting = ['127.0.0.1', 'localhost', '[::1]'].includes(location.hostname);
    const routingEndpoint = 'https://router.project-osrm.org/route/v1/driving/';
    let routeRevision = 0, routeController = null, routeTimer = null, routeLayers = [], routeQueue = Promise.resolve(), lastRouteRequest = 0;
    const searchState = {
      origin: {timer: null, controller: null, results: [], revision: 0},
      destination: {timer: null, controller: null, results: [], revision: 0}
    };
    const pointInputs = {origin: originInput, destination: destinationInput};
    const clearEstimate = () => {
      verifiedRoute = null;
      routeRevision += 1; routeController?.abort(); window.clearTimeout(routeTimer);
      routeLayers.forEach(layer => map?.removeLayer(layer)); routeLayers = [];
      clearRouteStatus();
      nextButton.disabled = true;
      for (const key of ['route_outbound_m','route_return_m','route_duration_ms','route_return_duration_ms','route_source','fuel_litres','fuel_cost_baht']) field(key).value = '';
      for (const key of ['outbound','returning','roundtrip','duration','returnDuration','totalDuration','litres','fuelCost','costRange']) put(key, 'ยังไม่มีข้อมูลที่ตรวจสอบได้');
      updateFuelEstimate();
    };
    const icon = kind => L.divIcon({className: `travel-map-marker travel-map-marker-${kind}`, html: '<span class="travel-map-pin" aria-hidden="true"></span>', iconSize: [32, 40], iconAnchor: [16, 38]});
    const coordinates = point => `${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`;
    const setPoint = (kind, location, label, pan = false, selectedFromSearch = false, documentLabel = '') => {
      const lat = Number(location.lat), lng = Number(location.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return;
      cancelSearch(kind);
      const maxDisplayLength = kind === 'destination' ? 500 : 160;
      const cleanLabel = typeof label === 'string' ? label.trim().slice(0, maxDisplayLength) : '';
      const point = {lat, lng, label: cleanLabel.slice(0, 500) || 'จุดที่เลือกบนแผนที่'};
      if (kind === 'origin') origin = point; else destination = point;
      if (kind === 'destination') {
        const cleanDocumentLabel = typeof documentLabel === 'string' ? documentLabel.trim().slice(0, 160) : '';
        destinationName = selectedFromSearch && cleanDocumentLabel && cleanDocumentLabel !== 'จุดที่เลือกบนแผนที่' && cleanDocumentLabel.length <= 500 ? cleanDocumentLabel : '';
        field('destination').value = destinationName;
        field('destination_source').value = destinationName ? 'search' : 'map';
      }
      const currentMarker = kind === 'origin' ? originMarker : destinationMarker;
      const markerOptions = {title: `${kind === 'origin' ? 'ต้นทาง' : 'ปลายทาง'}: ${point.label}`, alt: kind === 'origin' ? 'หมุดต้นทาง' : 'หมุดปลายทาง', icon: icon(kind)};
      if (currentMarker) {
        currentMarker.options.title = markerOptions.title; currentMarker.options.alt = markerOptions.alt;
        currentMarker.setLatLng([lat, lng]).setIcon(markerOptions.icon).bindTooltip(markerOptions.title);
        currentMarker.getElement()?.setAttribute('title', markerOptions.title);
        currentMarker.getElement()?.setAttribute('aria-label', markerOptions.alt);
      }
      else {
        const created = L.marker([lat, lng], markerOptions).addTo(map).bindTooltip(markerOptions.title);
        if (kind === 'origin') originMarker = created; else destinationMarker = created;
      }
      pointInputs[kind].value = point.label;
      document.getElementById(`travel-${kind}-location`).textContent = `พิกัด ${coordinates(point)}`;
      if (kind === 'origin') field('origin').value = point.label;
      field(`${kind}_lat`).value = lat.toFixed(6); field(`${kind}_lng`).value = lng.toFixed(6);
      put(kind, point.label);
      if (pan && map) map.setView([lat, lng], Math.max(map.getZoom(), 15));
      clearEstimate();
      status.textContent = `${kind === 'origin' ? 'เลือกต้นทาง' : 'เลือกปลายทาง'}แล้ว · ${point.label}`;
      scheduleRoute();
    };
    const clearPoint = kind => {
      if (kind === 'origin') {
        origin = null;
        if (originMarker && map) map.removeLayer(originMarker);
        originMarker = null;
      } else {
        destination = null;
        if (destinationMarker && map) map.removeLayer(destinationMarker);
        destinationMarker = null;
      }
      document.getElementById(`travel-${kind}-location`).textContent = kind === 'origin' ? 'ยังไม่ได้เลือกต้นทาง' : 'ยังไม่ได้เลือกปลายทาง';
      document.getElementById(`travel-${kind}`).value = '';
      field(`${kind}_lat`).value = ''; field(`${kind}_lng`).value = '';
      if (kind === 'destination') { destinationName = ''; field('destination_source').value = ''; }
      put(kind, 'ยังไม่ได้เลือก');
      clearEstimate();
    };
    function resultLabel(properties) {
      const street = [properties.housenumber, properties.street].filter(Boolean).join(' ');
      const parts = [properties.name, street, properties.district, properties.city, properties.state, properties.country]
        .filter(value => typeof value === 'string' && value.trim());
      return [...new Map(parts.map(value => [value.trim().toLocaleLowerCase(), value.trim()])).values()].join(', ').slice(0, 500);
    }
    function hideResults(kind) {
      const list = document.getElementById(`travel-${kind}-results`);
      list.replaceChildren(); list.hidden = true; searchState[kind].results = [];
    }
    function cancelSearch(kind) {
      const state = searchState[kind];
      state.revision += 1; state.controller?.abort(); window.clearTimeout(state.timer); hideResults(kind);
    }
    function showResults(kind, features) {
      const state = searchState[kind]; state.results = features;
      const list = document.getElementById(`travel-${kind}-results`);
      list.replaceChildren();
      if (!features.length) {
        const empty = document.createElement('p'); empty.className = 'travel-search-message'; empty.textContent = 'ไม่พบสถานที่ ลองใช้คำค้นอื่น'; list.append(empty);
        list.hidden = false; return;
      }
      features.forEach((feature, index) => {
        const button = document.createElement('button'); button.type = 'button'; button.role = 'option'; button.setAttribute('aria-selected', 'false');
        button.className = 'travel-search-option';
        button.textContent = resultLabel(feature.properties || {}) || 'สถานที่จาก OpenStreetMap';
        button.addEventListener('click', () => {
          const coords = feature.geometry?.coordinates;
          if (!Array.isArray(coords) || coords.length < 2) return;
          const properties = feature.properties || {};
          const label = resultLabel(properties);
          hideResults(kind);
          // Keep the A4 destination identical to the full, user-visible search result.
          // Manual map pins still pass no searched label and therefore remain blank in the form.
          setPoint(kind, {lat: coords[1], lng: coords[0]}, label, true, Boolean(label), label);
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
      } catch (error) {
        if (error.name === 'AbortError' || revision !== state.revision) return;
        const list = document.getElementById(`travel-${kind}-results`);
        list.replaceChildren(); const message = document.createElement('p'); message.className = 'travel-search-message';
        message.textContent = 'ค้นหาสถานที่ไม่สำเร็จ ลองอีกครั้งหรือเลือกจุดบนแผนที่'; list.append(message); list.hidden = false;
      }
    }
    for (const kind of ['origin','destination']) {
      const input = pointInputs[kind], state = searchState[kind];
      input.addEventListener('input', () => {
        cancelSearch(kind); clearPoint(kind);
        const query = input.value.trim();
        if (query.length < 3) return;
        const wait = Math.max(450, 1000 - (Date.now() - lastGeocodeRequest));
        const revision = state.revision;
        state.timer = window.setTimeout(() => void searchPlaces(kind, query, revision), wait);
      });
      input.addEventListener('keydown', event => {
        if (event.key === 'Escape') hideResults(kind);
        if (event.key === 'Enter') {
          const first = document.getElementById(`travel-${kind}-results`).querySelector('[role=option]');
          if (first) { event.preventDefault(); first.click(); }
        }
      });
      input.addEventListener('focus', () => { if (state.results.length) document.getElementById(`travel-${kind}-results`).hidden = false; });
    }
    function scheduleRoute() {
      if (!origin || !destination) return;
      if (!localRouting) { showRouteStatus('บริการคำนวณเส้นทางยังไม่พร้อมใช้งานในสภาพแวดล้อมนี้'); return; }
      const revision = routeRevision, from = {...origin}, to = {...destination};
      clearRouteStatus();
      routeTimer = window.setTimeout(() => {
        routeQueue = routeQueue.catch(() => {}).then(async () => {
          if (revision !== routeRevision) return;
          const controller = new AbortController(); routeController = controller;
          const request = async (start, end, direction) => {
            const delay = Math.max(0, 1100 - (Date.now() - lastRouteRequest));
            if (delay) await new Promise(resolve => window.setTimeout(resolve, delay));
            if (controller.signal.aborted || revision !== routeRevision) throw new DOMException('Cancelled', 'AbortError');
            lastRouteRequest = Date.now();
            const url = `${routingEndpoint}${start.lng},${start.lat};${end.lng},${end.lat}?overview=full&geometries=geojson&steps=false&alternatives=false&radiuses=1000;1000`;
            const timeout = window.setTimeout(() => controller.abort('timeout'), 20000);
            try {
              const response = await fetch(url, {signal: controller.signal, credentials: 'omit', headers: {Accept: 'application/json'}});
              let data;
              try { data = await response.json(); } catch (_) { throw Error('OSRM_INVALID_JSON'); }
              if (!response.ok) {
                if (['NoRoute','NoSegment'].includes(data?.code)) throw Error('OSRM_' + data.code);
                throw Error('OSRM_HTTP_' + response.status);
              }
              const route = readOsrmRoute(data);
              route.http = response.status; route.direction = direction;
              return route;
            } finally { window.clearTimeout(timeout); }
          };
          try {
            const outbound = await request(from, to, 'ขาไป');
            const returning = await request(to, from, 'ขากลับ');
            if (revision !== routeRevision || controller.signal.aborted) return;
            routeLayers = [L.geoJSON(outbound.geometry, {style: {color: '#512d6d', weight: 5}}).addTo(map), L.geoJSON(returning.geometry, {style: {color: '#b91c1c', weight: 3, dashArray: '8 6'}}).addTo(map)];
            map.fitBounds(routeLayers[0].getBounds().extend(routeLayers[1].getBounds()), {padding: [24,24]});
            put('outbound', `${fmt(outbound.distance / 1000, 2)} กม.`);
            put('returning', `${fmt(returning.distance / 1000, 2)} กม.`);
            put('roundtrip', `${fmt((outbound.distance + returning.distance) / 1000, 2)} กม.`);
            const duration = seconds => { const minutes = Math.round(seconds / 60); return `${Math.floor(minutes / 60)} ชม. ${minutes % 60} นาที`; };
            put('duration', duration(outbound.duration)); put('returnDuration', duration(returning.duration)); put('totalDuration', duration(outbound.duration + returning.duration));
            field('route_outbound_m').value = String(outbound.distance); field('route_return_m').value = String(returning.distance); field('route_duration_ms').value = String(outbound.duration * 1000); field('route_return_duration_ms').value = String(returning.duration * 1000); field('route_source').value = 'osrm';
            verifiedRoute = {outbound: outbound.distance, returning: returning.distance}; updateFuelEstimate();
            syncNextButton();
            clearRouteStatus();
          } catch (error) {
            if (revision !== routeRevision) return;
            const messages = {OSRM_NoSegment: 'หาถนนสำหรับรถยนต์ใกล้หมุดภายใน 1 กม. ไม่พบ กรุณาย้ายหมุดไปบนถนน', OSRM_NoRoute: 'ไม่พบเส้นทางรถยนต์ที่เชื่อมสองจุดนี้', OSRM_INVALID_JSON: 'บริการส่งข้อมูลที่ไม่ใช่ JSON', OSRM_INVALID_RESPONSE: 'ข้อมูลเส้นทางจากบริการไม่ครบหรือไม่ถูกต้อง'};
            showRouteStatus(controller.signal.reason === 'timeout' ? 'บริการคำนวณเส้นทางใช้เวลานานเกินไป กรุณาเลือกจุดใหม่เพื่อลองอีกครั้ง' : (messages[error.message] || (/^OSRM_HTTP_\d+$/.test(error.message) ? 'บริการคำนวณเส้นทางตอบกลับผิดพลาด กรุณาลองอีกครั้ง' : 'เชื่อมต่อบริการคำนวณเส้นทางไม่สำเร็จ กรุณาตรวจเครือข่ายแล้วลองอีกครั้ง')));
          }
        });
      }, 650);
    }
    function updateFuelEstimate() {
      const usable = fuelReference?.usable === true;
      field('fuel_efficiency_reference_km_per_l').value = usable ? String(fuelReference.mean) : '';
      if (usable) put('efficiency', `ค่าเฉลี่ยตัวอย่าง ${fmt(fuelReference.mean, 4)} กม./ลิตร · ช่วง ${fmt(fuelReference.min, 2)}–${fmt(fuelReference.max, 2)} · ${fuelReference.sample_count} คัน · ไม่ใช่ค่าจริงของรถที่เลือก`);
      else put('efficiency', 'ยังไม่มีอัตราอ้างอิงที่ยืนยันว่าเหมาะสมกับชนิดน้ำมันนี้');
      const result = verifiedRoute && Date.now() < priceExpires ? referenceFuelEstimate(verifiedRoute.outbound, verifiedRoute.returning, price, fuelReference, fuelType) : null;
      if (!result) {
        for (const key of ['litres','fuelCost','costRange']) put(key, 'ยังคำนวณไม่ได้');
        field('fuel_litres').value = ''; field('fuel_cost_baht').value = '';
        fuelEstimateStatus.textContent = !usable ? 'ยังคำนวณปริมาณน้ำมันและค่าใช้จ่ายไม่ได้: ไม่มีอัตราอ้างอิงที่ยืนยันว่าเหมาะสมกับชนิดน้ำมันนี้' : !verifiedRoute ? 'ยังคำนวณปริมาณน้ำมันและค่าใช้จ่ายไม่ได้: รอเส้นทาง OSRM ที่ตรวจสอบสำเร็จทั้งขาไปและขากลับ' : 'ยังคำนวณปริมาณน้ำมันและค่าใช้จ่ายไม่ได้: ยังไม่สามารถยืนยันราคาน้ำมันได้';
        return;
      }
      put('litres', `${fmt(result.litres, 2)} ลิตร · ช่วง ${fmt(result.litresMin, 2)}–${fmt(result.litresMax, 2)} ลิตร`);
      put('fuelCost', `${fmt(result.cost, 2)} บาท`);
      put('costRange', `${fmt(result.costMin, 2)}–${fmt(result.costMax, 2)} บาท`);
      field('fuel_litres').value = String(result.litres); field('fuel_cost_baht').value = String(result.cost);
      fuelEstimateStatus.textContent = 'ประมาณการจากค่าเฉลี่ยของตัวอย่างรถตู้ในรายงาน สนข. 2566 ไม่ใช่ค่าจริงของรถที่เลือก ช่วงค่าใช้จ่ายคำนวณจากอัตราต่ำสุด–สูงสุดของตัวอย่าง ไม่ใช่ช่วงความเชื่อมั่น';
    }
    async function loadPrices() {
      updateFuelEstimate();
      if (!fuelType) { put('fuelPrice', 'ยังไม่สามารถยืนยันราคาน้ำมันได้ · รถยังไม่มีชนิดน้ำมัน'); put('fuelType', 'รอข้อมูลชนิดน้ำมันของรถ'); updateFuelEstimate(); return; }
      put('fuelType', fuelType); updateFuelEstimate();
      field('fuel_type').value = fuelType;
      try {
        const response = await fetch('/bookings/travel-estimate/fuel-prices', {credentials: 'same-origin'});
        if (!response.ok) throw Error('PRICE_UNAVAILABLE');
        const feed = await response.json();
        const matched = fuelPriceFor(feed, fuelType);
        if (feed.status !== 'PASS' || !Number.isFinite(matched) || matched <= 0) throw Error('FUEL_TYPE_UNMATCHED');
        price = matched; priceDate = String(feed.effective_note || feed.price_record_date || '');
        const box = values('fuelPrice'); box.replaceChildren();
        const thaiTime = value => new Intl.DateTimeFormat('th-TH', {timeZone:'Asia/Bangkok',dateStyle:'medium',timeStyle:'short'}).format(new Date(value));
        if (!feed.effective_at || !feed.fetched_at || !Number.isFinite(Date.parse(feed.effective_at)) || !Number.isFinite(Date.parse(feed.fetched_at))) throw Error('PRICE_DATE_UNCONFIRMED');
        for (const line of [`${fmt(price, 2)} บาท/ลิตร`, `ชนิดน้ำมัน: ${fuelType}`, 'แหล่งอ้างอิง: บางจาก', `ราคามีผล: ${thaiTime(feed.effective_at)}`, `${feed.updated_today ? 'ข้อมูลราคาน้ำมันอัปเดตวันนี้' : 'ข้อมูลราคาน้ำมันวันที่ ' + feed.source_date}`, `ตรวจสอบข้อมูล: ${thaiTime(feed.fetched_at)}${feed.cached ? ' (Cache)' : ''}`]) {
          const text = document.createElement('span'); text.className='block'; text.textContent=line; box.append(text);
        }
        const fetchedAt = Date.parse(feed.fetched_at), effectiveAt = Date.parse(feed.effective_at);
        if (fetchedAt > Date.now() + 60000 || effectiveAt > Date.now() || fetchedAt + 3600000 <= Date.now()) throw Error('PRICE_STALE');
        priceExpires = fetchedAt + 3600000;
        window.clearTimeout(priceTimer); priceTimer = window.setTimeout(() => { price = null; priceExpires = 0; updateFuelEstimate(); void loadPrices(); }, Math.max(1000, priceExpires - Date.now()));
        updateFuelEstimate();
        field('fuel_price_baht_per_l').value = String(price); field('fuel_price_date').value = priceDate;
        root.querySelector('[data-travel-price-note]').textContent = feed.area || 'ราคาอ้างอิงอาจต่างจากพื้นที่เดินทาง';
      } catch (_) { price = null; priceExpires = 0; field('fuel_price_baht_per_l').value = ''; field('fuel_price_date').value = ''; put('fuelPrice', 'ยังไม่สามารถยืนยันราคาน้ำมันได้'); updateFuelEstimate(); }
    }
    function showMapError(message) {
      mapNode.replaceChildren(); const panel = document.createElement('div');
      panel.className = 'map-load-error'; panel.textContent = message; mapNode.append(panel); status.textContent = message;
    }
    function initMap() {
      if (!window.L || typeof L.map !== 'function') { showMapError('โหลดเครื่องมือแผนที่ไม่สำเร็จ กรุณาตรวจอินเทอร์เน็ตแล้วลองใหม่'); return; }
      try {
        map = L.map(mapNode, {scrollWheelZoom: true, tapHold: false}).setView([startPoint.lat, startPoint.lng], 14);
        const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          minZoom: 3, maxZoom: 19,
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'
        }).addTo(map);
        setPoint('origin', {lat: startPoint.lat, lng: startPoint.lng}, startPoint.label);
        let touchStart = null, holdTimer = null, suppressClickUntil = 0, held = false;
        const cancelHold = () => { window.clearTimeout(holdTimer); holdTimer = null; };
        mapNode.addEventListener('pointerdown', event => {
          if (event.pointerType !== 'touch' && event.pointerType !== 'pen') return;
          if (touchStart) { cancelHold(); touchStart = null; return; }
          held = false;
          touchStart = {id: event.pointerId, x: event.clientX, y: event.clientY};
          holdTimer = window.setTimeout(() => {
            held = true; suppressClickUntil = Number.POSITIVE_INFINITY;
            setPoint('destination', map.containerPointToLatLng(L.point(event.clientX - mapNode.getBoundingClientRect().left, event.clientY - mapNode.getBoundingClientRect().top)));
          }, 650);
        }, {passive: true});
        mapNode.addEventListener('pointermove', event => {
          if (touchStart && Math.hypot(event.clientX - touchStart.x, event.clientY - touchStart.y) > 10) cancelHold();
        }, {passive: true});
        for (const type of ['pointerup', 'pointercancel', 'pointerleave']) mapNode.addEventListener(type, () => { cancelHold(); touchStart = null; if (held) { suppressClickUntil = Date.now() + 1000; held = false; } });
        mapNode.addEventListener('click', event => {
          if (Date.now() < suppressClickUntil) { event.preventDefault(); event.stopImmediatePropagation(); }
        }, true);
        mapNode.addEventListener('contextmenu', event => event.preventDefault());
        map.on('click', event => { if (Date.now() >= suppressClickUntil) setPoint('origin', event.latlng); });
        map.on('contextmenu', event => {
          L.DomEvent.preventDefault(event.originalEvent);
          if (Date.now() >= suppressClickUntil) setPoint('destination', event.latlng);
        });
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
        if ('ResizeObserver' in window) {
          const mapResizeObserver = new ResizeObserver(() => window.requestAnimationFrame(invalidateMapSize));
          mapResizeObserver.observe(mapNode);
        } else {
          window.addEventListener('resize', () => window.requestAnimationFrame(invalidateMapSize), {passive: true});
        }
        window.setTimeout(invalidateMapSize, 0);
      } catch (_) { showMapError('เปิดแผนที่ไม่สำเร็จ กรุณาโหลดหน้านี้ใหม่'); }
    }
    form.addEventListener('submit', event => {
      syncNextButton();
      if (nextButton.disabled) event.preventDefault();
    });
    void loadPrices(); initMap();
  }
}
