// Runs on old.reddit.com. Three ways a reply happens:
//   1. Inbox pages: every UNREAD item (comment reply or PM) is answered on load.
//   2. Inbox pages: permalinks queued on the WordPress "Comment Queue" page are answered.
//   3. Comment pages: an "um-ackshually reply" link on each comment answers that one on click.
// In all cases the WordPress plugin holds the prompt + OpenAI key, decides caps/dedupe,
// and records the history. Posting goes through the site's own /api/comment.
(async function () {
  const api = globalThis.browser || globalThis.chrome;
  const TAG = '[umack]';
  const log = (...a) => console.log(TAG, ...a);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const send = (msg) => api.runtime.sendMessage(msg);
  const wp = (path, body, method) => send({ type: 'wp', path, body, method });

  // ---------- banner ----------
  let stopped = false, bar, text, stopBtn;
  function banner() {
    if (bar) return;
    bar = document.createElement('div');
    bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#1b3a5c;color:#fff;' +
      'font:13px/1.4 system-ui,sans-serif;padding:6px 12px;display:flex;gap:12px;align-items:center;box-shadow:0 1px 4px rgba(0,0,0,.4)';
    text = document.createElement('span'); text.style.flex = '1';
    stopBtn = document.createElement('button'); stopBtn.textContent = 'Stop';
    stopBtn.style.cssText = 'font:inherit;padding:2px 10px';
    stopBtn.onclick = () => { stopped = true; status('stopped by you'); };
    const opts = document.createElement('a'); opts.textContent = 'options'; opts.href = '#';
    opts.style.color = '#cde'; opts.onclick = (e) => { e.preventDefault(); send({ type: 'openOptions' }); };
    bar.append(text, stopBtn, opts); document.body.prepend(bar);
    document.body.style.paddingTop = '32px';
  }
  const status = (s) => { banner(); text.textContent = 'um-ackshually: ' + s; log(s); };

  // ---------- page facts ----------
  const settings = await send({ type: 'settings' });
  const me = (document.querySelector('#header-bottom-right .user a') || {}).textContent || '';
  const uh = (document.querySelector('input[name="uh"]') || {}).value || '';
  const replied = new Set(settings.replied || []);
  const skipAuthors = new Set([me.toLowerCase(), 'automoderator', '[deleted]', '']);
  const isInbox = location.pathname.startsWith('/message/');
  log('loaded on', location.pathname, '| logged in as:', me || '(nobody)', '| modhash:', uh ? 'found' : 'MISSING', '| enabled:', !!settings.enabled);

  // ---------- helpers ----------
  function permalinkOf(el) {
    let p = el.getAttribute('data-permalink') || '';
    if (!p) { const a = el.querySelector('a.bylink[data-event-action="permalink"], a.bylink'); if (a) p = new URL(a.href).pathname; }
    return p;
  }
  function itemFromThing(el) {
    const bodyEl = el.querySelector('.usertext-body .md');
    const timeEl = el.querySelector('time[datetime]');
    return {
      el, fullname: el.getAttribute('data-fullname') || '', author: el.getAttribute('data-author') || '',
      subreddit: el.getAttribute('data-subreddit') || '',
      subject: ((el.querySelector('.subject') || {}).textContent || '').trim(),
      body: bodyEl ? bodyEl.innerText.trim() : '',
      created_utc: timeEl ? Math.floor(Date.parse(timeEl.getAttribute('datetime')) / 1000) : 0,
      permalink: permalinkOf(el),
    };
  }
  function isPostable(it) { return it.fullname && !replied.has(it.fullname) && !skipAuthors.has(it.author.toLowerCase()); }

  // Fetch a comment permalink's JSON: post + ancestor chain + the target comment itself.
  async function fetchThread(permalink, targetFullname) {
    const r = await fetch('https://old.reddit.com' + permalink.replace(/\/$/, '') + '.json?context=8&raw_json=1', { credentials: 'same-origin' });
    const j = await r.json();
    const post = j[0].data.children[0].data;
    const chain = []; let node = (j[1].data.children[0] || {}).data, target = null;
    while (node) {
      if (!targetFullname || node.name === targetFullname) { target = node; break; }
      chain.push(`[u/${node.author}] ${node.body || ''}`);
      node = node.replies && node.replies.data ? (node.replies.data.children[0] || {}).data : null;
    }
    return { post, chain, target };
  }

  async function contextFor(it) {
    if (it.fullname.startsWith('t4')) {
      return {
        root: 'pm:' + it.author,
        thread: `PRIVATE MESSAGE from u/${it.author}\nSUBJECT: ${it.subject}\n\nMESSAGE YOU ARE REPLYING TO:\n[u/${it.author}] ${it.body}`,
        permalink: 'https://old.reddit.com/message/messages/' + it.fullname.slice(3),
      };
    }
    let title = '', selftext = '', linkId = '', chain = [];
    try {
      const t = await fetchThread(it.permalink, it.fullname);
      title = t.post.title || ''; selftext = t.post.selftext || ''; linkId = t.post.name || ''; chain = t.chain;
      if (t.target) { it.body = it.body || t.target.body || ''; it.subreddit = it.subreddit || t.target.subreddit || ''; }
    } catch (e) { log('context fetch failed, replying with less context', e); }
    if (!linkId) { const m = it.permalink.match(/\/comments\/([a-z0-9]+)\//i); linkId = m ? 't3_' + m[1] : it.permalink; }
    let t = `SUBREDDIT: r/${it.subreddit}\nPOST TITLE: ${title}\n`;
    if (selftext.trim()) t += `POST BODY: ${selftext.slice(0, 1500)}\n`;
    if (chain.length) t += `\nEARLIER IN THE THREAD:\n${chain.join('\n').slice(-2500)}\n`;
    t += `\nCOMMENT YOU ARE REPLYING TO:\n[u/${it.author}] ${it.body}`;
    return { root: `${linkId}:${it.author}`, thread: t, permalink: 'https://www.reddit.com' + it.permalink };
  }

  async function redditPost(path, form) {
    const r = await fetch('https://old.reddit.com' + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ api_type: 'json', uh, ...form }).toString(),
    });
    return r.json().catch(() => ({}));
  }

  // The whole pipeline for one item. Returns 'ok' | 'skip' | 'dry' | 'stop' | 'error:<msg>'.
  async function answer(it, label) {
    status(`${label} building context for u/${it.author}`);
    const ctx = await contextFor(it);
    status(`${label} asking OpenAI for a reply to u/${it.author}`);
    const res = await wp('/reply', {
      root: ctx.root, fullname: it.fullname, author: it.author, subreddit: it.subreddit,
      permalink: ctx.permalink, body: it.body, thread: ctx.thread, created_utc: it.created_utc,
    });
    if (res.error) { status(`error from WordPress: ${res.error}`); return 'error:' + res.error; }
    if (res.skip) { log('skipped', it.fullname, res.skip); await send({ type: 'markReplied', fullname: it.fullname }); return 'skip'; }
    if (res.dry_run) {
      log('DRY RUN, would reply to', it.fullname, ':\n' + res.reply);
      if (it.el) it.el.style.outline = '2px dashed #e90';   // not marked replied: a real run can redo it
      status(`${label} dry run: reply for u/${it.author} logged, not posted (see console)`);
      return 'dry';
    }
    const post = await redditPost('/api/comment', { thing_id: it.fullname, text: res.reply });
    const errs = (post.json && post.json.errors) || [];
    if (errs.length) {
      status(`Reddit refused: ${errs[0][1]}${errs[0][0] === 'RATELIMIT' ? ' (stopping)' : ''}`);
      return errs[0][0] === 'RATELIMIT' ? 'stop' : 'error:' + errs[0][1];
    }
    const posted = (((post.json || {}).data || {}).things || [{}])[0].data || {};
    const postedName = posted.name || posted.id || '';
    await wp(`/replies/${res.message_id}/posted`, { fullname: postedName });
    if (isInbox) await redditPost('/api/read_message', { id: it.fullname });
    await send({ type: 'markReplied', fullname: it.fullname });
    if (it.el) { it.el.classList.remove('new'); it.el.style.outline = '2px solid #2a7'; }
    log('replied to', it.fullname, 'as', postedName);
    return 'ok';
  }

  async function countdown(prefix) {
    for (let s = settings.delaySeconds; s > 0 && !stopped; s--) { status(`${prefix} next in ${s}s`); await sleep(1000); }
  }

  // ---------- 3. comment pages: a reply link on every comment ----------
  if (!isInbox) {
    if (!me || !uh) { log('not logged in or no modhash, no reply links added'); return; }
    const things = document.querySelectorAll('.thing.comment');
    log(things.length, 'comments on page');
    for (const el of things) {
      const buttons = el.querySelector('ul.flat-list.buttons');
      const it = itemFromThing(el);
      if (!buttons) { log('no button row on', it.fullname); continue; }
      if (!isPostable(it)) {
        log('no link for', it.fullname, 'by', it.author, ':', replied.has(it.fullname) ? 'in replied history' : 'author is skipped (self/AutoModerator/deleted)');
        continue;
      }
      const li = document.createElement('li'); const a = document.createElement('a');
      a.href = '#'; a.textContent = 'um-ackshually reply'; a.style.color = '#1b3a5c';
      a.onclick = async (e) => {
        e.preventDefault(); if (a.dataset.busy) return; a.dataset.busy = '1'; a.textContent = 'replying…';
        const r = await answer(itemFromThing(el), '');
        a.textContent = r === 'ok' ? 'replied' : r === 'dry' ? 'dry run (see console)' : r === 'skip' ? 'skipped' : 'failed';
        if (r === 'ok' || r === 'dry') status(`done, replied to u/${it.author}`);
      };
      li.append(a); buttons.append(li);
    }
    return;
  }

  // ---------- inbox pages ----------
  if (!settings.enabled) { status('paused (enable it in options)'); stopBtn.remove(); return; }
  if (!me || !uh) { status('not logged in, or modhash not found'); return; }

  // 1. unread items
  const items = [...document.querySelectorAll('.thing.new')].map(itemFromThing)
    .filter((it) => isPostable(it) && (it.fullname.startsWith('t1') || it.fullname.startsWith('t4')));
  // 2. queued permalinks from WordPress
  const queue = await wp('/queue?status=pending', null, 'GET');
  const queued = Array.isArray(queue) ? queue : [];
  if (queue && queue.error) log('queue fetch failed', queue.error);

  if (!items.length && !queued.length) { status('nothing unread and nothing queued'); return; }
  const total = items.length + queued.length;
  status(`${items.length} unread + ${queued.length} queued to answer`);

  let n = 0;
  for (const it of items) {
    if (stopped) break;
    n++;
    const r = await answer(it, `(${n}/${total})`);
    if (r === 'stop' || r.startsWith('error:')) return;
    if (r === 'ok' && n < total) await countdown(`(${n}/${total}) replied to u/${it.author}.`);
  }
  for (const q of queued) {
    if (stopped) break;
    n++;
    const m = String(q.url || '').match(/reddit\.com(\/r\/[^/]+\/comments\/[a-z0-9]+\/[^/]*\/([a-z0-9]+))/i);
    if (!m) { await wp(`/queue/${q.id}/status`, { status: 'error', note: 'Not a valid Reddit comment permalink' }); continue; }
    let it;
    try {
      const t = await fetchThread(m[1], 't1_' + m[2]);
      if (!t.target) throw new Error('comment not found in thread');
      it = { fullname: t.target.name, author: t.target.author || '', subreddit: t.target.subreddit || '',
             body: t.target.body || '', created_utc: Math.floor(t.target.created_utc || 0), permalink: m[1] };
    } catch (e) { await wp(`/queue/${q.id}/status`, { status: 'error', note: String(e.message || e).slice(0, 240) }); continue; }
    if (!isPostable(it)) { await wp(`/queue/${q.id}/status`, { status: 'error', note: `u/${it.author} is not repliable (self/AutoModerator/deleted/already handled)` }); continue; }
    const r = await answer(it, `(${n}/${total}) queued:`);
    if (r === 'stop') return;
    if (r.startsWith('error:')) { await wp(`/queue/${q.id}/status`, { status: 'error', note: r.slice(6, 246) }); return; }
    await wp(`/queue/${q.id}/status`, { status: 'done', note: r === 'dry' ? 'Dry run, not posted' : r === 'skip' ? 'Skipped (already handled or capped)' : 'Replied' });
    if (r === 'ok' && n < total) await countdown(`(${n}/${total}) replied to u/${it.author}.`);
  }
  if (!stopped) status(`done, handled ${n} item${n === 1 ? '' : 's'}`);
})();
