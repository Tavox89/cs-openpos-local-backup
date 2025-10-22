# CS OpenPOS Local Backup

Respaldo local (offline/online) de órdenes de OpenPOS:
- **Primario:** File System Access (carpeta elegida por el usuario) → un `.json` por orden en `YYYY-MM-DD/`.
- **Secundario:** IndexedDB (`csfx-orders/orders`) como índice para listados/UX.
- **Fallback:** descarga `.json` si FS Access no está disponible.

Incluye:
- UI flotante en el POS (badge) para elegir carpeta y exportar el último documento.
- **Cierre Diario**: visor HTML local (`assets/csfx-cierre.html`) con totales y exportación a CSV.
- **Script Node** (`tools/cierre.js`) para generar CSV por día en `Reports/`.

## Instalación
1. Copia la carpeta `cs-openpos-local-backup` al directorio `wp-content/plugins/`.
2. Activa el plugin en WP Admin.
3. Abre la pantalla del POS (OpenPOS) y pulsa **“Seleccionar carpeta…”** en el badge.
4. Opera normalmente; cada checkout genera un `.json` en la subcarpeta del día.

## Cierre Diario
- WP Admin → **CSFX Local Backup → Cierre Diario** → abre el visor en nueva pestaña.
- **Seleccionar carpeta (día)** → ver totales → **Descargar CSV** o **Imprimir**.

## Script Node
```bash
node tools/cierre.js "C:\\ClubSams\\OpenPOS-Backups" 2025-10-22

Genera Reports/csfx_cierre_YYYY-MM-DD.csv.

Estado / Checklist

WP Admin → CSFX Local Backup → Estado / Checklist.

Fase 1 (MVP) marcada por defecto como lista.

Fase 2 (Extensiones): marcar al completar.

Consideraciones

FS Access requiere HTTPS o localhost (Chrome/Edge/Brave en desktop). Safari/iOS: fallback por descarga + IndexedDB.

Anti-duplicados: recentGate(orderNumber, 8s).

Subcarpeta por día: YYYY-MM-DD.

device-id.txt en la raíz de backups (persistente por terminal).

Ref global: CSFX-<deviceId>-<YYYYMMDD-HHmmss>-<orderNumberOrIncId>.

Roadmap (Fase 2)

Comparar con WooCommerce (REST).

Cifrado opcional (AES-GCM).

Reimprimir ticket desde .json.

Reimportar orden perdida con validaciones.

BroadcastChannel para sincronizar visor auxiliar.
