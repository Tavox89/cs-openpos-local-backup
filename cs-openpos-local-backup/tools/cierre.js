#!/usr/bin/env node
// Uso: node tools/cierre.js <ruta_backups> <YYYY-MM-DD>
const fs = require('fs');
const path = require('path');

const root = process.argv[2];
const day  = process.argv[3] || new Date().toISOString().slice(0,10);
if(!root){ console.error('Uso: node tools/cierre.js <ruta_backups> <YYYY-MM-DD>'); process.exit(1); }

const dayDir = path.join(root, day);
if(!fs.existsSync(dayDir)){ console.error('No existe carpeta del día:', dayDir); process.exit(1); }

const files = fs.readdirSync(dayDir).filter(f=>f.endsWith('.json'));
const rows=[];
for(const f of files){
  try{
    const o = JSON.parse(fs.readFileSync(path.join(dayDir,f),'utf-8'));
    const t = o.createdAtParts || {};
    const hora = `${t.H||'??'}:${t.M||'??'}:${t.S||'??'}`;
    const tot = o.totals || o.cart?.totals || {};
    const pay = (o.rawResponse?.payments || o.cart?.payments || []).map(p=>p?.method || p?.title).filter(Boolean);
    const caj = o.cart?.cashier || o.cart?.salesperson || o.salesperson || '';
    rows.push({
      hora,
      ref: o.ref || o.key || '',
      orden: o.orderNumber || '',
      cajero: caj,
      subtotal: tot.subtotal || tot.sub_total || 0,
      tax: tot.tax || tot.iva || 0,
      desc: tot.discount || 0,
      total: tot.total || tot.grand_total || 0,
      metodos: (pay.length?pay: (o.rawResponse?.payment_method ? [o.rawResponse.payment_method] : [])).join('+')
    });
  }catch{}
}

function csv(rows){
  const head=['Hora','Ref','Orden','Cajero','Subtotal','Impuestos','Descuento','Total','Metodos'];
  const lines=[head.join(',')];
  for(const r of rows){
    lines.push([r.hora,r.ref,r.orden,r.cajero,r.subtotal,r.tax,r.desc,r.total,r.metodos]
      .map(v=>`"${String(v??'').replace(/"/g,'""')}"`).join(','));
  }
  return lines.join('\n');
}

const repDir = path.join(root, 'Reports');
if(!fs.existsSync(repDir)) fs.mkdirSync(repDir, {recursive:true});
const out = path.join(repDir, `csfx_cierre_${day}.csv`);
fs.writeFileSync(out, csv(rows), 'utf-8');
console.log('Reporte generado:', out);
