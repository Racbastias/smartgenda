<x-app-layout title="Mi cuenta">
    <div class="page-head">
        <div><h1>Mi cuenta</h1><p class="sub">{{ $user->email }}</p></div>
    </div>

    <div class="profile-grid">
        {{-- Avisos --}}
        <section class="card card-pad stack profile-push" x-data="pushPanel(@js($vapidKey))" aria-labelledby="avisos">
            <div class="profile-head">
                <span class="profile-icon is-orange"><x-icon name="bell"/></span>
                <div><h2 id="avisos">Avisos</h2><p class="muted small">Te aviso en el teléfono o el computador cuando llega algo que pide tu acción y antes de cada reunión.</p></div>
            </div>

            <div class="push-state" x-show="state === 'on'" x-cloak>
                <p class="push-ok"><x-icon name="check"/> Activos en este dispositivo.</p>
                <div class="row" style="flex-wrap:wrap">
                    <button type="button" class="btn" x-on:click="test()" :disabled="busy">Enviar un aviso de prueba</button>
                    <button type="button" class="btn btn-ghost" x-on:click="disable()" :disabled="busy">Desactivar aquí</button>
                </div>
            </div>
            <div class="push-state" x-show="state === 'off'" x-cloak>
                <button type="button" class="btn btn-primary" x-on:click="enable()" :disabled="busy"><x-icon name="bell"/> Activar avisos en este dispositivo</button>
            </div>
            <p class="hint" x-show="state === 'ios'" x-cloak>En iPhone, los avisos funcionan con Smartgenda en la pantalla de inicio: en Safari toca <b>Compartir → Agregar a inicio</b>, abre la app desde ese ícono y vuelve aquí.</p>
            <p class="hint" x-show="state === 'denied'" x-cloak>Los avisos están bloqueados para este sitio. Actívalos en los ajustes de notificaciones del navegador o del teléfono y vuelve a intentarlo.</p>
            <p class="hint" x-show="state === 'unsupported'" x-cloak>Este navegador no permite avisos. Prueba con Chrome, Edge, Firefox o Safari actualizados.</p>
            <p class="small" x-show="msg" x-text="msg" role="status" x-cloak></p>

            <form method="post" action="{{ route('profile.notifications') }}" class="stack push-prefs">
                @csrf @method('patch')
                <label class="check"><input type="hidden" name="notify_mail" value="0"><input type="checkbox" name="notify_mail" value="1" @checked($user->notify_mail) onchange="this.form.submit()"> Correos nuevos que requieren tu acción o son de prioridad alta</label>
                <label class="check"><input type="hidden" name="notify_meetings" value="0"><input type="checkbox" name="notify_meetings" value="1" @checked($user->notify_meetings) onchange="this.form.submit()"> Reuniones, 10 minutos antes (con el enlace para unirte)</label>
                <noscript><button class="btn btn-sm">Guardar</button></noscript>
            </form>

            @if ($devices->isNotEmpty())
                <div>
                    <p class="field-label">Dispositivos con avisos</p>
                    <ul class="device-list">
                        @foreach ($devices as $d)
                            <li>
                                <span>{{ $d->device ?: 'Dispositivo' }} <span class="muted small">· desde {{ $d->created_at->setTimezone($user->timezone ?: config('app.work_timezone'))->locale('es')->isoFormat('D MMM') }}</span></span>
                                <form method="post" action="{{ route('push.unsubscribe') }}">@csrf @method('delete')<input type="hidden" name="id" value="{{ $d->id }}"><button class="btn btn-ghost btn-sm" title="Quitar"><x-icon name="x"/><span class="sr-only">Quitar</span></button></form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- Contraseña --}}
        <form class="card card-pad stack" method="post" action="{{ route('profile.password') }}" aria-labelledby="clave">
            @csrf @method('put')
            <div class="profile-head">
                <span class="profile-icon"><x-icon name="lock"/></span>
                <div><h2 id="clave">Contraseña</h2><p class="muted small">Al cambiarla se cierran tus sesiones en otros equipos.</p></div>
            </div>
            <label class="field"><span>Contraseña actual</span><input class="input" name="current_password" type="password" autocomplete="current-password" required></label>
            <label class="field"><span>Contraseña nueva (mín. 10 caracteres)</span><input class="input" name="password" type="password" autocomplete="new-password" minlength="10" required></label>
            <label class="field"><span>Repite la nueva</span><input class="input" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" required></label>
            <p><button class="btn btn-primary">Cambiar contraseña</button></p>
        </form>

        {{-- Datos --}}
        <form class="card card-pad stack" method="post" action="{{ route('profile.update') }}" aria-labelledby="datos">
            @csrf @method('patch')
            <div class="profile-head">
                <span class="profile-icon"><x-icon name="user"/></span>
                <div><h2 id="datos">Tus datos</h2><p class="muted small">Tu nombre aparece en el saludo de Hoy.</p></div>
            </div>
            <label class="field"><span>Nombre</span><input class="input" name="name" value="{{ old('name', $user->name) }}" maxlength="100" required></label>
            <label class="field"><span>Correo para entrar</span><input class="input" value="{{ $user->email }}" disabled></label>
            <p><button class="btn">Guardar</button></p>
        </form>
    </div>

    <x-slot:scripts>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('pushPanel', (vapidKey) => ({
                state: 'loading', msg: '', busy: false,
                async init() {
                    const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
                    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
                    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                        this.state = ios && !standalone ? 'ios' : 'unsupported'; return;
                    }
                    if (Notification.permission === 'denied') { this.state = 'denied'; return; }
                    const reg = await navigator.serviceWorker.register('/sw.js');
                    const sub = await reg.pushManager.getSubscription();
                    this.state = sub ? 'on' : 'off';
                    if (sub) sgFetch(@js(route('push.subscribe')), 'POST', this.payload(sub)); // mantiene el registro al día
                },
                key() {
                    const pad = '='.repeat((4 - vapidKey.length % 4) % 4);
                    const raw = atob((vapidKey + pad).replace(/-/g, '+').replace(/_/g, '/'));
                    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
                },
                payload(sub) {
                    const j = sub.toJSON();
                    const enc = (PushManager.supportedContentEncodings || ['aes128gcm']);
                    return { endpoint: j.endpoint, keys: j.keys, contentEncoding: enc.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' };
                },
                async enable() {
                    this.busy = true; this.msg = '';
                    try {
                        const perm = await Notification.requestPermission();
                        if (perm !== 'granted') { this.state = perm === 'denied' ? 'denied' : 'off'; return; }
                        const reg = await navigator.serviceWorker.ready;
                        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.key() });
                        const res = await sgFetch(@js(route('push.subscribe')), 'POST', this.payload(sub));
                        if (!res.ok) throw new Error(res.body.message || 'No se pudo guardar.');
                        this.state = 'on'; this.msg = res.body.message;
                    } catch (e) { this.msg = 'No se pudieron activar: ' + e.message; }
                    finally { this.busy = false; }
                },
                async disable() {
                    this.busy = true; this.msg = '';
                    const reg = await navigator.serviceWorker.ready;
                    const sub = await reg.pushManager.getSubscription();
                    if (sub) { await sgFetch(@js(route('push.unsubscribe')), 'DELETE', { endpoint: sub.endpoint }); await sub.unsubscribe(); }
                    this.state = 'off'; this.busy = false; this.msg = 'Avisos desactivados en este dispositivo.';
                },
                async test() {
                    this.busy = true;
                    const res = await sgFetch(@js(route('push.test')), 'POST', {});
                    this.msg = res.body.message || (res.ok ? 'Enviado.' : 'No se pudo enviar.');
                    this.busy = false;
                },
            }));
        });
    </script>
    </x-slot:scripts>
</x-app-layout>
