# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## ¿Qué es este proyecto?

Microservicio de **generación de QR de cobro bancario** construido en Laravel 12. Otros sistemas le envían una petición y este microservicio se conecta al banco, genera el QR y lo devuelve como imagen base64. El cliente final escanea el QR con su app bancaria y paga.

**Banco actual:** BNB (Banco Nacional de Bolivia)
**Banco próximo:** Banco Unión (estructura ya preparada)

---

## Comandos

```bash
# Instalar dependencias
composer install && npm install

# Levantar (2 terminales)
php artisan serve        # Terminal 1 — servidor PHP en localhost:8000
npm run dev              # Terminal 2 — Vite

# Base de datos
php artisan migrate

# BNB: cambiar contraseña del banco (OBLIGATORIO la primera vez)
php artisan bnb:update-credentials "NuevaPassword123*"

# Tests
npm run test
php artisan test --filter NombreDelTest

# Formateo de código
./vendor/bin/pint
```

---

## Variables de entorno clave (.env)

```env
# API Key propia — se envía en el header X-API-Key para proteger los endpoints
API_KEY=...

# Credenciales otorgadas por el BNB
BNB_ACCOUNT_ID=...
BNB_AUTHORIZATION_ID=...

# URLs del BNB (ambiente de pruebas por defecto)
BNB_AUTH_URL=http://test.bnb.com.bo/ClientAuthentication.API/api/v1/auth
BNB_QR_URL=http://test.bnb.com.bo/QRSimple.API/api/v1/main
```

Para producción cambiar las URLs a `https://www.bnb.com.bo/PortalBNB/Api/OpenBanking` y poner `APP_DEBUG=false`.

---

## Arquitectura

### Estructura de carpetas

```
app/
├── Banks/
│   ├── Contracts/
│   │   └── QrBankServiceInterface.php  ← interfaz que todos los bancos deben implementar
│   ├── BNB/
│   │   ├── BnbService.php              ← conexión con API del BNB
│   │   └── BnbController.php           ← endpoints HTTP del BNB
│   └── Union/                          ← preparado para Banco Unión
├── Console/Commands/
│   └── BnbUpdateCredentials.php        ← comando artisan para cambiar credenciales BNB
├── Http/Middleware/
│   └── ValidateApiKey.php              ← protege endpoints con X-API-Key header
└── Models/
    └── QrCode.php                      ← registro de todos los QRs generados

config/
└── bnb.php                             ← configuración del BNB (URLs, TTL del token)

routes/
└── api.php                             ← rutas organizadas por banco con prefijo /bnb/, /union/
```

### Flujo completo de un pago

```
Sistema externo
    │
    ▼
POST /api/bnb/qr/generate  (con X-API-Key)
    │
    ▼
BnbController → BnbService
    │
    ├── 1. Pide token al BNB (se cachea 50 min)
    ├── 2. Llama a getQRWithImageAsync con los datos del pago
    ├── 3. BNB devuelve { id, qr (base64), success }
    ├── 4. Se guarda en tabla qr_codes
    └── 5. Se devuelve qr_image al sistema externo
              │
              ▼
         Cliente escanea QR y paga
              │
              ▼
POST /api/bnb/qr/notification  (BNB llama a este endpoint automáticamente)
    │
    └── Actualiza status_id = 2 (Usado) en qr_codes
```

### Autenticación

- **Entre sistemas externos y este microservicio:** Header `X-API-Key`
- **Entre este microservicio y el BNB:** Bearer Token JWT (se obtiene automáticamente y se cachea)
- **Endpoint `/notification`:** Sin API Key — el banco BNB llama directamente a este endpoint

---

## Endpoints

Todos requieren headers:
```
Content-Type: application/json
Accept: application/json
X-API-Key: {valor de API_KEY en .env}   ← excepto /notification
```

### BNB - Banco Nacional de Bolivia

| Método | URL | Descripción | Auth |
|--------|-----|-------------|------|
| POST | `/api/bnb/qr/generate` | Genera QR de cobro | X-API-Key |
| POST | `/api/bnb/qr/status` | Consulta estado del QR | X-API-Key |
| POST | `/api/bnb/qr/cancel` | Cancela un QR | X-API-Key |
| POST | `/api/bnb/qr/list` | Lista QRs por fecha | X-API-Key |
| POST | `/api/bnb/qr/notification` | El banco avisa que se pagó | Sin auth |

### Ejemplos de body

**generate:**
```json
{
  "currency": "BOB",
  "gloss": "Pago reserva #123",
  "amount": 150.00,
  "single_use": true,
  "expiration_date": "2026-12-31",
  "additional_data": "referencia interna opcional",
  "destination_account_id": 1
}
```
- `currency`: `BOB` (bolivianos) o `USD` (dólares)
- `destination_account_id`: `1` = cuenta en moneda nacional, `2` = cuenta en moneda extranjera
- `qr_image` en la respuesta es base64 → usar como `<img src="data:image/png;base64,{qr_image}">`

**status:**
```json
{ "qr_id": 51 }
```

**cancel:**
```json
{ "qr_id": 51 }
```

**list:**
```json
{ "generation_date": "2026-03-24" }
```

---

## Base de datos

### Tabla `qr_codes`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `bank` | string | Banco que generó el QR: `bnb`, `union` |
| `bank_qr_id` | integer | ID del QR retornado por el banco |
| `currency` | string | `BOB` o `USD` |
| `amount` | decimal | Monto del pago |
| `gloss` | string | Descripción del pago |
| `single_use` | boolean | Si el QR es de un solo uso |
| `expiration_date` | date | Fecha de vencimiento |
| `additional_data` | string | Datos extra opcionales |
| `destination_account_id` | tinyint | 1=nacional, 2=extranjera |
| `status_id` | tinyint | Ver estados abajo |
| `qr_image` | text | Imagen en base64 |
| `voucher_id` | string | Código de bancarización (llega al pagar) |
| `source_bank` | integer | Banco desde el que se pagó |
| `transaction_date` | timestamp | Fecha del pago |
| `notification_received_at` | timestamp | Cuando llegó la notificación del banco |

**Estados del QR (`status_id`):**
- `1` = No Usado
- `2` = Usado (pagado)
- `3` = Expirado
- `4` = Con Error
- `5` = Cancelado (local, no viene del banco)

---

## Cómo agregar un nuevo banco (Banco Unión u otro)

1. Crear `app/Banks/Union/UnionService.php` implementando `QrBankServiceInterface`
2. Crear `app/Banks/Union/UnionController.php`
3. Crear `config/union.php` con las URLs y credenciales del banco
4. Agregar variables al `.env` y `.env.example`
5. Descomentar las rutas de Union en `routes/api.php`
6. Crear comando Artisan si el banco requiere cambio de credenciales inicial

---

## Notas importantes

- **Primera vez con credenciales BNB:** ejecutar `php artisan bnb:update-credentials` antes de cualquier otra cosa
- **Token BNB:** se obtiene automáticamente y se cachea. Si expira, se renueva solo
- **Notificación de pagos en desarrollo:** el banco no puede llamar a `localhost`. Usar [ngrok](https://ngrok.com): `ngrok http 8000` y registrar la URL pública en el banco
- **El banco devuelve el token en el campo `message`** (no en `token`), es un detalle particular de la API del BNB
