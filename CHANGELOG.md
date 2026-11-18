# Changelog

## 2.3.0 – 2026-11-18

First public release since 2.0.4; it also carries 2.1.0 and 2.2.0 below.

- **Check after a deploy:** a cron job every 5 minutes fingerprints the static content version and the installed
  module versions (`setup_module`). When they change, the module asks MageSentinel for a check with reason `deploy`, and
  the run shows "Deploy on the store" in the dashboard.

## 2.2.0 – released in 2.3.0

- **Test orders through a hidden payment method** (off by default), for stores that take only cards or PayPal. Only
  MageSentinel's checks, carrying this store's token, see "MageSentinel test order"; it takes no payment, its orders send
  no email, never show in Sales > Orders and are cancelled (stock returns, never deleted) by the check or by a 15-minute
  sweep after 30 minutes. Nine independent locks guard the cancel; every refusal is logged.
- New REST route `POST /V1/magesentinel/orders/:incrementId/test-cancel`; diagnostics report `capabilities`.

## 2.1.0 – released in 2.3.0

- **Injected scripts:** Store health looks through active CMS blocks and pages and the design head/footer fields for
  the obfuscated loaders card skimmers use (`eval(atob(…))`, `String.fromCharCode`, `document.write(unescape(…))`,
  WebSocket exfiltration, long hex-escaped strings). Matches are named by place, never printed.
- **PHP files in `pub/media`:** a PHP file among uploads is almost always a web shell. Up to 20,000 files per reading.
- **Order emails sent:** orders from the last 24 hours (older than 15 minutes) that asked for a confirmation email and
  never got one.
- **Redis** and **OpenSearch/Elasticsearch** reachable: every Redis server in `env.php` (cache, page cache, sessions)
  and the configured search engine answer on their port.
- **Admin address:** a watch when the admin still sits at `/admin` (or `/backend`), the first address bots try.
- **Health checks redesigned:** three cards (Taking orders, Security, Platform), each with a score and a bar of its
  checks. Problems sit on top, opened up and coloured, with a Fix button; healthy checks are compact one-line rows.
- **Where the time went** is live: the running step grows in the bar as it runs (with a moving shine, no number,
  since progress can arrive late), and segments resize smoothly instead of the bar being rebuilt on every update.
- The **Settings →** link in the Alerts card stays on one line with its arrow.
- **Health over 7 days** is an hourly heatmap: one column per day, one square per hour (worst reading of that hour),
  with the share of readings free of problems under each day and in the header. Hovering a day names the checks that
  made it red. Days recorded before 2.1.0 have no hourly data and show only their problem count.
- **Checkout speed** draws the slow threshold (1.3× the median) as a dotted line, so it is clear why a bar a little
  above the median stays blue.
- The saved Light/Dark choice is applied before the page paints, so there is no dark flash on a light admin.
- Coding standard: `phpcs --standard=Magento2` reports nothing (no errors, no warnings). Files and the search-engine
  probe go through Magento's filesystem driver and HTTP client; the TCP/TLS probes and `parse_url` keep PHP's own
  functions, each marked with the reason. Templates escape through `$escaper`.

## 2.0.4 – 2026-10-25

- **Free plan:** a connected store whose MageSentinel workspace is on the Free plan shows "Free plan: connector
  inactive" instead of "Set up, not checking in". Free keeps a daily checkout check without test orders or store
  diagnostics, so the module can stay installed. The plan is read when an admin opens Store health, at most hourly.

## 2.0.3 – 2026-10-16

- **Test purchase:** the step that is running shows no clock any more. Progress can arrive late, and the counter
  could read 99 s for a step that then finished in 3.7 s. Each step shows its real time once it is done.

## 2.0.2 – 2026-10-15

- **Dark colours** on Store health, like the WordPress plugin. A switch in the header picks Match my system (the
  default), Light or Dark; the choice is kept in this browser.
- **Orders, last 7 days** puts each day next to the same day a week earlier (teal against grey).
- **Checkout speed** bars are blue; amber now means a run clearly slower than your store's usual time, red a problem.

## 2.0.1 – 2026-10-15

- Passes the Magento 2 coding standard with no errors (needed for the Adobe Commerce Marketplace): every template value
  is escaped through `$escaper`, and file and network reads no longer use the `@` operator.

## 2.0.0 – 2026-10-15

- **MageSentinel menu** sits right under Dashboard, with its coloured logo.
- **Store health page** under MageSentinel › Store health: payment and shipping methods, orders coming in, stuck payments,
  cron, indexers, caches, fatal error reports, pending `setup:upgrade`, HTTPS certificate, production mode, disk space.
  Each row links to the screen that fixes it. Free, no account.
- **Health emails every 15 minutes** through Magento's cron: one email when a check turns red, one when it is fixed.
  Paused while MageSentinel is connected and checking in.
- **Weekly summary email** (opt-in): last week's orders, revenue, average order and cancelled or unpaid orders.
- **Test cart & checkout** button: runs the store's MageSentinel monitor now, or the free monthly test (no account, no
  order placed) on a store that is not connected. Steps and times shown live.
- `GET /rest/V1/magesentinel/proof` for the free test's ownership check.
- Greek translation (`el_GR`).
- The diagnostics endpoint records when MageSentinel last read the store.

## 1.1.0

- Optional CAPTCHA exemption for MageSentinel's own checks (reCAPTCHA and Magento image CAPTCHA).

## 1.0.0

- Read-only diagnostics endpoint and optional cleanup of MageSentinel's own test orders.
