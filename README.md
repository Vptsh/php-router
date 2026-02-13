# 🛡 Secure Signed PHP Router

### Zero-Trust File Access • Anti-Replay • Anti-Bot • Stealth Ban • Adaptive Security

---

## 📌 Project Overview

This project is a **high-security PHP request router** designed to prevent:

- Direct file access
- URL tampering
- Token replay attacks
- Bot scraping
- Mass link sharing abuse
- Path traversal attacks
- Configuration leaks
- Automated brute traffic

Instead of allowing users to open files directly, every request must pass **cryptographic signature verification + behavioural security checks** before content is served.

This router follows a **Zero Trust Request Model** — every request is treated as potentially hostile until verified.

---

## 🧠 Why This Router Exists

Traditional routing:

```
user → /dashboard.php → file served
```

Secure signed routing:

```
user → signed URL → router → security validation → file served
```

Attack surface becomes extremely small.

---

## 🏗 Core Security Architecture

### Multi-Layer Security Stack

```
Request
 ↓
Forbidden Path Filter
 ↓
Signature Validation
 ↓
Token Replay Protection
 ↓
Token Leak Detection
 ↓
Rate Limiting
 ↓
Bot Behaviour Scoring
 ↓
Stealth Ban Check
 ↓
Secure File Serve
```

Each layer independently blocks attacks.

---

## 📂 Project Folder Structure

```
project-root/

v.php                     → Main router entry point
error.html                → Generic error page

.set/
  router_config.php       → Main configuration

.runtime/
  m.json                  → Route mapping database
  u.json                  → Token replay database
  b.json                  → Bot score database
  r.json                  → Rate limit database
  k.json                  → Token leak tracker
  x.json                  → Stealth banned IP list

  a.log                   → Access logs
  s.log                   → Security logs
```

---

## ⚙ Configuration (router\_config.php)

This file controls behaviour of entire system.

### Example

```php
define('ROUTER_NAME','v.php');
define('SIGN_SECRET','CHANGE_THIS_TO_LONG_RANDOM_SECRET');
define('SIGNED_TTL',300);
define('LOG_DEDUPE_WINDOW',5);
```

---

### 🔐 SIGN\_SECRET

Used for:

- URL signature generation
- Tamper detection

If changed:

- All existing signed URLs instantly become invalid

Use:

- 64+ random characters
- Never commit real secret to public repo

---

### ⏳ SIGNED\_TTL

Controls signed URL lifetime.

| Value    | Behaviour              |
| -------- | ---------------------- |
| 60 sec   | Ultra secure           |
| 300 sec  | Balanced (recommended) |
| 600+ sec | High performance / CDN |

---

### 🧾 LOG\_DEDUPE\_WINDOW

Prevents repeated identical log spam.

---

## 🔒 Security Constants (Router File)

---

### Rate Limiting

```
RATE_LIMIT_MAX = 40
RATE_LIMIT_WINDOW = 60 seconds
```

Meaning: Maximum 40 suspicious events per minute per IP.

---

### Token Replay Window

```
TOKEN_REPLAY_WINDOW = 7200 seconds
```

Token valid for 2 hours maximum.

---

### Bot Detection Base Score

```
BOT_SCORE_BASE = 60
```

Adaptive threshold adjusts dynamically based on traffic behaviour.

---

### Stealth Ban Time

```
STEALTH_BAN_TIME = 900 seconds
```

IP silently blocked for 15 minutes.

Returns fake 404 to avoid attacker detection.

---

## 🗄 Runtime Database System

Router uses JSON files instead of SQL for speed and portability.

---

### u.json → Token Replay Database

Tracks:

- Token signature
- IP used
- Session used
- Refresh count
- Expiry time

Prevents:

- Link sharing abuse
- Mass replay attacks

---

### b.json → Bot Score Database

Stores per-IP behaviour score.

Score increases when:

- Invalid signature
- Replay attempt
- Token leak detected

---

### r.json → Rate Limit Database

Stores timestamp list of suspicious actions per IP.

---

### k.json → Token Leak Tracker

Tracks how many unique IPs use same token.

If > 5 → suspicious.

---

### x.json → Stealth Ban List

Stores banned IP + expiry time.

---

## 🧹 Automatic Database Cleaning

Old entries automatically deleted:

| File   | Lifetime |
| ------ | -------- |
| u.json | 24 hours |
| k.json | 7 days   |
| b.json | 14 days  |
| r.json | 1 hour   |

Prevents storage growth.

---

## 👤 Visitor Identity System

Each session receives unique visitor ID:

```
VIS-XXXXXX
```

Used for:

- Behaviour tracking
- Log correlation
- Session pattern analysis

---

## 🌐 Network Intelligence System

Router collects:

- Country
- City
- ISP
- VPN detection
- Hosting detection
- Mobile network detection

Cached for 24 hours per session for performance.

---

## 🔑 Signed URL Security Model

Signed URL contains:

```
id   → route ID
exp  → expiry timestamp
router → router filename
sig  → HMAC signature
```

---

### Signature Generation Logic

```
1. Sort parameters
2. Build query string
3. Generate HMAC SHA256 using SIGN_SECRET
```

If any parameter is modified → signature mismatch.

---

## 🔄 Token Replay Protection

Each token:

✔ Bound to IP\
✔ Bound to session\
✔ Allows limited refresh\
✔ Expires automatically

If used from different IP → blocked.

---

## 🧬 Token Leak Detection

If same signed token used by multiple IPs:

Score increases → eventually blocked.

Prevents:

- Telegram link sharing
- Public forum link posting
- Scraper distribution

---

## 🤖 Bot Scoring Engine

Each suspicious behaviour adds points.

Example:

| Action            | Score |
| ----------------- | ----- |
| Invalid signature | +10   |
| Replay attempt    | +15   |
| Token leak        | +20   |

Adaptive threshold increases if bot traffic increases.

---

## 👻 Stealth Ban System

Instead of blocking normally:

Returns:

```
404 Not Found
```

Attacker thinks resource missing, not blocked.

---

## 🧭 Route Mapping System

Stored in:

```
.runtime/m.json
```

Example:

```json
{
 "0": "index.html",
 "a92bd1": "dashboard.php"
}
```

---

### Auto Mapping

If user accesses:

```
/dashboard.php
```

Router automatically:

1. Generates route ID
2. Stores mapping
3. Redirects to signed URL

---

## 📊 Logging System

---

### Access Log (a.log)

Tracks:

- Visitor ID
- Route ID
- Access Type
- Original URL
- Final Signed URL
- Device
- Network type

---

### Security Log (s.log)

Tracks:

- Attack type
- Exact URL
- IP
- Device
- ISP
- Country
- Reason for block

---

## 🧠 Access Behaviour Classification

Router classifies traffic:

| Type              | Meaning                  |
| ----------------- | ------------------------ |
| USER\_ACTION      | Normal navigation        |
| AUTO\_BROWSER     | Reload or resource load  |
| BACKGROUND\_FETCH | AJAX or silent request   |
| REDIRECT\_CHAIN   | Multi redirect flow      |
| SESSION\_EXPIRE   | Session timeout redirect |

Useful for analytics + bot detection.

---

## 🛡 Attack Protection Coverage

---

### Replay Attack

Protected using token usage tracking.

---

### URL Tampering

Blocked using HMAC signature verification.

---

### Brute Force Requests

Rate limiting + bot scoring.

---

### Path Traversal

Blocked using realpath directory enforcement.

---

### Config File Exposure

Blocked using forbidden path filters.

---

### Bot Crawling

Adaptive scoring system blocks suspicious behaviour.

---

## 🚀 Performance Optimizations

- Session caching for IP intelligence
- JSON lazy cleaning
- Log deduplication
- Smart 2-second router dedupe
- No database overhead

---

## ⚙ Behaviour Tuning Profiles

---

### 🔒 High Security Mode

```
SIGNED_TTL = 120
RATE_LIMIT_MAX = 20
BOT_SCORE_BASE = 40
```

---

### ⚖ Balanced Mode (Recommended)

```
SIGNED_TTL = 300
RATE_LIMIT_MAX = 40
BOT_SCORE_BASE = 60
```

---

### ⚡ High Performance Mode

```
SIGNED_TTL = 600
RATE_LIMIT_MAX = 80
BOT_SCORE_BASE = 80
```

---

## 🔧 Installation

---

### Step 1

Clone repository.

---

### Step 2

Create runtime folder:

```
mkdir .runtime
chmod 777 .runtime
```

---

### Step 3

Create config file:

```
.set/router_config.php
```

---

### Step 4

Set strong SIGN\_SECRET.

---

### Step 5

Point web root to router location.

---

## ☁ Production Deployment Recommendations

---

### With Cloudflare

Enable:

- Bot Fight Mode
- Rate Limiting Rules
- WAF protection

---

### With Nginx

Disable direct PHP execution except router.

---

### With Apache

Use:

```
Options -Indexes
```

---

## 🔮 Future Upgrade Ideas

- Redis memory token store
- JWT token replacement
- Machine learning bot detection
- Device fingerprint scoring
- Behaviour anomaly detection
- Real time threat dashboard

---

## 📜 License

MIT License 

---

## 👨‍💻 Author Philosophy

This router is built on:

- Security first design
- Minimal trust surface
- Behaviour driven blocking
- Low infrastructure dependency
- High portability

---

## ⭐ Contributing

Pull requests are welcome. For major changes, open an issue first to discuss what you would like to change.

---


## ❤️ Acknowledgement

Built with focus on real-world attack patterns and practical defence strategies.

