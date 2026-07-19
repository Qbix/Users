# Users Plugin — LLM Coding Primer

Supplement to the Q Framework primer. Covers identity, auth, contacts, roles,
devices, intents, referrals, and Web3. Read before writing Users-related code.

---

## 1. User Identity

```php
$user = Users::loggedInUser(true);       // throws if not logged in
$user = Users::loggedInUser(false);      // null if not logged in
$userId = Users::loggedInUserId();       // shortcut — returns string or null
$user = Users::fetch($userId, true);     // throws if missing

// Community vs person
Users::isCommunityId($userId);           // true if starts with uppercase
$communityId = Users::communityId();     // main community publisher

// Display name
$name = Users::displayName($userId, array('short' => true));

// Export safe fields to client
$exported = $user->exportArray();
// Returns: id, username, signedUpWith, icon, url, xids, preferredLanguage, sessionCount
```

```javascript
// JS
var user = Q.Users.loggedInUser;          // object or null
var userId = Q.Users.loggedInUserId();    // string or ""
Q.Users.isCommunityId(userId);

// Events
Q.Users.onLogin.set(function (user) { ... }, 'MyPlugin');
Q.Users.onLogout.set(function () { ... }, 'MyPlugin');
```

---

## 2. Registration

```php
$user = Users::register(
    $username,           // can be '' for auto-generated
    $identifier,         // email, mobile number, or array
    $icon,               // array of icon URLs, or callable
    $options             // see below
);

// Identifier can be:
// 1. Email string: "alice@example.com"
// 2. Phone string: "+15551234567"
// 3. Platform array: array('app' => array('platform' => 'facebook'))
// 4. Device array: array('device' => array('deviceId' => ..., 'platform' => 'ios', ...))

// Options:
// 'skipIdentifier' => true   — register without email/mobile
// 'leaveDefaultIcon' => true — don't search for gravatar/social icon

// Events fired: Users/register {before}, {after}
// After: Users_User row inserted, session established, personal streams created
```

---

## 3. Authentication

```php
// Platform auth (Facebook, Twitter, Web3, etc.)
$user = Users::authenticate(
    $platform,           // 'facebook', 'web3', etc. Must be in Users/apps/platforms config
    $appId,              // null = Q::app()
    $authenticated,      // OUT: true, 'connected', 'adopted', or false
    $import              // field names to import from platform profile
);

// Native login (identifier + passphrase)
$user = Users::login($identifier, $passphrase, $isHashed);
// $isHashed = true if client already hashed with Users.hashing()

// Set logged-in user manually (after custom auth)
Users::setLoggedInUser($user);

// Logout
Users::logout();
```

```javascript
// JS login dialog
Q.Users.login({
    identifierType: 'email,mobile',
    using: 'native,web3,facebook',
    onSuccess: function (user) { /* user.id, user.displayName */ }
});
Q.Users.logout({ onSuccess: function () { ... } });

// Passphrase hashing (client-side, before sending to server)
var hashed = Users.hashing(passphrase, identifier);
```

---

## 4. External Platform Identities

```php
// Fetch the ExternalFrom record for a platform user
$ef = Users_ExternalFrom::authenticate('facebook', $appId);
// Returns Users_ExternalFrom_Facebook (or null if not authenticated)

// Look up Qbix userId from platform identity
$userId = Users_ExternalFrom::fetchUserId('facebook', $appId, $xid);

// Platform-specific extras
$ef->getExtra('accessToken');
$ef->setExtra('refreshToken', $token);
$ef->save();

// User's xids JSON field
$xid = $user->getXid('facebook_' . $appId);  // returns xid string or null
$user->setXid('facebook_' . $appId, $xid);
$user->clearXid('facebook_' . $appId);
$allXids = $user->getAllXids();               // returns decoded JSON object

// Supported adapters:
// Users_ExternalFrom_Facebook, _Web3, _Twitch, _Discourse, _Ios, _Android, _Amb, _Wallet
```

---

## 5. Contacts & Labels

```php
// Add contact under a label
$contact = new Users_Contact();
$contact->userId = $userId;
$contact->label = 'Users/friends';
$contact->contactUserId = $friendId;
$contact->save(true);  // upsert

// Remove contact from label
Users_Contact::delete()->where(array(
    'userId' => $userId,
    'label' => 'Users/friends',
    'contactUserId' => $friendId
))->execute();

// Fetch contacts under a label
$contacts = Users_Contact::select('*')->where(array(
    'userId' => $userId,
    'label' => 'Users/friends'
))->fetchDbRows();

// Check if user can manage contacts (respects role hierarchy)
$authorized = Users::canManageContacts(
    $asUserId,          // who is trying
    $userId,            // whose contacts
    $label,             // which label
    false               // throwIfNotAuthorized
);

// Create a label
$label = new Users_Label();
$label->userId = $communityId;
$label->label = 'MyPlugin/vip';
$label->title = 'VIP Members';
$label->icon = 'labels/MyPlugin/vip';
$label->save(true);

// Check if user can manage labels
Users::canManageLabels($asUserId, $userId, 'MyPlugin/', false);
// Returns true if $asUserId has a role with canManageLabels including 'MyPlugin/'
```

---

## 6. Roles

```php
// Get roles for a user in a community
$roles = Users::roles($communityId, $userId);
// Returns array of label strings: ['Users/members', 'Users/speakers']

// Check role
if (in_array('Users/admins', Users::roles($communityId, $userId))) { ... }

// Get all userIds holding specific roles
$userIds = Users::byRoles(
    array('Users/admins', 'Users/owners'),  // filter by roles
    array('communityId' => $communityId)
);
```

```javascript
// JS — roles loaded on page for logged-in user
if (Q.Users.roles && Q.Users.roles['Users/admins']) { /* is admin */ }
```

Role hierarchy config (`Users.roles`):
```json
{
    "Users/owners": {
        "canGrant": ["Users/admins", "Users/members", "Users/guests"],
        "canRevoke": ["Users/admins", "Users/members", "Users/guests"],
        "canSee": ["Users/owners", "Users/admins", "Users/members"],
        "canManageLabels": ["Users/"]
    }
}
```

---

## 7. Devices & Push Notifications

```php
// Register a device (usually handled by client-side UsersDevice.js)
$device = new Users_Device();
$device->userId = $userId;
$device->deviceId = $pushToken;
$device->platform = 'ios';           // ios, android, chrome, firefox, safari, web
$device->appId = $appId;
$device->sessionId = Q_Session::id();
$device->formFactor = 'mobile';
$device->save(true);

// Push notification from PHP (goes through Node.js)
Q_Utils::sendToNode(array(
    'Q/method' => 'Users/pushNotifications',
    'userId' => $userId,
    'notification' => array(
        'alert' => array('title' => 'Hello', 'body' => 'World'),
        'badge' => 1
    )
));

// Web Push fields: auth, p256dh (VAPID keys)
```

---

## 8. Intents (Cross-Session Actions)

```php
// Create an intent (e.g. for QR-code auth)
$intent = Users_Intent::newIntent(
    'Users/authenticate',              // action
    array('platform' => 'web3'),       // instructions
    array('duration' => 300)           // options — 5 min window
);
$token = $intent->token;              // give this to the other device

// Resolve an intent (from the other device/session)
$intent = Users_Intent::fetch($token, true);  // throws if missing
$instructions = $intent->getAllInstructions();

// Complete an intent
$intent->complete(array('userId' => $userId));
// Sets completedTime, notifies originating session via socket

// Check validity
$intent->isValid();                   // false if expired or already completed
```

---

## 9. Referrals

```php
// Record a referral (usually called by Streams plugin on invite accept)
Users_Referred::handleReferral(
    $userId,              // the user who was referred
    $communityId,         // to which community
    'Streams/invite/accept',  // action that triggered it
    'Streams/chat',       // type context
    array('invitingUserId' => $referrerId)
);
// Walks the referral chain, awards points based on config

// Look up who referred a user
$referrer = Users_Referred::referrer($userId, $communityId);
// Returns Users_Referred row or null

// Points config (plugin.json):
// "Users": { "referred": { "Streams/invite/accept": { "": { "points": 1 } } } }
```

---

## 10. Voting & Totals

```php
// Cast a vote (auto-updates Users_Total)
Users_Vote::vote(
    'MyPlugin/likes',       // forType
    array($streamName),     // forId (array)
    array(1),               // values (array)
    array(1),               // weights (array)
    $userId                 // voter
);

// Read aggregated total
$total = new Users_Total();
$total->forType = 'MyPlugin/likes';
$total->forId = $streamName;
if ($total->retrieve()) {
    $count = (int)$total->voteCount;
    $avg   = (float)$total->value;       // weighted average
    $sum   = (float)$total->weightTotal; // sum of weights
}
// NEVER update Users_Total directly — it's maintained by Users_Vote hooks

// Check if user already voted
$vote = new Users_Vote();
$vote->userId = $userId;
$vote->forType = 'MyPlugin/likes';
$vote->forId = $streamName;
$alreadyVoted = $vote->retrieve();
```

---

## 11. Email & Mobile

```php
// Add email (sends activation code)
$user->addEmail($emailAddress);
// Fires Users/addIdentifier {after}

// Set verified email directly (admin use)
$user->setEmailAddress($emailAddress, true);

// Add mobile
$user->addMobile($mobileNumber);

// Set verified mobile directly
$user->setMobileNumber($mobileNumber, true);

// Remove identifiers
$user->removeEmail($emailAddress);
$user->removeMobile($mobileNumber);

// Look up user by identifier
$user = Users::userFromContactInfo('email', $emailAddress);
$user = Users::userFromContactInfo('mobile', $mobileNumber);

// Identify table lookup (type-prefixed)
$ui = Users::identify('email', $emailAddress, 'verified');
if ($ui) { $userId = $ui->userId; }

// Future users (placeholder for pre-invited people)
$user = Users::futureUser('email', 'alice@example.com', $status, $inserted);
// Creates user + identify row in 'future' state if not exists
// When real user registers with this email, they "adopt" the future user
```

---

## 12. Passphrase Handling

```php
// Hash a passphrase
$hash = $user->hashPassphrase($passphrase, 'password_hash');

// Verify
$valid = $user->verifyPassphrase($user->passphraseHash, $passphrase, $isHashed);

// Prepare passphrase (normalize from client)
$passphrase = $user->preparePassphrase($raw, $isHashed);

// Config:
// Users.passphrase.algorithms.password_hash.algorithm = "default" (bcrypt)
// Users.passphrase.algorithms.hash_pbkdf2.iterations = 64000
```

---

## 13. Capabilities

```php
// Generate capability for current request
$cap = Users::capability();
// Returns signed token encoding socket permissions

// Config: Q.capability.permissions maps route letters to permission names:
// "u" => "Users/socket", "s" => "Streams/observe", "a" => "Users/authenticate"
```

---

## 14. Quotas (Rate Limiting)

```php
// Check and enforce quota
$quota = Users_Quota::check($userId, 'Streams/invite', true);
// true = throw Users_Exception_Quota if exceeded

// Config in plugin.json:
// "Users": { "quotas": { "Streams/invite": { "86400": { "": 10, "Users/admins": 1000 } } } }
// Format: { "seconds": { "label_or_empty": limit } }
```

---

## 15. Deliver Notifications

```php
// Send notification to user via best available channel
Users::deliver(
    $userId,
    'Streams',                    // module
    'chat/message',               // event name
    array(                        // template fields
        'displayName' => $name,
        'content' => $messageContent
    ),
    array('Streams/content'),     // text array for subject lookup
    false                         // usePending
);
```

---

## 16. Web3

```php
// Cached blockchain query
$web3 = new Users_Web3();
$web3->chainId = '0x89';
$web3->contract = $contractAddress;
$web3->methodName = 'balanceOf';
$web3->params = Q::json_encode(array($address));
if ($web3->retrieve()) {
    $result = $web3->result;
}

// Transaction tracking
$tx = new Users_Web3Transaction();
$tx->chainId = '0x89';
$tx->transactionId = $txHash;
$tx->status = 'pending';     // 'signed', 'pending', 'mined', 'rejected'
$tx->contract = $contractAddress;
$tx->methodName = 'transfer';
$tx->fromAddress = $sender;
$tx->save(true);

// Chain config access
$chain = Q_Config::get('Users', 'web3', 'chains', '0x89', array());
$rpcUrl = $chain['publicRPC'];
$explorer = $chain['blockExplorerUrl'];
```

---

## 17. Key Utility Methods

```php
// Normalize identifier type
$type = Users::identifierType($input, $normalized);
// Returns 'email' or 'mobile', $normalized gets cleaned value

// Request identifier from current request
$identifier = Users::requestedIdentifier($type);

// Icon URL for a user
$url = Users::iconUrl($user->icon, '80.png');
$url = $user->iconUrl('80.png');

// Import icon from URL
Users::importIcon($user, array('https://example.com/photo.jpg'), $directory);

// Split name
$parts = Streams::splitFullName('John Doe');
// Returns array('first' => 'John', 'last' => 'Doe', ...)
```

---

## 18. Common Mistakes

| Wrong | Right |
|-------|-------|
| `Users::loggedInUser()` without checking null | `Users::loggedInUser(true)` throws; `false` returns null — pick one |
| `$user->save()` to update email/mobile | Use `$user->setEmailAddress()` / `setMobileNumber()` — handles activation flow |
| Writing to `users_total` directly | Use `Users_Vote::vote()` — totals auto-update via hooks |
| `$user->passphraseHash = hash(...)` | Use `$user->hashPassphrase($pass)` — handles salt, iterations, algorithm |
| Checking roles with `Users_Contact` queries | Use `Users::roles($communityId, $userId)` — handles label hierarchy |
| `Users::identify('email', $raw)` with un-normalized email | Normalize first: `Q_Valid::email($raw, $normalized)` |
| Granting roles without checking `canGrant` | Use `Users::canManageContacts()` — enforces role hierarchy |
| `$user->xids = json_encode(...)` directly | Use `$user->setXid($platformApp, $xid)` / `clearXid()` |
| Creating future user without `Users::identify()` check | Use `Users::futureUser()` — handles dedup and adopt logic |

---

## 19. Key Schema

### users_user
```sql
id                   varbinary(31)   PK
username             varchar(63)     UNIQUE
emailAddress         varbinary(255)  NULL
mobileNumber         varbinary(255)  NULL
emailAddressPending  varbinary(255)  DEFAULT ''
mobileNumberPending  varbinary(255)  DEFAULT ''
signedUpWith         varchar(31)     DEFAULT 'none'  -- 'email','mobile','facebook','web3',...
passphraseHash       varchar(255)    NULL
icon                 varbinary(255)
xids                 varchar(1023)   DEFAULT '{}'  -- JSON {platform_appId: [xid,...]}
sessionId            varbinary(255)  NULL
sessionCount         int             DEFAULT 0
preferredLanguage    varchar(3)      DEFAULT 'en'
salt                 varbinary(63)   NULL
insertedTime         timestamp
updatedTime          timestamp       NULL
```

### users_identify
```sql
identifier    varbinary(255)  PK   -- type-prefixed: "email:alice@example.com"
state         enum('verified','future','unlinked')
userId        varbinary(31)
insertedTime  timestamp
updatedTime   timestamp       NULL
```

### users_contact
```sql
userId         varbinary(31)   PK
label          varchar(63)     PK   -- 'Users/friends', 'MyPlugin/vip', etc.
contactUserId  varbinary(31)   PK
nickname       varchar(255)    DEFAULT ''
insertedTime   timestamp
```

### users_label
```sql
userId        varbinary(31)   PK
label         varchar(63)     PK
icon          varbinary(255)  DEFAULT 'default'
title         varchar(255)
insertedTime  timestamp
updatedTime   timestamp       NULL
```

### users_external_from
```sql
platform      varchar(31)     PK
appId         varbinary(200)  PK
xid           varbinary(31)   PK
userId        varbinary(31)
accessToken   varchar(1023)   NULL
extra         varchar(1023)   DEFAULT '{}'  -- JSON
insertedTime  timestamp
updatedTime   timestamp       NULL
```

### users_device
```sql
userId     varbinary(31)   PK
deviceId   varbinary(700)  PK  -- push token or Web Push endpoint URL
platform   varchar(31)         -- ios, android, chrome, firefox, safari, web
appId      varchar(200)    NULL
sessionId  varbinary(255)
formFactor enum('mobile','tablet','desktop')
auth       varchar(31)     DEFAULT ''  -- Web Push auth
p256dh     varchar(1023)   NULL        -- Web Push ECDH key
```

### users_intent
```sql
token          varbinary(255)  PK
action         varbinary(31)        -- 'Users/authenticate', 'Users/bridge'
instructions   varbinary(2047)      -- JSON (hidden from token holder)
url            varbinary(2083) NULL
sessionId      varbinary(255) NULL
userId         varbinary(31)  NULL
startTime      timestamp      NULL
endTime        timestamp      NULL
completedTime  timestamp      NULL
insertedTime   timestamp
updatedTime    timestamp      NULL
```

### users_referred
```sql
userId            varbinary(31)   PK
toCommunityId     varbinary(31)   PK
referredByUserId  varbinary(31)   PK  -- NOT byUserId or invitingUserId
insertedTime      timestamp
updatedTime       timestamp       NULL
points            decimal(4,2)    NULL
qualifiedTime     timestamp       NULL
extra             varchar(1023)   DEFAULT '{}'
```

### users_session
```sql
id            varbinary(255)  PK
content       varchar(4095)        -- JSON (JS-readable)
php           varchar(4095)        -- PHP serialized
userId        varbinary(31)  NULL
deviceId      varbinary(700)
platform      varchar(31)    NULL
appId         varchar(200)   NULL
formFactor    enum('mobile','tablet','desktop')
ipv4          varbinary(16)  NULL
ipv6          varbinary(64)  NULL
duration      int            DEFAULT 0
insertedTime  timestamp
updatedTime   timestamp
```