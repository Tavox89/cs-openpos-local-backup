# Changelog

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
