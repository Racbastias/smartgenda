@props(['accounts'])
{{-- Modal único para crear eventos. En móvil se muestra como bottom sheet. Se abre con sgNewEvent({...}). --}}
<div x-data="eventModal(@js($accounts->firstWhere('is_default', true)?->id ?? $accounts->first()->id))"
     x-on:sg:new-event.window="open($event.detail)" x-on:keydown.escape.window="close()">
    <div class="modal-backdrop" x-show="visible" x-cloak x-transition.opacity x-on:click.self="close()">
        <form class="modal" role="dialog" aria-modal="true" aria-labelledby="ev-title-label" x-on:submit.prevent="save()">
            <div class="grabber"></div>
            <div class="modal-head">
                <h2 id="ev-title-label">Nuevo evento</h2>
                <button type="button" class="btn btn-ghost btn-sm" x-on:click="close()" aria-label="Cerrar"><x-icon name="x"/></button>
            </div>
            <div class="modal-body">
                <input class="title-input" x-ref="title" x-model="form.title" placeholder="Título del evento" required maxlength="200" aria-label="Título">

                <div class="field"><span>Agenda</span>
                    <div class="cal-options">
                        @foreach ($accounts as $account)
                            <label class="cal-option" style="--cal-color: {{ $account->color }}">
                                <input type="radio" name="calendar_account_id" value="{{ $account->id }}" x-model.number="form.account">
                                <span class="dot"></span> {{ $account->name }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <label class="field"><span>Fecha</span><input class="input tnum" type="date" x-model="form.date" required></label>
                <label class="check"><input type="checkbox" x-model="form.allDay"> Todo el día</label>
                <div class="grid-2" x-show="!form.allDay">
                    <label class="field"><span>Inicio</span><input class="input tnum" type="time" step="300" x-model="form.start" x-on:change="keepDuration()" :required="!form.allDay"></label>
                    <label class="field"><span>Fin</span><input class="input tnum" type="time" step="300" x-model="form.end" :required="!form.allDay"></label>
                </div>
                <label class="field"><span>Notas</span><textarea class="input" rows="3" x-model="form.notes" maxlength="5000"></textarea></label>
                <p class="error-text" x-show="error" x-text="error" role="alert"></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" x-on:click="close()">Cancelar</button>
                <button class="btn btn-primary" :disabled="saving" x-text="saving ? 'Guardando…' : 'Guardar'">Guardar</button>
            </div>
        </form>
    </div>
</div>
