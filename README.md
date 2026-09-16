# um-ackshually

Responds to frequently asked questions.

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
