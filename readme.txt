=== Guardify Pro ===
Contributors: tansiqlabs
Tags: fraud detection, woocommerce, delivery, otp, courier
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.4.1
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

= 0.4.1 =
* ফোন সিংক সম্পূর্ণ রিরাইট — ২০০/ব্যাচ, মাল্টি-ব্যাচ, অর্ডার ID ট্র্যাকিং
* ব্লকিং API কল — সাকসেস কনফার্ম ছাড়া এগোয় না
* ২ মিনিট ক্রন + ৫৫ সেকেন্ড রানটাইম লিমিট
* সেটিংসে ফোন সিংক স্ট্যাটাস ও ম্যানুয়াল সিংক বাটন
* HPOS (High-Performance Order Storage) সামঞ্জস্যতা

= 0.4.0 =
* অ্যাডমিন ফাইনান্স ড্যাশবোর্ড — রেভিনিউ KPI, MRR, চার্ট, প্ল্যান/মাধ্যম ব্রেকডাউন
* অ্যাডমিন ফোন সিঙ্ক ড্যাশবোর্ড — সিঙ্ক স্ট্যাটাস, ঝুঁকি বিতরণ, ফোর্স সিঙ্ক
* লাইসেন্স তৈরি UX উন্নতি — ৩-স্টেপ উইজার্ড (ইউজার সার্চ → API কী → প্ল্যান)
* নাইটলি বাল্ক এনরিচমেন্ট (রাত ৩-৫টা BST) — বড় ব্যাচ, stale ডেটা রি-ফেচ
* ফোর্স সিঙ্ক অল — নতুন কুরিয়ার যোগে সব ফোনে অটো আপডেট
* পোর্টাল সাইডবারে ফাইনান্স ও ফোন সিঙ্ক লিংক

= 0.3.8 =
* কুরিয়ার স্ট্যাটাস → WooCommerce অর্ডার স্ট্যাটাস অটো-সিঙ্ক
* নতুন কাস্টম স্ট্যাটাস: "Waiting for Shipment" (in_review/in_transit)
* Steadfast delivered/partial_delivered → Completed
* Steadfast cancelled/returned → Cancelled
* Steadfast hold → On Hold
* টিকেটে প্লাগইন এনভায়রনমেন্ট মেটাডেটা (WP User, IP, Domain, Version)
* অ্যাডমিন টিকেট ডিটেইলে "Plugin Environment" কার্ড
* টিকেট লিস্টে Source কলাম (ডোমেইন + ভার্সন)

= 0.3.7 =
* ডাটাবেইজ অটো ব্যাকআপ ও ইজি রিস্টোর সিস্টেম
* পার-API-কী কুরিয়ার কনফিগ আর্কিটেকচার
* ব্যাকআপ শিডিউল (প্রতি ৬ ঘণ্টা, ১২ ঘণ্টা, দৈনিক, সাপ্তাহিক)
* ম্যানুয়াল ব্যাকআপ ও রিস্টোর UI
* রিস্টোরের আগে স্বয়ংক্রিয় সেফটি ব্যাকআপ
* ফ্রড ডিটেকশন ডুপ্লিকেট হুক ফিক্স
* ফোন সিংক ক্রন শিডিউল অর্ডার ফিক্স
* সার্চ ফোন ভ্যালিডেশন স্ট্রিক্ট ম্যাচিং
* সর্বোচ্চ ব্যাকআপ সাইজ 1000MB

= 0.3.6 =
* ইনকমপ্লিট অর্ডার সম্পূর্ণ রিবিল্ড (OrderGuard প্যাটার্ন অনুসরণ)
* সার্ভার-সাইড ক্যাপচার (checkout_update_order_review হুক)
* beforeunload + sendBeacon দিয়ে পেজ বন্ধের আগে ডেটা ক্যাপচার
* কনফিগারেবল কুলডাউন (কুকি + ডিবি চেক, ফোন ভ্যারিয়েশন ম্যাচিং)
* বাল্ক SMS, বাল্ক কনভার্ট, বাল্ক ডিলিট
* CSV এক্সপোর্ট (বাংলা BOM সাপোর্ট)
* SMS মডাল — এডিটেবল টেমপ্লেট + প্লেসহোল্ডার ({customer_name}, {product_name}, {order_total}, {siteurl})
* কনভার্ট মডাল — স্ট্যাটাস সিলেক্টর (Pending/Processing/On Hold/Completed)
* প্রোডাক্ট ভ্যারিয়েশন সাপোর্ট (variation_id + attributes)
* COD পেমেন্ট + shipping address সেট
* সার্চ ফিল্টার (ফোন/নাম দিয়ে)
* রিকভারি স্ট্যাটিস্টিক্স (পেন্ডিং, রিকভার্ড, রেট)
* কনফিগারেবল রিটেনশন পিরিয়ড (0 = কখনো মুছবে না)
* DB স্কিমা আপগ্রেড: email, state, country, postcode কলাম যোগ

= 0.3.5 =
* UI/UX ওভারহল — ইনকমপ্লিট অর্ডার ও SMS লগস পেজ সম্পূর্ণ রিডিজাইন
* ইনকমপ্লিট অর্ডার: gf-table, SVG আইকন বাটন, কার্ড র‍্যাপার, স্টাইলড পেজিনেশন
* SMS লগস: gf-table, gf-modal প্যাটার্ন, ইনলাইন স্টাইল ব্লক মুছে ফেলা
* সেটিংস: সেভ বাটন শুধু প্রাসঙ্গিক ট্যাবে দেখায় (Features, Smart Filter, Notifications)
* CSS: নতুন ইউটিলিটি ক্লাস (gf-table, gf-modal, gf-icon-btn, gf-pagination, gf-count-pill)

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
