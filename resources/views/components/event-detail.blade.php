{{-- Detalle de un evento con opción de eliminarlo. Se abre con sgShowEvent({...}). --}}
<div x-data="eventDetail" x-on:sg:show-event.window="open($event.detail)" x-on:keydown.escape.window="close()">
    <div class="modal-backdrop" x-show="visible" x-cloak x-transition.opacity x-on:click.self="close()">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="ed-title">
            <div class="grabber"></div>
            <div class="modal-head">
                <h2 id="ed-title" class="row" style="flex-wrap:nowrap"><span class="dot" :style="`--cal-color:${ev.color}`"></span><span x-text="ev.title" style="overflow-wrap:anywhere"></span></h2>
                <button type="button" class="btn btn-ghost btn-sm" x-on:click="close()" aria-label="Cerrar"><x-icon name="x"/></button>
            </div>
            <div class="modal-body">
                <p class="tnum" x-text="ev.when"></p>
                <p class="muted" x-text="ev.agenda"></p>
                <p x-show="ev.location" x-text="ev.location"></p>
                <p class="muted" x-show="ev.description" x-text="ev.description" style="white-space:pre-line;overflow-wrap:anywhere;max-height:200px;overflow:auto"></p>
                <p class="error-text" x-show="error" x-text="error" role="alert"></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-danger" x-on:click="remove()" :disabled="busy" x-text="busy ? 'Eliminando…' : 'Eliminar evento'"></button>
                <button type="button" class="btn" x-on:click="close()">Cerrar</button>
            </div>
        </div>
    </div>
</div>
