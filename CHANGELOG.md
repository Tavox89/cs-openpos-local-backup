# Changelog

## 1.1.0 – Persistencia y detección real
- Persiste la carpeta seleccionada en IndexedDB (`handles`) y revalida permisos automáticamente
- Detecta los endpoints `pos_action`/REST de OpenPOS y crea respaldos `pending` antes del pago
- Actualiza el respaldo a `confirmed` cuando llega el número/ID de pedido y reescribe el archivo definitivo
- Nuevo botón “Cambiar carpeta…” y modo debug (`window.CSFX_DEBUG = true`)
- Determina el modo REST mediante `apply_filters('pos_enable_rest_ful', true)` y reconoce todas las variantes de la vista POS
- El badge flotante se movió a la esquina inferior izquierda para no interferir con los controles del POS
- Versionado automático del script principal con `filemtime` para refrescar la caché del navegador en cada despliegue
- Mensajes de diagnóstico cuando la API File System Access no está disponible (ej. sitios HTTP sin contexto seguro)

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
