const SW_UPDATE_INTERVAL_MS = 60 * 1000;
const REGISTERED_FLAG = '__egg13PwaRegistrationInitialized';

function isNativeWrapper() {
  const userAgent = navigator.userAgent || '';
  const isCapacitorUa = /Capacitor/i.test(userAgent);
  const hasCapacitorRuntime = Boolean(window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function'
    ? window.Capacitor.isNativePlatform()
    : false);

  return isCapacitorUa || hasCapacitorRuntime;
}

function supportsServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    return false;
  }

  if (isNativeWrapper()) {
    return false;
  }

  if (window.isSecureContext) {
    return true;
  }

  return location.hostname === 'localhost' || location.hostname === '127.0.0.1';
}

function resolveScopePath() {
  return new URL('./', document.baseURI).pathname;
}

async function registerServiceWorker() {
  if (window[REGISTERED_FLAG]) {
    return;
  }
  window[REGISTERED_FLAG] = true;

  if (!supportsServiceWorker()) {
    return;
  }

  const baseUrl = new URL('./', document.baseURI);
  const serviceWorkerUrl = new URL('sw.js', baseUrl).toString();
  const scope = resolveScopePath();

  try {
    const registration = await navigator.serviceWorker.register(serviceWorkerUrl, { scope });

    if (registration.waiting) {
      registration.waiting.postMessage({ type: 'SKIP_WAITING' });
    }

    registration.addEventListener('updatefound', () => {
      const installingWorker = registration.installing;
      if (!installingWorker) {
        return;
      }

      installingWorker.addEventListener('statechange', () => {
        if (installingWorker.state === 'installed' && navigator.serviceWorker.controller) {
          installingWorker.postMessage({ type: 'SKIP_WAITING' });
        }
      });
    });

    setInterval(() => {
      registration.update().catch(() => {});
    }, SW_UPDATE_INTERVAL_MS);

    let reloading = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (reloading) {
        return;
      }

      reloading = true;
      window.location.reload();
    });
  } catch (error) {
    console.warn('PWA service worker registration failed.', error);
  }
}

window.addEventListener('load', () => {
  registerServiceWorker().catch(() => {});
});
