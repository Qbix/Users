# Users Plugin

Identity, authentication, sessions, contacts, roles, push notifications, referrals, and Web3 integration for the Qbix platform. Users is the foundational layer — every other plugin depends on it. It provides user accounts, multi-platform login, contact management, role-based permissions, device registration for push notifications, an intent system for cross-session workflows, and an OAuth 2.0 authorization server.

## Core Concepts

The plugin manages six fundamental things: **users** (identity and credentials), **sessions** (authenticated state), **contacts and labels** (social graph), **devices** (push notification endpoints), **external identities** (platform logins), and **intents** (cross-session action tokens).

### Users

A **user** is identified by an opaque `id` (a varbinary(31) string). Users can sign up with an email address, mobile number, or external platform account (Facebook, X/Twitter, Web3 wallet, etc.). The `signedUpWith` field records which method was used originally.

Each user has a `username` (unique, validated for length and characters), an `icon` (path to a folder of size variants: 40.png, 50.png, 80.png, 1000.png), an optional `passphraseHash`, and optional verified `emailAddress` and `mobileNumber`. The `xids` JSON field stores a mapping of external platform identifiers (e.g. `{"facebook_appId": ["12345"], "twitter_appId": ["67890"]}`).

User IDs come in two flavors. Regular user IDs are short random strings. **Community IDs** follow the pattern `AppName` or `AppName_suffix` (starting with uppercase) and represent organizational entities rather than individuals — think of them as the "publisher" in the Streams plugin's `publisherId` field. The `isCommunityId()` method distinguishes between them.

### Identifiers and the Identify Table

The `identify` table is a universal lookup from any identifier to a user id. The `identifier` column is a normalized, type-prefixed string — for example `email:alice@example.com` or `mobile:+15551234567`. The `state` field is `verified` (the user has confirmed this identifier), `future` (a placeholder mapping created before the user exists — used for pre-inviting people by email or phone), or `unlinked` (the mapping was removed but the row kept for history).

When someone is invited by email before they have an account, the system creates a "future user" with a row in `identify` in the `future` state. When that person later registers with that email, the system "adopts" the future user — merging the placeholder identity with the real account so that all prior invitations, contacts, and access grants transfer automatically.

### Sessions

Sessions are stored in the `session` table and managed via PHP's session infrastructure with database backing. Each session row stores the PHP-serialized session data, a JSON `content` field readable by JavaScript, the `userId` of the logged-in user (if any), device and platform information, and IP addresses. The `duration` field controls session lifetime.

Session IDs are generated with user-awareness: the `Q_session_generateId` hook can prefix session IDs for sharding or routing. Sessions integrate with the Metrics plugin — the `Users/before/Metrics_Visit_id` hook can prefix visit IDs with the user ID for activity tracking.

### Authentication Flow

The plugin supports multiple authentication paths, all converging through `Users::authenticate()`:

**Native (email/mobile + passphrase):** The user provides an identifier and passphrase. The system normalizes the identifier, looks it up via `Users::identify()`, retrieves the user, and verifies the passphrase hash. Passphrases are hashed using configurable algorithms — by default `password_hash` (bcrypt) or `hash_pbkdf2` with 64000 iterations.

**Platform (Facebook, X/Twitter, Twitch, etc.):** OAuth 2.0 three-legged flow. Each platform has an adapter class (`Users_ExternalFrom_Facebook`, etc.) that implements the `authenticate()` interface. The adapter exchanges the OAuth code for an access token, fetches the user's profile, and either matches an existing user (via the `external_from` table) or creates a new one. Profile data (name, avatar, email) can be imported into the user's account.

**Web3 (Ethereum wallets):** The user signs a challenge message with their wallet. The plugin verifies the signature against the claimed address, then looks up or creates a user via the `Users_ExternalFrom_Web3` adapter. This bridges blockchain identity with the Qbix user system.

**Device (iOS/Android):** Mobile apps authenticate by providing a device token. The device is registered for push notifications, and a user is created or retrieved.

After successful authentication, `Users::setLoggedInUser()` establishes the session, updates the user's `sessionId` and `sessionCount`, fires the `Users/setLoggedInUser` event (which other plugins hook into), and returns session extras to the client.

### External Identity Tables

Two tables track the mapping between Qbix users and their identities on external platforms:

**external_from** stores mappings from `(platform, appId, xid)` → `userId`. When Facebook tells us that user with Facebook ID 12345 authenticated, this table maps that to the Qbix user id. The `xid` is the user's identifier on the external platform.

**external_to** stores the reverse mapping: `(platform, appId, userId)` → `xid`. Given a Qbix user, look up their external identity on a platform.

Each row carries an `extra` JSON field for platform-specific metadata (access tokens, refresh tokens, profile URLs, etc.) and timestamps.

### Contacts and Labels

The contact system models a directed social graph. A **contact** is a row saying "user A has placed user B under label L." Labels are freeform strings following the `Module/name` convention.

**Labels** serve dual purpose — they work as both personal contact categories and community roles. The `label` table stores per-user label definitions with icons and titles. Built-in labels include:

Personal labels: `Users/friends`, `Users/family`, `Users/business`, `Users/dating`, `Users/neighbors`.

Community roles: `Users/owners`, `Users/admins`, `Users/members`, `Users/guests`, `Users/testers`, `Users/speakers`, `Users/hidden`.

### Role Hierarchy

Community roles form a permission hierarchy defined in the `Users.roles` config. Each role specifies `canGrant` (which other roles this role can assign), `canRevoke` (which it can remove), `canSee` (which roles are visible to holders of this role), and `canManageLabels` (which label prefixes this role can create/edit).

The hierarchy looks like:

`Users/owners` can grant and revoke `admins`, `members`, `guests`, `testers`, `speakers`, `hidden`. They can manage any `Users/` prefixed label. `Users/admins` can grant and revoke `members`, `guests`, `testers`, `speakers`, `hidden`. `Users/members` can grant and revoke `guests`. `Users/guests` and `Users/testers` cannot grant anything.

The `Users::canManageContacts()` and `Users::canManageLabels()` methods enforce this hierarchy when users try to add contacts or create labels.

### Devices and Push Notifications

The `device` table registers endpoints for push notifications. Each device row stores a `userId`, a `deviceId` (the push registration token or Web Push endpoint URL), a `platform` (ios, android, chrome, firefox, safari, web), and the current `sessionId`.

Platform-specific subclasses handle the actual push delivery: `Users_Device_Ios` uses APNs (via the bundled ApnsPHP library), `Users_Device_Android` uses FCM, `Users_Device_Chrome`/`Firefox`/`Safari` use Web Push with VAPID keys, and `Users_Device_Web` is a generic Web Push adapter.

The `Users.pushNotifications()` server-side method (Node.js) fans out notifications to all of a user's registered devices. The `Users_ExternalFrom::pushNotification()` method handles platform-specific notification delivery (e.g. sending a notification through Facebook Messenger or iMessage via the Amb/Sendblue adapters).

### Intents

An **intent** is a short-lived, cross-session action token. Intents solve the problem of "user needs to do something on device A that was initiated on device B" — for example, authenticating on a mobile app via a QR code scanned on desktop, or bridging a Web3 wallet connection from one browser tab to another.

Each intent has a random `token`, an `action` (e.g. `Users/authenticate`, `Users/bridge`), JSON `instructions` (opaque to the token holder), a `sessionId` and `userId` (linking it to the session that created it), and a time window (`startTime`/`endTime`). When the intent is completed (the target action is performed), the `completedTime` is set and the results are broadcast to the originating session via socket.

The intent flow: (1) Session A creates an intent via `Users_Intent::newIntent()`, getting back a token. (2) The token is transmitted to Session B (via QR code, URL, NFC, etc.). (3) Session B calls `Users/intent` with the token, which executes the action described in `instructions`. (4) The intent is marked complete, and Session A is notified via WebSocket.

### Capabilities

Capabilities are signed permission tokens included in HTTP responses. They encode which socket operations a client is allowed to perform (e.g. `Users/socket` for WebSocket connections, `Users/authenticate` for authentication, `Streams/observe` for real-time stream observation). The capability is generated server-side based on the user's roles and the page being viewed, then verified by the Node.js socket server on connection.

### Quotas

The quota system rate-limits sensitive operations. Each quota is defined by an operation name, a time window (in seconds), and per-role limits. For example, `Users/web3/transaction` allows 10 transactions per 60 seconds for regular users, 100 for owners and admins. The `quota` table tracks per-user usage.

### Voting and Totals

The `vote` table stores individual votes: a user casts a vote with a `value` and `weight` for a subject identified by `(forType, forId)`. The `total` table maintains aggregated counts and weighted averages across all votes. This is a general-purpose mechanism used by other plugins for likes, ratings, flagging, and any numeric aggregation.

### Referrals

The `referred` table tracks multi-level referral chains. When user A invites user B to a community and B accepts, a `referred` row is created recording the chain. Referrals carry `points` (awarded based on configurable rules per action — e.g. 1 point for invite acceptance, 3 for subscription) and a `qualifiedTime` (when the referral became meaningful). The `Users_Referred::handleReferral()` method walks the referral chain, awarding points at each level.

### Web3 Integration

The Web3 subsystem provides a full-stack bridge between EVM-compatible blockchains and the Qbix platform:

**Chain configuration** — The `Users.web3.chains` config maps chain IDs (hex) to RPC endpoints, WebSocket URLs, block explorers, ABI URLs, and native currency metadata. Preconfigured chains include Ethereum, Polygon, BSC, Arbitrum, Base, Optimism, Linea, Blast, and testnets.

**Wallet support** — The `Users.web3.wallets` config defines supported wallets (WalletConnect, MetaMask, Trust Wallet, Phantom, Kaikas) with deep-link URL templates.

**Contract interactions** — The `web3` table caches results from on-chain queries (keyed by chainId, contract, method, params) to avoid redundant RPC calls. The `web3_transaction` table tracks submitted transactions through their lifecycle: `signed` → `pending` → `mined` (or `rejected`).

**Community contracts** — The plugin includes contract addresses for on-chain community management (Community factory, ReleaseManager, Voting) deployed on Polygon and BSC.

## Database Schema

### user

The central identity table.

| Column | Type | Purpose |
|---|---|---|
| `id` | varbinary(31) | PK. Opaque user identifier. |
| `username` | varchar(63) | Unique display name. |
| `icon` | varbinary(255) | Path to icon folder. |
| `url` | varbinary(255) | User's profile URL. |
| `emailAddress` | varbinary(255) | Verified primary email. |
| `mobileNumber` | varbinary(255) | Verified primary mobile. |
| `emailAddressPending` | varbinary(255) | Unverified email awaiting activation. |
| `mobileNumberPending` | varbinary(255) | Unverified mobile awaiting activation. |
| `signedUpWith` | varchar(31) | Registration method (email, mobile, facebook, etc.). |
| `passphraseHash` | varchar(255) | Hashed passphrase. |
| `xids` | varchar(1023) | JSON: `{platformName: [xid1, ...]}`. |
| `sessionId` | varbinary(255) | Most recent authenticated session. |
| `sessionCount` | int | Total sessions created. |
| `preferredLanguage` | varchar(3) | ISO language code (default: en). |
| `salt` | varbinary(63) | Cryptographic salt. |
| `pincodeHash` | varbinary(255) | Secondary authentication code hash. |
| `fb_uid` | bigint | Legacy Facebook UID. |
| `insertedTime` | timestamp | Registration time. |
| `updatedTime` | timestamp | Last update. |

### session

PHP sessions backed by the database.

| Column | Type | Purpose |
|---|---|---|
| `id` | varbinary(255) | PK. Session identifier. |
| `content` | varchar(4095) | JSON (JS-readable). |
| `php` | varchar(4095) | PHP-serialized session data. |
| `userId` | varbinary(31) | Logged-in user, if any. |
| `deviceId` | varbinary(700) | Attached push device. |
| `timeout` | int | Seconds until pincode re-entry required. |
| `duration` | int | Session lifetime in seconds. |
| `platform` | varchar(31) | Client platform. |
| `appId` | varchar(200) | External app identifier. |
| `version` | varchar(34) | Platform version. |
| `formFactor` | enum | `mobile`, `tablet`, `desktop`. |
| `ipv4` | varbinary(16) | Client IPv4 address. |
| `ipv6` | varbinary(64) | Client IPv6 address. |
| `insertedTime` | timestamp | Session creation time. |
| `updatedTime` | timestamp | Last activity. |

### email

Email addresses with activation and auth codes.

| Column | Type | Purpose |
|---|---|---|
| `address` | varbinary(255) | PK. Normalized email. |
| `userId` | varbinary(31) | Owner. |
| `state` | enum | `unverified`, `active`, `suspended`, `unsubscribed`. |
| `activationCode` | varbinary(255) | One-time verification code. |
| `activationCodeExpires` | timestamp | Code expiry. |
| `authCode` | varbinary(255) | Persistent auth token for email links. |

### mobile

Mobile numbers with activation codes.

| Column | Type | Purpose |
|---|---|---|
| `number` | varbinary(255) | PK. Normalized phone number. |
| `userId` | varbinary(31) | Owner. |
| `state` | enum | `unverified`, `active`, `suspended`, `unsubscribed`. |
| `carrier` | enum | Detected carrier. |
| `capabilities` | enum | SMS, WAP, internet. |
| `activationCode` | varbinary(255) | One-time verification code. |
| `authCode` | varbinary(255) | Persistent auth token. |

### identify

Universal identifier → user lookup.

| Column | Type | Purpose |
|---|---|---|
| `identifier` | varbinary(255) | PK. Type-prefixed normalized identifier. |
| `state` | enum | `verified`, `future`, `unlinked`. |
| `userId` | varbinary(31) | The user this identifier resolves to. |

### contact

Directed social graph edges.

| Column | Type | Purpose |
|---|---|---|
| `userId` | varbinary(31) | PK. The user who owns the contact. |
| `label` | varchar(63) | PK. The label (e.g. `Users/friends`). |
| `contactUserId` | varbinary(31) | PK. The contact user. |
| `nickname` | varchar(255) | Optional display name override. |
| `insertedTime` | timestamp | When the contact was added. |

### label

Label definitions per user.

| Column | Type | Purpose |
|---|---|---|
| `userId` | varbinary(31) | PK. Owner. |
| `label` | varchar(63) | PK. Label identifier. |
| `icon` | varbinary(255) | Path to label icon. |
| `title` | varchar(255) | Human-readable name. |

### device

Push notification endpoints.

| Column | Type | Purpose |
|---|---|---|
| `userId` | varbinary(31) | PK. Owner. |
| `deviceId` | varbinary(700) | PK. Push registration token or endpoint URL. |
| `platform` | varchar(31) | ios, android, chrome, firefox, safari, web. |
| `version` | varchar(45) | Platform version. |
| `appId` | varchar(200) | External app identifier. |
| `sessionId` | varbinary(255) | Current session. |
| `formFactor` | enum | mobile, tablet, desktop. |
| `auth` | varchar(31) | Web Push auth key. |
| `p256dh` | varchar(1023) | Web Push ECDH public key. |

### external_from

Platform identity → Qbix user mapping.

| Column | Type | Purpose |
|---|---|---|
| `platform` | varchar(31) | PK. Platform name. |
| `appId` | varbinary(200) | PK. App identifier on the platform. |
| `xid` | varbinary(31) | PK. External user ID. |
| `userId` | varbinary(31) | Qbix user ID. |
| `insertedTime` | timestamp | When the mapping was created. |
| `updatedTime` | timestamp | Last update. |
| `accessToken` | varchar(1023) | OAuth access token. |
| `extra` | varchar(1023) | JSON for platform-specific data. |

### external_to

Qbix user → platform identity mapping (reverse of external_from).

| Column | Type | Purpose |
|---|---|---|
| `platform` | varchar(31) | PK. |
| `appId` | varbinary(200) | PK. |
| `userId` | varbinary(31) | PK. Qbix user ID. |
| `xid` | varbinary(31) | External user ID. |

### link

Imported contact information that may later resolve to users.

| Column | Type | Purpose |
|---|---|---|
| `identifier` | varbinary(255) | PK. Contact info (email, phone). |
| `userId` | varbinary(31) | PK. Who imported this link. |
| `extraInfo` | varchar(255) | JSON: labels, firstName, lastName. |

### vote

Individual user votes.

| Column | Type | Purpose |
|---|---|---|
| `userId` | varbinary(31) | PK. Voter. |
| `forType` | varbinary(31) | PK. Subject type. |
| `forId` | varbinary(255) | PK. Subject identifier. |
| `value` | decimal(14,4) | Vote value (can be averaged). |
| `weight` | decimal(14,4) | Vote weight (default 1). |
| `extra` | varchar(1023) | JSON metadata. |

### total

Aggregated vote totals.

| Column | Type | Purpose |
|---|---|---|
| `forType` | varbinary(31) | PK. |
| `forId` | varbinary(255) | PK. |
| `voteCount` | bigint | Number of votes. |
| `weightTotal` | decimal(14,4) | Sum of weights. |
| `value` | decimal(14,4) | Weighted average value. |

### intent

Cross-session action tokens.

| Column | Type | Purpose |
|---|---|---|
| `token` | varbinary(255) | PK. Random token. |
| `action` | varbinary(31) | Action name (e.g. `Users/authenticate`). |
| `instructions` | varbinary(2047) | JSON payload for the action. |
| `url` | varbinary(2083) | Optional URL associated with the intent. |
| `sessionId` | varbinary(255) | Originating session. |
| `userId` | varbinary(31) | Originating user. |
| `startTime` | timestamp | Valid-from time. |
| `endTime` | timestamp | Expiry time. |
| `completedTime` | timestamp | When the intent was fulfilled. |

### referred

Referral chain tracking.

| Column | Type | Purpose |
|---|---|---|
| `userId` | varbinary(31) | PK. The referred user. |
| `toCommunityId` | varbinary(31) | PK. Which community. |
| `referredByUserId` | varbinary(31) | PK. The referrer. |
| `points` | decimal(4,2) | Referral points awarded. |
| `qualifiedTime` | timestamp | When referral qualified. |
| `extra` | varchar(1023) | JSON metadata. |

### quota

Rate-limiting counters.

| Column | Type | Purpose |
|---|---|---|
| `userId` | varbinary(31) | PK. |
| `action` | varchar(63) | PK. The rate-limited operation. |
| `insertedTime` | timestamp | Window start. |
| `updatedTime` | timestamp | Last increment. |

### permission

Named permissions granted to labels.

| Column | Type | Purpose |
|---|---|---|
| `userId` | varbinary(31) | PK. Granting user. |
| `label` | varchar(63) | PK. Target label. |
| `permission` | varchar(255) | Permission name. |
| `extra` | text | JSON parameters. |

### web3

Cached blockchain query results.

| Column | Type | Purpose |
|---|---|---|
| `chainId` | varchar(10) | PK. Hex chain ID. |
| `contract` | varchar(42) | PK. Contract address. |
| `methodName` | varchar(63) | PK. Method called. |
| `params` | varchar(1023) | PK. Call parameters. |
| `result` | text | Cached response. |

### web3_transaction

Blockchain transaction lifecycle tracking.

| Column | Type | Purpose |
|---|---|---|
| `chainId` | varchar(10) | PK. |
| `transactionId` | varchar(66) | PK. Transaction hash. |
| `contract` | varchar(42) | Target contract. |
| `contractABIName` | varchar(255) | ABI identifier. |
| `methodName` | varchar(63) | Method called. |
| `fromAddress` | varchar(42) | Sender address. |
| `status` | enum | `signed`, `pending`, `mined`, `rejected`. |

## Server-Side Architecture

### Plugin Hooks

Users registers hooks into the Qbix event system:

**Before Q/objects** — Handles the core request lifecycle: session start, user authentication, access control, and routing to login pages when `requireLogin` is configured.

**Before/After Q/responseExtras** — Injects user-related JavaScript configuration, session data, and capability tokens into page responses.

**Before/After Q/sessionExtras** — Manages session-to-client data synchronization, including logged-in user info and nonce values.

**After Q/session/write** — Syncs PHP session data to the database, maintaining the JSON `content` field for client-side access.

**After Q/image/save** — Handles user icon updates when profile images are uploaded.

**After Users_User/saveExecute** — Post-save hook for user row updates — used to trigger avatar updates, session refreshes, and related stream synchronization.

### Request Handlers

Key HTTP endpoints:

`Users/authenticate` — POST: platform authentication. `Users/login` — POST: native login with identifier + passphrase. `Users/activate` — POST: verify activation codes sent via email/SMS. `Users/authorize` — POST: OAuth 2.0 authorization for third-party apps. `Users/intent` — POST/PUT: create or resolve cross-session intents. `Users/contact` — POST/PUT/DELETE: manage contacts. `Users/label` — POST/PUT/DELETE: manage labels. `Users/device` — POST/DELETE: register or remove push notification devices. `Users/identifier` — POST/DELETE: add or remove email/mobile identifiers. `Users/oauth` — OAuth 2.0 token exchange endpoint. `Users/session` — Session management. `Users/analytics` — User analytics data.

### Transactional Messages

The plugin sends verification and notification messages via email and SMS. Templates are defined in `Users.transactional`:

`activation` — Welcome email with verification link, sent on registration. `identifier` — Verification email/SMS when adding a new email or mobile. `resend` — Password reset / code resend. `authenticated` — Notification that someone logged in (optional).

Email views are PHP templates in `views/Users/email/`, and mobile views in `views/Users/mobile/`. The `Users.email.head` config injects custom styles into email HTML.

### Notification Channels

Beyond standard email and SMS, the plugin supports platform-specific notification channels:

**Amb** — Amazon Messaging Bridge for push via Amazon SNS. `Users/Amb/` contains the client adapter.

**Sendblue** — iMessage delivery for Apple ecosystem users. `Users/Sendblue/` contains the client adapter.

Both channels implement the same `pushNotification()` interface and are used by the Streams plugin for delivering subscription notifications.

## Client-Side Architecture

### Users.js

The core client-side module handles:

**Login UI** — The login dialog supporting native (email/mobile + passphrase), platform (Facebook, X/Twitter, Web3), and Telegram authentication. The `Users.login.using` config controls which methods are available.

**Session management** — Client-side session state, nonce handling, and authenticated request signing.

**Socket connections** — WebSocket setup with capability-based authentication. The `Users.Socket` namespace manages the connection lifecycle and exposes `emitToUser()` for server-to-client events.

**User caching** — Client-side user object caching and avatar display.

### UsersDevice.js

Handles push notification registration on the client side. Detects the platform, requests notification permission, obtains the push token, and POSTs it to `Users/device` to register.

### Tools

`Users/avatar` — Renders user avatars with configurable sizes and click actions. `Users/identifier` — Email/mobile input and verification UI. `Users/friendSelector` — Contact picker for selecting users from the social graph. `Users/getintouch` — "Contact this user" widget with multiple channel options. `Users/importContacts` — Bulk contact import from Google, Yahoo, etc.

## Configuration

Main configuration in `plugin.json` under `Users`:

```json
{
    "Users": {
        "login": {
            "identifierType": "email,mobile",
            "using": "native,web3,facebook,telegram",
            "iconType": "wavatar",
            "gravatar": true,
            "noRegister": false
        },
        "apps": {
            "platforms": ["facebook", "twitter", ...],
            "web3": { "*": { "appIdForAuth": "all" } }
        },
        "register": {
            "terms": { "uri": "{{baseUrl}}/terms" },
            "icon": { "search": [], "leaveDefault": false }
        },
        "passphrase": {
            "algorithms": {
                "password_hash": { "algorithm": "default" },
                "hash_pbkdf2": { "iterations": 64000 }
            }
        },
        "labels": {
            "Users/friends": { "title": "Friends", "icon": "..." },
            "Users/family": { "title": "Family", "icon": "..." }
        },
        "roles": {
            "Users/owners": {
                "canGrant": ["Users/admins", "Users/members", ...],
                "canRevoke": ["Users/admins", "Users/members", ...],
                "canSee": ["Users/owners", "Users/admins", ...]
            }
        },
        "web3": {
            "chains": { "0x1": { ... }, "0x89": { ... } },
            "wallets": { "metamask": { ... } }
        },
        "exportFields": ["id", "username", "signedUpWith", "icon", ...],
        "intents": {
            "actions": {
                "Users/authenticate": { "duration": 300 },
                "Users/bridge": { "duration": 300 }
            }
        }
    }
}
```

`Users.login` — Controls the login dialog behavior, accepted identifier types, and which authentication methods are shown. `Users.apps.platforms` — Whitelist of enabled external authentication platforms. `Users.register` — Terms of service, icon search providers, and activation behavior. `Users.passphrase` — Hashing algorithms and parameters. `Users.labels` and `Users.roles` — Default label definitions and role hierarchy. `Users.web3` — Blockchain configuration: chain RPC endpoints, contract addresses, wallet deep links. `Users.exportFields` — Which user fields are safe to send to clients. `Users.transactional` — Email/SMS template configuration for verification and notification messages. `Users.intents` — Per-action intent durations and instructions.

## Integration Points

### With Streams Plugin

Streams depends on Users for identity and access control. When a user registers, Streams creates their personal streams (firstName, lastName, icon, etc.). When user fields change (username, icon), Streams syncs the corresponding user streams. The avatar system in Streams uses Users contacts and labels to determine what profile fields each user can see.

### With Metrics Plugin

Users hooks into Metrics to prefix visit IDs with user IDs (for user-level analytics) and to track Metrics visit sessions alongside PHP sessions. The `Users/after/Q_metrics` handler bridges the two systems.

### With Apps

Apps configure their authentication platforms in `Users.apps`, define custom roles in `Users.roles`, and set up community structures using community user IDs. The contact/label system provides the social graph that apps use for access control, notifications, and discovery.

## Routes

| Route | Handler | Purpose |
|---|---|---|
| `login/facebook` | `Users/facebook` | Facebook OAuth callback. |
| `Users/oauth` | `Users/oauth` | OAuth 2.0 token exchange. |
| `Users/session` | `Users/session` | Session management. |
| `Users/intent` | `Users/intent` | Cross-session intent system. |
| `Users/authorize` | `Users/authorize` | OAuth 2.0 authorization. |
| `Users/unsubscribe` | `Users/unsubscribe` | Email unsubscribe. |
| `appleLogin` | `Users/appleLogin` | Sign in with Apple callback. |
| `m/:mobileNumber` | `Users/activate` | Mobile activation link. |
| `e/:emailAddress` | `Users/activate` | Email activation link. |
| `Users/contractMetadata/:communityId.json` | `Users/contractMetadata` | On-chain community metadata. |