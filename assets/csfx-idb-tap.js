(() => {
  if (window.__CSFX_IDB_TAP__) return;
  window.__CSFX_IDB_TAP__ = true;

  // Shim para interceptar guardados en IndexedDB (store "orders") de OpenPOS.
  window.CSFX_IDB_TAP_ACTIVE = true;
  if (!('indexedDB' in window) || !('IDBObjectStore' in window)) return;

  var StoreProto = window.IDBObjectStore && window.IDBObjectStore.prototype;
  if (!StoreProto) return;

  function isOrdersStoreName(name) {
    return name === 'orders';
  }

  function shouldLogStoreNames(storeNames) {
    if (!window || !window.CSFX_DEBUG) return false;
    if (!storeNames) return false;
    if (typeof storeNames === 'string') {
      return isOrdersStoreName(storeNames);
    }
    if (Array.isArray(storeNames)) {
      return storeNames.indexOf('orders') !== -1;
    }
    if (typeof storeNames.contains === 'function') {
      try {
        return storeNames.contains('orders');
      } catch (err) {
        return false;
      }
    }
    if (typeof storeNames.length === 'number') {
      for (var i = 0; i < storeNames.length; i++) {
        if (isOrdersStoreName(storeNames[i])) return true;
      }
    }
    return false;
  }

  var DBProto = window.IDBDatabase && window.IDBDatabase.prototype;
  if (DBProto && !DBProto.__csfxWrapped) {
    var txOriginal = DBProto.transaction;
    DBProto.transaction = function(storeNames) {
      var logTx = shouldLogStoreNames(storeNames);
      if (logTx) {
        debugLog('[CSFX][IDB tap] transaction abierta', storeNames);
      }
      var tx = txOriginal.apply(this, arguments);
      if (tx && typeof tx.objectStore === 'function') {
        var osOrig = tx.objectStore;
        tx.objectStore = function(name) {
          if (shouldLogStoreNames(name)) {
            debugLog('[CSFX][IDB tap] objectStore solicitado', name);
          }
          var store = osOrig.apply(this, arguments);
          return store;
        };
      }
      return tx;
    };
    DBProto.__csfxWrapped = true;
  }

  function debugLog() {
    if (window && window.CSFX_DEBUG && typeof console !== 'undefined') {
      console.log.apply(console, arguments);
    }
  }

  function tryExtractOrder(data) {
    var candidate = data && (data.order || data.data || data) || null;
    var looksLikeOrder = candidate && (
      Array.isArray(candidate.items) ||
      candidate.cart ||
      candidate.totals ||
      candidate.payment_method ||
      candidate.payments ||
      candidate.customer ||
      candidate.order_number ||
      candidate.order_id
    );
    return looksLikeOrder ? candidate : null;
  }

  function isOwnOrder(candidate) {
    if (!candidate || typeof candidate !== 'object') return false;
    if (candidate.action === 'offline-idb') return true;
    if (candidate.ref && typeof candidate.ref === 'string' && candidate.ref.indexOf('CSFX-') === 0) return true;
    if (candidate.key && typeof candidate.key === 'string' && candidate.key.indexOf('csfx_') === 0) return true;
    if (candidate.rawRequest && candidate.rawRequest.action === 'offline-idb') return true;
    return false;
  }

  function fire(order) {
    debugLog('[CSFX][IDB tap] offline order detectada', order && (order.order_number || order.order_number_format || order.order_id || order.id) || order);

    try {
      document.dispatchEvent(new CustomEvent('csfx.offline.order.saved', { detail: { order: order } }));
    } catch (err) {}

    try {
      if (typeof window.csfxPersistPending === 'function') {
        window.csfxPersistPending(order, { thin: true, writePendingFs: 'offline-only' });
      }
    } catch (err) {}
  }

  function wrap(methodName) {
    var original = StoreProto[methodName];
    if (typeof original !== 'function') return;

    StoreProto[methodName] = function () {
      try {
        if (this && this.name === 'orders') {
          var order = tryExtractOrder(arguments[0]);
          if (order && isOwnOrder(order)) {
            debugLog('[CSFX][IDB tap] store orders ignorada (doc CSFX)', methodName, order && (order.ref || order.key || order.order_number || order.order_id) || order);
          } else if (order) {
            fire(order);
          } else if (isOrdersStoreName(this && this.name)) {
            debugLog('[CSFX][IDB tap] store orders interceptada pero sin order reconocible', methodName, arguments[0]);
          }
        }
      } catch (err) {}
      return original.apply(this, arguments);
    };
  }

  wrap('add');
  wrap('put');
})();
