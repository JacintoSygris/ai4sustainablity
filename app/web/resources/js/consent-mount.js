import { getBrowserConsent } from '../../public/consent/core.mjs';
import { mountConsent } from '../../public/consent/ui.mjs';

function mount() {
    const host = document.getElementById('airis-consent-root');
    if (host) mountConsent(host, getBrowserConsent());
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true });
else mount();
