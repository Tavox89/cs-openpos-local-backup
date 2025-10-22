(()=> {
  const DB_NAME='csfx-orders', STORE='orders';
  let fsRoot=null, deviceId=null;
  const recentMap = new Map(); // dedupe 8s

  // --- utils ---
  const p2=n=>String(n).padStart(2,'0');
  const nowParts=()=>{const d=new Date(); return {
    y:d.getFullYear(), m:p2(d.getMonth()+1), d:p2(d.getDate()),
    H:p2(d.getHours()), M:p2(d.getMinutes()), S:p2(d.getSeconds())
  }};
  const dayFolderName=()=>{const t=nowParts(); return `${t.y}-${t.m}-${t.d}`};

  function openDB(){return new Promise((res,rej)=>{
    const r=indexedDB.open(DB_NAME,1);
    r.onupgradeneeded=e=>{
      const db=e.target.result;
      if(!db.objectStoreNames.contains(STORE)){
        const os=db.createObjectStore(STORE,{keyPath:'key'});
        os.createIndex('by_time','createdAt');
        os.createIndex('by_orderNumber','orderNumber');
      }
    };
    r.onsuccess=e=>res(e.target.result); r.onerror=e=>rej(e.target.error);
  })}

  async function saveDB(doc){
    const db=await openDB();
    const tx=db.transaction(STORE,'readwrite');
    tx.objectStore(STORE).put(doc);
    await new Promise(r=>tx.oncomplete=r);
  }

  async function pruneDB(max=500){
    const db=await openDB();
    const tx=db.transaction(STORE,'readwrite');
    const idx=tx.objectStore(STORE).index('by_time');
    let kept=0;
    await new Promise(resolve=>{
      idx.openCursor(null,'prev').onsuccess=e=>{
        const c=e.target.result; if(!c){resolve();return;}
        kept++; if(kept>max){ c.delete(); c.continue(); } else c.continue();
      };
    });
  }

  async function persist(){try{await navigator.storage?.persist?.()}catch{}}

  // --- File System Access ---
  async function ensureRoot(interactive=false){
    if(!('showDirectoryPicker' in window)) return false;
    try{
      if(!fsRoot && interactive){
        fsRoot = await showDirectoryPicker({mode:'readwrite'});
      }
      if(!fsRoot) return false;
      let st = await fsRoot.queryPermission({mode:'readwrite'});
      if(st==='granted') return true;
      if(st==='prompt' && interactive) st=await fsRoot.requestPermission({mode:'readwrite'});
      return st==='granted';
    }catch{return false}
  }

  async function getSubdir(root, name){
    try{ return await root.getDirectoryHandle(name, {create:true}); }
    catch{ return null }
  }

  async function readTextFile(handle, name){
    try{ const f = await handle.getFileHandle(name); const file = await f.getFile(); return await file.text(); }
    catch{ return null }
  }

  async function writeTextFile(handle, name, text){
    try{
      const fh = await handle.getFileHandle(name, {create:true});
      const w = await fh.createWritable();
      await w.write(text); await w.close(); return true;
    }catch{ return false }
  }

  async function ensureDeviceId(){
    if(deviceId) return deviceId;
    if(!(await ensureRoot(false))) return null;
    let txt = await readTextFile(fsRoot, 'device-id.txt');
    if(!txt){
      txt = crypto.randomUUID();
      await writeTextFile(fsRoot, 'device-id.txt', txt);
    }
    deviceId = String(txt||'').trim();
    return deviceId;
  }

  async function writeOrderDoc(doc){
    const blob = new Blob([JSON.stringify(doc, null, 2)], {type:'application/json'});
    const t = doc.createdAtParts; const fname = `${t.H}-${t.M}-${t.S}_${doc.orderNumber||'order'}.json`;
    if(await ensureRoot(false)){
      const dayDir = await getSubdir(fsRoot, dayFolderName());
      if(dayDir){
        try{
          const fh = await dayDir.getFileHandle(fname, {create:true});
          const w = await fh.createWritable();
          await w.write(blob); await w.close(); return true;
        }catch{}
      }
    }
    // Fallback: descarga
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob); a.download=fname;
    document.body.appendChild(a); a.click();
    setTimeout(()=>{URL.revokeObjectURL(a.href); a.remove()}, 600);
    return false;
  }

  // --- captura carrito ---
  window.CSFX = window.CSFX || {};
  document.addEventListener('openpos.cart.update', e=>{
    window.CSFX.cartSnapshot = e?.detail || null;
  });

  // --- heurística checkout + doc ---
  function looksLikeCheckout(url, body){
    const u = String(url||'').toLowerCase();
    if(!(u.includes('openpos')||u.includes('admin-ajax.php')||u.includes('/wp-json/'))) return false;
    return /checkout|pos|op_?check|confirm|place.?order/i.test(String(body||''));
  }
  function pick(o,k){return (o && o[k]!=null) ? o[k] : null}

  async function sha256(obj){
    const sorted = JSON.stringify(obj ?? {}, Object.keys(obj ?? {}).sort());
    const enc = new TextEncoder().encode(sorted);
    const hash = await crypto.subtle.digest('SHA-256', enc);
    return [...new Uint8Array(hash)].map(b=>b.toString(16).padStart(2,'0')).join('');
  }

  async function buildDoc(raw){
    const t = nowParts();
    const createdAt = Date.now();
    const cart = window.CSFX.cartSnapshot || null;
    const data = raw?.data || {};
    const orderNumber =
      pick(raw,'order_number') || pick(raw,'orderNumber') || pick(raw,'number') ||
      pick(data,'order_number') || pick(data,'increment_id') || pick(data,'number');

    const dev = await ensureDeviceId();
    const cartHash = await sha256(cart || {});
    const ref = `CSFX-${dev||'nodev'}-${t.y}${t.m}${t.d}-${t.H}${t.M}${t.S}-${orderNumber||('unknown-'+cartHash.substring(0,8))}`;

    // Redacta básico si hiciera falta (extiende según tu esquema)
    const sanitizedRaw = raw && typeof raw==='object' ? JSON.parse(JSON.stringify(raw)) : raw;
    if(sanitizedRaw?.payment?.cardNumber) sanitizedRaw.payment.cardNumber = 'REDACTED';

    return {
      key: `${orderNumber||'order'}_${t.y}${t.m}${t.d}_${t.H}${t.M}${t.S}`,
      ref,
      deviceId: dev || null,
      orderNumber: orderNumber || null,
      createdAt,
      createdAtText: new Date(createdAt).toISOString(),
      createdAtParts: t,
      totals: cart?.totals || null,
      cart,
      rawResponse: sanitizedRaw ?? null
    };
  }

  function inRecentGate(orderNumber){
    const k = orderNumber||'__unknown__';
    const now = performance.now();
    const last = recentMap.get(k) || 0;
    if(now - last < 8000){ return true; }
    recentMap.set(k, now);
    return false;
  }

  async function handleCheckoutResponse(text){
    let json=null; try{ json = JSON.parse(text); }catch{}
    const doc = await buildDoc(json || {text});
    if(inRecentGate(doc.orderNumber)) return;
    await saveDB(doc);
    await writeOrderDoc(doc);
    pruneDB(500).catch(()=>{});
    window.CSFX_LAST_DOC = doc;
  }

  // --- interceptores fetch / XHR ---
  const _fetch = window.fetch;
  window.fetch = async function(i, init){
    const url=typeof i==='string'?i:(i&&i.url), m=(init?.method)||(i&&i.method)||'GET';
    const isPOST = String(m).toUpperCase()==='POST';
    const body = init?.body || '';
    const maybe = isPOST && looksLikeCheckout(url, body);
    const resp = await _fetch.apply(this, arguments);
    if(!maybe) return resp;
    try{ const txt = await resp.clone().text(); handleCheckoutResponse(txt); }catch{}
    return resp;
  };

  (function patchXHR(){
    const X = window.XMLHttpRequest; if(!X) return;
    const O=X.prototype.open, S=X.prototype.send;
    X.prototype.open=function(m,u){ this.__m=m; this.__u=u; return O.apply(this, arguments); };
    X.prototype.send=function(b){
      const isPOST = String(this.__m||'').toUpperCase()==='POST';
      const maybe = isPOST && looksLikeCheckout(this.__u, b);
      if(maybe){ this.addEventListener('loadend', ()=>{ try{ handleCheckoutResponse(this.responseText); }catch{} }); }
      return S.apply(this, arguments);
    };
  })();

  // --- UI mínima (badge flotante) ---
  (function ui(){
    persist();
    const box=document.createElement('div');
    box.style.cssText='position:fixed;right:14px;bottom:110px;z-index:2147483647;background:#111;color:#fff;font:12px system-ui;padding:10px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.25)';
    box.innerHTML=`
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
        <div id="csfx-dot" style="width:10px;height:10px;border-radius:50%;background:#e74c3c"></div>
        <strong>Respaldo local</strong>
        <span id="csfx-status" style="opacity:.7;margin-left:auto">sin carpeta</span>
      </div>
      <div style="display:flex;gap:8px">
        <button id="csfx-choose" style="padding:6px 10px;border:0;border-radius:6px;background:#09f;color:#fff;cursor:pointer">Seleccionar carpeta…</button>
        <button id="csfx-export" style="padding:6px 10px;border:0;border-radius:6px;background:#1abc9c;color:#fff;cursor:pointer">Exportar último</button>
      </div>
    `;
    document.body.appendChild(box);
    const dot=box.querySelector('#csfx-dot'), st=box.querySelector('#csfx-status');

    async function refresh(){
      const ok = await ensureRoot(false);
      dot.style.background = ok ? '#2ecc71' : '#e74c3c';
      st.textContent = ok ? 'carpeta lista' : 'sin carpeta';
    }
    box.querySelector('#csfx-choose').onclick=async()=>{ await ensureRoot(true); await ensureDeviceId(); refresh(); };
    box.querySelector('#csfx-export').onclick=()=>{
      const doc = window.CSFX_LAST_DOC; if(!doc) return;
      const blob=new Blob([JSON.stringify(doc,null,2)],{type:'application/json'});
      const t=doc.createdAtParts;
      const a=document.createElement('a');
      a.href=URL.createObjectURL(blob);
      a.download=`${t.H}-${t.M}-${t.S}_${doc.orderNumber||'order'}.json`;
      document.body.appendChild(a); a.click();
      setTimeout(()=>{URL.revokeObjectURL(a.href); a.remove()}, 600);
    };
    refresh();
  })();
})();
