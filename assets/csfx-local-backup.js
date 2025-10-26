(() => {
  if (window.__CSFX_LB_INIT__) {
    console.debug('[CSFX] skip duplicate');
    return;
  }
  window.__CSFX_LB_INIT__ = true;

  const SETTINGS = window.CSFX_LB_SETTINGS || {};
  const CHECKOUT_ACTIONS = Array.isArray(SETTINGS.checkout_actions) ? SETTINGS.checkout_actions.map(String) : [];
  const RESTFUL_ENABLED = !!SETTINGS.restful_enabled;
  const DB_NAME = 'csfx-orders';
  const DB_VERSION = 2;
  const STORE_ORDERS = 'orders';
  const STORE_HANDLES = 'handles';
  const MAX_RECORDS = 500;
  const CHECKOUT_ACTION_SET = new Set(CHECKOUT_ACTIONS);

  let fsRoot = null;
  let deviceId = null;
  let csfxLastCart = null;
  const recentMap = new Map();
  let lastPendingOmitKey = null;
  let lastPendingLogKey = null;
  const isDebug = () => !!window.CSFX_DEBUG;
  const STRICT_ENDPOINTS = SETTINGS.strict_endpoints !== false;
  const REQUIRE_BACKUP_FOLDER = (SETTINGS.require_backup_folder !== false);
  const REQUIRE_BACKUP_FS = SETTINGS.require_backup_fs === true;
  const ENFORCE_BACKUP = REQUIRE_BACKUP_FOLDER || REQUIRE_BACKUP_FS;
  const WRITE_PENDING_MODE = typeof SETTINGS.write_pending_fs === 'string' ? SETTINGS.write_pending_fs : 'always';
  const PRUNE_EVERY = Number.isFinite(Number(SETTINGS.prune_every_n_puts)) ? Math.max(1, Number(SETTINGS.prune_every_n_puts)) : 50;
  const EVENT_DEBOUNCE_MS = Number.isFinite(Number(SETTINGS.debounce_ms)) ? Math.max(0, Number(SETTINGS.debounce_ms)) : 180;
  const thinEventTimers = new Map();
  let putsSincePrune = 0;
  let FOLDER_GRANTED = false;

  function normalizePaymentsCollection(payments) {
    const result = [];
    if (!payments) return result;

    const isPaymentLike = obj => {
      if (!obj || typeof obj !== 'object') return false;
      const code =
        obj.code ||
        obj.payment_code ||
        obj.paymentCode ||
        obj.method ||
        obj.type ||
        obj.id ||
        null;
      if (!code) return false;
      const hasPaymentData =
        'paid' in obj ||
        'paid_currency_formatted' in obj ||
        'amount' in obj ||
        'return' in obj ||
        'offline_order' in obj ||
        'allow_refund' in obj ||
        'allow_zero' in obj ||
        'name' in obj ||
        'payment_ref' in obj ||
        'fee' in obj;
      return hasPaymentData;
    };

    const push = entry => {
      if (!entry) return;
      if (typeof entry === 'string') {
        const code = entry.trim();
        if (!code) return;
        result.push({
          code,
          name: code,
          currency: null,
          paid: null,
          return: null
        });
        return;
      }
      if (Array.isArray(entry)) {
        entry.forEach(push);
        return;
      }
      if (typeof entry === 'object') {
        if (isPaymentLike(entry)) {
          try {
            result.push(JSON.parse(JSON.stringify(entry)));
          } catch {
            result.push(entry);
          }
          return;
        }
        Object.keys(entry).forEach(key => push(entry[key]));
        return;
      }
    };

    push(payments);
    return result.filter(item => item && typeof item === 'object' && item.code);
  }

  function cloneLight(value) {
    if (value === undefined || value === null) return null;
    if (typeof structuredClone === 'function') {
      try {
        return structuredClone(value);
      } catch (err) {}
    }
    try {
      return JSON.parse(JSON.stringify(value));
    } catch (err) {
      return null;
    }
  }

  function compactCart(cart) {
    if (!cart) return null;
    const list = Array.isArray(cart.items) ? cart.items : (Array.isArray(cart) ? cart : null);
    if (!list || !list.length) return null;
    return list.map(item => ({
      id: item?.id ?? item?.item_id ?? item?.product_id ?? null,
      sku: item?.sku ?? item?.barcode ?? item?.product?.sku ?? null,
      name: item?.name ?? item?.product?.name ?? '',
      qty: item?.qty ?? item?.cart_qty ?? item?.quantity ?? 0,
      price: item?.final_price ?? item?.price ?? item?.price_incl_tax ?? null,
      total: item?.total ?? item?.total_incl_tax ?? item?.final_price ?? null,
      discount: item?.discount ?? item?.final_discount_amount ?? item?.discount_amount ?? 0
    }));
  }

  function compactPayments(payments) {
    const normalized = normalizePaymentsCollection(payments);
    if (!normalized.length) return null;
    return normalized.map(p => ({
      code: p.code || null,
      name: p.name || null,
      amount: p.paid ?? p.amount ?? null,
      change: p.return ?? null,
      type: p.type ?? p.method ?? null,
      ref: p.ref ?? p.reference ?? p.payment_ref ?? '',
      currency: p.currency
        ? {
            code: p.currency.code || null,
            symbol: p.currency.symbol || null,
            decimal_separator: p.currency.decimal_separator ?? null,
            thousand_separator: p.currency.thousand_separator ?? null
          }
        : null
    }));
  }

  function compactTotals(totals) {
    if (!totals || typeof totals !== 'object') return null;
    const thin = {
      grand_total: totals.grand_total ?? null,
      total_paid: totals.total_paid ?? null,
      remain_paid: totals.remain_paid ?? null,
      sub_total: totals.sub_total ?? null,
      currency: totals.currency ?? null
    };
    if (
      thin.grand_total == null &&
      thin.total_paid == null &&
      thin.remain_paid == null &&
      thin.sub_total == null
    ) {
      return null;
    }
    return thin;
  }

  function compactCustomer(customer) {
    if (!customer || typeof customer !== 'object') return null;
    const fullName = [customer.firstname, customer.lastname].filter(Boolean).join(' ').trim();
    const thin = {
      id: customer.id ?? customer.customer_id ?? null,
      name: customer.name ?? (fullName || null),
      email: customer.email ?? null,
      phone: customer.phone ?? customer.telephone ?? null
    };
    const hasValue = Object.values(thin).some(value => value);
    return hasValue ? thin : null;
  }

  function sumItemsTotal(cart) {
    const list = Array.isArray(cart) ? cart : (cart && Array.isArray(cart.items) ? cart.items : null);
    if (!list || !list.length) return null;
    let total = 0;
    let hasValue = false;
    for (const item of list) {
      const value = Number(item?.total ?? item?.total_incl_tax ?? item?.final_price ?? item?.price ?? 0);
      if (Number.isFinite(value)) {
        total += value;
        hasValue = true;
      }
    }
    return hasValue ? total : null;
  }

  function sumItemsDiscount(cart) {
    const list = Array.isArray(cart) ? cart : (cart && Array.isArray(cart.items) ? cart.items : null);
    if (!list || !list.length) return null;
    let total = 0;
    let hasValue = false;
    for (const item of list) {
      const value = Number(
        item?.discount ??
        item?.final_discount_amount ??
        item?.discount_amount ??
        ((item?.qty ?? item?.quantity ?? 0) * (item?.price ?? item?.price_incl_tax ?? 0) - (item?.total ?? item?.total_incl_tax ?? item?.final_price ?? 0))
      );
      if (Number.isFinite(value) && value !== 0) {
        total += value;
        hasValue = true;
      }
    }
    return hasValue ? total : null;
  }

  function sumPaymentsAmount(payments) {
    if (!Array.isArray(payments) || !payments.length) return null;
    let total = 0;
    let hasValue = false;
    for (const payment of payments) {
      const value = Number(payment?.amount ?? payment?.paid ?? payment?.total ?? payment?.in_amount ?? 0);
      if (Number.isFinite(value)) {
        total += value;
        hasValue = true;
      }
    }
    return hasValue ? total : null;
  }

  function ensureThinTotals(totals, cart, payments) {
    const currency = totals && typeof totals === 'object' ? totals.currency || null : null;
    const discountFromTotals =
      totals && typeof totals === 'object'
        ? (totals.discount_amount ?? totals.total_discount ?? null)
        : null;
    const hasTotals =
      totals &&
      (totals.grand_total != null || totals.total_paid != null || totals.sub_total != null || totals.remain_paid != null);
    if (hasTotals) {
      return {
        ...totals,
        discount_amount: discountFromTotals ?? sumItemsDiscount(cart) ?? null,
        currency: currency || (totals && totals.currency) || null
      };
    }
    const computedCart = sumItemsTotal(cart);
    const computedPayments = sumPaymentsAmount(payments);
    const computedDiscount = sumItemsDiscount(cart);
    if (computedCart == null && computedPayments == null) {
      return totals || null;
    }
    return {
      grand_total: computedCart ?? computedPayments ?? null,
      total_paid: computedPayments ?? null,
      remain_paid: null,
      sub_total: computedCart ?? null,
      discount_amount: computedDiscount ?? null,
      currency: currency || null
    };
  }

  function buildThinTotalsFromOrderData(od) {
    if (!od || typeof od !== 'object') return null;
    const currency = {
      sub_total: od.sub_total_currency_formatted ?? null,
      tax_amount: od.tax_amount_currency_formatted ?? null,
      discount_amount: od.discount_amount_currency_formatted ?? od.total_discount_currency_formatted ?? null,
      grand_total: od.grand_total_currency_formatted ?? null,
      total_paid: od.total_paid_currency_formatted ?? null,
      remain_paid: od.remain_paid_currency_formatted ?? null
    };
    const thin = {
      sub_total: od.sub_total ?? od.subtotal ?? null,
      grand_total: od.grand_total ?? null,
      total_paid: od.total_paid ?? null,
      remain_paid: od.remain_paid ?? null,
      discount_amount: od.discount_amount ?? od.total_discount ?? null,
      currency
    };
    return Object.values(thin).some(value => value != null && value !== '') ? thin : null;
  }

  function createBackupRequiredError() {
    if (typeof DOMException === 'function') {
      try {
        return new DOMException('CSFX_BACKUP_FOLDER_REQUIRED', 'AbortError');
      } catch (err) {}
    }
    const error = new Error('CSFX_BACKUP_FOLDER_REQUIRED');
    error.name = 'AbortError';
    return error;
  }

  function deriveOrderIdentifiers(source) {
    if (!source || typeof source !== 'object') {
      return { orderNumber: null, orderId: null, orderLocalId: null };
    }
    const data = source.data || source.order || source || {};
    const orderNumber =
      data.order_number ??
      data.order_number_format ??
      source.order_number ??
      source.order_number_format ??
      null;
    const orderId =
      data.order_id ??
      data.id ??
      source.order_id ??
      source.id ??
      null;
    const orderLocalId =
      source.order_local_id ??
      source.local_id ??
      data.order_local_id ??
      data.local_id ??
      null;
    return { orderNumber: orderNumber || null, orderId: orderId ?? null, orderLocalId: orderLocalId ?? null };
  }

  function pullCartSource(detail) {
    if (!detail) return csfxLastCart || window?.CSFX?.cartSnapshot || null;
    if (detail.cart) return detail.cart;
    if (Array.isArray(detail.items)) return detail.items;
    const data = detail.data || detail.order || null;
    if (data && data.cart) return data.cart;
    if (data && Array.isArray(data.items)) return data.items;
    return csfxLastCart || window?.CSFX?.cartSnapshot || null;
  }

  function pullPaymentsSource(detail) {
    if (!detail) return window?.CSFX?.lastCheckoutPayments || null;
    if (detail.payments) return detail.payments;
    if (detail.payment) return detail.payment;
    const data = detail.data || detail.order || null;
    if (data && data.payments) return data.payments;
    if (data && data.payment_method) return data.payment_method;
    return window?.CSFX?.lastCheckoutPayments || null;
  }

  function pullTotalsSource(detail) {
    if (!detail) return window?.CSFX?.lastCheckoutTotals || null;
    if (detail.totals) return detail.totals;
    const data = detail.data || detail.order || null;
    if (data && data.totals) return data.totals;
    const cart = pullCartSource(detail);
    if (cart && cart.totals) return cart.totals;
    return window?.CSFX?.lastCheckoutTotals || null;
  }

  function pullCustomerSource(detail) {
    if (!detail) return window?.CSFX?.lastCheckoutCustomer || null;
    if (detail.customer) return detail.customer;
    const data = detail.data || detail.order || null;
    if (data && data.customer) return data.customer;
    const cart = pullCartSource(detail);
    if (cart && cart.customer) return cart.customer;
    return window?.CSFX?.lastCheckoutCustomer || null;
  }

  function normalizeCashierValue(value) {
    if (value === null || value === undefined) return null;
    const text = String(value).trim();
    return text || null;
  }

  const DEFAULT_CASHIER = normalizeCashierValue(
    SETTINGS.staff_login ||
    SETTINGS.staff_username ||
    SETTINGS.staff_display ||
    SETTINGS.staff_email ||
    null
  );
  try {
    if (DEFAULT_CASHIER) {
      localStorage.setItem('csfx_lb_staff_login', DEFAULT_CASHIER);
    }
  } catch (err) {
    if (isDebug()) log('localStorage staff_login error', err);
  }

  function resolveCashierFromDetail(detail) {
    if (!detail) return null;
    const data = detail.data || detail.order || {};
    const user = detail.user || data.user || {};
    const userName = normalizeCashierValue(user.username || user.user_login || user.login || user.name || user.email || user.id);
    const saleUsername = normalizeCashierValue(detail.sale_person_username || data.sale_person_username);
    const cashierField = normalizeCashierValue(detail.cashier || detail.cashier_name || data.cashier || data.cashier_name);
    const saleName = normalizeCashierValue(detail.sale_person_name || data.sale_person_name);
    return userName || saleUsername || cashierField || saleName || DEFAULT_CASHIER;
  }

  function resolveCashierFromOrderData(orderData) {
    if (!orderData || typeof orderData !== 'object') return null;
    const user = orderData.user || orderData.operator || {};
    const userName = normalizeCashierValue(user.username || user.user_login || user.login || user.name || user.email || user.id);
    const saleUsername = normalizeCashierValue(orderData.sale_person_username || orderData.salesperson_username);
    const saleName = normalizeCashierValue(orderData.sale_person_name || orderData.salesperson || orderData.cashier_name);
    const cashierField = normalizeCashierValue(orderData.cashier || orderData.cashier_code);
    return userName || saleUsername || cashierField || saleName || DEFAULT_CASHIER;
  }

  function refreshSnapshots(detail) {
    const cartSource = pullCartSource(detail);
    if (cartSource) {
      const clonedCart = cloneLight(cartSource) || {};
      const resolvedCashier =
        resolveCashierFromDetail(detail) ||
        clonedCart.cashier ||
        clonedCart.salesperson ||
        window.CSFX.lastCheckoutCashier ||
        DEFAULT_CASHIER;
      if (resolvedCashier) {
        if (typeof clonedCart === 'object') {
          clonedCart.cashier = clonedCart.cashier || resolvedCashier;
          clonedCart.salesperson = clonedCart.salesperson || resolvedCashier;
        }
        window.CSFX.lastCheckoutCashier = resolvedCashier;
      }
      const detailRegisterName = detail?.register?.name || detail?.data?.register?.name || null;
      if (detailRegisterName) {
        window.CSFX.lastCheckoutRegister = detailRegisterName;
        if (typeof clonedCart === 'object') {
          clonedCart.register = clonedCart.register || {};
          try {
            if (typeof clonedCart.register === 'object') {
              clonedCart.register.name = clonedCart.register.name || detailRegisterName;
            }
          } catch (err) {}
        }
      }
      window.CSFX.lastCheckoutSnapshot = clonedCart;
    }
    const paymentsSource = pullPaymentsSource(detail);
    if (paymentsSource) {
      window.CSFX.lastCheckoutPayments = cloneLight(paymentsSource);
    }
    const totalsSource = pullTotalsSource(detail);
    if (totalsSource) {
      window.CSFX.lastCheckoutTotals = cloneLight(totalsSource);
    }
    const customerSource = pullCustomerSource(detail);
    if (customerSource) {
      window.CSFX.lastCheckoutCustomer = cloneLight(customerSource);
    }
    if (!window.CSFX.lastCheckoutCashier) {
      const fallbackCashier = resolveCashierFromDetail(detail) || DEFAULT_CASHIER;
      if (fallbackCashier) {
        window.CSFX.lastCheckoutCashier = fallbackCashier;
      }
    }
    if (!window.CSFX.lastCheckoutRegister && (detail?.register?.name || detail?.data?.register?.name)) {
      window.CSFX.lastCheckoutRegister = detail?.register?.name || detail?.data?.register?.name || null;
    }
  }

  function buildThinSnapshotFromDetail(source, detail) {
    const identifiers = deriveOrderIdentifiers(detail);
    const cartSource = pullCartSource(detail);
    const paymentsSource = pullPaymentsSource(detail);
    const totalsSource = pullTotalsSource(detail);
    const customerSource = pullCustomerSource(detail);
    const cashierName =
      resolveCashierFromDetail(detail) ||
      cartSource?.cashier ||
      cartSource?.salesperson ||
      window.CSFX.lastCheckoutCashier ||
      DEFAULT_CASHIER;
    const registerName =
      detail?.register?.name ||
      detail?.data?.register?.name ||
      window.CSFX.lastCheckoutRegister ||
      null;
    const cartItems = compactCart(cartSource);
    const cartThin = cartItems ? { items: cartItems } : null;
    if (cartThin && cashierName) {
      try {
        cartThin.cashier = cartThin.cashier || cashierName;
        cartThin.salesperson = cartThin.salesperson || cashierName;
      } catch (err) {}
    }
    if (cartThin && registerName) {
      if (!cartThin.register || typeof cartThin.register !== 'object') {
        cartThin.register = { name: registerName };
      } else if (!cartThin.register.name) {
        cartThin.register.name = registerName;
      }
    }
    return {
      cart: cartThin,
      payments: compactPayments(paymentsSource),
      totals: compactTotals(totalsSource),
      customer: compactCustomer(customerSource),
      orderNumber: identifiers.orderNumber,
      orderId: identifiers.orderId,
      orderLocalId: identifiers.orderLocalId,
      eventName: source,
      eventDetail: null,
      amount: detail?.amount ?? detail?.data?.amount ?? null,
      session: detail?.session ?? detail?.data?.session ?? null,
      cashier: cashierName,
      registerName
    };
  }

  function buildThinSnapshotFromMemory(source) {
    const identifiers = deriveOrderIdentifiers(window?.CSFX?.lastCheckoutSnapshot || {});
    const cashierName =
      window?.CSFX?.lastCheckoutCashier ||
      (window?.CSFX?.lastCheckoutSnapshot && (window.CSFX.lastCheckoutSnapshot.cashier || window.CSFX.lastCheckoutSnapshot.salesperson)) ||
      DEFAULT_CASHIER;
    const registerName =
      window?.CSFX?.lastCheckoutRegister ||
      (window?.CSFX?.lastCheckoutSnapshot && window.CSFX.lastCheckoutSnapshot.register && window.CSFX.lastCheckoutSnapshot.register.name) ||
      null;
    const sourceCart = window?.CSFX?.lastCheckoutSnapshot || window?.CSFX?.cartSnapshot || csfxLastCart || null;
    const cartItems = compactCart(sourceCart);
    const cartThin = cartItems ? { items: cartItems } : null;
    if (cartThin && cashierName) {
      cartThin.cashier = cartThin.cashier || cashierName;
      cartThin.salesperson = cartThin.salesperson || cashierName;
    }
    if (cartThin && registerName) {
      if (!cartThin.register || typeof cartThin.register !== 'object') {
        cartThin.register = { name: registerName };
      } else if (!cartThin.register.name) {
        cartThin.register.name = registerName;
      }
    }
    return {
      cart: cartThin,
      payments: compactPayments(window?.CSFX?.lastCheckoutPayments || null),
      totals: compactTotals(window?.CSFX?.lastCheckoutTotals || null),
      customer: compactCustomer(window?.CSFX?.lastCheckoutCustomer || null),
      orderNumber: identifiers.orderNumber,
      orderId: identifiers.orderId,
      orderLocalId: identifiers.orderLocalId,
      eventName: source,
      eventDetail: null,
      amount: null,
      session: null,
      cashier: cashierName,
      registerName
    };
  }

  const THIN_ACTION_MAP = {
    'openpos.start.payment': 'start-payment',
    'openpos.cart.saved': 'cart.saved',
    'openpos.start.refund': 'start-refund',
    'network.stub': 'network.stub'
  };

  function scheduleThinPendingFromEvent(source, detail, persistOptions = {}) {
    const snapshot = buildThinSnapshotFromDetail(source, detail);
    if (!snapshot) return;
    const hasUsefulData = snapshot.cart || snapshot.payments || snapshot.totals || snapshot.customer;
    if (!hasUsefulData) return;
    const key = snapshot.orderLocalId || snapshot.orderNumber || snapshot.orderId || `${source}`;
    const now = Date.now();
    if (thinEventTimers.has(key)) {
      clearTimeout(thinEventTimers.get(key));
    }
    const timer = setTimeout(() => {
      thinEventTimers.delete(key);
      persistThinPending(source, snapshot, persistOptions);
    }, EVENT_DEBOUNCE_MS);
    thinEventTimers.set(key, timer);
  }

  function persistThinPending(source, snapshot, persistOptions = {}) {
    const action = THIN_ACTION_MAP[source] || source;
    persistPendingDoc(action, snapshot, '', `event:${source}`, 'EVENT', {
      thin: true,
      writePendingFs: persistOptions.writePendingFs || 'auto'
    }).catch(err => {
      if (isDebug()) log('persistThinPending error', err);
    });
  }

  function resolveFsMode(options) {
    if (options && typeof options.writePendingFs === 'string') {
      if (options.writePendingFs === 'auto') {
        return WRITE_PENDING_MODE;
      }
      return options.writePendingFs;
    }
    return WRITE_PENDING_MODE;
  }

  function shouldWriteDocForPending(action, status, options) {
    const mode = resolveFsMode(options);
    if (status === 'confirmed') return true;
    if (mode === 'never') return false;
    if (mode === 'always') return true;
    if (mode === 'offline-only') return action === 'offline-idb';
    // default / auto
    if (mode === 'offline-first') {
      return action === 'offline-idb';
    }
    return action === 'offline-idb';
  }

  function maybePruneLater() {
    putsSincePrune += 1;
    if (putsSincePrune < PRUNE_EVERY) return;
    putsSincePrune = 0;
    const task = () => pruneOrders(MAX_RECORDS).catch(() => {});
    if (typeof requestIdleCallback === 'function') {
      requestIdleCallback(task);
    } else {
      setTimeout(task, 0);
    }
  }

  async function persistThinDoc(action, payload, url, method, options = {}) {
    const identifiers = {
      orderNumber: payload.orderNumber || null,
      orderId: payload.orderId || null,
      orderLocalId: payload.orderLocalId || null
    };
    const cartHash = payload.orderLocalId
      ? `lid:${payload.orderLocalId}`
      : payload.orderNumber
        ? `num:${payload.orderNumber}`
        : null;
    const restfulMeta = RESTFUL_ENABLED && typeof url === 'string' && url.toLowerCase().includes('/wp-json/op/v1/');
    const thinCart = payload.cart ? cloneLight(payload.cart) : null;
    if (thinCart && payload.cashier) {
      try {
        thinCart.cashier = thinCart.cashier || payload.cashier;
        thinCart.salesperson = thinCart.salesperson || payload.cashier;
      } catch (err) {}
    }
    if (thinCart && payload.registerName) {
      try {
        if (!thinCart.register || typeof thinCart.register !== 'object') {
          thinCart.register = { name: payload.registerName };
        } else if (!thinCart.register.name) {
          thinCart.register.name = payload.registerName;
        }
      } catch (err) {}
    }
    const thinPayments = payload.payments ? cloneLight(payload.payments) : null;
    let thinTotals = payload.totals ? cloneLight(payload.totals) : null;
    thinTotals = ensureThinTotals(thinTotals, thinCart, thinPayments);
    const thinCustomer = payload.customer ? cloneLight(payload.customer) : null;
    let doc = await findExistingDocByIdentifiers(identifiers, cartHash);
    const now = Date.now();
    const parts = nowParts();
    const dayFolder = dayFolderFromParts(parts);
    const dev = await ensureDeviceId();
    if (doc) {
      const updated = { ...doc };
      updated.lastAction = action;
      updated.action = action;
      updated.updatedAt = now;
      updated.updatedAtText = new Date(now).toISOString();
      updated.cartHash = cartHash || updated.cartHash || null;
      if (thinCart) updated.cart = thinCart;
      if (thinPayments) updated.payments = thinPayments;
      if (thinTotals) updated.totals = thinTotals;
      if (thinCustomer) updated.customer = thinCustomer;
      if (payload.cashier) updated.cashier = payload.cashier;
      if (payload.registerName) updated.registerName = payload.registerName;
      if (payload.orderNumber) updated.orderNumber = payload.orderNumber;
      if (payload.orderId) updated.orderId = payload.orderId;
      if (payload.orderLocalId) updated.orderLocalId = payload.orderLocalId;
      if (!updated.rawRequest) updated.rawRequest = {};
      updated.rawRequest = {
        ...updated.rawRequest,
        url,
        method,
        action,
        restful: restfulMeta,
        thin: true
      };
      updated.version = '1.2.0';
      await putOrder(updated);
      updateExportButton(updated);
      window.CSFX_LAST_DOC = updated;
      if (shouldWriteDocForPending(action, updated.status, options)) {
        const task = () => writeOrderDoc(updated, { pending: updated.status !== 'confirmed' }).catch(() => {});
        if (typeof requestIdleCallback === 'function') {
          requestIdleCallback(task, { timeout: 1500 });
        } else {
          setTimeout(task, 0);
        }
      }
      maybePruneLater();
      if (isDebug()) log('Thin pending actualizado', { action, orderNumber: updated.orderNumber, orderLocalId: updated.orderLocalId });
      return { docKey: updated.key, action, cartHash: updated.cartHash || cartHash || null };
    }

    const key = `csfx_${now}_${Math.random().toString(16).slice(2, 8)}`;
    const pendingFileName = `pending_${parts.H}-${parts.M}-${parts.S}_${Math.random().toString(16).slice(2, 8)}.json`;
    doc = {
      key,
      status: 'pending',
      action,
      deviceId: dev || null,
      ref: `CSFX-${dev || 'nodev'}-${parts.y}${parts.m}${parts.d}-${parts.H}${parts.M}${parts.S}-pending`,
      dayFolder,
      createdAt: now,
      createdAtText: new Date(now).toISOString(),
      createdAtParts: parts,
      cartHash,
      cart: thinCart,
      payments: thinPayments,
      transactions: null,
      customer: thinCustomer,
      totals: thinTotals,
      cashier: payload.cashier || null,
      registerName: payload.registerName || null,
      rawRequest: {
        url,
        method,
        action,
        restful: restfulMeta,
        thin: true
      },
      rawResponse: null,
      orderNumber: payload.orderNumber || payload.orderId || payload.orderLocalId || null,
      orderId: payload.orderId || payload.orderLocalId || null,
      orderLocalId: payload.orderLocalId || null,
      pendingFileName,
      fileName: pendingFileName,
      version: '1.2.0',
      orderData: null,
      eventName: payload.eventName || null,
      eventDetail: options.storeEventDetail ? cloneLight(payload.eventDetail) : null,
      eventAmount: payload.amount ?? null,
      eventSession: payload.session ?? null,
      deviceRef: dev || null
    };
    await putOrder(doc);
    updateExportButton(doc);
    window.CSFX_LAST_DOC = doc;
    if (shouldWriteDocForPending(action, doc.status, options)) {
      const task = () => writeOrderDoc(doc, { pending: true }).catch(() => {});
      if (typeof requestIdleCallback === 'function') {
        requestIdleCallback(task, { timeout: 1500 });
      } else {
        setTimeout(task, 0);
      }
    }
    maybePruneLater();
    if (isDebug()) log('Thin pending creado', { action, orderNumber: doc.orderNumber, orderLocalId: doc.orderLocalId });
    return { docKey: doc.key, action, cartHash: doc.cartHash || cartHash || null };
  }

  function isCheckoutUrlCandidate(url) {
    if (!url) return false;
    const u = String(url).toLowerCase();
    if (u.includes('/wp-json/op/v1/order/create')) return true;
    if (u.includes('/wp-json/op/v1/transaction/create')) return true;
    if (u.includes('admin-ajax.php') && u.includes('action=openpos')) {
      if (u.includes('pos_action=order') || u.includes('pos_action=transaction')) return true;
    }
    return false;
  }

  window.csfxPersistPending = function(orderData, options) {
    try {
      if (!orderData) return;
      if (isDebug()) {
        log('csfxPersistPending recibido', {
          orderNumber: orderData.order_number || orderData.order_number_format || null,
          orderLocalId: orderData.order_local_id || orderData.local_id || orderData.id || null,
          items: Array.isArray(orderData.items) ? orderData.items.length : null
        });
      }
      const opts = options || {};
      const useThin = opts.thin !== undefined ? !!opts.thin : true;
      const cashierName = resolveCashierFromOrderData(orderData);
      const cartThinItems = compactCart(orderData?.cart || orderData?.items || null);
      const resolvedCashier = cashierName || window?.CSFX?.lastCheckoutCashier || DEFAULT_CASHIER;
      if (resolvedCashier) {
        window.CSFX.lastCheckoutCashier = resolvedCashier;
      }
      const cartThin = cartThinItems
        ? { items: cartThinItems }
        : null;
      if (cartThin && resolvedCashier) {
        cartThin.cashier = resolvedCashier;
        cartThin.salesperson = resolvedCashier;
      }
      const paymentsThin = compactPayments(orderData?.payments || orderData?.payment_method || orderData?.transactions || null);
      const totalsSource = orderData?.totals || buildThinTotalsFromOrderData(orderData);
      const totalsThin = ensureThinTotals(totalsSource, cartThin, paymentsThin);

      const payload = {
        cart: useThin ? cartThin : (orderData.cart ?? orderData.items ?? null),
        payments: useThin ? paymentsThin : (orderData.payments ?? orderData.payment_method ?? orderData.transactions ?? null),
        totals: useThin ? totalsThin : (orderData.totals || totalsSource || null),
        transactions: useThin ? null : (orderData.transactions ?? null),
        customer: orderData?.customer ?? null,
        orderNumber: orderData.order_number || orderData.order_number_format || null,
        orderId: orderData.order_id || orderData.id || null,
        orderLocalId: orderData.order_local_id || orderData.local_id || orderData.id || null,
        cashier: resolvedCashier,
        registerName: orderData?.register?.name || null,
        orderData: useThin ? undefined : orderData,
        eventDetail: useThin ? { source: 'indexeddb' } : { source: 'indexeddb', raw: orderData }
      };

      if (useThin) {
        delete payload.orderData;
      }

      const persistOptions = {
        thin: useThin,
        writePendingFs: opts.writePendingFs || 'always'
      };
      persistPendingDoc('offline-idb', payload, '', 'idb:orders', 'IDB', persistOptions).catch(() => {});
    } catch (err) {
      if (isDebug()) log('csfxPersistPending error', err);
    }
  };

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

  // Mantener snapshot del carrito para reconstruir órdenes si el POST no lo incluye
  window.CSFX = window.CSFX || {};
  window.CSFX.cartSnapshot = window.CSFX.cartSnapshot || null;
  window.CSFX.lastCheckoutSnapshot = window.CSFX.lastCheckoutSnapshot || null;
  window.CSFX.lastCheckoutPayments = window.CSFX.lastCheckoutPayments || null;
  window.CSFX.lastCheckoutTotals = window.CSFX.lastCheckoutTotals || null;
  window.CSFX.lastCheckoutCustomer = window.CSFX.lastCheckoutCustomer || null;
  window.CSFX.lastCheckoutCashier = window.CSFX.lastCheckoutCashier || DEFAULT_CASHIER;
  window.CSFX.lastCheckoutRegister = window.CSFX.lastCheckoutRegister || null;

  document.addEventListener('openpos.cart.update', e => {
    csfxLastCart = e?.detail || null;
    window.CSFX.cartSnapshot = csfxLastCart;
    if (isDebug()) console.log('[CSFX] cart snapshot (event)', csfxLastCart);
  });

  try {
    const sharedBC = new BroadcastChannel('shared-data');
    sharedBC.onmessage = ev => {
      const payload = ev?.data;
      if (payload && payload.key === 'cart') {
        csfxLastCart = payload.value || null;
        window.CSFX.cartSnapshot = csfxLastCart;
        if (isDebug()) console.log('[CSFX] cart snapshot (BroadcastChannel)', csfxLastCart);
      }
    };
  } catch (err) {
    if (isDebug()) console.warn('[CSFX] BroadcastChannel no disponible', err);
  }

  document.addEventListener('openpos.start.payment', async e => {
    try {
      if (!(REQUIRE_BACKUP_FOLDER || REQUIRE_BACKUP_FS)) return;
      const granted = await ensureRoot(false);
      if (granted) return;
      if (typeof e.preventDefault === 'function') e.preventDefault();
      if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
      if (typeof e.stopPropagation === 'function') e.stopPropagation();
      uiControls?.showGuard?.();
    } catch (err) {
      log('folder gate event error', err);
    }
  }, true);

  // Captura del pedido cuando se inicia el pago (funciona online y offline)
  document.addEventListener('openpos.start.payment', e => {
    try {
      const detail = e?.detail || {};
      if (isDebug()) log('Evento openpos.start.payment recibido', detail);
      refreshSnapshots(detail);
      scheduleThinPendingFromEvent('openpos.start.payment', detail, { writePendingFs: 'always' });
    } catch (err) {
      console.error('[CSFX] openpos.start.payment handler error', err);
      log('openpos.start.payment handler error', err);
    }
  });

  // (Opcional) Captura de reembolsos, siguiendo el mismo patrón
  document.addEventListener('openpos.start.refund', e => {
    try {
      const detail = e?.detail || {};
      if (isDebug()) log('Evento openpos.start.refund recibido', detail);
      refreshSnapshots(detail);
      scheduleThinPendingFromEvent('openpos.start.refund', detail, { writePendingFs: 'offline-only' });
    } catch (err) {
      console.error('[CSFX] openpos.start.refund handler error', err);
      log('openpos.start.refund handler error', err);
    }
  });

  // Captura del carrito guardado (especialmente en modo offline)
  document.addEventListener('openpos.cart.saved', e => {
    try {
      const detail = e?.detail || {};
      if (isDebug()) log('Evento openpos.cart.saved recibido', detail);

      refreshSnapshots(detail);
      scheduleThinPendingFromEvent('openpos.cart.saved', detail, { writePendingFs: 'offline-only' });
    } catch (err) {
      console.error('[CSFX] openpos.cart.saved handler error', err);
      log('openpos.cart.saved handler error', err);
    }
  });

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
      totals: null,
      orderNumber: null,
      orderId: null,
      orderLocalId: null,
      orderData: null,
      raw: null,
      params: null,
      transactions: null
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
            if (!result.orderLocalId && obj.order_local_id != null) result.orderLocalId = obj.order_local_id;
            if (!result.orderLocalId && obj.local_id != null) result.orderLocalId = obj.local_id;
            if (!result.orderLocalId && obj.cart && obj.cart.order_local_id != null) result.orderLocalId = obj.cart.order_local_id;
            if (!result.orderLocalId && obj.cart && obj.cart.id != null) result.orderLocalId = obj.cart.id;
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
    if (!result.orderLocalId) {
      const maybeCart = result.cart || (parsedData && parsedData.cart);
      if (maybeCart && typeof maybeCart === 'object') {
        if (maybeCart.order_local_id != null) result.orderLocalId = maybeCart.order_local_id;
        else if (maybeCart.local_id != null) result.orderLocalId = maybeCart.local_id;
        else if (maybeCart.id != null) result.orderLocalId = maybeCart.id;
      }
    }
    if (!result.orderLocalId && parsedData && parsedData.order_local_id != null) {
      result.orderLocalId = parsedData.order_local_id;
    }
      const normalizeOrderData = orderData => {
        if (!orderData || typeof orderData !== 'object') return;
        result.orderData = orderData;
        if (!result.posAction && orderData.action) {
          result.posAction = orderData.action;
        }
        if (!result.cart && orderData.cart !== undefined) {
          result.cart = parseMaybeJSON(orderData.cart);
        }
        if (!result.cart && Array.isArray(orderData.items)) {
          result.cart = orderData.items;
        }
        if (!result.payments && orderData.payments !== undefined) {
          result.payments = parseMaybeJSON(orderData.payments);
        }
        if (!result.payments && Array.isArray(orderData.payment_method)) {
          result.payments = orderData.payment_method;
        }
        if (!result.payments && Array.isArray(orderData.transactions)) {
          result.payments = Object.values(orderData.transactions);
        }
        if (!result.customer && orderData.customer !== undefined) {
          result.customer = parseMaybeJSON(orderData.customer);
        }
        if (!result.totals) {
          result.totals = {
            sub_total: orderData.sub_total ?? null,
            sub_total_incl_tax: orderData.sub_total_incl_tax ?? null,
            tax_amount: orderData.tax_amount ?? null,
            discount_amount: orderData.discount_amount ?? orderData.total_discount ?? null,
            grand_total: orderData.grand_total ?? null,
            total_paid: orderData.total_paid ?? null,
            remain_paid: orderData.remain_paid ?? null,
            currency: {
              sub_total: orderData.sub_total_currency_formatted ?? null,
              sub_total_incl_tax: orderData.sub_total_incl_tax_currency_formatted ?? null,
              tax_amount: orderData.tax_amount_currency_formatted ?? null,
              discount_amount: orderData.discount_amount_currency_formatted ?? orderData.total_discount_currency_formatted ?? null,
              grand_total: orderData.grand_total_currency_formatted ?? null,
              total_paid: orderData.total_paid_currency_formatted ?? null,
              remain_paid: orderData.remain_paid_currency_formatted ?? null
            }
          };
        }
        if (!result.posAction && orderData.pos_action) {
          result.posAction = orderData.pos_action;
        }
        if (!result.orderNumber && (orderData.order_number || orderData.order_number_format)) {
          result.orderNumber = orderData.order_number || orderData.order_number_format;
        }
        if (!result.orderId && (orderData.order_id != null || orderData.id != null)) {
          result.orderId = orderData.order_id != null ? orderData.order_id : orderData.id;
        }
      };

      const orderParam = params.get('order');
      if (orderParam) {
        const orderData = parseMaybeJSON(orderParam);
        normalizeOrderData(orderData);
      }

      const ordersParam = params.get('orders');
      if (ordersParam) {
        const offlineList = parseMaybeJSON(ordersParam);
        if (Array.isArray(offlineList)) {
          result.offlineOrders = offlineList.map(entry => {
            const norm = {};
            norm.action = entry?.action || entry?.type || null;
            let orderData = entry?.order || entry?.data || null;
            orderData = parseMaybeJSON(orderData) || orderData;
            norm.order = orderData;
            norm.payments = entry?.payment_method || entry?.payments || null;
            norm.transactions = entry?.transactions || null;
            norm.orderLocalId = orderData?.order_local_id ?? orderData?.local_id ?? orderData?.id ?? null;
            return norm;
          }).filter(Boolean);
          if (!result.orderData && result.offlineOrders[0]?.order) {
            normalizeOrderData(result.offlineOrders[0].order);
          }
          if (!result.orderLocalId && result.offlineOrders[0]?.orderLocalId != null) {
            result.orderLocalId = result.offlineOrders[0].orderLocalId;
          }
        }
      }

      const pendingParam = params.get('pending_order');
      if (pendingParam) {
        const pendingData = parseMaybeJSON(pendingParam);
        let pendingList = [];
        if (Array.isArray(pendingData)) pendingList = pendingData;
        else if (pendingData && Array.isArray(pendingData.orders)) pendingList = pendingData.orders;
        else if (pendingData && pendingData.order) pendingList = [pendingData];
        if (pendingList.length) {
          if (isDebug()) {
            const pendingSample = pendingList[0] && (pendingList[0].order || pendingList[0].data || pendingList[0]) || null;
            const pendingSummary = pendingSample && (pendingSample.order_number || pendingSample.order_number_format || pendingSample.order_local_id || pendingSample.local_id || pendingSample.id) || null;
            const logKey = pendingSummary ? `${pendingSummary}:${pendingList.length}` : `__none__:${pendingList.length}`;
            if (logKey !== lastPendingLogKey) {
              lastPendingLogKey = logKey;
              log('pending_order detectado', { count: pendingList.length, summary: pendingSummary });
            }
          }
          const mapped = pendingList.map(entry => {
            const norm = {};
            norm.action = entry?.action || entry?.type || entry?.status || null;
            let orderData = entry?.order || entry?.data || entry;
            orderData = parseMaybeJSON(orderData) || orderData;
            norm.order = orderData;
            norm.payments = entry?.payment_method || entry?.payments || orderData?.payment_method || null;
            norm.transactions = entry?.transactions || orderData?.transactions || null;
            norm.orderLocalId = orderData?.order_local_id ?? orderData?.local_id ?? orderData?.id ?? null;
            return norm;
          }).filter(Boolean);
          if (mapped.length) {
            result.offlineOrders = (result.offlineOrders || []).concat(mapped);
            if (!result.orderData && mapped[0]?.order) {
              normalizeOrderData(mapped[0].order);
            }
            if (!result.orderLocalId && mapped[0]?.orderLocalId != null) {
              result.orderLocalId = mapped[0].orderLocalId;
            }
          }
        }
      }
      const transactionParam = params.get('transaction');
      if (transactionParam) {
        const transactionData = parseMaybeJSON(transactionParam);
        if (transactionData) {
          result.transactions = transactionData;
          if (!result.orderNumber && transactionData.ref) {
            const refMatch = String(transactionData.ref).match(/#?(\d+)/);
            if (refMatch && refMatch[1]) {
              result.orderNumber = refMatch[1];
            }
          }
          if (!result.orderNumber && transactionData.source_data && transactionData.source_data.order_number) {
            result.orderNumber = transactionData.source_data.order_number;
          }
          if (!result.orderId && transactionData.source != null) {
            result.orderId = transactionData.source;
          }
          if (!result.orderId && transactionData.source_data && transactionData.source_data.order_id != null) {
            result.orderId = transactionData.source_data.order_id;
          }
          if (!result.orderLocalId && transactionData.source_data && transactionData.source_data.order_local_id != null) {
            result.orderLocalId = transactionData.source_data.order_local_id;
          }
        }
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
    const match = lower.match(/\/wp-json\/op\/v1\/([^?]+)/);
    if (match && match[1]) {
      const path = decodeURIComponent(match[1]);
      if (CHECKOUT_ACTIONS.includes(path)) {
        return path;
      }
      for (const candidate of CHECKOUT_ACTIONS) {
        if (path.startsWith(`${candidate}/`)) {
          return candidate;
        }
      }
      const segment = path.split('/')[0] || null;
      return segment;
    }
    return null;
  }

  function detectAction(url, bodyStr) {
    const actionFromBody = extractActionFromBody(bodyStr);
    if (actionFromBody) return actionFromBody;
    const urlStr = String(url || '');
    try {
      const params = new URL(urlStr, window.location.origin).searchParams;
      const qAction = params.get('pos_action');
      if (qAction) return decodeURIComponent(qAction);
      if (params.get('action') === 'openpos') {
        const ajaxAction = params.get('ajax_action') || params.get('pos_action');
        if (ajaxAction) return decodeURIComponent(ajaxAction);
      }
      const actionParam = params.get('action');
      if (actionParam) return decodeURIComponent(actionParam);
    } catch {}
    return extractActionFromUrl(urlStr);
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

  function extractOrderLocalIdFromPayload(payload) {
    if (!payload || typeof payload !== 'object') return null;
    const paths = [
      'order_local_id',
      'local_id',
      'pending_id',
      'data.order_local_id',
      'data.local_id',
      'result.order_local_id',
      'order.order_local_id',
      'order.local_id'
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

  async function getAllOrders(db) {
    const connection = db || await openDB();
    return await new Promise((resolve, reject) => {
      const tx = connection.transaction(STORE_ORDERS, 'readonly');
      const req = tx.objectStore(STORE_ORDERS).getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  }

  async function findExistingDocByIdentifiers(identifiers, cartHash) {
    const db = await openDB();
    const normalize = value => normalizeId(value);

    let ordersCache = null;

    const searchByOrderNumber = async number => {
      if (!number) return null;
      const attempt = async value => new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_ORDERS, 'readonly');
        const idx = tx.objectStore(STORE_ORDERS).index('by_orderNumber');
        const req = idx.get(value);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
      });
      const first = await attempt(number);
      if (first) return first;
      const numeric = Number(number);
      if (!Number.isNaN(numeric)) {
        return await attempt(numeric);
      }
      return null;
    };

    const ensureOrders = async () => {
      if (!ordersCache) {
        ordersCache = await getAllOrders(db);
      }
      return ordersCache;
    };

    const normalizedNumber = normalize(identifiers.orderNumber);
    if (normalizedNumber) {
      const found = await searchByOrderNumber(normalizedNumber);
      if (found) return found;
    }

    const orders = await ensureOrders();
    const normalizedId = normalize(identifiers.orderId);
    if (normalizedId) {
      const match = orders.find(doc => normalize(doc.orderId) === normalizedId);
      if (match) return match;
    }

    const normalizedLocalId = normalize(identifiers.orderLocalId);
    if (normalizedLocalId) {
      const match = orders.find(doc => normalize(doc.orderLocalId) === normalizedLocalId);
      if (match) return match;
    }

    if (cartHash) {
      const match = orders.find(doc => doc.cartHash && cartHash && doc.cartHash === cartHash);
      if (match) return match;
    }

    return null;
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
        FOLDER_GRANTED = true;
        return true;
      }
      if (permission === 'prompt' && interactive && typeof stored.requestPermission === 'function') {
        try {
          const req = await stored.requestPermission({ mode: 'readwrite' });
          if (req === 'granted') {
            fsRoot = stored;
            log('Permiso concedido para handle almacenado');
            FOLDER_GRANTED = true;
            return true;
          }
        } catch (err) {
          log('requestPermission error', err);
        }
      }
      if (permission !== 'granted') {
        FOLDER_GRANTED = false;
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
      FOLDER_GRANTED = true;
      return true;
    } catch (err) {
      log('showDirectoryPicker cancelado o fallido', err);
      FOLDER_GRANTED = false;
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

    const hasRoot = await ensureRoot(false);
    if (hasRoot && fsRoot) {
      const dayDir = await getSubdir(fsRoot, folderName, true);
      if (dayDir) {
        try {
          const fileHandle = await dayDir.getFileHandle(fileName, { create: true });
          const writable = await fileHandle.createWritable();
          await writable.write(blob);
          await writable.close();
          if (isDebug()) log('writeOrderDoc guardado en carpeta', { folderName, fileName });
          return true;
        } catch (err) {
          log('writeOrderDoc error', err);
        }
      }
    }

    if (REQUIRE_BACKUP_FS) {
      uiControls?.showGuard?.();
      return false;
    }
    if (isDebug()) log('writeOrderDoc usando fallback descarga', { fileName });
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
    FOLDER_GRANTED = !!granted;
    if (uiControls) {
      uiControls.setState(granted, text);
      if (granted) {
        uiControls.hideGuard?.();
      } else if (REQUIRE_BACKUP_FS || REQUIRE_BACKUP_FOLDER) {
        uiControls.showGuard?.();
      } else {
        uiControls.hideGuard?.();
      }
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

  async function persistPendingDoc(action, payload, bodyStr, url, method, options = {}) {
    if (options && options.thin) {
      return await persistThinDoc(action, {
        cart: payload.cart,
        payments: payload.payments,
        totals: payload.totals,
        transactions: payload.transactions,
        customer: payload.customer,
        orderNumber: payload.orderNumber,
        orderId: payload.orderId,
        orderLocalId: payload.orderLocalId,
        eventName: payload.eventName,
        eventDetail: payload.eventDetail,
        amount: payload.amount,
        session: payload.session,
        cashier: payload.cashier,
        registerName: payload.registerName
      }, url, method, options);
    }
    const snapshot = csfxLastCart;
    const detail = payload.eventDetail || {};
    const baseOrder = payload.orderData ?? detail.data ?? detail.order ?? null;
    const orderData = baseOrder || {};
    const incomingLocalId = payload.orderLocalId
      ?? detail.order_local_id
      ?? detail.local_id
      ?? payload.params?.pending_id
      ?? payload.params?.order_local_id
      ?? payload.params?.local_id
      ?? null;
    const rawCart = payload.cart
      ?? orderData.cart
      ?? (Array.isArray(orderData.items) ? orderData.items : null)
      ?? detail.cart
      ?? detail.items
      ?? (detail.data && (detail.data.cart ?? (Array.isArray(detail.data.items) ? detail.data.items : null)))
      ?? null;
    let cartRaw = rawCart ?? (payload.raw && (payload.raw.cart ?? payload.raw.items)) ?? (snapshot && (snapshot.items || snapshot.cart)) ?? snapshot ?? null;
    if (!cartRaw && window.CSFX && window.CSFX.lastCheckoutSnapshot) {
      cartRaw = window.CSFX.lastCheckoutSnapshot;
    }

    let paymentsList = [];
    const basePayments = payload.payments ?? orderData.payment_method;
    if (Array.isArray(basePayments)) paymentsList = paymentsList.concat(basePayments);
    else if (basePayments) paymentsList.push(basePayments);

    const transactionsSource = payload.transactions ?? orderData.transactions ?? null;
    if (!paymentsList.length && snapshot && Array.isArray(snapshot.payments)) {
      paymentsList = paymentsList.concat(snapshot.payments);
    }
    if (!paymentsList.length && window.CSFX && window.CSFX.lastCheckoutPayments) {
      paymentsList = paymentsList.concat(window.CSFX.lastCheckoutPayments);
    }

    const customerRaw = payload.customer
      ?? orderData.customer
      ?? detail.customer
      ?? (detail.data && detail.data.customer)
      ?? (payload.raw && payload.raw.customer)
      ?? (snapshot && snapshot.customer)
      ?? null;
    const fallbackCustomer = window.CSFX && window.CSFX.lastCheckoutCustomer ? window.CSFX.lastCheckoutCustomer : null;
    const mergedCustomerRaw = customerRaw || fallbackCustomer;
    const totalsSource = payload.totals
      || orderData.totals
      || detail.totals
      || (detail.data && detail.data.totals)
      || (cartRaw && cartRaw.totals)
      || (payload.raw && payload.raw.totals)
      || (snapshot && snapshot.totals)
      || (payload.amount ? { grand_total: payload.amount } : null)
      || null;
    const fallbackTotals = totalsSource || (window.CSFX && window.CSFX.lastCheckoutTotals ? window.CSFX.lastCheckoutTotals : null);
    const orderNumberRaw = payload.orderNumber || orderData.order_number || orderData.order_number_format || (orderData.order_number_details && (orderData.order_number_details.order_number || orderData.order_number_details.order_number_formatted)) || null;
    const orderIdRaw = payload.orderId || orderData.order_id || orderData.id || (orderData.order_number_details && orderData.order_number_details.order_id) || null;
    const orderLocalId = incomingLocalId
      || payload.orderLocalId
      || orderData.order_local_id
      || orderData.local_id
      || (orderData.order_number_details && orderData.order_number_details.order_local_id)
      || null;
    let transactionsList = null;
    if (Array.isArray(transactionsSource)) {
      transactionsList = transactionsSource;
      if (!paymentsList.length) {
        paymentsList = paymentsList.concat(transactionsSource);
      }
    } else if (transactionsSource && typeof transactionsSource === 'object') {
      transactionsList = [transactionsSource];
      if (!paymentsList.length) {
        paymentsList.push(transactionsSource);
      }
    }

    paymentsList = normalizePaymentsCollection(paymentsList);

    const cartHash = await hashValue(cartRaw || payload.raw || bodyStr || `${action}-${Date.now()}`);
    const parts = nowParts();
    const dayFolder = dayFolderFromParts(parts);
    const createdAt = Date.now();
    const identifiers = {
      orderNumber: orderNumberRaw || payload.orderNumber || detail.order_number || detail.order_number_format || null,
      orderId: orderIdRaw || payload.orderId || detail.order_id || null,
      orderLocalId: orderLocalId
    };
    const existingDoc = await findExistingDocByIdentifiers(identifiers, cartHash);

    if (!existingDoc) {
      const dedupeKey = (orderNumberRaw && normalizeId(orderNumberRaw)) || (orderIdRaw && normalizeId(orderIdRaw)) || (orderLocalId && normalizeId(orderLocalId)) || null;
      const allowRecentGate = action !== 'offline-idb';
      if (allowRecentGate && dedupeKey && inRecentGate(dedupeKey)) {
        log('Orden ignorada por recent gate', dedupeKey);
        return null;
      }
    }

    if (!existingDoc && (action === 'transaction/create' || action === 'pending-order')) {
      if (window.CSFX_DEBUG) log(`${action} ignorado (sin orden previo)`, { orderNumber: identifiers.orderNumber, orderLocalId: identifiers.orderLocalId });
      return null;
    }

    const key = existingDoc ? existingDoc.key : `csfx_${createdAt}_${Math.random().toString(16).slice(2, 8)}`;
    const pendingFileName = existingDoc?.pendingFileName || existingDoc?.fileName || `pending_${parts.H}-${parts.M}-${parts.S}_${cartHash.slice(0, 8)}.json`;
    const dev = await ensureDeviceId();

    const restfulMeta = RESTFUL_ENABLED && url.toLowerCase().includes('/wp-json/op/v1/');

    if (existingDoc) {
      const doc = { ...existingDoc };
      const updatedAt = Date.now();
      doc.lastAction = action;
      doc.action = action;
      doc.updatedAt = updatedAt;
      doc.updatedAtText = new Date(updatedAt).toISOString();
      doc.cartHash = cartHash || doc.cartHash;
      if (cartRaw) doc.cart = deepClone(cartRaw);
      if (paymentsList.length) doc.payments = deepClone(paymentsList);
      if (transactionsList) doc.transactions = deepClone(transactionsList);
      if (mergedCustomerRaw) doc.customer = deepClone(mergedCustomerRaw);
      if (fallbackTotals) doc.totals = deepClone(fallbackTotals);
      if (orderNumberRaw) doc.orderNumber = orderNumberRaw;
      if (orderIdRaw) doc.orderId = orderIdRaw;
      if (orderLocalId) doc.orderLocalId = orderLocalId;
      if (payload.cashier) doc.cashier = payload.cashier;
      if (payload.registerName) doc.registerName = payload.registerName;
      doc.rawRequest = {
        url,
        method,
        action,
        body: bodyStr,
        restful: restfulMeta
      };
      if (orderData && Object.keys(orderData).length) {
        doc.orderData = deepClone(orderData);
      }
      if (payload.eventName) doc.eventName = payload.eventName;
      if (payload.eventDetail) doc.eventDetail = deepClone(detail);
      if (payload.amount != null) doc.eventAmount = payload.amount;
      if (payload.session) doc.eventSession = payload.session;
      doc.version = '1.2.0';
      doc.deviceId = doc.deviceId || dev || null;
      doc.pendingFileName = doc.pendingFileName || pendingFileName;
      await putOrder(doc);
      updateExportButton(doc);
      window.CSFX_LAST_DOC = doc;
      if (shouldWriteDocForPending(action, doc.status, options)) {
        await writeOrderDoc(doc, { pending: doc.status !== 'confirmed' });
      }
      maybePruneLater();
      log('Checkout actualizado', action, { url, orderNumber: orderNumberRaw, orderId: orderIdRaw, orderLocalId, amount: fallbackTotals ? fallbackTotals.grand_total : undefined });
      return { docKey: doc.key, action, cartHash: doc.cartHash };
    }

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
      payments: deepClone(paymentsList.length ? paymentsList : null),
      transactions: deepClone(transactionsList),
      customer: deepClone(mergedCustomerRaw),
      totals: deepClone(fallbackTotals),
      cashier: payload.cashier || null,
      registerName: payload.registerName || null,
      rawRequest: {
        url: url,
        method: method,
        action,
        body: bodyStr,
        restful: restfulMeta
      },
      rawResponse: null,
      orderNumber: orderNumberRaw || orderLocalId || null,
      orderId: orderIdRaw || orderLocalId || null,
      orderLocalId: orderLocalId || null,
      pendingFileName,
      fileName: pendingFileName,
      version: '1.2.0',
      orderData: deepClone(orderData),
      eventName: payload.eventName || null,
      eventDetail: deepClone(detail),
      eventAmount: payload.amount ?? null,
      eventSession: payload.session ?? null
    };

    await putOrder(doc);
    updateExportButton(doc);
    window.CSFX_LAST_DOC = doc;
    if (shouldWriteDocForPending(action, doc.status, options)) {
      await writeOrderDoc(doc, { pending: true });
    }
    maybePruneLater();
    log('Checkout detectado', action, { url, orderNumber: orderNumberRaw, amount: fallbackTotals ? fallbackTotals.grand_total : undefined });
    if (action === 'offline-idb' && isDebug()) {
      log('offline-idb persistido', {
        orderNumber: doc.orderNumber,
        orderId: doc.orderId,
        orderLocalId: doc.orderLocalId,
        items: doc.cart && Array.isArray(doc.cart.items) ? doc.cart.items.length : Array.isArray(doc.cart) ? doc.cart.length : null
      });
    }

    return { docKey: key, action, cartHash };
  }

  async function createPendingContext(url, method, body) {
    try {
      if (ENFORCE_BACKUP && !FOLDER_GRANTED) {
        return null;
      }
      if (!isCheckoutUrlCandidate(url)) {
        if (STRICT_ENDPOINTS && window.CSFX_DEBUG) {
          log('URL descartada por gating estricto', url);
        }
        return null;
      }
      const action = detectAction(url, '');
      if (!action) return null;
      const snapshot = buildThinSnapshotFromMemory('network.stub');
      const hasUsefulData = snapshot.cart || snapshot.payments || snapshot.totals || snapshot.customer;
      if (!hasUsefulData) return null;
      snapshot.eventName = 'network.stub';
      return await persistPendingDoc(action, snapshot, '', url, method, {
        thin: true,
        writePendingFs: 'auto'
      });
    } catch (err) {
      log('createPendingContext error', err);
      return null;
    }
  }

  async function applyConfirmationByKey(docKey, responseText, parsedPayload, ctx) {
    if (!docKey) return;
    const doc = await getOrder(docKey);
    if (!doc) return;
    if (doc.action === 'pending-order') {
      if (isDebug()) log('Finalización omitida para pending-order', doc.orderLocalId || doc.key);
      return;
    }
    if (doc.status === 'confirmed' && doc.orderNumber && inRecentGate(doc.orderNumber)) {
      log('Respuesta duplicada ignorada', doc.orderNumber);
      return;
    }
    let payload = parsedPayload;
    if (!payload && responseText) {
      try {
        payload = JSON.parse(responseText);
      } catch {
        payload = null;
      }
    }
    const orderNumber = extractOrderNumber(payload);
    const orderId = extractOrderId(payload);
    const totals = extractTotals(payload);
    doc.status = 'confirmed';
    doc.orderNumber = orderNumber || doc.orderNumber;
    doc.orderId = orderId || doc.orderId;
    doc.rawResponse = payload ?? { text: responseText };
    if (totals) {
      const clonedTotals = cloneLight(totals);
      if (clonedTotals) {
        doc.totals = clonedTotals;
      }
    }
    const parts = doc.createdAtParts || nowParts();
    const dev = doc.deviceId || (await ensureDeviceId()) || 'nodev';
    const refSource = doc.orderNumber || doc.orderId || doc.orderLocalId || (ctx && ctx.cartHash ? ctx.cartHash : 'unknown');
    doc.ref = `CSFX-${dev}-${parts.y}${parts.m}${parts.d}-${parts.H}${parts.M}${parts.S}-${refSource}`;
    doc.fileName = `${parts.H}-${parts.M}-${parts.S}_${refSource}.json`;
    await putOrder(doc);
    window.CSFX_LAST_DOC = doc;
    updateExportButton(doc);
    await deleteFileIfExists(doc.dayFolder || dayFolderFromParts(parts), doc.pendingFileName);
    await writeOrderDoc(doc, { pending: false });
    maybePruneLater();
    if (doc.orderNumber) {
      inRecentGate(doc.orderNumber);
    }
    log('Checkout confirmado', doc.orderNumber || doc.orderId || 'sin número', doc);
    if (window.CSFX) {
      window.CSFX.lastCheckoutSnapshot = null;
      window.CSFX.lastCheckoutTotals = null;
      window.CSFX.lastCheckoutPayments = null;
      window.CSFX.lastCheckoutCustomer = null;
    }
  }

  async function finalizeCheckout(ctx, responseText) {
    if (!ctx || !ctx.docKey) return;
    try {
      await applyConfirmationByKey(ctx.docKey, responseText, null, ctx);
    } catch (err) {
      log('finalizeCheckout error', err);
    }
  }

  async function finalizeByResponse(responseText) {
    let payload = null;
    try {
      payload = JSON.parse(responseText);
    } catch {
      payload = null;
    }
    if (!payload) return;
    const identifiers = {
      orderNumber: extractOrderNumber(payload),
      orderId: extractOrderId(payload),
      orderLocalId: extractOrderLocalIdFromPayload(payload)
    };
    if (!identifiers.orderNumber && !identifiers.orderId && !identifiers.orderLocalId) return;
    const existing = await findExistingDocByIdentifiers(identifiers, null);
    if (!existing) return;
    await applyConfirmationByKey(existing.key, responseText, payload, null);
  }

  function setupUI() {
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'position:fixed;left:14px;bottom:14px;z-index:2147483647;display:flex;flex-direction:column;align-items:flex-start;gap:10px';
    document.body.appendChild(wrapper);

    const mini = document.createElement('button');
    mini.type = 'button';
    mini.style.cssText = 'display:flex;align-items:center;gap:6px;background:rgba(17,17,17,.85);color:#fff;padding:6px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.08);box-shadow:0 6px 18px rgba(0,0,0,.3);font:12px system-ui;letter-spacing:.4px;text-transform:uppercase;cursor:pointer';
    mini.innerHTML = `<span class="csfx-mini-dot" style="width:8px;height:8px;border-radius:50%;background:#e74c3c;display:inline-block"></span><span class="csfx-mini-label">Backup</span>`;
    wrapper.appendChild(mini);

    const box = document.createElement('div');
    box.style.cssText = 'background:#111;color:#fff;font:12px system-ui;padding:10px;border-radius:10px;box-shadow:0 12px 28px rgba(0,0,0,.35);max-width:240px;display:none';
    box.innerHTML = `
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
        <div id="csfx-dot" style="width:10px;height:10px;border-radius:50%;background:#e74c3c"></div>
        <strong>Respaldo local</strong>
        <span id="csfx-status" style="opacity:.7;margin-left:auto">sin carpeta</span>
        <button id="csfx-collapse" type="button" style="margin-left:8px;background:transparent;border:0;color:#fff;opacity:.6;font-size:14px;cursor:pointer;padding:0 4px;line-height:1">×</button>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button id="csfx-choose" style="flex:1 1 100%;padding:6px 10px;border:0;border-radius:6px;background:#09f;color:#fff;cursor:pointer">Seleccionar carpeta…</button>
        <button id="csfx-change" style="flex:1 1 48%;padding:6px 10px;border:0;border-radius:6px;background:#555;color:#fff;cursor:pointer">Cambiar carpeta…</button>
        <button id="csfx-export" style="flex:1 1 48%;padding:6px 10px;border:0;border-radius:6px;background:#1abc9c;color:#fff;cursor:pointer">Exportar último</button>
      </div>
    `;
    wrapper.appendChild(box);

    const dot = box.querySelector('#csfx-dot');
    const status = box.querySelector('#csfx-status');
    const chooseBtn = box.querySelector('#csfx-choose');
    const changeBtn = box.querySelector('#csfx-change');
    const exportBtn = box.querySelector('#csfx-export');
    const collapseBtn = box.querySelector('#csfx-collapse');
    const miniDot = mini.querySelector('.csfx-mini-dot');

    let expanded = false;
    const expandPanel = () => {
      if (expanded) return;
      expanded = true;
      box.style.display = 'block';
      mini.style.display = 'none';
    };
    const collapsePanel = () => {
      expanded = false;
      box.style.display = 'none';
      mini.style.display = 'flex';
    };

    mini.addEventListener('click', expandPanel);
    collapseBtn.addEventListener('click', collapsePanel);

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
      mini.title = message;
    }

    const guard = document.createElement('div');
    guard.id = 'csfx-backup-guard';
    guard.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;padding:16px;pointer-events:none';
    guard.innerHTML = `
      <div style="background:#fff;max-width:460px;width:90%;border-radius:10px;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.45);font:15px system-ui;display:flex;flex-direction:column;gap:16px">
        <div>
          <h3 style="margin:0 0 8px;font-size:18px">Respaldo local requerido</h3>
          <p style="margin:0;color:#333;line-height:1.4">Debes configurar la carpeta de respaldo local antes de continuar con el pago.</p>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
          <button id="csfx-guard-close" style="padding:8px 14px;border:0;border-radius:6px;background:#7f8c8d;color:#fff;cursor:pointer">Cerrar</button>
          <button id="csfx-guard-open" style="padding:8px 14px;border:0;border-radius:6px;background:#09f;color:#fff;cursor:pointer">Seleccionar carpeta…</button>
        </div>
      </div>`;
    document.body.appendChild(guard);

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
      collapsePanel();
    });

    const showGuardOverlay = () => {
      guard.style.display = 'flex';
      guard.style.pointerEvents = 'all';
    };
    const hideGuardOverlay = () => {
      guard.style.display = 'none';
      guard.style.pointerEvents = 'none';
    };

    const guardClose = guard.querySelector('#csfx-guard-close');
    const guardOpen = guard.querySelector('#csfx-guard-open');
    guardClose.addEventListener('click', () => {
      hideGuardOverlay();
    });
    guardOpen.addEventListener('click', async () => {
      try {
        const ok = await ensureRoot(true);
        if (ok) {
          await ensureDeviceId();
          updateUIState(true, 'carpeta lista');
        } else {
          updateUIState(false, 'sin carpeta');
        }
      } catch (err) {
        log('ensureRoot desde guard error', err);
      }
      if (FOLDER_GRANTED) {
        hideGuardOverlay();
      } else {
        showGuardOverlay();
      }
    });

    if (REQUIRE_BACKUP_FS && !FOLDER_GRANTED) {
      showGuardOverlay();
    }

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
        if (miniDot) miniDot.style.background = granted ? '#2ecc71' : '#e74c3c';
        status.textContent = text || (granted ? 'carpeta lista' : 'sin carpeta');
        mini.title = text || (granted ? 'carpeta lista' : 'sin carpeta');
        changeBtn.disabled = !granted || !('showDirectoryPicker' in window);
      },
      setExport(doc) {
        exportBtn.disabled = !doc;
      },
      showGuard() {
        expandPanel();
        showGuardOverlay();
      },
      hideGuard() {
        hideGuardOverlay();
      }
    };
  }

  function showBackupRequiredModal() {
    if (!REQUIRE_BACKUP_FOLDER) return;
    const id = 'csfx-backup-required';
    if (document.getElementById(id)) return;
    const wrap = document.createElement('div');
    wrap.id = id;
    wrap.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center';
    wrap.innerHTML = `
      <div style="background:#fff;max-width:420px;width:90%;border-radius:10px;padding:16px;box-shadow:0 12px 40px rgba(0,0,0,.35);font:14px system-ui">
        <h3 style="margin:0 0 8px">Respaldo local requerido</h3>
        <p style="margin:0 0 12px">Debes configurar una carpeta de respaldo local antes de facturar.</p>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button id="csfx-modal-cancel" style="padding:6px 10px;border:0;border-radius:6px;background:#777;color:#fff;cursor:pointer">Cerrar</button>
          <button id="csfx-modal-open" style="padding:6px 10px;border:0;border-radius:6px;background:#09f;color:#fff;cursor:pointer">Seleccionar carpeta…</button>
        </div>
      </div>`;
    document.body.appendChild(wrap);
    const close = () => {
      try { wrap.remove(); } catch (e) {}
    };
    wrap.querySelector('#csfx-modal-cancel').onclick = () => close();
    wrap.querySelector('#csfx-modal-open').onclick = async () => {
      try {
        const ok = await ensureRoot(true);
        if (ok) {
          await ensureDeviceId();
        }
      } catch (err) {
        log('ensureRoot desde modal error', err);
      }
      close();
      refreshHandleStatus();
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

  // Hooks de red deshabilitados (network_hooks:'none'); el control de carpeta se aplica vía eventos.
})();
