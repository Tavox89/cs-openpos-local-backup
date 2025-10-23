# Changelog

## 1.1.0 – Persistencia y detección real
- Persiste la carpeta seleccionada en IndexedDB (`handles`) y revalida permisos automáticamente
- Detecta los endpoints `pos_action`/REST de OpenPOS y crea respaldos `pending` antes del pago
- Actualiza el respaldo a `confirmed` cuando llega el número/ID de pedido y reescribe el archivo definitivo
- Nuevo botón “Cambiar carpeta…” y modo debug (`window.CSFX_DEBUG = true`)

## 1.0.0 – MVP
- Captura `openpos.cart.update`
- Intercepta `fetch` y `XMLHttpRequest`
- Heurística de checkout y armado de documento
- IndexedDB (`csfx-orders/orders`)
- File System Access: `.json` por orden, subcarpeta por día, `device-id.txt`
- Dedupe 8s + prune(500) + storage.persist()
- UI flotante: seleccionar carpeta / exportar último
- Visor de Cierre (HTML) con CSV + Imprimir
- Script Node para CSV en `Reports/`
