# Smartgenda

Agenda unificada: junta en una sola vista las agendas de **Lilienfeld (Zimbra)**, **Aminfo (Google Workspace)** y la **personal (iCloud)**, avisa los choques de horario y permite crear eventos que quedan guardados en la agenda original (se ven en el teléfono, Outlook, Zimbra, etc.).

Es multiusuario: cada persona entra con su propio usuario y conecta sus propias agendas. Nadie ve las agendas de otro.

## Etapas

1. **Agenda consolidada** (esta versión): conectar agendas, vista día/semana/mes/lista, choques de horario, crear eventos.
2. **Correos resumidos**: resumen diario de las casillas de Lilienfeld (IMAP) y Aminfo (Gmail) con pendientes y compromisos.
3. **Mi día**: reuniones, pendientes y correos por responder en una sola pantalla.

## Cómo se conecta cada agenda

| Agenda | Protocolo | Qué se pide |
|---|---|---|
| Zimbra (Lilienfeld) | CalDAV | Dirección del webmail, usuario y contraseña |
| iCloud (personal) | CalDAV (`caldav.icloud.com`) | Apple ID y una **contraseña de aplicación** (appleid.apple.com → Inicio de sesión y seguridad) |
| Google (Aminfo) | Google Calendar API, OAuth | Iniciar sesión con Google |

Las contraseñas y tokens se guardan cifrados con `APP_KEY`. Si se pierde `APP_KEY`, hay que volver a conectar las agendas.

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

Requiere un plan con acceso SSH (Premium o superior) y PHP 8.3.

**Una sola vez, en hPanel:**

1. Crear el sitio o subdominio (ej. `agenda.midominio.cl`) y una base de datos MySQL.
2. Activar SSH (hPanel → Avanzado → Acceso SSH) y agregar una clave pública. Guardar la privada para GitHub.
3. Por SSH, crear la carpeta de la app y el `.env`:
   ```bash
   mkdir -p ~/domains/agenda.midominio.cl/smartgenda
   cd ~/domains/agenda.midominio.cl/smartgenda
   # copiar .env.example como .env y completar: APP_URL, APP_ENV=production, APP_DEBUG=false,
   # DB_CONNECTION=mysql y los datos de la base, GOOGLE_* (ver abajo)
   ```
4. Hacer que el sitio apunte a la carpeta `public` de la app:
   ```bash
   cd ~/domains/agenda.midominio.cl
   mv public_html public_html.bak && ln -s smartgenda/public public_html
   ```
5. En GitHub → Settings → Secrets and variables → Actions, crear `HOSTINGER_HOST`, `HOSTINGER_PORT` (65002), `HOSTINGER_USER`, `HOSTINGER_SSH_KEY` y `HOSTINGER_PATH` (`domains/agenda.midominio.cl/smartgenda`).
6. Hacer un deploy (push a `main` o Actions → Deploy a Hostinger → Run workflow). Luego, por SSH, una sola vez:
   ```bash
   php artisan key:generate && php artisan config:cache
   php artisan user:create ruben@... "Rubén" --admin
   ```
7. hPanel → Avanzado → Cron Jobs: cada minuto
   `cd ~/domains/agenda.midominio.cl/smartgenda && php artisan schedule:run`
   (sincroniza las agendas cada 10 minutos).

Desde ahí, cada push a `main` con tests en verde se publica solo.

### Credenciales de Google (para Aminfo)

1. console.cloud.google.com → proyecto nuevo → habilitar **Google Calendar API** y **Gmail API**.
2. Pantalla de consentimiento OAuth: tipo **Interno** (solo usuarios de aminfo.cl, sin revisión de Google).
3. Credenciales → ID de cliente OAuth → Aplicación web. URI de redirección: `https://agenda.midominio.cl/cuentas/google/callback`.
4. Copiar el ID y el secreto a `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET` en el `.env` del servidor.

Si alguien fuera de aminfo.cl (por ejemplo Karin con otra cuenta de Google) necesita conectar Google, la pantalla de consentimiento debe ser **Externa** y esa persona se agrega como usuario de prueba.

## Dar acceso a otra persona

Un administrador entra a **Usuarios** y crea el acceso. La persona entra con esa contraseña y conecta sus agendas en **Mis agendas**.

## Estructura

- `app/Calendar/Providers/CalDavProvider.php`: Zimbra, iCloud y CalDAV genérico (descubrimiento, lectura con repeticiones expandidas, creación).
- `app/Calendar/Providers/GoogleProvider.php`: Google Calendar.
- `app/Calendar/CalendarSync.php`: copia local de los eventos (ventana de 14 días atrás a 90 adelante).
- `app/Calendar/ConflictDetector.php`: choques de horario entre agendas distintas.
- `public/vendor/fullcalendar/`: FullCalendar 6.1.15 (MIT), incluido para no depender de un CDN.
