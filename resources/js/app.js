import { registerComponents } from './components';
import { registerPlaceholders } from './placeholders';

document.addEventListener('alpine:init', () => {
    registerComponents(window.Alpine);
    registerPlaceholders(window.Alpine);
});
