# authentication.md — X (Twitter) credentials and portal setup

X hands out two unrelated families of credentials, and most of the confusion in
setting this plugin up comes from mixing them. This page says what each one is,
which half of the plugin uses it, and where to get the OAuth 2.0 Client ID that
login actually needs.

## The two credential families

**App-only / OAuth 1.0a** — the Consumer Key, Secret Key, and Bearer Token shown
on the "Keys and tokens" tab when you first create an app. These authenticate the
*app*, not any user. The Bearer Token is the app-only token you pass as
`Authorization: Bearer …` to read public data; the Consumer Key + Secret are the
1.0a consumer pair, and can also be exchanged (`client_credentials`) to mint that
same app-only bearer.

**OAuth 2.0 user-context** — a Client ID, and a Client Secret if the app is a
confidential client. These drive the three-legged login: the user consents, X
issues an access token (and a refresh token) that represents *that user*, scoped
to whatever permissions you requested. This is what "Login with X" runs on.

The app-only credentials cannot log anyone in. The OAuth 2.0 credentials are a
separate thing you generate after turning on user authentication.

## What this plugin uses each for

| Credential | `plugin.json` key | Used by |
|---|---|---|
| Consumer Key | `apiKey` | `Twitter` class app-context reads (`encodeConsumerPayload` → `obtainBearerToken`) |
| Secret Key | `secret` | same — the 1.0a consumer secret half |
| Bearer Token | `bearerToken` | `Twitter::api()` directly, via `bearerTokenFromConfig()` — app-context reads |
| OAuth 2.0 Client ID | `clientId` | login + every user-context action (the per-user token) |
| OAuth 2.0 Client Secret | `clientSecret` | token exchange, **confidential clients only** (omit for public) |

In short: the app-only set powers the read side (`byUsernames`, timelines, search —
the app speaking as itself). The OAuth 2.0 set powers login and everything done
*as the user* (`postTweet`, likes, follows, DMs, …).

## Getting the OAuth 2.0 Client ID

The Client ID does not exist until you enable OAuth 2.0 on the app. In the X
developer portal:

1. **Projects & Apps → your app → User authentication settings → Set up.**
2. Choose **App permissions** to match what you need — Read, Read and write, or
   Read and write and Direct Messages. (The action methods need write; DMs need
   the DM permission.)
3. Choose the **Type of App** (see confidential vs public, below).
4. Under App info, set the **Callback URI / Redirect URL** to the absolute URL
   that maps to your `Users/oauth` action — it must match `oauth2.redirectUri`
   and the portal value *exactly*, byte for byte — and set a Website URL. Save.
5. Back on the **Keys and tokens** tab, an **OAuth 2.0 Client ID and Client
   Secret** section now appears. Generate it and copy the Client ID (and the
   Client Secret, if the app is confidential). X shows the full secret only once;
   losing it means regenerating.

Put the Client ID in `clientId`. Put the Client Secret in `clientSecret`, or leave
`clientSecret` out entirely for a public client.

## Confidential vs public client

This choice decides how the token exchange authenticates, and getting it wrong
produces a misleading `unauthorized_client` error. X apps default to
**confidential**.

- **Confidential** (Web App, Automated App, or Bot): you get a Client Secret, and
  the token exchange must send `Authorization: Basic base64(clientId:clientSecret)`.
- **Public** (Native App, Single page App): no secret; the exchange sends
  `client_id` in the request body, relying on PKCE alone.

This plugin runs the token exchange server-side in the `Users/oauth` handler, so a
confidential Web App is the more secure choice and works fine — just make sure
`Users_OAuth::exchange` sends the Basic header (it does when `clientSecret` is set,
and falls back to the public PKCE body when it isn't). Whichever you pick, the
exchange path and the app type have to agree.

You can verify the type after the fact: base64-decode the Client ID. It ends in
`:ci` for a confidential client and `:na` for a native (public) one.

## Scopes

OAuth 2.0 access is scoped. Request only what the app uses; `offline.access` is
what gets you a refresh token. Set these in `oauth2.scopes`.

| Capability | Scope |
|---|---|
| Read profile / login | `tweet.read`, `users.read` |
| Refresh token | `offline.access` |
| Post / delete / retweet | `tweet.write` |
| Like / unlike | `like.write` |
| Follow / unfollow | `follows.write` |
| Mute / unmute | `mute.write` |
| Block / unblock | `block.write` |
| Bookmark / unbookmark | `bookmark.write` (and `bookmark.read` to list) |
| Hide / unhide reply | `tweet.moderate.write` |
| Direct messages | `dm.write` (and `dm.read`) |
| Upload media | `media.write` |

Adding write scopes widens the consent screen, so the shipped default keeps the
minimal login set (`tweet.read`, `users.read`, `offline.access`); add the rest when
you actually wire up the corresponding actions.

## Access-tier caveats (as of 2025)

A few endpoints exist but are gated by paid API tier, not by your code:

- Creating a like (`POST /2/users/:id/likes`) was removed from the Free tier in
  August 2025; it works on Basic and above.
- Bookmark endpoints require at least Basic ($200/mo).

On the Free tier these return X's permission error, which the action methods pass
through unchanged.

## A word on secrets

Treat the Secret Key, Bearer Token, and OAuth 2.0 Client Secret as live secrets:
store them outside source control, and regenerate any that leak (a screenshot
counts). The Consumer Key and OAuth 2.0 Client ID are closer to public identifiers,
but the secrets paired with them are not.