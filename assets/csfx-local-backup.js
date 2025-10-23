(()=> {
  const SETTINGS = window.CSFX_LB_SETTINGS || {};
  const CHECKOUT_ACTIONS = Array.isArray(SETTINGS.checkout_actions) ? SETTINGS.checkout_actions.map(String) : [];
  const RESTFUL_ENABLED = !!SETTINGS.restful_enabled;
  const DB_NAME = 'csfx-orders';
  const DB_VERSION = 2;
  const STORE_ORDERS = 'orders';
  const STORE_HANDLES = 'handles';
  const MAX_RECORDS = 500;

  let fsRoot = null;
  let deviceId = null;
  const recentMap = new Map();
  const isDebug = () => !!window.CSFX_DEBUG;

  function log(...args) {
    if (isDebug()) {
      console.log('[CSFX]', ...args);
    }
  }

  const p2 = n => String(n).padStart(2, '0');
  const nowParts = () => {
    const d = new Date();
    return {
      y: d.getFullYear(),
      m: p2(d.getMonth() + 1),
      d: p2(d.getDate()),
      H: p2(d.getHours()),
      M: p2(d.getMinutes()),
      S: p2(d.getSeconds())
    };
  };
  const dayFolderFromParts = parts => {
    const t = parts || nowParts();
    return `${t.y}-${t.m}-${t.d}`;
  };

  function bodyToString(body) {
    if (!body) return '';
    if (typeof body === 'string') return body;
    if (body instanceof URLSearchParams) return body.toString();
    if (body instanceof FormData) {
      const pairs = [];
      try {
        for (const [key, value] of body.entries()) {
          if (typeof value === 'string') {
            pairs.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
          }
        }
      } catch (err) {
        log('FormData parse error', err);
      }
      return pairs.join('&');
    }
    if (body && typeof body === 'object' && typeof body.entries === 'function') {
      const pairs = [];
      try {
        for (const [key, value] of body.entries()) {
          if (typeof value === 'string') {
            pairs.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
          }
        }
      } catch (err) {
        log('entries parse error', err);
      }
      if (pairs.length) return pairs.join('&');
    }
    if (body instanceof ArrayBuffer || ArrayBuffer.isView(body)) return '';
    if (body instanceof Blob) return '';
    try {
      return JSON.stringify(body);
    } catch {
      return '';
    }
  }

  function parseMaybeJSON(value) {
    if (value === null || value === undefined) return null;
    if (typeof value === 'object') return value;
    if (typeof value !== 'string') return value;
    const trimmed = value.trim();
    if (!trimmed) return null;
    if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
      try {
        return JSON.parse(trimmed);
      } catch (err) {
        log('parseMaybeJSON error', err);
      }
    }
    return value;
  }

  function parseCheckoutPayload(bodyStr) {
    const result = {
      posAction: null,
      cart: null,
      payments: null,
      customer: null,
      raw: null,
      params: null
    };
    if (!bodyStr) return result;
    const trimmed = bodyStr.trim();
    if (trimmed) {
      if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
        try {
          const obj = JSON.parse(trimmed);
          result.raw = obj;
          if (obj && typeof obj === 'object') {
            if (typeof obj.pos_action === 'string') result.posAction = obj.pos_action;
            if (!result.cart && obj.cart !== undefined) result.cart = parseMaybeJSON(obj.cart);
            if (!result.cart && obj.data && obj.data.cart !== undefined) result.cart = parseMaybeJSON(obj.data.cart);
            if (!result.payments && obj.payments !== undefined) result.payments = parseMaybeJSON(obj.payments);
            if (!result.payments && obj.payment !== undefined) result.payments = parseMaybeJSON(obj.payment);
            if (!result.payments && obj.data && obj.data.payments !== undefined) result.payments = parseMaybeJSON(obj.data.payments);
            if (!result.customer && obj.customer !== undefined) result.customer = parseMaybeJSON(obj.customer);
            if (!result.customer && obj.data && obj.data.customer !== undefined) result.customer = parseMaybeJSON(obj.data.customer);
          }
          return result;
        } catch (err) {
          log('JSON body parse error', err);
        }
      }
    }
    try {
      const params = new URLSearchParams(bodyStr);
      const map = {};
      params.forEach((value, key) => {
        map[key] = value;
      });
      result.params = map;
      if (params.has('pos_action')) result.posAction = params.get('pos_action');
      const cartParam = params.get('cart');
      if (cartParam) result.cart = parseMaybeJSON(cartParam);
      const dataParam = params.get('data');
      const parsedData = dataParam ? parseMaybeJSON(dataParam) : null;
      if (!result.cart && parsedData && parsedData.cart !== undefined) {
        result.cart = parseMaybeJSON(parsedData.cart);
      }
      if (!result.payments) {
        const payParam = params.get('payments') ?? params.get('payment');
        if (payParam) result.payments = parseMaybeJSON(payParam);
      }
      if (!result.payments && parsedData && parsedData.payments !== undefined) {
        result.payments = parseMaybeJSON(parsedData.payments);
      }
      if (!result.customer) {
        const customerParam = params.get('customer');
        if (customerParam) result.customer = parseMaybeJSON(customerParam);
      }
      if (!result.customer && parsedData && parsedData.customer !== undefined) {
        result.customer = parseMaybeJSON(parsedData.customer);
      }
    } catch (err) {
      log('URLSearchParams parse error', err);
    }
    return result;
  }

  function extractActionFromBody(bodyStr) {
    if (!bodyStr) return null;
    const payload = parseCheckoutPayload(bodyStr);
    return payload.posAction || null;
  }

  function extractActionFromUrl(url) {
    if (!url) return null;
    const lower = String(url).toLowerCase();
    const match = lower.match(/\/wp-json\/op\/v1\/([^\/?]+)/);
    if (match && match[1]) {
      return decodeURIComponent(match[1]);
    }
    return null;
  }

  function detectAction(url, bodyStr) {
    const actionFromBody = extractActionFromBody(bodyStr);
    if (actionFromBody) return actionFromBody;
    return extractActionFromUrl(url);
  }

  async function resolveRequestBody(input, init) {
    if (init && Object.prototype.hasOwnProperty.call(init, 'body')) {
      return init.body;
    }
    if (typeof Request !== 'undefined' && input instanceof Request) {
      try {
        const clone = input.clone();
        if (typeof clone.formData === 'function') {
          return await clone.formData();
        }
        if (typeof clone.text === 'function') {
          return await clone.text();
        }
      } catch (err) {
        log('resolveRequestBody error', err);
      }
    }
    return '';
  }

  function isOpenposCheckout(url, body) {
    const urlStr = String(url || '');
    const lowerUrl = urlStr.toLowerCase();
    const bodyStr = bodyToString(body);
    const actionFromBody = extractActionFromBody(bodyStr);
    const actionFromUrl = extractActionFromUrl(urlStr);
    const isAjax = lowerUrl.includes('admin-ajax.php');
    const isRest = lowerUrl.includes('/wp-json/op/v1/');
    if (!isAjax && !isRest) return false;
    if (isAjax && !actionFromBody) return false;
    if (isRest && !actionFromUrl) return false;
    const action = actionFromBody || actionFromUrl;
    if (!action) return false;
    return CHECKOUT_ACTIONS.includes(action);
  }

  function inRecentGate(orderNumber) {
    if (!orderNumber) return false;
    const key = String(orderNumber);
    const now = performance.now();
    const last = recentMap.get(key) || 0;
    recentMap.set(key, now);
    return now - last < 8000;
  }

  function deepClone(value) {
    if (value === undefined) return null;
    try {
      return JSON.parse(JSON.stringify(value));
    } catch {
      return value && typeof value === 'object' ? null : value;
    }
  }

  function normalizeId(value) {
    if (value === undefined || value === null) return null;
    if (typeof value === 'number') return String(value);
    if (typeof value === 'string') {
      const trimmed = value.trim();
      return trimmed === '' ? null : trimmed;
    }
    return null;
  }

  function getValueByPath(source, path) {
    if (!source || typeof source !== 'object') return null;
    const parts = path.split('.');
    let current = source;
    for (const part of parts) {
      if (current && typeof current === 'object' && part in current) {
        current = current[part];
      } else {
        return null;
      }
    }
    return current;
  }

  function extractOrderNumber(payload) {
    if (!payload || typeof payload !== 'object') return null;
    const paths = [
      'number',
      'order_number',
      'increment_id',
      'order.number',
      'order.order_number',
      'order.increment_id',
      'data.number',
      'data.order_number',
      'data.increment_id',
      'result.number',
      'result.order_number'
    ];
    for (const path of paths) {
      const value = getValueByPath(payload, path);
      const normalized = normalizeId(value);
      if (normalized) return normalized;
    }
    return null;
  }

  function extractOrderId(payload) {
    if (!payload || typeof payload !== 'object') return null;
    const paths = [
      'order_id',
      'id',
      'order.id',
      'data.order_id',
      'result.order_id'
    ];
    for (const path of paths) {
      const value = getValueByPath(payload, path);
      const normalized = normalizeId(value);
      if (normalized) return normalized;
    }
    return null;
  }

  function extractTotals(payload) {
    if (!payload || typeof payload !== 'object') return null;
    const candidates = [
      payload.totals,
      payload.data && payload.data.totals,
      payload.order && payload.order.totals,
      payload.result && payload.result.totals
    ];
    for (const candidate of candidates) {
      if (candidate && typeof candidate === 'object') return candidate;
    }
    return null;
  }

  function downloadBlob(blob, fileName) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    setTimeout(() => {
      URL.revokeObjectURL(link.href);
      link.remove();
    }, 600);
  }

  async function hashValue(value) {
    let payload = '';
    if (typeof value === 'string') {
      payload = value;
    } else {
      try {
        payload = JSON.stringify(value ?? {});
      } catch {
        payload = String(value ?? '');
      }
    }
    const enc = new TextEncoder().encode(payload);
    const hash = await crypto.subtle.digest('SHA-256', enc);
    return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
  }

  function openDB() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = event => {
        const db = event.target.result;
        let ordersStore;
        if (!db.objectStoreNames.contains(STORE_ORDERS)) {
          ordersStore = db.createObjectStore(STORE_ORDERS, { keyPath: 'key' });
        } else {
          ordersStore = event.target.transaction.objectStore(STORE_ORDERS);
        }
        if (ordersStore && !ordersStore.indexNames.contains('by_time')) {
          ordersStore.createIndex('by_time', 'createdAt');
        }
        if (ordersStore && !ordersStore.indexNames.contains('by_orderNumber')) {
          ordersStore.createIndex('by_orderNumber', 'orderNumber');
        }
        if (!db.objectStoreNames.contains(STORE_HANDLES)) {
          db.createObjectStore(STORE_HANDLES, { keyPath: 'key' });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }

  async function putOrder(doc) {
    const db = await openDB();
    await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ORDERS, 'readwrite');
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
      tx.objectStore(STORE_ORDERS).put(doc);
    });
    return doc;
  }

  async function getOrder(key) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ORDERS, 'readonly');
      const req = tx.objectStore(STORE_ORDERS).get(key);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  }

  async function pruneOrders(max = MAX_RECORDS) {
    try {
      const db = await openDB();
      await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_ORDERS, 'readwrite');
        const idx = tx.objectStore(STORE_ORDERS).index('by_time');
        let kept = 0;
        idx.openCursor(null, 'prev').onsuccess = event => {
          const cursor = event.target.result;
          if (!cursor) return;
          kept++;
          if (kept > max) {
            cursor.delete();
          }
          cursor.continue();
        };
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
      });
    } catch (err) {
      log('pruneOrders error', err);
    }
  }

  async function saveHandle(key, handle) {
    try {
      const db = await openDB();
      await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_HANDLES, 'readwrite');
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.objectStore(STORE_HANDLES).put({ key, handle });
      });
    } catch (err) {
      log('saveHandle error', err);
    }
  }

  async function getHandle(key) {
    try {
      const db = await openDB();
      return await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_HANDLES, 'readonly');
        const req = tx.objectStore(STORE_HANDLES).get(key);
        req.onsuccess = () => resolve(req.result ? req.result.handle : null);
        req.onerror = () => reject(req.error);
      });
    } catch (err) {
      log('getHandle error', err);
      return null;
    }
  }

  async function deleteHandle(key) {
    try {
      const db = await openDB();
      await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_HANDLES, 'readwrite');
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.objectStore(STORE_HANDLES).delete(key);
      });
    } catch (err) {
      log('deleteHandle error', err);
    }
  }

  async function ensureRoot(interactive = false) {
    if (fsRoot) return true;

    const stored = await getHandle('backupRoot');
    if (stored) {
      let permission = 'granted';
      if (typeof stored.queryPermission === 'function') {
        try {
          permission = await stored.queryPermission({ mode: 'readwrite' });
        } catch (err) {
          log('queryPermission error', err);
          permission = 'denied';
        }
      }
      if (permission === 'granted') {
        fsRoot = stored;
        log('Handle restaurado desde IndexedDB');
        return true;
      }
      if (permission === 'prompt' && interactive && typeof stored.requestPermission === 'function') {
        try {
          const req = await stored.requestPermission({ mode: 'readwrite' });
          if (req === 'granted') {
            fsRoot = stored;
            log('Permiso concedido para handle almacenado');
            return true;
          }
        } catch (err) {
          log('requestPermission error', err);
        }
      }
    }

    if (!interactive) return false;
    if (!('showDirectoryPicker' in window)) {
      log('File System Access no disponible en este navegador');
      return false;
    }
    try {
      const handle = await showDirectoryPicker({ mode: 'readwrite' });
      fsRoot = handle;
      await saveHandle('backupRoot', handle);
      log('Carpeta seleccionada manualmente');
      return true;
    } catch (err) {
      log('showDirectoryPicker cancelado o fallido', err);
      return false;
    }
  }

  async function getSubdir(root, name, create = true) {
    try {
      return await root.getDirectoryHandle(name, { create });
    } catch (err) {
      if (create) log('getSubdir error', err);
      return null;
    }
  }

  async function readTextFile(handle, name) {
    try {
      const fileHandle = await handle.getFileHandle(name);
      const file = await fileHandle.getFile();
      return await file.text();
    } catch {
      return null;
    }
  }

  async function writeTextFile(handle, name, text) {
    try {
      const fileHandle = await handle.getFileHandle(name, { create: true });
      const writable = await fileHandle.createWritable();
      await writable.write(text);
      await writable.close();
      return true;
    } catch (err) {
      log('writeTextFile error', err);
      return false;
    }
  }

  async function deleteFileIfExists(folderName, fileName) {
    if (!folderName || !fileName) return;
    if (!(await ensureRoot(false))) return;
    if (!fsRoot) return;
    try {
      const dir = await getSubdir(fsRoot, folderName, false);
      if (!dir) return;
      if (typeof dir.removeEntry === 'function') {
        await dir.removeEntry(fileName).catch(err => log('removeEntry error', err));
      }
    } catch (err) {
      log('deleteFileIfExists error', err);
    }
  }

  async function ensureDeviceId() {
    if (deviceId) return deviceId;
    if (!(await ensureRoot(false))) return null;
    if (!fsRoot) return null;
    let txt = await readTextFile(fsRoot, 'device-id.txt');
    if (!txt) {
      txt = crypto.randomUUID();
      await writeTextFile(fsRoot, 'device-id.txt', txt);
    }
    deviceId = String(txt || '').trim();
    return deviceId;
  }

  async function writeOrderDoc(doc, { pending = false } = {}) {
    const fileName = pending ? (doc.pendingFileName || doc.fileName) : doc.fileName;
    if (!fileName) return false;
    const folderName = doc.dayFolder || dayFolderFromParts(doc.createdAtParts);
    const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' });

    if (await ensureRoot(false)) {
      const dayDir = await getSubdir(fsRoot, folderName, true);
      if (dayDir) {
        try {
          const fileHandle = await dayDir.getFileHandle(fileName, { create: true });
          const writable = await fileHandle.createWritable();
          await writable.write(blob);
          await writable.close();
          return true;
        } catch (err) {
          log('writeOrderDoc error', err);
        }
      }
    }

    downloadBlob(blob, fileName);
    return false;
  }

  async function persistStorage() {
    if (!navigator.storage || !navigator.storage.persist) return;
    try {
      const granted = await navigator.storage.persist();
      log('navigator.storage.persist', granted);
    } catch (err) {
      log('storage.persist error', err);
    }
  }

  let uiControls = null;

  function updateUIState(granted, text) {
    if (uiControls) {
      uiControls.setState(granted, text);
    }
  }

  function updateExportButton(doc) {
    if (uiControls) {
      uiControls.setExport(doc);
    }
  }

  async function refreshHandleStatus() {
    if (!('showDirectoryPicker' in window)) {
      updateUIState(false, 'sin File System Access');
      return;
    }
    const stored = await getHandle('backupRoot');
    if (!stored) {
      fsRoot = null;
      updateUIState(false, 'sin carpeta');
      return;
    }
    let permission = 'granted';
    if (typeof stored.queryPermission === 'function') {
      try {
        permission = await stored.queryPermission({ mode: 'readwrite' });
      } catch (err) {
        log('queryPermission error', err);
        permission = 'denied';
      }
    }
    if (permission === 'granted') {
      fsRoot = stored;
      await ensureDeviceId();
      updateUIState(true, 'carpeta lista');
    } else if (permission === 'prompt') {
      fsRoot = stored;
      updateUIState(false, 'permiso requerido');
    } else {
      fsRoot = null;
      updateUIState(false, 'sin permisos');
    }
  }

  async function createPendingContext(url, method, body) {
    try {
      const bodyStr = bodyToString(body);
      if (!isOpenposCheckout(url, bodyStr)) {
        if (window.CSFX_DEBUG) {
          log('POST ignorado (no es checkout)', { url, body: bodyStr.slice(0, 200) });
        }
        return null;
      }
      const action = detectAction(url, bodyStr);
      if (!action || !CHECKOUT_ACTIONS.includes(action)) {
        if (window.CSFX_DEBUG) {
          log('Acción POS no reconocida', { url, action, body: bodyStr.slice(0, 200) });
        }
        return null;
      }
      const payload = parseCheckoutPayload(bodyStr);
      const cartRaw = payload.cart ?? (payload.raw && payload.raw.cart) ?? null;
      const paymentsRaw = payload.payments ?? (payload.raw && payload.raw.payments) ?? null;
      const customerRaw = payload.customer ?? (payload.raw && payload.raw.customer) ?? null;
      const cartHash = await hashValue(cartRaw || payload.raw || bodyStr || `${action}-${Date.now()}`);
      const parts = nowParts();
      const dayFolder = dayFolderFromParts(parts);
      const createdAt = Date.now();
      const key = `csfx_${createdAt}_${Math.random().toString(16).slice(2, 8)}`;
      const pendingFileName = `pending_${parts.H}-${parts.M}-${parts.S}_${cartHash.slice(0, 8)}.json`;
      const dev = await ensureDeviceId();

      const doc = {
        key,
        status: 'pending',
        action,
        deviceId: dev || null,
        ref: `CSFX-${dev || 'nodev'}-${parts.y}${parts.m}${parts.d}-${parts.H}${parts.M}${parts.S}-pending`,
        dayFolder,
        createdAt,
        createdAtText: new Date(createdAt).toISOString(),
        createdAtParts: parts,
        cartHash,
        cart: deepClone(cartRaw),
        payments: deepClone(paymentsRaw),
        customer: deepClone(customerRaw),
        totals: deepClone((cartRaw && cartRaw.totals) || (payload.raw && payload.raw.totals) || null),
        rawRequest: {
          url: url,
          method,
          action,
          body: bodyStr,
          restful: RESTFUL_ENABLED && url.toLowerCase().includes('/wp-json/op/v1/')
        },
        rawResponse: null,
        orderNumber: null,
        orderId: null,
        pendingFileName,
        fileName: pendingFileName,
        version: '1.1.0'
      };

      await putOrder(doc);
      updateExportButton(doc);
      window.CSFX_LAST_DOC = doc;
      await writeOrderDoc(doc, { pending: true });
      pruneOrders(MAX_RECORDS).catch(() => {});
      log('Checkout detectado', action, url);

      return { docKey: key, action, cartHash };
    } catch (err) {
      log('createPendingContext error', err);
      return null;
    }
  }

  async function finalizeCheckout(ctx, responseText) {
    if (!ctx) return;
    try {
      const doc = await getOrder(ctx.docKey);
      if (!doc) return;

      if (doc.status === 'confirmed' && doc.orderNumber && inRecentGate(doc.orderNumber)) {
        log('Respuesta duplicada ignorada', doc.orderNumber);
        return;
      }

      let payload = null;
      try {
        payload = JSON.parse(responseText);
      } catch {
        payload = null;
      }

      const orderNumber = extractOrderNumber(payload);
      const orderId = extractOrderId(payload);
      const totals = extractTotals(payload);

      doc.status = 'confirmed';
      doc.orderNumber = orderNumber || doc.orderNumber;
      doc.orderId = orderId || doc.orderId;
      doc.rawResponse = payload ?? { text: responseText };
      if (totals && !doc.totals) {
        doc.totals = deepClone(totals);
      }

      const parts = doc.createdAtParts || nowParts();
      const dev = doc.deviceId || (await ensureDeviceId()) || 'nodev';
      const orderRef = doc.orderNumber || doc.orderId || (ctx.cartHash ? ctx.cartHash.slice(0, 8) : 'unknown');
      doc.ref = `CSFX-${dev}-${parts.y}${parts.m}${parts.d}-${parts.H}${parts.M}${parts.S}-${orderRef}`;
      doc.fileName = `${parts.H}-${parts.M}-${parts.S}_${orderRef}.json`;

      await putOrder(doc);
      window.CSFX_LAST_DOC = doc;
      updateExportButton(doc);
      await deleteFileIfExists(doc.dayFolder || dayFolderFromParts(parts), doc.pendingFileName);
      await writeOrderDoc(doc, { pending: false });
      pruneOrders(MAX_RECORDS).catch(() => {});
      if (doc.orderNumber) {
        inRecentGate(doc.orderNumber);
      }
      log('Checkout confirmado', doc.orderNumber || doc.orderId || 'sin número');
    } catch (err) {
      log('finalizeCheckout error', err);
    }
  }

  function setupUI() {
    const box = document.createElement('div');
    box.style.cssText = 'position:fixed;left:14px;bottom:14px;z-index:2147483647;background:#111;color:#fff;font:12px system-ui;padding:10px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.25);max-width:240px';
    box.innerHTML = `
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
        <div id="csfx-dot" style="width:10px;height:10px;border-radius:50%;background:#e74c3c"></div>
        <strong>Respaldo local</strong>
        <span id="csfx-status" style="opacity:.7;margin-left:auto">sin carpeta</span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button id="csfx-choose" style="flex:1 1 100%;padding:6px 10px;border:0;border-radius:6px;background:#09f;color:#fff;cursor:pointer">Seleccionar carpeta…</button>
        <button id="csfx-change" style="flex:1 1 48%;padding:6px 10px;border:0;border-radius:6px;background:#555;color:#fff;cursor:pointer">Cambiar carpeta…</button>
        <button id="csfx-export" style="flex:1 1 48%;padding:6px 10px;border:0;border-radius:6px;background:#1abc9c;color:#fff;cursor:pointer">Exportar último</button>
      </div>
    `;
    document.body.appendChild(box);

    const dot = box.querySelector('#csfx-dot');
    const status = box.querySelector('#csfx-status');
    const chooseBtn = box.querySelector('#csfx-choose');
    const changeBtn = box.querySelector('#csfx-change');
    const exportBtn = box.querySelector('#csfx-export');

    const hasFSAccess = 'showDirectoryPicker' in window;
    const secureContext = window.isSecureContext;
    log('File System Access soportado:', hasFSAccess, 'Secure context:', secureContext);
    if (!hasFSAccess) {
      let message = 'sin FS Access';
      if (!secureContext) {
        message += ' (requiere HTTPS o localhost)';
        if (window.CSFX_DEBUG) {
          console.warn('[CSFX] File System Access requiere HTTPS/localhost.', { origin: window.location.origin, secureContext });
        }
      } else {
        if (window.CSFX_DEBUG) {
          console.warn('[CSFX] File System Access no disponible en este navegador.', { userAgent: navigator.userAgent });
        }
      }
      status.textContent = message;
    }

    chooseBtn.addEventListener('click', async () => {
      log('Click seleccionar carpeta');
      if (!('showDirectoryPicker' in window)) {
        log('No se puede seleccionar carpeta: File System Access no soportado');
        if (window.CSFX_DEBUG) {
          console.warn('[CSFX] Este navegador no soporta File System Access. Usa Chrome/Edge en HTTPS.');
        }
        updateUIState(false, 'sin FS Access');
        return;
      }
      const ok = await ensureRoot(true);
      if (ok) {
        await ensureDeviceId();
        updateUIState(true, 'carpeta lista');
      } else {
        updateUIState(false, 'sin carpeta');
      }
    });

    changeBtn.addEventListener('click', async () => {
      log('Click cambiar carpeta');
      if (!('showDirectoryPicker' in window)) {
        log('No se puede cambiar carpeta: File System Access no soportado');
        if (window.CSFX_DEBUG) {
          console.warn('[CSFX] Este navegador no soporta File System Access. Usa Chrome/Edge en HTTPS.');
        }
        return;
      }
      await deleteHandle('backupRoot');
      fsRoot = null;
      deviceId = null;
      updateUIState(false, 'sin carpeta');
    });

    exportBtn.addEventListener('click', () => {
      log('Click exportar último', !!window.CSFX_LAST_DOC);
      const doc = window.CSFX_LAST_DOC;
      if (!doc) {
        console.warn('[CSFX] No hay respaldo reciente para exportar.');
        return;
      }
      const parts = doc.createdAtParts || nowParts();
      const name = `${parts.H}-${parts.M}-${parts.S}_${doc.orderNumber || doc.orderId || 'orden'}.json`;
      const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' });
      downloadBlob(blob, name);
    });

    return {
      setState(granted, text) {
        dot.style.background = granted ? '#2ecc71' : '#e74c3c';
        status.textContent = text || (granted ? 'carpeta lista' : 'sin carpeta');
        changeBtn.disabled = !granted || !('showDirectoryPicker' in window);
      },
      setExport(doc) {
        exportBtn.disabled = !doc;
      }
    };
  }

  window.CSFX = window.CSFX || {};
  if (typeof window.CSFX_DEBUG === 'undefined') {
    window.CSFX_DEBUG = false;
  }

  log('CSFX Local Backup init', { actions: CHECKOUT_ACTIONS, restful: RESTFUL_ENABLED });

  uiControls = setupUI();
  updateExportButton(window.CSFX_LAST_DOC || null);

  persistStorage();
  refreshHandleStatus();

  const nativeFetch = window.fetch;
  window.fetch = function(input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const method = (init && init.method) || (input && input.method) || 'GET';
    const upperMethod = String(method || 'GET').toUpperCase();

    const bodyPromise = upperMethod === 'POST'
      ? resolveRequestBody(input, init).catch(err => {
          log('resolve body failed', err);
          return '';
        })
      : Promise.resolve('');

    const ctxPromise = upperMethod === 'POST'
      ? bodyPromise.then(bodyPayload => createPendingContext(url, upperMethod, bodyPayload)).catch(err => {
          log('fetch pending error', err);
          return null;
        })
      : null;

    const responsePromise = nativeFetch.apply(this, arguments);

    if (!ctxPromise) {
      return responsePromise;
    }

    return responsePromise.then(response => {
      ctxPromise.then(ctx => {
        if (!ctx) return;
        try {
          response.clone().text().then(text => finalizeCheckout(ctx, text));
        } catch (err) {
          log('fetch response clone error', err);
        }
      });
      return response;
    });
  };

  (function patchXHR() {
    const XHR = window.XMLHttpRequest;
    if (!XHR) return;
    const open = XHR.prototype.open;
    const send = XHR.prototype.send;

    XHR.prototype.open = function(method, url) {
      this.__csfxMethod = method;
      this.__csfxUrl = url;
      return open.apply(this, arguments);
    };

    XHR.prototype.send = function(body) {
      const method = String(this.__csfxMethod || 'GET').toUpperCase();
      if (method === 'POST') {
        const url = this.__csfxUrl || '';
        const prepare = createPendingContext(url, method, body).catch(err => {
          log('xhr pending error', err);
          return null;
        });
        this.addEventListener('loadend', function() {
          prepare.then(ctx => {
            if (!ctx) return;
            try {
              finalizeCheckout(ctx, this.responseText);
            } catch (err) {
              log('xhr finalize error', err);
            }
          });
        });
      }
      return send.apply(this, arguments);
    };
  })();
})();
