'use strict';

// Powrót z pamięci historii (bfcache) musi ponownie przejść kontrolę PHP.
// Ukrywamy opuszczany dokument, aby jego stary widok nie mignął po przywróceniu.
window.addEventListener('pagehide', () => {
    document.documentElement.hidden = true;
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        window.location.reload();
        return;
    }
    document.documentElement.hidden = false;
});
