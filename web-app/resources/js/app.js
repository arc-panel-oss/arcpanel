// Panel app entry (docs/design/05 §5.1): Alpine with one module per
// behaviour, and the Vue island loader. Pages work without this script;
// it only adds behaviour on top of server-rendered HTML.
import collapse from '@alpinejs/collapse';
import Alpine from 'alpinejs';
import { registerAll } from './alpine/index.js';
import { initDropdowns } from './dropdowns.js';
import { loadIslands } from './islands/loader.js';

document.documentElement.classList.add('has-js');
initDropdowns(document);

window.Alpine = Alpine;
Alpine.plugin(collapse);
registerAll(Alpine);
Alpine.start();

loadIslands(document);
