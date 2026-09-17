// Runs on old.reddit.com's inbox pages. For every UNREAD item it: builds the
// thread context, asks the WordPress plugin for a reply (the plugin holds the
// prompt + OpenAI key and decides caps/dedupe), posts the reply through the
// site's own comment endpoint, marks the item read, and reports back.
(async function () {
  const api = globalThis.browser || globalThis.chrome;
  const TAG = '[umack]';
  const log = (...a) => console.log(TAG, ...a);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const send = (msg) => api.runtime.sendMessage(msg);

  // ---------- banner ----------
  let stopped = false;
  const bar = document.createElement('div');
  bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#1b3a5c;color:#fff;' +
    'font:13px/1.4 system-ui,sans-serif;padding:6px 12px;display:flex;gap:12px;align-items:center;box-shadow:0 1px 4px rgba(0,0,0,.4)';
  const text = document.createElement('span'); text.style.flex = '1';
  const stopBtn = document.createElement('button'); stopBtn.textContent = 'Stop';
  stopBtn.style.cssText = 'font:inherit;padding:2px 10px';
  stopBtn.onclick = () => { stopped = true; status('stopped by you'); };
  const opts = document.createElement('a'); opts.textContent = 'options'; opts.href = '#';
  opts.style.color = '#cde'; opts.onclick = (e) => { e.preventDefault(); send({ type: 'openOptions' }); };
  bar.append(text, stopBtn, opts); document.body.prepend(bar);
  document.body.style.paddingTop = '32px';
  const status = (s) => { text.textContent = 'um-ackshually: ' + s; log(s); };

  // ---------- page facts ----------
  const settings = await send({ type: 'settings' });
  if (!settings.enabled) { status('paused (enable it in options)'); stopBtn.remove(); return; }
  const me = (document.querySelector('#header-bottom-right .user a') || {}).textContent || '';
  const uh = (document.querySelector('input[name="uh"]') || {}).value || '';
  if (!me || !uh) { status('not logged in, or modhash not found'); return; }
  const replied = new Set(settings.replied || []);
  const skipAuthors = new Set([me.toLowerCase(), 'automoderator', '[deleted]', '']);

  // ---------- collect unread items ----------
  const items = [];
  for (const el of document.querySelectorAll('.thing.new')) {
    const fullname = el.getAttribute('data-fullname') || '';
    const author = el.getAttribute('data-author') || '';
    if (!fullname || replied.has(fullname) || skipAuthors.has(author.toLowerCase())) continue;
    const kind = fullname.slice(0, 2);            // t1 comment, t4 private message
    if (kind !== 't1' && kind !== 't4') continue;
    const bodyEl = el.querySelector('.usertext-body .md');
    const timeEl = el.querySelector('time[datetime]');
    let permalink = el.getAttribute('data-permalink') || '';
    if (!permalink) {
      const a = el.querySelector('a.bylink[data-event-action="permalink"], a.bylink');
      if (a) permalink = new URL(a.href).pathname;
    }
    items.push({
      el, fullname, author, kind,
      subreddit: el.getAttribute('data-subreddit') || '',
      subject: ((el.querySelector('.subject') || {}).textContent || '').trim(),
      body: bodyEl ? bodyEl.innerText.trim() : '',
      created_utc: timeEl ? Math.floor(Date.parse(timeEl.getAttribute('datetime')) / 1000) : 0,
      permalink,
    });
  }
  if (!items.length) { status('nothing unread to answer'); return; }
  status(`${items.length} unread to answer`);

  // ---------- helpers ----------
  async function threadFor(it) {
    if (it.kind === 't4') {
      return {
        root: 'pm:' + it.author,
        thread: `PRIVATE MESSAGE from u/${it.author}\nSUBJECT: ${it.subject}\n\nMESSAGE YOU ARE REPLYING TO:\n[u/${it.author}] ${it.body}`,
        permalink: 'https://old.reddit.com/message/messages/' + it.fullname.slice(3),
      };
    }
    // Comment reply: pull the post + ancestor chain, same shape the daemon built.
    let title = '', selftext = '', linkId = '', chain = [];
    try {
      const r = await fetch('https://old.reddit.com' + it.permalink + '.json?context=8&raw_json=1', { credentials: 'same-origin' });
      const j = await r.json();
      const post = j[0].data.children[0].data;
      title = post.title || ''; selftext = post.selftext || ''; linkId = post.name || '';
      let node = (j[1].data.children[0] || {}).data;
      while (node && node.name !== it.fullname) {
        chain.push(`[u/${node.author}] ${node.body || ''}`);
        node = node.replies && node.replies.data ? (node.replies.data.children[0] || {}).data : null;
      }
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

  // ---------- main loop ----------
  let n = 0;
  for (const it of items) {
    if (stopped) break;
    n++;
    status(`(${n}/${items.length}) building context for u/${it.author}`);
    const ctx = await threadFor(it);

    status(`(${n}/${items.length}) asking OpenAI for a reply to u/${it.author}`);
    const res = await send({ type: 'wp', path: '/reply', body: {
      root: ctx.root, fullname: it.fullname, author: it.author, subreddit: it.subreddit,
      permalink: ctx.permalink, body: it.body, thread: ctx.thread, created_utc: it.created_utc,
    } });
    if (res.error) { status(`error from WordPress: ${res.error}`); return; }
    if (res.skip) { log('skipped', it.fullname, res.skip); await send({ type: 'markReplied', fullname: it.fullname }); continue; }

    if (res.dry_run) {
      log('DRY RUN, would reply to', it.fullname, ':\n' + res.reply);
      it.el.style.outline = '2px dashed #e90';
      await send({ type: 'markReplied', fullname: it.fullname });
      status(`(${n}/${items.length}) dry run: reply for u/${it.author} logged, not posted (see console)`);
      continue;
    }

    const post = await redditPost('/api/comment', { thing_id: it.fullname, text: res.reply });
    const errs = (post.json && post.json.errors) || [];
    if (errs.length) {
      status(`Reddit refused: ${errs[0][1]}${errs[0][0] === 'RATELIMIT' ? ' (stopping)' : ''}`);
      return;
    }
    const posted = (((post.json || {}).data || {}).things || [{}])[0].data || {};
    const postedName = posted.name || posted.id || '';
    await send({ type: 'wp', path: `/replies/${res.message_id}/posted`, body: { fullname: postedName } });
    await redditPost('/api/read_message', { id: it.fullname });
    await send({ type: 'markReplied', fullname: it.fullname });
    it.el.classList.remove('new'); it.el.style.outline = '2px solid #2a7';
    log('replied to', it.fullname, 'as', postedName);

    if (n < items.length) {
      for (let s = settings.delaySeconds; s > 0 && !stopped; s--) {
        status(`replied to u/${it.author}. next in ${s}s`); await sleep(1000);
      }
    }
  }
  if (!stopped) status(`done, answered ${n} item${n === 1 ? '' : 's'}`);
})();
