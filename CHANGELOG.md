# Changelog

## 1.2.0 – Captura por evento
- Captura pedidos al dispararse `openpos.start.payment`, generando un respaldo inmediato tanto en modo online como offline.
- Se incorpora `csfx-idb-tap.js`, un shim que intercepta la store `orders` de IndexedDB para crear el respaldo `pending` incluso cuando el POS opera offline.
- Añade listener para `openpos.start.refund`, preparando el flujo de reembolsos con la misma estructura de respaldo.
- Mantiene la confirmación del pedido desde la respuesta del servidor y se alinea con los cambios de OpenPOS 8.
- Opción `require_backup_folder` (activa por defecto) que bloquea el checkout cuando no hay carpeta concedida, mostrando un modal para seleccionarla.
- Opción `require_backup_fs` que bloquea por completo el flujo de cobro (overlay) y deshabilita el fallback por descarga hasta que se seleccione la carpeta.
- Los respaldos “thin” guardan cajero/registro y los totales se reconstruyen automáticamente; el visor utiliza estos fallbacks.
- En modo offline (`offline-idb`) se persiste el documento completo (no-thin) para conservar el `orderData` íntegro en el JSON.
- Los respaldos “thin” incluyen cajero, pagos y totales reconstruidos (cart/pagos), y el visor de cierre usa esos fallbacks para evitar montos en 0.

## 1.1.0 – Persistencia y detección real
- Persiste la carpeta seleccionada en IndexedDB (`handles`) y revalida permisos automáticamente
- Detecta los endpoints `pos_action`/REST de OpenPOS 8 (`order/create`, `transaction/create`, etc.), aun cuando `pos_action` sólo venga en la URL o dentro del campo `order`, y crea respaldos `pending` antes del pago
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
