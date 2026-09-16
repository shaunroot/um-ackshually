# um-ackshually

A Reddit comment auto-responder. The owner queues a comment link in the WordPress
admin; this bot fetches the thread for context, replies with OpenAI, and carries the
conversation forward on its own — following up when the *original* commenter replies,
ignoring everyone else, and disengaging on silence or a reply cap.

Bot account: **u/smart-responder-robo-dude**. Companion WordPress plugin (owner front end)
lives in its own repo and installs on **shaunroot.net** (blog 1 of the multisite).

## Layout

```
um_ackshually_bot.php     the daemon (poll → reply → follow up → close)
prompt.txt                fallback system prompt (the live one is edited in WP Settings)
config-example.json       copy to config.json (gitignored) — bootstrap only (WP url + api key)
um-ackshually.service     systemd unit
state/                    lock-guarded JSON runtime state (gitignored)
logs/                     activity log (gitignored)
```

## Setup

1. `composer install` (pulls Guzzle).
2. `cp config-example.json config.json` — the only things it needs are the **wordpress** REST base
   (`https://www.shaunroot.net/wp-json/umack/v1`) and the **API key** from the plugin's Settings page.
   Everything else is managed in WordPress (next step).
3. In the plugin's **Settings** page in WordPress, fill in:
   - **Reddit** — bot account username/password + the script app's client id/secret.
   - **OpenAI** — API key and model (default `gpt-4o-mini`).
   - **Behavior** — poll interval (120s), conversation timeout (24h), reply cap (10), and the
     **Disable Reddit posting** dry-run toggle.
   - **System prompt** — persona, standard answers, behavioral/disclosure rules.
   The bot fetches all of this over REST each poll cycle, so changes take effect within one cycle —
   no restart. `config.json` (bootstrap) and `prompt.txt` (prompt) are only fallbacks for when
   WordPress is unreachable.
4. Install the service:
   ```
   sudo cp um-ackshually.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now um-ackshually
   journalctl -u um-ackshually -f        # or: tail -f logs/um-ackshually.log
   ```
   To test one pass without the daemon: `php um_ackshually_bot.php --once`.

## How it decides who to answer

- Replies once per queued comment (first reply includes the automated-assistant disclosure).
- Watches the inbox; only the **original commenter's** replies get a follow-up.
- **Never** replies to itself, AutoModerator, or `[deleted]`.
- Closes a conversation after `conversation_timeout_hours` of silence, or when `reply_cap`
  replies have been sent (guards against bot-vs-bot loops).

## Testing without bothering anyone

Two tools, both in the plugin Settings page:

- **Test OpenAI** — paste a sample comment and see the reply the current prompt + model produce,
  rendered right there. Never touches Reddit. Use it to tune the prompt.
- **Disable Reddit posting (dry run)** — the bot runs the *full* pipeline (pulls the queued comment,
  builds context, generates a reply, records it to the conversation history) but **skips the actual
  Reddit post**, logging "DRY RUN — would reply…" instead. Flip it off when you're ready to go live.
  `--status` shows the current posting mode.

## Tuning the prompt (see exactly what OpenAI gets)

Paste any Reddit comment permalink and the bot prints the two things it would send: the
**system prompt** (from WP Settings, `prompt.txt` if WP is unreachable) and the **user message**
(subreddit, post title/body, up to 8 ancestor comments, then the target comment):

```
php um_ackshually_bot.php --context <comment permalink>    # context only, no OpenAI call
php um_ackshually_bot.php --preview <comment permalink>    # context + the reply OpenAI generates
```

Neither posts to Reddit, touches `state/`, or writes to WordPress. Loop: edit the prompt in
WP Settings → rerun `--preview` → repeat. Needs the Reddit app client id/secret in WP Settings.

## Checking the throttle (when can I stop posting by hand?)

Reddit exposes no single "how throttled am I" number, so run:

```
php um_ackshually_bot.php --status
```

It reports the three things that together answer it:

- **Karma + account age** — what drives the new-account throttle. Low/young = throttled.
- **OAuth rate-limit budget** — the 600-requests / 10-min API window (rarely the bottleneck here).
- **Posting throttle** — how many comments posted OK vs. how often Reddit returned `RATELIMIT`
  ("try again in N minutes"), and when it last happened.

It ends with a plain-English verdict. Rule of thumb: once karma has grown, the bot has posted
several times, and there's been **no recent RATELIMIT**, you can stop posting manually and let it
run — just spot-check the conversation history. Every posting attempt updates these counters (they
live in `state/`), so `--status` reflects real behavior, not a guess.

## Operational notes (Reddit rules & accountability)

- **Disclosure is a Reddit requirement, not optional** — the model must say it's automated.
- **Check each subreddit's bot/AI-content rules before queueing a comment there.**
- A new, low-karma account is rate-limited by Reddit at first; expect slow going early on.
- Someone should skim the conversation history regularly — the owner is accountable for
  everything posted under the account.

## Config note

The requirements described configuration via environment variables; this project uses a
gitignored `config.json` instead, to match the sibling Spiral Tower bot. Same values, one file.
