# CS OpenPOS Local Backup

Respaldo local (offline/online) de órdenes de OpenPOS:
- **Primario:** File System Access en una carpeta elegida por el usuario → un `.json` por orden organizado en `YYYY-MM-DD/`.
- **Índice:** IndexedDB (`csfx-orders/orders`) mantiene el catálogo local para búsquedas rápidas.
- **Fallback:** descarga automática del `.json` cuando el navegador no expone File System Access.

## Novedades 1.2.4 – Firma HMAC y visor seguro
- Cada respaldo `.json` incorpora una firma HMAC (HS256) generada con las *WP salts* del sitio: el POS la añade al guardar y tanto el visor como los endpoints REST la validan antes de procesar la orden.
- El visor marca como **“Archivo alterado”** cualquier documento con firma inválida y advierte cuando la firma falta o no pudo validarse (navegador sin WebCrypto).
- Las descargas manuales (botón “Exportar último…”) re-firman el documento en el momento para impedir que se exporten copias sin integridad comprobada.
- La clave HMAC se persiste en WordPress (`csfx_lb_signature_secret_v1`), evitando falsos positivos cuando el sitio rota las *salts* o está en modo local; si un respaldo antiguo trae firma inválida puedes pulsar **Re-firmar** en el visor (requiere permisos de escritura en la carpeta) para actualizarla.
- El botón **Re-firmar** funciona incluso en navegadores sin `crypto.subtle`: el visor solicita la firma al backend y reescribe el archivo automáticamente cuando se concede acceso de escritura a la carpeta.

## Novedades 1.2.0
- El plugin captura el pedido al recibir el evento `openpos.start.payment`, guardando un respaldo inmediatamente incluso cuando el POS está en modo offline.
- Se intercepta la escritura en la store `orders` de IndexedDB mediante `csfx-idb-tap.js`, creando el respaldo `pending` en cuanto OpenPOS guarda la venta offline.
- La confirmación definitiva sigue dependiendo de la respuesta del servidor para actualizar el documento y cerrar el flujo pendiente.
- Gating estricto de red: solo se interceptan los POST finales (`/wp-json/op/v1/order/create`, `/wp-json/op/v1/transaction/create`, `admin-ajax.php?action=openpos&pos_action=order|transaction`). El resto de peticiones (p. ej. `pos-state`) quedan libres para no frenar el POS. Puedes ajustar este comportamiento con `csfx_lb_strict_endpoints` o `window.CSFX_LB_SETTINGS.strict_endpoints`.
- Los pending generados desde eventos y red son “thin”: se deduplican vía debounce por `order_local_id`, guardan únicamente cart/payments/totals compactados (incluyendo cajero) y difieren la escritura en disco hasta la confirmación (salvo el modo offline-idb).
- En modo offline (`offline-idb`) se persiste el documento completo (`orderData`) para que los JSON contengan todos los detalles originales.
- Nuevas opciones en `CSFX_LB_SETTINGS`: `network_hooks` (`none`, `confirm-only`, `full`), `confirm_on_response`, `write_pending_fs` (`always` por defecto), `prune_every_n_puts` (50) y `debounce_ms` (180). Todos son filtrables para instalaciones con necesidades específicas.
- `require_backup_folder` (por defecto `true`): bloquea los requests de checkout (`order/create`, `transaction/create` y sus variantes AJAX) hasta que se haya concedido acceso a una carpeta. Se muestra un modal con acciones para seleccionar carpeta desde el propio POS (el badge sigue intacto).
- `require_backup_fs` (por defecto `false`): fuerza un overlay de “Respaldo local requerido”, bloquea cualquier intento de cobro y evita el fallback por descarga hasta que el usuario seleccione la carpeta local.
- Los pending “thin” reconstruyen totales cuando OpenPOS no los entrega (suma de ítems o pagos), garantizando montos consistentes en el visor de cierre incluso en modo offline.
- Verificación directa contra WooCommerce desde el visor de cierre: botones de verificación individual/general, notas automáticas (“Cierre verificado online/offline”) y flujo de re-sync cuando la orden no existe aún en Woo.

## Novedades 1.1
- La carpeta de respaldo se persiste en IndexedDB y sólo se solicita la primera vez; el plugin revalida permisos en cada carga.
- Los checkouts se detectan en los endpoints reales de OpenPOS (`admin-ajax.php?action=openpos&pos_action=...` o `/wp-json/op/v1/<acción>` como `order/create` o `transaction/create`), incluso cuando `pos_action` sólo viene en la URL o dentro del campo `order` del formulario (cambio de OpenPOS 8).
- Se crea un respaldo `pending` previo al pago y se actualiza a `confirmed` cuando llega el `order_number`/`order_id`, reescribiendo el archivo definitivo.
- Botón “Cambiar carpeta…” para limpiar el handle almacenado y seleccionar otra ubicación.
- `window.CSFX_DEBUG = true` habilita logs detallados sobre permisos, IndexedDB y detección de checkouts.
- Detección automática de la vista POS (admin, `/pos/`, `/openpos_sw`, cocina, etc.) sin depender de parámetros personalizados.
- El modo REST se determina mediante `apply_filters('pos_enable_rest_ful', true)` para respetar la configuración de OpenPOS.
- El badge “Respaldo local” ahora vive en la esquina inferior izquierda, lejos del panel de acciones del POS.
- El script del POS usa *cache busting* vía `filemtime`, obligando al navegador a descargar la última versión tras cada actualización.

## Requisitos de navegador
- File System Access está soportado en Chrome, Edge, Opera y navegadores Chromium en escritorio (HTTPS o `localhost`).
- En Safari/iOS y navegadores sin FS Access, el respaldo cae automáticamente al modo descarga + IndexedDB.
- El botón **“Seleccionar carpeta…”** sólo aparecerá operativo en navegadores con File System Access habilitado; en otros casos permanece deshabilitado aunque se pueda exportar el último respaldo.
- Necesitas un **contexto seguro**: usa HTTPS o `http://localhost`. Si trabajas con un dominio `http://` personalizado verás el mensaje “requiere HTTPS o localhost” porque el navegador bloquea la API.

## Instalación y uso
1. Copia `cs-openpos-local-backup` dentro de `wp-content/plugins/` y activa el plugin.
2. Abre la pantalla de facturación de OpenPOS; el badge “Respaldo local” aparecerá en la esquina inferior izquierda.
3. Pulsa **“Seleccionar carpeta…”** una sola vez, elige la carpeta raíz para tus respaldos y concede permiso.
4. Cada venta generará un archivo `.json` en la subcarpeta del día y un registro en IndexedDB.

### Cambiar la carpeta de respaldo
- Desde el badge, pulsa **“Cambiar carpeta…”** para borrar el handle guardado. El indicador pasará a rojo y podrás escoger una nueva carpeta con **“Seleccionar carpeta…”**.

### Recuperar respaldos desde IndexedDB
1. Abre las DevTools del navegador → pestaña **Application** → **IndexedDB** → base `csfx-orders` → store `orders`.
2. Exporta los registros necesarios (pending o confirmed) y, si la carpeta original no está disponible, usa el botón **Exportar último** o exporta manualmente desde los datos almacenados.

## Cierre Diario
- En WP Admin ve a **CSFX Local Backup → Cierre Diario**; el visor se carga embebido dentro del panel (o puedes abrirlo en pestaña nueva con el botón “Abrir visor en pestaña nueva”).
- Selecciona la carpeta del día, revisa totales por método/cajero, descarga CSV o imprime el resumen.
- El botón **Verificación general** ejecuta la comparación de todas las órdenes cargadas contra WooCommerce (requiere sesión con permisos `manage_woocommerce`).
- En entornos locales sin HTTPS el visor sigue funcionando porque se sirve mediante `admin-post.php?action=csfx_lb_viewer`, reutilizando tu sesión actual de WordPress.
- Si una orden no existe, pulsa **Re-sync**: se crea automáticamente en WooCommerce usando el snapshot (ítems, totales, pagos y vuelto), se registran las transacciones POS y se vuelve a verificar en el acto.

### Verificación vs WooCommerce
- Cada fila ofrece **Detalle**, **Verificar/Reverificar** y, si aplica, **Re-sync** (para intentar localizar órdenes aún no creadas en WooCommerce). Cuando la orden existe y coincide, se agrega la nota “CSFX Local Backup: Cierre verificado online/offline” en el pedido.
- Estados visibles en la tabla y en el panel derecho:
  - **Sin sinc.** (pendiente)
  - **Verificando…**
  - **Verificado** / **Verificado con advertencias** (mismatches menores o duplicados detectados)
  - **Con diferencias** (totales, productos o pagos no coinciden)
  - **Pedido no encontrado** (habilita el botón Re-sync)
  - **Error de verificación** (problemas de red o permisos)
- Antes de iniciar la comparación, el visor valida la firma HMAC del respaldo: si falta o no coincide, la orden aparece como “Archivo alterado” y se listan las advertencias correspondientes.
- Las advertencias y diferencias listadas muestran qué campo no coincidió (totales, métodos de pago, ítems duplicados, etc.) y se incluyen en la exportación CSV como columna `Status`.
- El botón **Woo** abre el pedido en el admin para una revisión manual. Si el re-sync encuentra o crea la orden, se añaden las notas “CSFX Local Backup: Pedido creado mediante resync automático.” y “CSFX Local Backup: Resync ejecutado desde el visor de cierre”.
- Si no tienes sesión o permisos suficientes, el visor avisa y no ejecuta la verificación hasta que accedas con un usuario que tenga `manage_woocommerce`.

## Script Node
```bash
node tools/cierre.js "C:\\ClubSams\\OpenPOS-Backups" 2025-10-22
```
Genera `Reports/csfx_cierre_YYYY-MM-DD.csv` a partir de los `.json` de la fecha indicada.

## Estado / Checklist
WP Admin → **CSFX Local Backup → Estado / Checklist** para marcar el avance de Fase 1 (MVP) y Fase 2 (Extensiones).
