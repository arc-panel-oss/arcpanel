{{-- Strings for scripts: only the keys of resources/js/i18n-keys.json, in the current locale. --}}
<script>window.__ap = Object.assign(window.__ap || {}, { i18n: {{ \Illuminate\Support\Js::from(\App\View\ScriptTranslations::current()) }} });</script>
