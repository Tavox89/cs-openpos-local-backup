# Changelog

## 1.2.3 – Resync con creación de pedidos
- El flujo **Re-sync** crea automáticamente el pedido en WooCommerce cuando no existe: replica ítems, totales, pagos/vueltos y las transacciones POS, agrega la nota “CSFX Local Backup: Pedido creado mediante resync automático.” y vuelve a ejecutar la verificación.
- Mejoras en la comparación de pagos: soporte para campos `out_amount` / `return_amount` y mensajes detallados con montos/vueltos local vs WooCommerce.
- Ajustes en la normalización de ítems (usa nombres normalizados) y en las fechas para respetar la zona horaria de WooCommerce.
- El visor ahora detecta si el pedido se creó durante el resync y lo muestra como advertencia.

## 1.2.1 – Verificación y resync desde el visor
- API REST (`/csfx-lb/v1/context|verify-order|resync-order`) para comparar pedidos del backup contra WooCommerce y registrar notas automáticas.
- El visor `csfx-cierre.html` muestra estados de verificación por orden, advertencias/diferencias, botón “Re-sync” cuando la orden no existe y acceso directo a la pantalla del pedido en WooCommerce.
- Botón “Verificación general” en la cabecera que recorre todas las órdenes cargadas respetando sesiones y permisos (`manage_woocommerce`).
- Manejo mejorado de errores: mensajes claros cuando faltan permisos o el servicio REST no está disponible, evitando mostrar HTML completo en los avisos.
- Exportación CSV actualizada para reflejar el estado de verificación y los totales por método/cajero del nuevo resumen.
- Nuevo endpoint `admin-post.php?action=csfx_lb_viewer` y vista embebida dentro de WP-Admin, para que el visor herede la sesión del usuario incluso en entornos locales sin HTTPS.

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
