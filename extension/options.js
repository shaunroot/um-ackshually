const api = globalThis.browser || globalThis.chrome;
const DEFAULTS = { wpUrl: 'https://www.shaunroot.net/wp-json/umack/v1', apiKey: '', enabled: false, dryRun: false, delaySeconds: 20 };
const $ = (id) => document.getElementById(id);

api.storage.local.get(DEFAULTS).then((s) => {
  $('wpUrl').value = s.wpUrl; $('apiKey').value = s.apiKey;
  $('delaySeconds').value = s.delaySeconds; $('enabled').checked = !!s.enabled; $('dryRun').checked = !!s.dryRun;
});

$('save').addEventListener('click', async () => {
  await api.storage.local.set({
    wpUrl: $('wpUrl').value.trim(), apiKey: $('apiKey').value.trim(),
    delaySeconds: Math.max(5, parseInt($('delaySeconds').value, 10) || 20),
    enabled: $('enabled').checked, dryRun: $('dryRun').checked,
  });
  $('status').textContent = 'Saved.';
});

$('clear').addEventListener('click', async () => {
  await api.storage.local.set({ replied: [] });
  $('status').textContent = 'Replied history cleared. Items still unread on Reddit will be answered again.';
});
