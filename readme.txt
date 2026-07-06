=== PixGrow Image Optimizer – Bulk Compress & WebP ===
Contributors: iamsantoshg
Tags: image-optimization, webp-converter, bulk-compressor, page-speed, client-side
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free browser-based WordPress image compressor & WebP converter. No API key, zero server load, unlimited bulk optimization.

== Description ==

**Quick answer:** PixGrow is a free WordPress plugin that compresses images and converts them to WebP directly in your browser using WebAssembly — no API key, no server load, no subscription, and no limit on how many images you can optimize.

= Compress WordPress Images Free — No API Key. No Server Load. No Subscription. =

PixGrow Image Optimizer compresses your WordPress images and converts them to WebP — completely free, with no API key, no external server, and no subscription required. All compression runs inside your browser, so your images never leave your server. It's a straightforward way to resolve PageSpeed Insights image warnings on any hosting plan, including shared hosting.

PixGrow uses **WebAssembly (Wasm)** to run image compression locally on your computer. No server CPU is consumed, no images are transmitted to third-party infrastructure, and no paid plan is required to compress your full Media Library.

=== What Is PixGrow Image Optimizer? ===

PixGrow Image Optimizer is a free, open-source WordPress plugin that performs bulk image compression and WebP conversion entirely inside your web browser. It uses a WebAssembly compression engine — powered by Mozilla's MozJPEG encoder — to reduce image file sizes and convert JPEG and PNG images to modern WebP format without involving any external server, cloud API, or third-party service.

Built and maintained by a WordPress plugin developer, with source code publicly available and auditable on the WordPress.org plugin repository under GPLv2. Support and bug reports are handled directly through the official WordPress.org support forum, listed at the bottom of this page.

=== Real Compression Results ===

Numbers from internal test runs on a standard WordPress Media Library (mixed JPEG/PNG product and blog images):

* Average JPEG file size reduction: **~55–70%** at default quality settings
* Average PNG-to-WebP conversion savings: **~45–65%**
* Server CPU usage during bulk runs: **0%** — all processing happens client-side
* Typical PHP timeout or memory errors encountered: **none**, since no server-side processing is triggered

Actual savings vary by image content, resolution, and the quality setting you choose. Run the dashboard's built-in optimization log on your own Media Library to see your site-specific results.

=== Why WordPress Site Owners Are Switching to PixGrow ===

* Fix PageSpeed Insights image warnings for free — no tier upgrade, no API credits, no monthly fee
* Compress WordPress images without slowing down your server or hitting PHP memory limits — compression runs in your browser, eliminating PHP timeouts on shared hosting
* No API key, no account, no registration — install, activate, and start compressing immediately
* WebP conversion included at no cost — convert JPEG and PNG to WebP, the next-gen format Google recommends in performance audits
* Images never leave your server — all compression happens locally via WebAssembly
* Non-destructive pipeline with automatic backups — every original image is backed up before replacement and restorable at any time
* Compatible with WooCommerce, Elementor, Divi, Gutenberg, Beaver Builder, and WPBakery — works at the Media Library level with any theme or page builder

=== The Real Problem: Why Server-Side Image Optimization Fails on Shared Hosting ===

Most WordPress image compression plugins process images either on external API servers or directly on your web server. Both approaches carry consistent, predictable failures:

1. **Server overload and PHP timeouts** — bulk compression running server-side frequently exhausts PHP memory limits and execution time on shared hosting plans, sometimes triggering temporary account suspension.
2. **API costs that scale against your growth** — cloud-based plugins cap their free tier at a few hundred images per month; a growing Media Library eventually needs a paid subscription to keep compressing.
3. **Loss of control over your media assets** — sending images to external compression APIs means your media, including client-owned or confidential content, passes through third-party infrastructure outside your control.

PixGrow was built to remove all three of these failure points by keeping compression entirely client-side.

=== PixGrow vs. API-Based Image Optimization Plugins ===

A direct comparison between PixGrow's local WebAssembly architecture and typical cloud-based API optimizers:

**Compression location** — PixGrow: local browser (WebAssembly) · API-based: external API server
**API key required** — PixGrow: no · API-based: yes
**Subscription required** — PixGrow: no · API-based: often, for bulk use
**Images transmitted externally** — PixGrow: never · API-based: yes, to third-party servers
**Server CPU during bulk runs** — PixGrow: zero · API-based: high, or offloaded to the API
**WebP conversion** — PixGrow: included, free · API-based: often paywalled
**Shared hosting compatibility** — PixGrow: yes, no server processing · API-based: timeout/memory risk
**Media privacy** — PixGrow: stays on your host · API-based: handled by a third party
**Backup and restore** — PixGrow: built-in, automatic · API-based: varies by plugin
**Bulk optimization limits** — PixGrow: unlimited, always free · API-based: typically capped on free plans
**License** — PixGrow: open source (GPLv2) · API-based: varies, often proprietary

If zero ongoing API cost, full media privacy, and shared-hosting reliability matter to you, PixGrow's architecture is built around exactly those three constraints.

=== How PixGrow Helps with Core Web Vitals and PageSpeed Audits ===

Unoptimized images are a common contributor to weak Google Lighthouse and Core Web Vitals (LCP) scores. PixGrow addresses two of the most frequent audit flags:

* **Serve images in next-gen formats** — PixGrow converts JPEG and PNG images to WebP, which typically produces smaller files at comparable visual quality.
* **Efficiently encode images** — MozJPEG compression reduces JPEG file sizes without visible quality loss.
* **Reduce total page weight** — smaller image files cut total page download size, which helps load times on mobile connections and contributes to a better Largest Contentful Paint (LCP).

PixGrow optimizes image assets specifically. It doesn't touch server configuration, caching rules, or JavaScript — those remain under your control.

=== Features ===

**Image Compression and WebP Conversion**
* Client-side bulk image compression via WebAssembly — zero server CPU usage
* WebP conversion for JPEG and PNG images
* MozJPEG encoding for efficient, high-quality JPEG output
* Processes all WordPress attachment sizes: thumbnail, medium, large, and custom registered sizes
* Background asynchronous upload optimization pipeline for new uploads

**Backup and Restore**
* Automatic file and database backups before every image replacement
* Non-destructive pipeline — originals preserved until you remove backups
* One-click restore to the original unoptimized image at any time
* Delete Backups toggle — preserve or remove backups and settings on uninstall

**Dashboard and Tooling**
* Optimization dashboard with Media Library statistics and queue management
* Optimization log showing original size, compressed size, and reduction per image
* Reference Path Scanner — locates hardcoded image paths in theme files and post content
* Visual quality comparison slider for original vs. compressed output
* Unsaved-changes notice before navigating away from settings

=== Who Uses PixGrow Image Optimizer ===

**Bloggers and content publishers** — compress new uploads with WebP conversion automatically, with no API account or recurring cost.

**Freelancers and agencies** — manage bulk optimization across multiple client sites, including shared hosting, without per-site API costs.

**WooCommerce store owners** — compress full product image libraries across all registered attachment sizes, with no per-image fees.

**Privacy-conscious site owners** — keep proprietary or confidential media entirely within your own hosting environment.

**Developers** — a lightweight plugin that integrates with any theme or page builder stack without bloat or external dependencies.

**Shared hosting users** — run full Media Library optimization without triggering PHP timeouts or memory errors.

== Installation ==

= Automatic Installation =

1. Log into your WordPress admin panel.
2. Go to Plugins > Add New Plugin.
3. Search for PixGrow Image Optimizer.
4. Click Install Now, then Activate.
5. Open the PixGrow menu in the left sidebar to begin.

= Manual Installation =

1. Download `pixgrow-image-optimizer.zip` from the WordPress.org plugin page.
2. Upload the `pixgrow-image-optimizer` folder to `/wp-content/plugins/` via FTP or your hosting file manager.
3. Activate the plugin from Plugins in your WordPress admin dashboard.
4. Open the PixGrow menu in the left sidebar to begin.

= Quick Start: Your First Optimization =

1. Open the PixGrow dashboard. The browser compiles the WebAssembly codecs on first load — this takes a few seconds.
2. Review your Media Library statistics in the dashboard overview.
3. Configure your preferred quality settings.
4. Click Start Bulk Optimization to begin the queue.
5. Keep the browser tab active until the queue completes.
6. Use the restore tool at any time to revert any image to its original version.

== Screenshots ==

1. Search and activate the PixGrow Image Optimizer plugin.
2. Verify successful plugin activation.
3. Open the PixGrow dashboard overview.
4. Monitor bulk image optimization progress.
5. Compress individual images from the optimization queue.
6. Review optimization history and restore original backups.
7. Compare original and optimized images side-by-side.
8. Scan for outdated image references and static paths.
9. Configure image optimization, automation, and queue settings.
== Frequently Asked Questions ==

= What is PixGrow Image Optimizer? =
A free, browser-based WordPress plugin that compresses images and converts them to WebP using WebAssembly. It needs no API key, no external server, and no subscription — all compression happens locally, so your images never leave your hosting environment.

= Does PixGrow require an API key or account? =
No. There's no API key, registration, or third-party account of any kind, and no usage quota tied to an external service.

= How does PixGrow compress images without a server or API? =
It uses WebAssembly (Wasm) to run compression codecs locally inside your browser. Your browser fetches the original images from your WordPress server, compresses them on your computer, and uploads the optimized files back — no third-party server is involved at any point.

= Is PixGrow good for shared hosting? =
Yes. Because compression runs in your browser rather than on the web server, it avoids the PHP memory limits and execution timeouts that cause server-side bulk optimizers to fail or trigger host suspensions on shared plans.

= Is PixGrow free and open source? =
Yes to both. PixGrow is fully free through the WordPress.org plugin directory, and it's distributed under the GPLv2 license with publicly auditable source code.

= How do I convert images to WebP in WordPress with PixGrow? =
Install the plugin, open the dashboard, and run the bulk optimizer. PixGrow uses your browser to convert existing JPEG and PNG files into WebP without manual configuration.

= How does PixGrow help fix "Serve images in next-gen formats" in PageSpeed Insights? =
It converts JPEG and PNG files to WebP, which is the specific fix Lighthouse and PageSpeed Insights recommend for that audit item.

= Does PixGrow send my images to any external server? =
No. Images travel from your WordPress server to your local browser for compression, then back as optimized files. No image data passes through third-party infrastructure.

= What is MozJPEG and how does PixGrow use it? =
MozJPEG is an open-source JPEG encoder maintained by Mozilla that produces smaller JPEG files than the standard encoder at comparable visual quality. PixGrow uses it inside its WebAssembly compression engine.

= Can I restore my original images after optimization? =
Yes. PixGrow automatically backs up the original file before replacing it, and you can restore any image — or your full Media Library — to its original state at any time.

= Do I need to keep the browser tab open during bulk optimization? =
Yes. Compression happens in the browser, so the dashboard tab needs to stay active during the queue. Closing or navigating away pauses the queue until you return.

= Does PixGrow work with WooCommerce, Elementor, Divi, or other page builders? =
Yes. PixGrow works at the WordPress Media Library level, so it's compatible with WooCommerce product images and any page builder or theme that uses standard Media Library attachments.

= Can PixGrow optimize new images automatically on upload? =
Yes. A background asynchronous upload optimization pipeline (added in version 1.0.2) can process new uploads automatically, reducing the need for repeated manual bulk runs.

= How do I get support for PixGrow? =
Through the official WordPress.org support forum: https://wordpress.org/support/plugin/pixgrow-image-optimizer/ — please include your WordPress version, PHP version, browser version, and hosting type with your request.

== Changelog ==

= 1.0.2 =
* Fixed background automatic upload optimization pipeline.
* Added custom event trigger window dispatch for asynchronous upload starts.
* Updated licensing checks to support both QA constants.

= 1.0.1 =
* Final UAT fixes and cache busting.
* Fixed single image compression and queue handling.

= 1.0.0 =
* Initial release of PixGrow Image Optimizer.
* Client-side WebAssembly WebP and JPEG compression.
* MozJPEG encoder support.
* Automatic local backups and restore functionality.
* Visual quality comparison slider.
* Reference Path Scanner.

== Upgrade Notice ==

= 1.0.2 =
Fixed background automatic upload optimization to ensure new images are processed seamlessly without manual intervention.

== Support ==

For questions, bug reports, and feature requests, use the official WordPress.org support forum:

https://wordpress.org/support/plugin/pixgrow-image-optimizer/

When submitting a support request, please include your WordPress version, PHP version, browser name and version, hosting environment type, and a clear description of the issue.