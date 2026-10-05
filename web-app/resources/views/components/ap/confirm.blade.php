{{-- The page's confirmation dialog (apConfirm, port of confirmAction.js). One per page, in the shell. --}}
<div x-data="apConfirm()" x-on:keydown="open && onKeydown($event)">
    <div class="ap-overlay" x-show="open" x-cloak x-on:click.self="cancel()">
        <div class="ap-modal" role="alertdialog" aria-modal="true" x-ref="dialog" aria-labelledby="ap-confirm-title" aria-describedby="ap-confirm-desc">
            <div class="ap-modal-head">
                <span class="ap-modal-icon" x-show="danger"><x-ap-icon name="alert" /></span>
                <div>
                    <h2 id="ap-confirm-title" x-text="title"></h2>
                    <p id="ap-confirm-desc" x-text="message"></p>
                </div>
            </div>
            <div class="ap-modal-foot">
                <button type="button" class="ap-btn ap-btn-ghost" x-on:click="cancel()">{{ __('Cancel') }}</button>
                <button type="button" class="ap-btn" x-bind:class="danger ? 'ap-btn-danger' : 'ap-btn-primary'" x-on:click="accept()" x-text="acceptLabel" autofocus></button>
            </div>
        </div>
    </div>
</div>
