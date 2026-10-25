# QvaPay for WooCommerce

Pasarela de pago oficial de [QvaPay](https://www.qvapay.com) para tiendas WooCommerce. Acepta pagos en **USD** desde el balance QvaPay de tus clientes, con liquidación instantánea vía webhook firmado.

> **Estado:** v1.0.0 — flujo de *invoice + redirect*. Sin reembolsos automáticos ni suscripciones recurrentes (ver [Roadmap](#roadmap)).

---

## Características

- Integración directa con la API v2 de merchants de QvaPay.
- Verificación criptográfica del webhook (HMAC-SHA256) con ventana anti-replay de ±5 minutos.
- Idempotencia: pedidos ya procesados no se duplican aunque QvaPay reintente la entrega.
- Compatible con **checkout clásico** y con **WooCommerce Blocks** (Cart/Checkout block).
- Compatibilidad declarada con WooCommerce HPOS (Custom Order Tables).
- Override opcional de la URL del webhook para sitios tras proxy/túnel (Cloudflare Tunnel, ngrok).
- Modo debug opcional que registra peticiones y webhooks en *WooCommerce → Status → Logs* (`source: qvapay`).
- Aviso en el admin cuando la tienda no está en USD o no se sirve por HTTPS.

## Requisitos

| Componente        | Versión |
|-------------------|---------|
| WordPress         | ≥ 6.0   |
| PHP               | ≥ 7.4   |
| WooCommerce       | ≥ 7.0   |
| Moneda de tienda  | **USD** (la API de QvaPay opera únicamente en USD) |
| HTTPS             | Obligatorio (QvaPay solo entrega webhooks a endpoints SSL) |

La pasarela se ocultará automáticamente del checkout si la moneda no es USD o si faltan las credenciales.

## Instalación

1. Descarga el ZIP desde la pestaña [Releases](../../releases) del repositorio.
2. En WordPress: *Plugins → Añadir nuevo → Subir plugin*.
3. Sube el ZIP y actívalo.
4. Crea una app en `https://www.qvapay.com/dev/app`.
   - **Callback URL** de la app: `https://TU-DOMINIO/wp-json/qvapay/v1/webhook`
5. En *WooCommerce → Settings → Payments → QvaPay*, pega `app-id` y `app-secret`.
6. Marca *Activar QvaPay como método de pago* y guarda.

## Cómo funciona

1. El cliente elige **QvaPay** en el checkout de WooCommerce.
2. El plugin crea una factura vía `POST /api/v2/create_invoice` y redirige al cliente a `https://www.qvapay.com/pay/{transaction_uuid}`.
3. El cliente paga con su balance de QvaPay.
4. QvaPay envía un webhook firmado con HMAC-SHA256 al endpoint `/wp-json/qvapay/v1/webhook`.
5. El plugin verifica firma + timestamp y llama a `payment_complete()` sobre el pedido.

### Endpoint del webhook

```
POST https://<tu-sitio>/wp-json/qvapay/v1/webhook
Headers:
  x-qvapay-signature: sha256=<hmac hex del cuerpo crudo, clave = app-secret>
  x-qvapay-timestamp: <unix timestamp, ventana ±300 s>
```

Reglas de aceptación (en orden):

1. Cabeceras presentes → si falta alguna, 401.
2. `|now − timestamp| ≤ 300 s` → fuera de rango, 401.
3. Firma calculada sobre el cuerpo **crudo** y comparada con `hash_equals` → mismatch, 401.
4. JSON válido con `transaction_uuid`, `remote_id`, `status` → faltante, 400.
5. Los webhooks de un mismo pedido se procesan de uno en uno (bloqueo por pedido en la base de datos): dos facturas del mismo pedido o un reenvío no se pisan. Si otro webhook del pedido sigue en curso más de 10 s → 503 (`order_busy`) y QvaPay reintenta. Si la base de datos no admite bloqueos con nombre (`GET_LOCK`), el webhook se procesa sin bloqueo y se registra en el log.
6. Pedido existe y su `payment_method == 'qvapay'`.
7. El `transaction_uuid` tiene que ser una de las facturas emitidas para ese pedido → si no, 400 (`uuid_mismatch`).
8. Si el pedido ya está en `processing` / `completed` / `refunded` → 200 con `already_processed=true` (idempotencia). Si llega pagada OTRA factura del mismo pedido, se deja una nota (una sola, aunque QvaPay reenvíe el webhook) para revisar la devolución.
9. `status == 'paid'` → `payment_complete()` y nota de pedido, salvo que el monto pagado sea menor que el total actual del pedido: entonces queda `on-hold` con una nota. El monto es el de la factura al emitirla; para facturas emitidas por la v1.0.0 (sin monto guardado) se usa el `amount` del webhook firmado, y si tampoco viene, el pedido queda `on-hold` para revisión. Otros estados se acusan con 200 y se loguean.

## Configuración

Los ajustes viven en la opción `woocommerce_qvapay_settings`:

| Campo         | Descripción                                                                 |
|---------------|-----------------------------------------------------------------------------|
| `enabled`     | Mostrar la pasarela en el checkout.                                         |
| `title`       | Texto que verá el cliente.                                                  |
| `description` | Descripción mostrada bajo el título.                                        |
| `app_id`      | UUID de tu app en `https://www.qvapay.com/dev/app`.                         |
| `app_secret`  | Clave secreta de tu app. Se usa también para verificar la firma del webhook. |
| `webhook_url_override` | Opcional. Dominio público (o URL completa) a usar como webhook. Útil tras proxy/túnel (Cloudflare Tunnel, ngrok). Vacío = autodetectar. |
| `webhook_url` | URL de webhook efectiva (solo lectura). Es la que se envía a QvaPay y la que debes copiar al *Callback URL*. |
| `debug`       | Registra peticiones y webhooks en *WooCommerce → Status → Logs*.            |

Estado por pedido: cada factura QvaPay emitida se guarda en el meta `_qvapay_invoices` (`uuid => monto`) y la última también en `_qvapay_transaction_uuid`. «Pagar este pedido» en WooCommerce emite una factura nueva cada vez, y el cliente puede pagar cualquiera de ellas.

## Seguridad

- Firma `x-qvapay-signature` verificada con `hash_equals` (comparación timing-safe).
- Validación del timestamp `x-qvapay-timestamp` con ventana de ±5 minutos (mitiga ataques de replay).
- Idempotencia por estado: pedidos en `processing`/`completed`/`refunded` no se reprocesan.
- Si el `transaction_uuid` recibido no es una de las facturas emitidas para el pedido, el webhook se rechaza con 400.
- El `app-secret` se almacena como password en los ajustes de WooCommerce.

## Alcance v1

Solo flujo de **invoice + redirect**. **No** incluye:

- Reembolsos (la API de QvaPay no expone aún un endpoint público de refunds).
- Suscripciones recurrentes (`authorize_payments` / `charge`).
- Entorno sandbox.

## Roadmap

- **Futuro** — Reembolsos automáticos cuando QvaPay exponga un endpoint público; suscripciones vía `authorize_payments` + `charge`.

## Estructura del proyecto

```
qvapay-for-woocommerce.php       Punto de entrada del plugin, constantes y bootstrap.
uninstall.php                    Borra woocommerce_qvapay_settings al desinstalar.
includes/
  class-qvapay-plugin.php             Singleton: registra gateway, rutas REST, action links, Blocks.
  class-qvapay-gateway.php            WC_Payment_Gateway: settings, is_available, process_payment.
  class-qvapay-api-client.php         Wrapper HTTP de la API v2 de merchants.
  class-qvapay-webhook.php            REST: /wp-json/qvapay/v1/webhook (verificación HMAC).
  class-qvapay-blocks-integration.php Wire-up del Payment Method Type para Blocks (lazy).
  class-qvapay-blocks-method.php      AbstractPaymentMethodType para WooCommerce Blocks.
  class-qvapay-logger.php             Wrapper de wc_get_logger() (source: qvapay).
assets/
  images/icon.svg                Icono mostrado en el checkout.
  js/checkout-blocks.js          Registro del método en el Cart/Checkout block (vanilla JS, sin build).
languages/                       Catálogos .po/.mo (text-domain: qvapay-for-woocommerce).
readme.txt                       Manifest estilo WordPress.org.
build.sh                         Genera dist/qvapay-for-woocommerce-<ver>.zip.
.github/workflows/release.yml    Publica el zip en GitHub Releases al hacer push de un tag v*.
.wordpress-org/                  Assets para el directorio WP.org (no se empaquetan).
```

## Desarrollo y release

- **Empaquetar:** `bash build.sh` produce `dist/qvapay-for-woocommerce-<versión>.zip` con `qvapay-for-woocommerce/` como directorio raíz, listo para subir vía *Plugins → Añadir nuevo → Subir plugin*.
- **Publicar release:** crea y empuja un tag `vX.Y.Z` que coincida con el header `Version:` de `qvapay-for-woocommerce.php`. El workflow `.github/workflows/release.yml` valida la coincidencia, construye el zip y lo adjunta a la GitHub Release.
- **Subir a WordPress.org:** sincroniza el contenido del zip al `trunk/` del SVN del plugin y los assets de `.wordpress-org/` a `/assets/` del SVN.

Al subir versión, mantén estos tres puntos sincronizados:

- `Version:` en el header de `qvapay-for-woocommerce.php`
- Constante `QVAPAY_WC_VERSION` en el mismo archivo
- `Stable tag:` en `readme.txt`

## Licencia

Publicado bajo la **GNU General Public License v2.0 o posterior (GPL-2.0-or-later)**.

> QvaPay for WooCommerce
> Copyright (C) 2026 QvaPay
>
> This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.
>
> This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

Texto completo en [`LICENSE`](LICENSE). Si redistribuyes o haces fork, debes mantener una licencia compatible con GPL-2.0.

## Enlaces

- [QvaPay](https://www.qvapay.com)
- [Panel de desarrolladores QvaPay](https://www.qvapay.com/dev/app)
