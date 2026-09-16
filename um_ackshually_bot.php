<?php
/**
 * um-ackshually — Reddit comment auto-responder bot.
 *
 * Runs continuously (see um-ackshually.service). Each cycle it:
 *   1. Pulls newly queued comment permalinks from the WordPress plugin (REST).
 *   2. For each, fetches the post + parent comment chain for context, generates
 *      a reply with OpenAI, posts it, and reports status + transcript back to WP.
 *   3. Watches the bot account's inbox; when the *original* commenter replies,
 *      posts a follow-up. Ignores anyone else who joins the thread.
 *   4. Closes conversations that are silent past the timeout or hit the reply cap.
 *
 * Patterns mirror the Spiral Tower bot: config.json (gitignored) + Guzzle +
 * Reddit OAuth password grant. State is a lock-guarded JSON file; the durable
 * conversation record lives in WordPress.
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class UmAckshuallyBot
{
    /** @var Client */ private $client;
    private $config;

    // Reddit
    private $accessToken = null;
    private $tokenExpires = 0;
    private $rate = [];   // last-seen x-ratelimit-* headers (OAuth 600/10min budget)

    // Tunables (from config)
    private $pollInterval;
    private $timeoutSeconds;
    private $replyCap;

    // Files
    private $stateFile;
    private $logFile;
    private $promptFile;

    private $state;   // decoded JSON state
    private $systemPrompt = null;   // pulled from WordPress each cycle; prompt.txt is the fallback
    private $running = true;

    public function __construct()
    {
        $configFile = __DIR__ . '/config.json';
        if (!file_exists($configFile)) {
            throw new Exception("Config file not found at: $configFile (copy config-example.json)");
        }
        $this->config = json_decode(file_get_contents($configFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error parsing config.json: " . json_last_error_msg());
        }

        // Most runtime config now lives in the WordPress plugin and is fetched over
        // REST each cycle; config.json only needs wordpress{url,api_key}. Guard the
        // shapes so early calls (before the first remote fetch) never hit undefined keys.
        $this->config += ['reddit' => [], 'openai' => [], 'disable_posting' => 0];
        $this->config['reddit'] += [
            'username' => '', 'password' => '', 'client_id' => '', 'client_secret' => '',
            'user_agent' => 'um-ackshually responder (by /u/smart-responder-robo-dude)',
        ];
        $this->config['openai'] += [
            'url' => 'https://api.openai.com/v1/chat/completions', 'key' => '', 'model' => 'gpt-4o-mini',
        ];

        $this->client         = new Client(['timeout' => 60]);
        $this->pollInterval   = (int) ($this->config['poll_interval_seconds'] ?? 120);
        $this->timeoutSeconds = (int) ($this->config['conversation_timeout_hours'] ?? 24) * 3600;
        $this->replyCap       = (int) ($this->config['reply_cap'] ?? 10);

        $this->stateFile  = $this->pathFrom($this->config['state_file']  ?? 'state/umack-state.json');
        $this->logFile    = $this->pathFrom($this->config['log_file']    ?? 'logs/um-ackshually.log');
        $this->promptFile = $this->pathFrom($this->config['prompt_file'] ?? 'prompt.txt');

        $this->loadState();

        // Graceful shutdown so systemd stop/restart doesn't sever mid-cycle.
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () { $this->running = false; });
            pcntl_signal(SIGINT,  function () { $this->running = false; });
        }
    }

    private function pathFrom($p)
    {
        return (strpos($p, '/') === 0) ? $p : __DIR__ . '/' . $p;
    }

    /* ------------------------------------------------------------------ */
    /*  Main loop                                                          */
    /* ------------------------------------------------------------------ */

    public function run()
    {
        $this->log("um-ackshually starting (poll={$this->pollInterval}s, timeout=" .
            ($this->timeoutSeconds / 3600) . "h, cap={$this->replyCap})");

        while ($this->running) {
            $this->cycle();
            $this->sleep($this->pollInterval);
        }
        $this->log("um-ackshually stopped.");
    }

    /** One poll pass. Public so --once can run exactly one. */
    public function cycle()
    {
        try {
            $this->applyRemoteConfig();   // creds + tunables + dry-run flag, from WordPress
            $this->ensureToken();
            $this->refreshPrompt();
            $this->processQueue();
            $this->pollInbox();
            $this->closeStaleConversations();
        } catch (Throwable $e) {
            $this->log("‼️ cycle error: " . $e->getMessage());
        }
    }

    /**
     * Pull the bot's runtime config (Reddit + OpenAI creds, tunables, dry-run flag)
     * from the WordPress plugin. A non-empty remote value wins over the local one, so
     * whichever source has real values takes effect; if WordPress is unreachable we
     * keep whatever we already had. Tunables are re-derived so changes apply live.
     */
    private function applyRemoteConfig()
    {
        $r = $this->wpGet('/config');
        if (!is_array($r)) {
            return;   // WordPress down — keep current values
        }
        if (!empty($r['reddit']) && is_array($r['reddit'])) {
            foreach (['username', 'password', 'client_id', 'client_secret', 'user_agent'] as $k) {
                if (!empty($r['reddit'][$k])) {
                    $this->config['reddit'][$k] = $r['reddit'][$k];
                }
            }
        }
        if (!empty($r['openai']) && is_array($r['openai'])) {
            foreach (['url', 'key', 'model'] as $k) {
                if (!empty($r['openai'][$k])) {
                    $this->config['openai'][$k] = $r['openai'][$k];
                }
            }
        }
        foreach (['poll_interval_seconds', 'conversation_timeout_hours', 'reply_cap'] as $k) {
            if (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) {
                $this->config[$k] = $r[$k];
            }
        }
        if (isset($r['disable_posting'])) {
            $this->config['disable_posting'] = (int) $r['disable_posting'];
        }
        $this->pollInterval   = (int) ($this->config['poll_interval_seconds'] ?? 120);
        $this->timeoutSeconds = (int) ($this->config['conversation_timeout_hours'] ?? 24) * 3600;
        $this->replyCap       = (int) ($this->config['reply_cap'] ?? 10);
    }

    /**
     * Pull the system prompt the owner maintains on the WordPress settings page.
     * Falls back to the local prompt.txt only if WordPress is unreachable and we
     * have nothing cached yet.
     */
    private function refreshPrompt()
    {
        $resp = $this->wpGet('/prompt');
        if (is_array($resp) && !empty($resp['prompt'])) {
            $this->systemPrompt = $resp['prompt'];
        } elseif ($this->systemPrompt === null) {
            $this->systemPrompt = file_exists($this->promptFile) ? file_get_contents($this->promptFile) : '';
            $this->log("⚠️ using local prompt.txt fallback (WordPress prompt unavailable)");
        }
    }

    /**
     * Print an account-health + throttle report. Reddit exposes no single
     * "how throttled am I" number, so we show the three things that together
     * answer it: karma + account age (what drives the new-account throttle),
     * the live OAuth rate-limit budget, and how often the bot has actually been
     * RATELIMIT'd while posting. When RATELIMIT stops firing and karma has grown,
     * you can stop posting by hand.
     */
    public function printStatus()
    {
        $this->applyRemoteConfig();   // creds come from WordPress now
        $this->ensureToken();
        $me = $this->getMe();
        $name       = $me['name'] ?? '?';
        $link       = (int) ($me['link_karma'] ?? 0);
        $comment    = (int) ($me['comment_karma'] ?? 0);
        $total      = (int) ($me['total_karma'] ?? ($link + $comment));
        $createdUtc = (int) ($me['created_utc'] ?? 0);
        $ageDays    = $createdUtc ? floor((time() - $createdUtc) / 86400) : 0;

        echo "\n=== um-ackshually account status ===\n";
        echo "Account:        u/$name\n";
        echo "Account age:    {$ageDays} days\n";
        echo "Karma:          {$total} total ({$link} post / {$comment} comment)\n";
        echo "Verified email: " . (!empty($me['has_verified_email']) ? 'yes' : 'NO — verify it, unverified accounts are throttled harder') . "\n";
        if (!empty($me['is_suspended'])) {
            echo "⚠️  ACCOUNT IS SUSPENDED — it cannot post.\n";
        }
        echo "Posting mode:   " . (!empty($this->config['disable_posting']) ? "DRY RUN (posting disabled)" : "LIVE") . "\n";

        echo "\n-- OAuth rate-limit budget (600 requests / 10 min window) --\n";
        if ($this->rate) {
            $reset = isset($this->rate['reset']) ? (int) $this->rate['reset'] . 's until reset' : '?';
            echo "Used: " . ($this->rate['used'] ?? '?') . "   Remaining: " . ($this->rate['remaining'] ?? '?') . "   ($reset)\n";
        } else {
            echo "(no data yet)\n";
        }

        $s = $this->state['stats'];
        echo "\n-- Posting throttle (the signal that matters) --\n";
        echo "Comments posted OK:   " . (int) ($s['posts_ok'] ?? 0) . "\n";
        echo "RATELIMIT rejections: " . (int) ($s['posts_ratelimited'] ?? 0) . "\n";
        if (!empty($s['last_ratelimit_at'])) {
            $ago = $this->ago($s['last_ratelimit_at']);
            $wait = isset($s['last_ratelimit_wait']) && $s['last_ratelimit_wait'] !== null ? " (Reddit asked to wait ~" . $s['last_ratelimit_wait'] . "s)" : '';
            echo "Last throttled:       {$ago}{$wait}\n";
            echo "  message: " . ($s['last_ratelimit_msg'] ?? '') . "\n";
        } else {
            echo "Last throttled:       never\n";
        }
        if (!empty($s['last_post_at'])) {
            echo "Last successful post: " . $this->ago($s['last_post_at']) . "\n";
        }

        echo "\n-- Read of the situation --\n";
        echo $this->throttleVerdict($total, $ageDays, $s) . "\n\n";
    }

    private function throttleVerdict($karma, $ageDays, $stats)
    {
        $recentLimit = !empty($stats['last_ratelimit_at']) && (time() - $stats['last_ratelimit_at']) < 6 * 3600;
        if ($recentLimit) {
            return "Still throttled: Reddit rate-limited a post in the last few hours. Keep posting manually.";
        }
        if ($karma < 50 || $ageDays < 3) {
            return "Likely still throttled: low karma / young account. Expect RATELIMITs; keep helping by hand.";
        }
        if (($stats['posts_ok'] ?? 0) >= 10 && empty($recentLimit)) {
            return "Looking clear: the bot has posted repeatedly with no recent RATELIMIT. Safe to let it run and just spot-check.";
        }
        return "Borderline: no recent throttling, but not much posting history yet. Let the bot try; re-run --status after it posts a few.";
    }

    private function getMe()
    {
        return $this->redditGet('https://oauth.reddit.com/api/v1/me');
    }

    private function ago($ts)
    {
        $d = time() - (int) $ts;
        if ($d < 60)    return "{$d}s ago";
        if ($d < 3600)  return floor($d / 60) . "m ago";
        if ($d < 86400) return floor($d / 3600) . "h ago";
        return floor($d / 86400) . "d ago";
    }

    private function sleep($seconds)
    {
        // Sleep in 1s slices so a SIGTERM is honoured promptly.
        for ($i = 0; $i < $seconds && $this->running; $i++) {
            sleep(1);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Reddit auth                                                        */
    /* ------------------------------------------------------------------ */

    private function ensureToken()
    {
        if ($this->accessToken && time() < $this->tokenExpires - 60) {
            return;
        }
        $r = $this->config['reddit'];
        $resp = $this->client->post('https://www.reddit.com/api/v1/access_token', [
            'auth' => [$r['client_id'], $r['client_secret']],
            'form_params' => [
                'grant_type' => 'password',
                'username'   => $r['username'],
                'password'   => $r['password'],
                'scope'      => 'read submit privatemessages identity',
            ],
            'headers' => ['User-Agent' => $r['user_agent']],
        ]);
        $body = json_decode($resp->getBody(), true);
        if (empty($body['access_token'])) {
            throw new Exception('Reddit auth returned no access_token');
        }
        $this->accessToken  = $body['access_token'];
        $this->tokenExpires = time() + (int) ($body['expires_in'] ?? 3600);
        $this->log("🔑 authenticated with Reddit (scopes: " . ($body['scope'] ?? '?') . ")");
    }

    private function redditGet($url, $query = [])
    {
        $resp = $this->client->get($url, [
            'headers' => [
                'Authorization' => "Bearer {$this->accessToken}",
                'User-Agent'    => $this->config['reddit']['user_agent'],
            ],
            'query' => $query,
        ]);
        $this->captureRateLimit($resp);
        $this->pace();
        return json_decode($resp->getBody(), true);
    }

    private function redditPost($url, $form)
    {
        $resp = $this->client->post($url, [
            'headers' => [
                'Authorization' => "Bearer {$this->accessToken}",
                'User-Agent'    => $this->config['reddit']['user_agent'],
            ],
            'form_params' => $form,
        ]);
        $this->captureRateLimit($resp);
        $this->pace();
        return json_decode($resp->getBody(), true);
    }

    /** Record Reddit's OAuth rate-limit budget headers from the last response. */
    private function captureRateLimit($resp)
    {
        foreach (['used', 'remaining', 'reset'] as $k) {
            $h = $resp->getHeaderLine('x-ratelimit-' . $k);
            if ($h !== '') {
                $this->rate[$k] = $h;
            }
        }
    }

    /** Reddit allows ~60 requests/min; stay well under it. */
    private function pace()
    {
        usleep(1200000);
    }

    /* ------------------------------------------------------------------ */
    /*  Queue processing (owner-queued comment links)                      */
    /* ------------------------------------------------------------------ */

    private function processQueue()
    {
        $pending = $this->wpGet('/queue', ['status' => 'pending']);
        if (!is_array($pending)) {
            return;
        }
        foreach ($pending as $item) {
            if (!$this->running) {
                break;
            }
            $id  = $item['id'];
            $url = trim($item['url'] ?? '');
            try {
                $commentId = $this->parseCommentId($url);
                if (!$commentId) {
                    $this->wpPost("/queue/$id/status", ['status' => 'error', 'note' => 'Not a valid Reddit comment permalink']);
                    continue;
                }
                $this->startConversation($commentId, $url);
                $this->wpPost("/queue/$id/status", ['status' => 'done', 'note' => 'Replied']);
            } catch (Throwable $e) {
                $this->log("❌ queue item $id failed: " . $e->getMessage());
                $this->wpPost("/queue/$id/status", ['status' => 'error', 'note' => substr($e->getMessage(), 0, 240)]);
            }
        }
    }

    /**
     * Reddit comment permalinks look like:
     *   https://www.reddit.com/r/SUB/comments/POSTID/slug/COMMENTID/
     *   https://old.reddit.com/r/SUB/comments/POSTID/comment/COMMENTID
     * Returns the base36 comment id, or null.
     */
    private function parseCommentId($url)
    {
        if (!preg_match('#reddit\.com/r/[^/]+/comments/[a-z0-9]+/[^/]*/([a-z0-9]+)#i', $url, $m)) {
            // also accept .../comment/COMMENTID form
            if (!preg_match('#reddit\.com/r/[^/]+/comments/[a-z0-9]+/comment/([a-z0-9]+)#i', $url, $m)) {
                return null;
            }
        }
        return $m[1];
    }

    private function startConversation($commentId, $permalink)
    {
        $fullname = "t1_$commentId";
        if (isset($this->state['conversations'][$fullname])) {
            $this->log("↩︎ already tracking $fullname, skipping duplicate queue entry");
            return;
        }

        $comment = $this->fetchThing($fullname);
        if (!$comment) {
            throw new Exception("Could not fetch comment $fullname");
        }
        $author = $comment['author'] ?? '';
        if (!$this->isRepliable($author)) {
            throw new Exception("Comment author '$author' is not repliable (self/AutoModerator/deleted)");
        }

        $context = $this->buildContext($comment);
        $reply   = $this->generateReply($context, true);

        $posted = $this->postComment($fullname, $reply);
        $subreddit = $comment['subreddit'] ?? '';

        // Register conversation in WordPress (durable record) + locally.
        $wpConv = $this->wpPost('/conversations', [
            'root_comment_id' => $fullname,
            'subreddit'       => $subreddit,
            'permalink'       => $permalink,
            'other_user'      => $author,
            'status'          => 'active',
        ]);
        $wpId = is_array($wpConv) && isset($wpConv['id']) ? (int) $wpConv['id'] : null;

        $now = time();
        $this->state['conversations'][$fullname] = [
            'wp_id'               => $wpId,
            'subreddit'           => $subreddit,
            'permalink'           => $permalink,
            'other_user'          => $author,
            'status'              => 'active',
            'reply_count'         => 1,
            'our_comment_ids'     => $posted ? [$posted] : [],
            'last_other_activity' => $now,
            'last_our_reply'      => $now,
            'created'             => $now,
        ];
        $this->saveState();

        // Report both sides of the opening exchange.
        $this->reportMessage($wpId, $author, 'user', $context['target_body'], $fullname, $comment['created_utc'] ?? $now);
        $this->reportMessage($wpId, $this->config['reddit']['username'], 'bot', $reply, $posted, $now);
        $this->log("💬 opened conversation with u/$author on $fullname");
    }

    /* ------------------------------------------------------------------ */
    /*  Inbox watching (follow-ups)                                        */
    /* ------------------------------------------------------------------ */

    private function pollInbox()
    {
        $data = $this->redditGet('https://oauth.reddit.com/message/unread', ['limit' => 100]);
        $children = $data['data']['children'] ?? [];
        foreach (array_reverse($children) as $child) {          // oldest first
            if (!$this->running) {
                break;
            }
            $msg = $child['data'];
            $fullname = $msg['name'] ?? '';
            if ($fullname) {
                $this->markRead($fullname);                     // always clear, even if ignored
            }
            if (($child['kind'] ?? '') !== 't1' || empty($msg['was_comment'])) {
                continue;                                       // only comment replies matter
            }
            if (isset($this->state['processed_inbox'][$fullname])) {
                continue;
            }
            $this->state['processed_inbox'][$fullname] = time();

            $parent = $msg['parent_id'] ?? '';
            $author = $msg['author'] ?? '';
            $conv   = $this->findConversationByOurComment($parent);
            if (!$conv) {
                continue;                                       // reply to something we don't track
            }
            $key = $conv['key'];
            $c   = &$this->state['conversations'][$key];

            if ($c['status'] !== 'active') {
                continue;
            }
            if (strcasecmp($author, $c['other_user']) !== 0) {
                $this->log("🙈 ignoring u/$author (not the original commenter) on {$key}");
                continue;
            }
            if (!$this->isRepliable($author)) {
                continue;
            }

            // Record their reply.
            $c['last_other_activity'] = time();
            $this->reportMessage($c['wp_id'], $author, 'user', $msg['body'] ?? '', $fullname, $msg['created_utc'] ?? time());

            if ($c['reply_count'] >= $this->replyCap) {
                $c['status'] = 'capped';
                $this->wpPost('/conversations/' . $c['wp_id'] . '/status', ['status' => 'capped']);
                $this->log("🧢 conversation {$key} hit reply cap ({$this->replyCap}) — closing");
                $this->saveState();
                continue;
            }

            // Build fresh context from their new comment and reply.
            $comment = $this->fetchThing($fullname);
            $context = $comment ? $this->buildContext($comment) : ['thread' => $msg['body'] ?? '', 'target_body' => $msg['body'] ?? '', 'title' => '', 'selftext' => ''];
            $reply   = $this->generateReply($context, false);
            $posted  = $this->postComment($fullname, $reply);

            $c['reply_count']++;
            $c['our_comment_ids'][] = $posted;
            $c['last_our_reply'] = time();
            $this->reportMessage($c['wp_id'], $this->config['reddit']['username'], 'bot', $reply, $posted, time());
            $this->saveState();
            $this->log("↪︎ followed up with u/$author on {$key} (reply #{$c['reply_count']})");
        }
        unset($c);
        $this->saveState();
    }

    private function findConversationByOurComment($parentFullname)
    {
        foreach ($this->state['conversations'] as $key => $conv) {
            if ($parentFullname === $key || in_array($parentFullname, $conv['our_comment_ids'], true)) {
                return ['key' => $key] + $conv;
            }
        }
        return null;
    }

    private function markRead($fullname)
    {
        try {
            $this->redditPost('https://oauth.reddit.com/api/read_message', ['id' => $fullname]);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Conversation lifecycle                                             */
    /* ------------------------------------------------------------------ */

    private function closeStaleConversations()
    {
        $now = time();
        $changed = false;
        foreach ($this->state['conversations'] as $key => &$c) {
            if ($c['status'] === 'active' && ($now - $c['last_other_activity']) > $this->timeoutSeconds) {
                $c['status'] = 'closed';
                if ($c['wp_id']) {
                    $this->wpPost('/conversations/' . $c['wp_id'] . '/status', ['status' => 'closed']);
                }
                $this->log("🕛 conversation {$key} closed after silence > " . ($this->timeoutSeconds / 3600) . "h");
                $changed = true;
            }
        }
        unset($c);
        if ($changed) {
            $this->saveState();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Reddit helpers                                                     */
    /* ------------------------------------------------------------------ */

    private function fetchThing($fullname)
    {
        $data = $this->redditGet('https://oauth.reddit.com/api/info', ['id' => $fullname]);
        $children = $data['data']['children'] ?? [];
        return $children[0]['data'] ?? null;
    }

    /**
     * Build the context we hand the model: submission title/body, the ancestor
     * comment chain, and the target comment we are replying to.
     */
    private function buildContext($comment)
    {
        $linkId = $comment['link_id'] ?? null;          // t3_...
        $title = $selftext = '';
        if ($linkId) {
            $submission = $this->fetchThing($linkId);
            if ($submission) {
                $title    = $submission['title'] ?? '';
                $selftext = $submission['selftext'] ?? '';
            }
        }

        // Walk parents up to the submission, collecting ancestors (bounded).
        $chain = [];
        $parent = $comment['parent_id'] ?? '';
        $hops = 0;
        while ($parent && strpos($parent, 't1_') === 0 && $hops < 8) {
            $p = $this->fetchThing($parent);
            if (!$p) {
                break;
            }
            array_unshift($chain, '[u/' . ($p['author'] ?? '?') . '] ' . ($p['body'] ?? ''));
            $parent = $p['parent_id'] ?? '';
            $hops++;
        }

        $threadText  = "SUBREDDIT: r/" . ($comment['subreddit'] ?? '') . "\n";
        $threadText .= "POST TITLE: $title\n";
        if (trim($selftext) !== '') {
            $threadText .= "POST BODY: " . $this->truncate($selftext, 1500) . "\n";
        }
        if ($chain) {
            $threadText .= "\nEARLIER IN THE THREAD:\n" . $this->truncate(implode("\n", $chain), 2500) . "\n";
        }
        $threadText .= "\nCOMMENT YOU ARE REPLYING TO:\n[u/" . ($comment['author'] ?? '?') . "] " . ($comment['body'] ?? '');

        return [
            'thread'      => $threadText,
            'title'       => $title,
            'selftext'    => $selftext,
            'target_body' => $comment['body'] ?? '',
        ];
    }

    private function postComment($parentFullname, $text)
    {
        // Dry run: skip the actual Reddit post but let the rest of the pipeline
        // run (context, generation, transcript) so you can review replies safely.
        if (!empty($this->config['disable_posting'])) {
            $this->log("🛑 DRY RUN — would reply to $parentFullname with:\n" . $text);
            return 't1_DRYRUN' . substr(md5($parentFullname . $text), 0, 8);
        }

        $resp = $this->redditPost('https://oauth.reddit.com/api/comment', [
            'api_type'  => 'json',
            'thing_id'  => $parentFullname,
            'text'      => $text,
        ]);
        $errors = $resp['json']['errors'] ?? [];
        if (!empty($errors)) {
            // Detect the new-account posting throttle so we can track it over time.
            foreach ($errors as $e) {
                if (strcasecmp($e[0] ?? '', 'RATELIMIT') === 0) {
                    $msg  = $e[1] ?? 'rate limited';
                    $wait = $resp['json']['ratelimit'] ?? $this->parseWaitSeconds($msg);
                    $s = &$this->state['stats'];
                    $s['posts_ratelimited']   = ($s['posts_ratelimited'] ?? 0) + 1;
                    $s['last_ratelimit_at']   = time();
                    $s['last_ratelimit_wait'] = $wait !== null ? (int) $wait : null;
                    $s['last_ratelimit_msg']  = $msg;
                    unset($s);
                    $this->saveState();
                    throw new Exception("RATELIMIT: $msg" . ($wait !== null ? " (~{$wait}s)" : ''));
                }
            }
            throw new Exception('Reddit rejected comment: ' . json_encode($errors));
        }
        // Success — record it so --status can show the throttle easing off.
        $this->state['stats']['posts_ok']    = ($this->state['stats']['posts_ok'] ?? 0) + 1;
        $this->state['stats']['last_post_at'] = time();
        $things = $resp['json']['data']['things'] ?? [];
        return $things[0]['data']['name'] ?? null;   // new t1_ fullname
    }

    /** Pull a wait time out of a "try again in N minutes/seconds" message. */
    private function parseWaitSeconds($msg)
    {
        if (preg_match('/(\d+)\s*minute/i', $msg, $m)) {
            return (int) $m[1] * 60;
        }
        if (preg_match('/(\d+)\s*second/i', $msg, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function isRepliable($author)
    {
        if ($author === '' || $author === '[deleted]') {
            return false;
        }
        if (strcasecmp($author, $this->config['reddit']['username']) === 0) {
            return false;   // never reply to ourselves
        }
        if (strcasecmp($author, 'AutoModerator') === 0) {
            return false;
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  OpenAI                                                             */
    /* ------------------------------------------------------------------ */

    /** The exact user-turn text sent to OpenAI: instruction + thread context. */
    private function buildUserMessage($context, $isFirstReply)
    {
        $instruction = $isFirstReply
            ? "Write your FIRST reply in this conversation. Per the disclosure rule, work in a "
              . "natural mention that you are an automated assistant. Reply to the comment below.\n\n"
            : "Write a follow-up reply continuing this conversation (disclosure already given earlier; "
              . "still answer honestly if asked whether you are a bot).\n\n";
        return $instruction . $context['thread'];
    }

    /**
     * Prompt-tuning aid (--context / --preview). Fetches the thread for a comment
     * permalink and prints exactly what would be sent to OpenAI: the system prompt
     * (from WordPress, prompt.txt as fallback) and the user message. With $callOpenAI
     * it also generates a reply. Never posts to Reddit, never touches state or WP.
     */
    public function previewContext($permalink, $callOpenAI = false)
    {
        $commentId = $this->parseCommentId($permalink);
        if (!$commentId) {
            throw new Exception("Not a valid Reddit comment permalink: $permalink");
        }
        $this->applyRemoteConfig();
        $this->ensureToken();
        $this->refreshPrompt();

        $comment = $this->fetchThing("t1_$commentId");
        if (!$comment) {
            throw new Exception("Could not fetch comment t1_$commentId");
        }
        $context = $this->buildContext($comment);

        $system = $this->systemPrompt;
        if ($system === null || $system === '') {
            $system = file_exists($this->promptFile) ? file_get_contents($this->promptFile) : '';
        }
        $source = ($this->systemPrompt !== null && $this->systemPrompt !== '') ? 'WordPress' : 'prompt.txt (fallback)';

        $bar = str_repeat('=', 72) . "\n";
        echo $bar . "SYSTEM PROMPT  (source: $source, model: " . ($this->config['openai']['model'] ?? '?') . ")\n" . $bar;
        echo rtrim($system) . "\n\n";
        echo $bar . "USER MESSAGE  (sent verbatim as the user turn)\n" . $bar;
        echo rtrim($this->buildUserMessage($context, true)) . "\n\n";

        if ($callOpenAI) {
            echo $bar . "OPENAI REPLY  (not posted)\n" . $bar;
            echo $this->generateReply($context, true) . "\n";
        }
    }

    private function generateReply($context, $isFirstReply)
    {
        $system = $this->systemPrompt;
        if ($system === null || $system === '') {   // e.g. --once before a cycle set it
            $system = file_exists($this->promptFile) ? file_get_contents($this->promptFile) : '';
        }

        $payload = [
            'model'    => $this->config['openai']['model'] ?? 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $this->buildUserMessage($context, $isFirstReply)],
            ],
            'temperature' => 0.7,
        ];

        $resp = $this->client->post($this->config['openai']['url'], [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->config['openai']['key'],
            ],
            'json' => $payload,
        ]);
        $body = json_decode($resp->getBody(), true);
        $text = $body['choices'][0]['message']['content'] ?? '';
        $text = trim($text);
        if ($text === '') {
            throw new Exception('OpenAI returned an empty reply');
        }
        return $text;
    }

    /* ------------------------------------------------------------------ */
    /*  WordPress REST                                                     */
    /* ------------------------------------------------------------------ */

    private function wpGet($path, $query = [])
    {
        try {
            $resp = $this->client->get(rtrim($this->config['wordpress']['url'], '/') . $path, [
                'headers' => ['X-UMACK-KEY' => $this->config['wordpress']['api_key']],
                'query'   => $query,
            ]);
            return json_decode($resp->getBody(), true);
        } catch (RequestException $e) {
            $this->log("⚠️ WP GET $path failed: " . $e->getMessage());
            return null;
        }
    }

    private function wpPost($path, $data)
    {
        try {
            $resp = $this->client->post(rtrim($this->config['wordpress']['url'], '/') . $path, [
                'headers' => ['X-UMACK-KEY' => $this->config['wordpress']['api_key']],
                'json'    => $data,
            ]);
            return json_decode($resp->getBody(), true);
        } catch (RequestException $e) {
            $this->log("⚠️ WP POST $path failed: " . $e->getMessage());
            return null;
        }
    }

    private function reportMessage($convId, $author, $role, $body, $fullname, $createdUtc)
    {
        if (!$convId) {
            return;
        }
        $this->wpPost('/conversations/' . $convId . '/messages', [
            'author'         => $author,
            'role'           => $role,          // bot | user
            'body'           => $body,
            'reddit_fullname'=> $fullname,
            'created_utc'    => (int) $createdUtc,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Local state (lock-guarded JSON)                                    */
    /* ------------------------------------------------------------------ */

    private function loadState()
    {
        if (file_exists($this->stateFile)) {
            $this->state = json_decode(file_get_contents($this->stateFile), true) ?: [];
        } else {
            $this->state = [];
        }
        $this->state += ['conversations' => [], 'processed_inbox' => [], 'stats' => []];
        // Trim the processed-inbox marker set so it can't grow unbounded.
        if (count($this->state['processed_inbox']) > 5000) {
            $this->state['processed_inbox'] = array_slice($this->state['processed_inbox'], -2000, null, true);
        }
    }

    private function saveState()
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->stateFile . '.tmp';
        file_put_contents($tmp, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $this->stateFile);   // atomic replace
    }

    /* ------------------------------------------------------------------ */
    /*  Logging                                                            */
    /* ------------------------------------------------------------------ */

    private function truncate($s, $n)
    {
        return (mb_strlen($s) > $n) ? mb_substr($s, 0, $n) . '…' : $s;
    }

    private function log($line)
    {
        $stamp = date('Y-m-d H:i:s');
        $out = "[$stamp] $line\n";
        echo $out;
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->logFile, $out, FILE_APPEND | LOCK_EX);
    }
}

/* -------------------------------------------------------------------------- */
/*  CLI entry                                                                  */
/* -------------------------------------------------------------------------- */

if (PHP_SAPI === 'cli') {
    try {
        $bot = new UmAckshuallyBot();
        $cmd = $argv[1] ?? '';
        if ($cmd === '--status') {
            // Account health + throttle report; run this anytime.
            $bot->printStatus();
        } elseif ($cmd === '--once') {
            // Single cycle — handy for testing without the daemon loop.
            $bot->cycle();
        } elseif ($cmd === '--context' || $cmd === '--preview') {
            // Prompt tuning: show exactly what OpenAI would receive for a comment
            // permalink; --preview also shows the reply it generates. Never posts.
            $url = $argv[2] ?? '';
            if ($url === '') {
                fwrite(STDERR, "Usage: php um_ackshually_bot.php $cmd <reddit comment permalink>\n");
                exit(2);
            }
            $bot->previewContext($url, $cmd === '--preview');
        } else {
            $bot->run();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Fatal: " . $e->getMessage() . "\n");
        exit(1);
    }
}
