const api = globalThis.browser || globalThis.chrome;
// Service worker: the only place that talks to WordPress. Content scripts can't
// fetch cross-origin, but a worker with host_permissions can.

const DEFAULTS = { wpUrl: 'https://www.shaunroot.net/wp-json/umack/v1', apiKey: '', enabled: false, delaySeconds: 20, replied: [] };

api.action.onClicked.addListener(() => api.runtime.openOptionsPage());

api.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg && msg.type === 'wp') {
    wpCall(msg.path, msg.body).then(sendResponse, (e) => sendResponse({ error: String(e && e.message || e) }));
    return true; // async
  }
  if (msg && msg.type === 'openOptions') { api.runtime.openOptionsPage(); return; }
  if (msg && msg.type === 'settings') {
    api.storage.local.get(DEFAULTS).then(sendResponse);
    return true;
  }
  if (msg && msg.type === 'markReplied') {
    api.storage.local.get({ replied: [] }).then(({ replied }) => {
      if (!replied.includes(msg.fullname)) replied.push(msg.fullname);
      return api.storage.local.set({ replied: replied.slice(-2000) });
    }).then(() => sendResponse({ ok: true }));
    return true;
  }
});

async function wpCall(path, body) {
  const { wpUrl, apiKey } = await api.storage.local.get(DEFAULTS);
  if (!apiKey) return { error: 'No API key set in the extension options.' };
  const res = await fetch(wpUrl.replace(/\/$/, '') + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-UMACK-KEY': apiKey },
    body: JSON.stringify(body || {}),
  });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch (e) { return { error: `WordPress returned non-JSON (HTTP ${res.status})` }; }
  if (!res.ok) return { error: json.message || `HTTP ${res.status}` };
  return json;
}
