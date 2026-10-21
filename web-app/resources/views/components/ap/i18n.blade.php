{{-- Strings for scripts: only the keys of resources/js/i18n-keys.json, in the current locale.
     A JSON data block, read by resources/js/i18n.js: the CSP allows no inline script (E39 W5 §9.2). --}}
<script type="application/json" id="ap-i18n">@json(\App\View\ScriptTranslations::current())</script>
