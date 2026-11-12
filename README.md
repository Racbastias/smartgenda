# Smartgenda

Agenda unificada: la pantalla **Hoy** muestra los pendientes del correo junto a la línea de tiempo del día; **Agenda** junta en una sola vista las agendas de **Lilienfeld (Zimbra)**, **Aminfo (Google Workspace)** y la **personal (iCloud)**, avisa los choques de horario y permite crear eventos que quedan guardados en la agenda original (se ven en el teléfono, Outlook, Zimbra, etc.).

Es multiusuario: cada persona entra con su propio usuario y conecta sus propias agendas. Nadie ve las agendas de otro.

## Etapas

1. **Agenda consolidada**: conectar agendas, vista día/semana/mes/lista, choques de horario, crear eventos.
2. **Correos resumidos** (en curso): la pantalla **Pendientes** muestra lo que piden los correos (responder, acciones, compromisos y plazos). Ya funciona con Zimbra (Lilienfeld); Gmail (Aminfo) llega con las credenciales de Google.
3. **Mi día**: reuniones, pendientes y correos por responder en una sola pantalla.

## Cómo se conecta cada agenda

| Agenda | Protocolo | Qué se pide |
|---|---|---|
| Zimbra (Lilienfeld) | API de Zimbra (SOAP + REST) | Dirección del webmail, correo, contraseña y, si hay doble factor, el código de 6 dígitos de la app autenticadora |
| iCloud (personal) | CalDAV (`caldav.icloud.com`) | Apple ID y una **contraseña de aplicación** (appleid.apple.com → Inicio de sesión y seguridad) |
| Google (Aminfo) | Google Calendar API, OAuth | Iniciar sesión con Google |

Las contraseñas y tokens se guardan cifrados con `APP_KEY`. Si se pierde `APP_KEY`, hay que volver a conectar las agendas.

### Zimbra con doble factor

Smartgenda inicia sesión en la API de Zimbra con la contraseña y el código de la app autenticadora, y se registra como **dispositivo de confianza**. Desde ahí la sesión se renueva sola (contraseña + token de confianza). Cuando Zimbra deja de aceptar ese token (lo define el administrador, por ejemplo cada 30 días) o si el servidor tiene desactivados los dispositivos de confianza, en **Mis agendas** aparece un campo para ingresar un código nuevo.

## Resumen de correos

En **Mis agendas**, una cuenta de Zimbra tiene el botón **Resumir correo**. Al activarlo, cada 30 minutos Smartgenda lee los correos nuevos de la bandeja de entrada (la primera vez, los de los últimos 3 días) y anota en **Pendientes** lo que piden. Cada acción se marca como hecha, se descarta o se agenda (abre el formulario de evento con el título y la fecha). El cuerpo del correo no se guarda.

Hay dos modos:

- **Sin costo (por defecto)**: reglas simples (`RuleSummarizer`). Toma los correos dirigidos a ti (no en copia) que no has respondido, detecta pedidos y preguntas ("¿podrías…?", "favor enviar", "necesito", "urgente") y fechas ("el viernes", "15/11", "mañana"). Muestra el inicio del correo como resumen y deja como prioridad baja los boletines y las notificaciones automáticas.
- **Con IA**: si existe `ANTHROPIC_API_KEY` (console.anthropic.com → API Keys, como secreto de GitHub; el deploy la escribe en el `.env`), los correos se envían a la API de Claude, que escribe el resumen y detecta compromisos con más precisión. Es pago por uso. El modelo se cambia con `ANTHROPIC_MODEL` (por defecto `claude-opus-5-5`). Si el modelo declina un correo por política, la API reintenta sola con el modelo alternativo por defecto.

## Desarrollo local

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan user:create tu@correo.cl "Tu Nombre" --admin
php artisan serve
```

Tests: `php artisan test`

## Deploy en Hostinger

Requiere un plan con SSH (Business sirve) y PHP 8.3 en hPanel → Avanzado → Configuración PHP.

**Una sola vez:**

1. hPanel → Avanzado → Acceso SSH: activar SSH y agregar la clave pública de deploy.
2. GitHub → Settings → Secrets and variables → Actions, crear:
   - `HOSTINGER_HOST`, `HOSTINGER_PORT` (65002) y `HOSTINGER_USER`, de la pantalla de Acceso SSH
   - `HOSTINGER_SSH_KEY`: la clave privada completa
   - `HOSTINGER_PATH`: `domains/<tu-sitio>/smartgenda`
   - `APP_URL` (opcional): si no se define, se usa `https://<tu-sitio>`
3. Fusionar a `main` (o Actions → Deploy a Hostinger → Run workflow). El primer deploy:
   - crea el `.env` de producción con su `APP_KEY` y una base SQLite,
   - corre las migraciones,
   - hace que el sitio sirva la carpeta `public` de la app (el `public_html` original queda como `public_html.bak-<fecha>`).
4. Abrir el sitio: la primera pantalla pide crear el usuario administrador.
5. hPanel → Avanzado → Cron Jobs, cada minuto:
   `cd ~/domains/<tu-sitio>/smartgenda && php artisan schedule:run`
   (sincroniza las agendas cada 10 minutos y resume el correo cada 30).

Desde ahí, cada push a `main` con tests en verde se publica solo. El `.env` y la base de datos del servidor no se tocan en los deploys siguientes. Para usar MySQL en vez de SQLite, editar `DB_*` en el `.env` del servidor.

### Credenciales de Google (para Aminfo)

1. console.cloud.google.com → proyecto nuevo → habilitar **Google Calendar API** y **Gmail API**.
2. Pantalla de consentimiento OAuth: tipo **Interno** (solo usuarios de aminfo.cl, sin revisión de Google).
3. Credenciales → ID de cliente OAuth → Aplicación web. URI de redirección: `https://agenda.midominio.cl/cuentas/google/callback`.
4. Guardar el ID y el secreto como secretos de GitHub `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET`. El próximo deploy los escribe en el `.env` del servidor (Actions → Deploy a Hostinger → Run workflow para aplicarlos sin esperar un push).

Si alguien fuera de aminfo.cl (por ejemplo Karin con otra cuenta de Google) necesita conectar Google, la pantalla de consentimiento debe ser **Externa** y esa persona se agrega como usuario de prueba.

## Dar acceso a otra persona

Un administrador entra a **Usuarios** y crea el acceso. La persona entra con esa contraseña y conecta sus agendas en **Mis agendas**.

## Estructura

- `app/Calendar/Providers/ZimbraProvider.php`: Zimbra por su API, con doble factor y dispositivo de confianza.
- `app/Calendar/Providers/CalDavProvider.php`: iCloud y CalDAV genérico (descubrimiento, lectura con repeticiones expandidas, creación).
- `app/Calendar/Providers/GoogleProvider.php`: Google Calendar.
- `app/Calendar/CalendarSync.php`: copia local de los eventos (ventana de 14 días atrás a 90 adelante).
- `app/Calendar/ConflictDetector.php`: choques de horario entre agendas distintas.
- `app/Inbox/`: resumen de correos (`InboxDigest` lee y guarda, `ClaudeSummarizer` resume con la API de Claude).
- `resources/views/components/`: layout (`<x-app-layout>`), modal único de eventos, selector de color, fila de cuenta.
- `public/css/app.css` y `public/js/app.js`: estilos y utilidades sin build. Alpine.js 3.14.9 (MIT) en `public/vendor/alpine/`.
- `public/vendor/fullcalendar/`: FullCalendar 6.1.15 (MIT), incluido para no depender de un CDN.
