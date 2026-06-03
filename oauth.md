# oauth.md — Login with X flow and user-context API

Generic "Sign in with X" for the Users plugin, a
platform adapter on top of `Users.authenticate`, driven by a `Users_Intent`. The
OAuth round trip runs in a popup against one generic handler; the opener logs
itself in afterward through the normal `Users.authenticate(platform)` chain, so
the popup never rotates the opener's session.

For credentials and portal setup, see `authentication.md`. This page is the flow
and the code.

## Why the Facebook model, not Telegram

A typical oAuth platform issues a per-user OAuth token, not a global bot token. So the token lives on the `Users_ExternalFrom_Twitter` row, and that row does the user-context API work itself — `getMe()`, DMs, and the full action set. The `Twitter` class keeps its app-context read methods (`byUsernames`, timelines; app bearer or `twitterapi.io`) and adds thin conveniences that resolve a row via `userExternalFrom()` and delegate to the instance. Telegram, with one shared bot token, puts the work on the class; Facebook and X put it on the row.

## Files

| File | Installs to |
|---|---|
| `Users/handlers/Users/oauth/response.php` | `platforms/Users/handlers/Users/oauth/response.php` |
| `Users/classes/Users/OAuth.php` | `platforms/Users/classes/Users/OAuth.php` (adds two methods) |
| `Users/classes/Users/ExternalFrom/Twitter.php` | `platforms/Users/classes/Users/ExternalFrom/Twitter.php` |
| `Users/web/js/methods/Users/OAuth/start.js` | `platforms/Users/web/js/methods/Users/OAuth/start.js` |
| `Twitter/classes/Twitter.php` | `plugins/Twitter/classes/Twitter.php` |
| `Twitter/web/js/Twitter.js` | `plugins/Twitter/web/js/Twitter.js` |
| `Twitter/web/js/methods/Users/authenticate/twitter.js` | `plugins/Twitter/web/js/methods/Users/authenticate/twitter.js` |
| `Twitter/config/plugin.json` | merge into your Twitter plugin / app config |
| `_patches/Users.OAuth.replacement.js` | hand-paste into `platforms/Users/web/js/Users.js` |

The server and JS pieces that *extend Users* live under the Users plugin, matching
Telegram (`Users_ExternalFrom_Telegram` is in Users too). The bootstrap, config,
and assets live in the Twitter plugin.

The `authenticate.twitter` body ships inside Twitter at
`Twitter/web/js/methods/Users/authenticate/twitter.js`. `Users.authenticate`
normally loads every `authenticate.*` slot from `{{Users}}/js/methods/Users/authenticate`
via `Q.Method.define`, but the slot is registered with a `customPath` option
pointing at `{{Twitter}}/…`, which overrides only the directory so the adapter can
travel with Twitter. `Twitter.js` registers the slot (in
`beforeDefineAuthenticateMethods`, so the loader picks it up) and provisions the
intent; the slot still receives `[Users, priv]` from the dispatcher's `argsFn`.
Only `OAuth/start.js` stays under Users, since it's a `Users.OAuth` method loaded
by that class's own `Q.Method.define`.

## Flow, end to end

1. On init, `Twitter.js` provisions a `Users/authenticate` intent; the token is
   cached.
2. On click, `authenticate.twitter` calls `Users.OAuth.start`.
3. With the token already cached, `start` opens the popup at
   `Users/oauth?intent={token}&platform=twitter` synchronously, still inside the
   click gesture. With no cached token it opens the popup blank in the gesture and
   fills the URL once provisioning returns. `openWindow:false` forces a full-page
   flow (e.g. webview), where the handler completes login itself on the way back
   (step 5a), since there is no opener to do it.
4. Phase 1 (`Users_oauth_response`, no `code`): stash `platform`, `appId`,
   `finalRedirect`, and the PKCE verifier on the intent; redirect to X with
   `state = token`.
5. Twitter redirects back to `Users/oauth?code=&state=`. Phase 2 exchanges the code
   (`Users_OAuth::exchange`), resolves the xid (`Users_ExternalFrom_Twitter::fetchMe`,
   which wraps the fresh token in a transient row and calls `getMe`), stages the
   tokens in a server-only `Users_ExternalFrom` row keyed `(twitter, appId, xid)`,
   clears the verifier, `complete()`s the intent with the public xid, and renders a
   page that closes the popup (or redirects to `finalRedirect` for full-page).
5a. On the full-page path only, phase 2 also calls `Users::authenticate` itself
   before redirecting, because that request runs in the user's own session and no
   opener will follow up. The popup path skips this so it never rotates the shared
   session.
6. The opener notices `popup.closed`, makes one
   `Q.req('Users/oauth', ['completed','ok','xid'])` with `check=1`, and on `ok`
   sets `Users.authPayload.twitter = {intent: token}` and calls
   `priv.handleXid('twitter', appId, xid, …, {prompt:false})`.
7. `handleXid` → `__doAuthenticate` picks up `authPayload.twitter` and
   `_doAuthenticate` POSTs `Users/authenticate` with `intent` in the fields.
   `Users_ExternalFrom_Twitter::authenticate` reads intent → xid → staged row,
   **deletes** the staged row, and returns a fresh `ExternalFrom` with `userId`
   unset. `Users::authenticate` then owns the insert and the From→To mirror,
   stamping the correct `userId`. Cancel or error → the intent never completes →
   `ok:false` → `_doCancel`, no POST.

The real xid (not `null`) must reach `handleXid`: its fast path is
`Users.loggedInUser.xids[key] == xid`, and `undefined == null` is `true` in JS, so
a `null` xid would let a logged-in user's *connect* attempt short-circuit without
authenticating. The status check returns the xid for exactly this reason — public,
and gated to the originating session.

## Why stage-then-delete

`Users::authenticate`'s session block re-saves the `ExternalFrom` row only when a
token field differs, so a pre-staged row left in place would keep its empty
`userId`. Returning a fresh, `userId`-unset row (and deleting the staged one)
reproduces the Facebook/Telegram path, where `Users::authenticate` inserts the row
with the right `userId` and the `afterSaveExecute` From→To mirror fires once,
correctly. Staging uses a query-level `insert()->onDuplicateKeyUpdate()` over the
token columns only (never `userId`), so the mirror does not fire early and a
returning user's row is not clobbered. Tokens live only in this server-side row,
never on the intent — the intent's `exportArray` would expose them — so only the
public xid and the transient, immediately-cleared verifier ever touch the intent.

## User-context action API

Once a user is logged in, anything done *as that user* goes through the per-user
token on their `Users_ExternalFrom_Twitter` row. Each capability is a pair: the
workhorse on the EF (uses `$this->accessToken`, and `$this->xid` as the
authenticating-user path segment for self-scoped endpoints), and a thin
`Twitter::` static that resolves the row via `userExternalFrom($appId, $userId)` —
`$userId` defaulting to the logged-in user — and delegates.

Signature pattern: `Twitter::method($appId, ...args, $userId = null)`. Each returns
X's decoded response array, or `null` on a missing row or transport failure.

| Convenience (and EF instance method) | X API v2 call |
|---|---|
| `postTweet($appId, $text, $options, $userId)` | `POST /2/tweets` — `$options`: `reply_to`, `quote_tweet_id`, `media_ids`, `poll_options`, `poll_duration_minutes`, `reply_settings`, `community_id`, `body` (raw merge) |
| `deleteTweet($appId, $tweetId, $userId)` | `DELETE /2/tweets/:id` |
| `likeTweet` / `unlikeTweet` | `POST` / `DELETE /2/users/:xid/likes[/:tweet_id]` |
| `retweet` / `unretweet` | `POST` / `DELETE /2/users/:xid/retweets[/:source_tweet_id]` |
| `followUser` / `unfollowUser` | `POST` / `DELETE /2/users/:xid/following[/:target_user_id]` |
| `muteUser` / `unmuteUser` | `POST` / `DELETE /2/users/:xid/muting[/:target_user_id]` |
| `blockUser` / `unblockUser` | `POST` / `DELETE /2/users/:xid/blocking[/:target_user_id]` |
| `bookmarkTweet` / `unbookmarkTweet` | `POST` / `DELETE /2/users/:xid/bookmarks[/:tweet_id]` |
| `hideReply` / `unhideReply` | `PUT /2/tweets/:id/hidden` `{hidden}` |
| `getBookmarks($appId, $options, $userId)` | `GET /2/users/:xid/bookmarks` |
| `getLikedTweets($appId, $options, $userId)` | `GET /2/users/:xid/liked_tweets` |
| `sendDirectMessage($appId, $recipientXid, $text, $options, $userId)` | `POST /2/dm_conversations/with/:participant_id/messages` |
| `uploadMedia($appId, $filePath, $options, $userId)` | `POST /2/media/upload` (multipart; returns `data.id` for `media_ids`) |

`handlePushNotification` is left as it was — its own `Q_Utils::post` to the row's
own user, with the self-DM caveat (sending with the recipient's own token is the
user messaging themselves; an app→user notification would need the app account's
token, a different lookup). `sendDirectMessage` is the general method for messaging
arbitrary recipients as the user.

On the EF, `apiRequest()` routes GET/POST through `Q_Utils` to match the rest of
the plugin, and DELETE/PUT through curl, since `Q_Utils` has no verb helper for
those. `uploadMedia` is a curl multipart POST with `CURLFile`; it covers the simple
single-request path for images and GIFs. Large video needs the chunked
INIT/APPEND/FINALIZE flow, which is not implemented here.

These methods need the matching OAuth 2.0 write scopes on the user's token and are
subject to X's access-tier gating — both covered in `authentication.md`.