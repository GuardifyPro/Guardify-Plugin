=== Guardify Pro ===
Contributors: tansiqlabs
Tags: fraud detection, woocommerce, delivery, otp, courier
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.1
License: Proprietary
License URI: https://guardify.pro/license

ফ্রড ডিটেকশন, কুরিয়ার ইন্টেলিজেন্স, OTP ভেরিফিকেশন ও স্মার্ট অর্ডার ফিল্টারিং — বাংলাদেশের ই-কমার্সের জন্য।

== Description ==

**Guardify Pro** is a WooCommerce plugin built specifically for Bangladesh e-commerce merchants. It connects to the Guardify Pro engine (api.guardify.pro) to provide:

* **Fraud Detection** — DP ratio-based auto-blocking, phone & IP blocklists, advanced filter rules
* **Courier Intelligence** — Steadfast & Pathao delivery performance data aggregation
* **OTP Verification** — Verify phone numbers at checkout to reduce fake orders
* **SMS Notifications** — Automated Bengali SMS on order status changes
* **Incomplete Order Recovery** — Capture dropped checkouts, send SMS reminders, convert to WC orders
* **Smart Filter** — Auto-block or flag suspicious orders based on delivery history
* **Phone History** — Track delivery performance per phone number
* **VPN Block** — Block orders placed via VPN/proxy

Requires an active account at [guardify.pro](https://guardify.pro).

== Installation ==

1. Upload the `guardify-pro` folder to `/wp-content/plugins/`
   — OR — upload the ZIP via **Plugins → Add New → Upload Plugin**
2. Activate the plugin through the **Plugins** screen
3. Go to **Guardify Pro → Settings** in the WordPress admin menu
4. Enter your API Key and Secret Key from [guardify.pro](https://guardify.pro)
5. Configure features as needed

== Frequently Asked Questions ==

= Where do I get my API Key? =

Register at [guardify.pro](https://guardify.pro), then go to the API Keys section in your dashboard.

= Does this work without WooCommerce? =

No. WooCommerce 7.0+ is required.

= How do automatic updates work? =

Updates are delivered via GitHub Releases. When a new version is published, your WordPress dashboard will show the update notification automatically. You can also manually check for updates under **Guardify Pro → Settings → আপডেট**.

== Changelog ==

= 0.3.0 =
* API কী সিম্পলিফাই — ছোট ও সহজ কানেকশন কী
* কানেকশনের পর অটো প্ল্যান, SMS ব্যালেন্স ও মেয়াদ দেখায়
* নন-ব্লকিং ফোন সিংক (সাইট স্লো হবে না)
* সার্চ পেজে ফ্রড রিপোর্ট দেখায়
* সাবস্ক্রিপশন ম্যানেজমেন্ট ওভারহল
* ডোমেইন অটো-রিপোর্ট

= 0.2.1 =
* Fix: Connection Key ফর্ম্যাট নির্দেশনা উন্নত এরর মেসেজ
* Fix: ইনপুট টাইপ password থেকে text এ পরিবর্তন (পেস্ট ভেরিফাই করতে)
* সেটিংস পেজে স্পষ্ট কানেকশন নির্দেশনা

= 0.2.0 =
* Send to Courier — Steadfast ও Pathao-তে সরাসরি অর্ডার পাঠান
* Bulk action — একাধিক অর্ডার একসাথে কুরিয়ারে পাঠান
* Orders list কুরিয়ার কলাম — প্রোভাইডার, কনসাইনমেন্ট ও স্ট্যাটাস
* ডিফল্ট কুরিয়ার সেটিংস
* Consignment status refresh from order page
* Performance ও caching উন্নতি
* Plugin error fixes ও UI improvements

= 0.1.0-beta =
* Initial beta release
* Fraud detection with DP ratio filtering
* OTP verification at checkout
* SMS order notifications
* Incomplete order recovery
* Steadfast & Pathao courier integration
* VPN blocking
* Smart filter with configurable thresholds
* Phone history tracking
* Automatic updates via GitHub Releases

== Upgrade Notice ==

= 0.2.0 =
কুরিয়ার ইন্টিগ্রেশন আপডেট — Steadfast/Pathao-তে সরাসরি অর্ডার পাঠান।

= 0.1.0-beta =
Initial beta release. Please test on a staging site before deploying to production.
