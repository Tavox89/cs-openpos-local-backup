# CS OpenPOS Local Backup

Respaldo local (offline/online) de órdenes de OpenPOS:
- **Primario:** File System Access en una carpeta elegida por el usuario → un `.json` por orden organizado en `YYYY-MM-DD/`.
- **Índice:** IndexedDB (`csfx-orders/orders`) mantiene el catálogo local para búsquedas rápidas.
- **Fallback:** descarga automática del `.json` cuando el navegador no expone File System Access.

## Novedades 1.1
- La carpeta de respaldo se persiste en IndexedDB y sólo se solicita la primera vez; el plugin revalida permisos en cada carga.
- Los checkouts se detectan en los endpoints reales de OpenPOS (`admin-ajax.php` con `pos_action` o `/wp-json/op/v1/<acción>`).
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
- En WP Admin ve a **CSFX Local Backup → Cierre Diario** y abre el visor (`assets/csfx-cierre.html`).
- Selecciona la carpeta del día, revisa totales por método/cajero, descarga CSV o imprime el resumen.

## Script Node
```bash
node tools/cierre.js "C:\\ClubSams\\OpenPOS-Backups" 2025-10-22
```
Genera `Reports/csfx_cierre_YYYY-MM-DD.csv` a partir de los `.json` de la fecha indicada.

## Estado / Checklist
WP Admin → **CSFX Local Backup → Estado / Checklist** para marcar el avance de Fase 1 (MVP) y Fase 2 (Extensiones).
