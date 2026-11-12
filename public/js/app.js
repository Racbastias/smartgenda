// Smartgenda: utilidades compartidas (sin build). Alpine se carga después con defer.
(() => {
    const pad = n => String(n).padStart(2, '0');
    const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const hm = d => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const csrf = () => document.querySelector('meta[name=csrf-token]').content;

    window.sg = { pad, ymd, hm };

    /** Petición JSON con CSRF. Devuelve { ok, status, body }. */
    window.sgFetch = async (url, method = 'GET', data = null) => {
        const res = await fetch(url, {
            method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: data ? JSON.stringify(data) : null,
        });
        return { ok: res.ok, status: res.status, body: await res.json().catch(() => ({})) };
    };

    /** Abre el modal de nuevo evento. start/end: Date; title, notes: texto; allDay: bool. */
    window.sgNewEvent = (detail = {}) => window.dispatchEvent(new CustomEvent('sg:new-event', { detail }));

    /** Muestra el detalle de un evento: { id, title, when, agenda, color, location, description }. */
    window.sgShowEvent = (detail) => window.dispatchEvent(new CustomEvent('sg:show-event', { detail }));

    document.addEventListener('alpine:init', () => {
        Alpine.data('eventDetail', () => ({
            visible: false, busy: false, error: '', ev: {},
            open(ev) { this.ev = ev; this.error = ''; this.visible = true; window.sgModalOpen = true; },
            close() { this.visible = false; window.sgModalOpen = false; },
            async remove() {
                if (!confirm(`¿Eliminar «${this.ev.title}»? También se borra de ${this.ev.agenda}.`)) return;
                this.busy = true; this.error = '';
                const res = await sgFetch(`${document.body.dataset.eventsUrl}/${this.ev.id}`, 'DELETE');
                this.busy = false;
                if (!res.ok) { this.error = res.body.message || 'No se pudo eliminar el evento.'; return; }
                this.close();
                window.dispatchEvent(new CustomEvent('sg:event-saved'));
            },
        }));

        Alpine.data('eventModal', defaultAccount => ({
            visible: false, saving: false, error: '', duration: 60,
            form: {},
            open(d = {}) {
                const start = d.start instanceof Date ? d.start : new Date(Math.ceil(Date.now() / 1800000) * 1800000);
                const end = d.end instanceof Date ? d.end : new Date(start.getTime() + 3600000);
                this.duration = Math.max(15, Math.round((end - start) / 60000));
                this.form = {
                    title: d.title || '', notes: d.notes || '', account: d.account || defaultAccount,
                    allDay: !!d.allDay, date: ymd(start), start: hm(start), end: hm(end),
                };
                this.error = '';
                this.visible = true; window.sgModalOpen = true;
                this.$nextTick(() => this.$refs.title.focus());
            },
            close() { this.visible = false; window.sgModalOpen = false; },
            keepDuration() {
                const [h, m] = this.form.start.split(':').map(Number);
                const end = new Date(2000, 0, 1, h, m + this.duration);
                this.form.end = end.getDate() === 1 ? hm(end) : '23:59';
            },
            async save() {
                const f = this.form;
                let start, end;
                if (f.allDay) {
                    const next = new Date(`${f.date}T12:00`); next.setDate(next.getDate() + 1);
                    start = f.date; end = ymd(next);
                } else {
                    start = new Date(`${f.date}T${f.start}`); end = new Date(`${f.date}T${f.end}`);
                    if (end <= start) { this.error = 'La hora de fin debe ser posterior al inicio.'; return; }
                    start = start.toISOString(); end = end.toISOString();
                }
                this.saving = true; this.error = '';
                const res = await sgFetch(document.body.dataset.storeUrl || '/agenda/eventos', 'POST', {
                    calendar_account_id: f.account, title: f.title, start, end, all_day: f.allDay, description: f.notes || null,
                });
                this.saving = false;
                if (!res.ok) { this.error = res.body.message || 'No se pudo guardar el evento.'; return; }
                this.visible = false; window.sgModalOpen = false;
                window.dispatchEvent(new CustomEvent('sg:event-saved'));
            },
        }));
    });
})();
