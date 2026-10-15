# Changelog

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
