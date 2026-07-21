=== Codegenie Pulse Connector ===
Contributors: codegenie
Tags: monitoring, error monitoring, uptime, fatal errors, deployment tracking
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress securely to Codegenie Pulse for site verification, application errors, and deployment tracking.

== Description ==

Codegenie Pulse Connector provides a small, security-focused connection between a WordPress site and a Codegenie Pulse account.

The plugin provides:

* automatic Pulse-first connection with explicit consent from a WordPress administrator;
* one-time, short-lived authorization without permanent secrets in the browser URL;
* site verification through `/.well-known/codegenie-pulse.txt`;
* a simple DSN connection with a connection test;
* configurable PHP error capture for production, extended use, and temporary debugging;
* automatic reporting of fatal PHP errors and unhandled exceptions;
* optional PHP warnings, notices, strict, and deprecated messages;
* optional reporting of failed WordPress email delivery without recipients or message content;
* optional reporting of REST API server errors with 5xx status codes;
* deployment tracking for WordPress, plugin, and theme changes;
* local removal of known secrets, email addresses, and URL query strings;
* encrypted DSN storage using WordPress salts and AES-256-GCM;
* token-safe diagnostics through WordPress Site Health.

The plugin requires no runtime Composer package, queue, cron job, or external JavaScript. The site URL, site name, WordPress version, connector version, PHP version, environment type, and multisite status are sent only after a WordPress administrator explicitly approves the connection. Error events are sent only when a valid DSN is available.

== Installation ==

1. Upload the plugin ZIP through `Plugins > Add New Plugin > Upload Plugin`.
2. Activate `Codegenie Pulse Connector`.
3. Open Codegenie Pulse and select `Add website > Connect WordPress`.
4. Enter the public HTTPS home URL of the WordPress site.
5. Sign in to WordPress if needed and approve the connection as an administrator.
6. Pulse configures site verification and the features available in the active subscription.

The existing verification token and DSN fields remain available as a manual fallback.

== Frequently Asked Questions ==

= What data does the plugin send? =

After an administrator approves a connection, the plugin sends the site URL, site name, WordPress version, connector version, PHP version, environment type, multisite status, and one-time technical authorization data. Error reports can contain a bounded error message, error class, sanitized file path, line number, URL without a query string, request method, status code, bounded stack trace, and redacted technical context. Deployment tracking sends the change type, component slug, and version. Custom context deliberately supplied by a developer can also be sent after redaction.

The plugin does not send cookies, authorization headers, incoming form data or request bodies, email recipients or message content, or WordPress users. It also does not send an inventory of all installed plugins or plugin versions.

= Why does the connection test create an event? =

The existing Codegenie Pulse ingestion endpoint confirms a connection by accepting a valid event. The test uses the `info` level and counts as one processed event under the active plan.

= Can the plugin be used in a local HTTP environment? =

Not by default. Production DSNs and automatic platform connections must use HTTPS and a safe public URL. Developers can enable the `codegenie_pulse_allow_insecure_dsn` and `codegenie_pulse_allow_insecure_platform_origin` filters only in a controlled local environment.

= What happens when WordPress salts change? =

The DSN can no longer be decrypted. Paste the DSN into the connector settings again.

= Are events retried? =

The plugin has no queue and does not automatically retry events. It applies a short local backoff after network, rate-limit, or token failures to prevent an error storm.

= Which PHP error capture mode should I use? =

`Production` is the recommended default and reports fatal errors and unhandled exceptions. `Extended` adds PHP warnings. `Debug` also adds notices, strict, and deprecated messages and should be used only temporarily on staging or during diagnosis. Non-fatal errors are intercepted only when enabled by the PHP `error_reporting` setting. `Off` stops automatic error capture, while explicit helper calls remain available.

= Does the plugin read existing PHP log files? =

No. The plugin does not read or tail an existing `debug.log` or PHP `error_log` file and does not import historical entries. It intercepts selected new PHP errors when they occur. Manual `error_log()` messages are not intercepted automatically.

= How does the plugin prevent an event storm? =

By default, an identical non-fatal error is sent at most once per minute. At most ten unique non-fatal errors are sent per request. Existing PHP error-handler and logging behavior continues afterward.

== External service ==

This plugin sends data to the Codegenie Pulse installation that a WordPress administrator approves through the automatic connection flow or configures manually through a DSN. The official Codegenie Pulse service terms are available at https://pulse.codegenie.be/terms and its privacy notice is available at https://pulse.codegenie.be/privacy.

An administrator can choose another compatible Pulse origin. In that case, data is sent to that chosen installation, and the administrator must review the terms and privacy notice of that installation.

The public discovery endpoint returns only connector information and a one-time site proof to the requesting Pulse installation. After explicit approval, WordPress sends the site URL, site name, WordPress version, connector version, PHP version, environment type, and multisite status. It does not send an inventory of installed plugins or plugin versions. The site verification URL is a public text endpoint containing only the verification token.

== Upgrade Notice ==

= 1.2.1 =

Clarifies the actual data transfer and makes one-time provisioning and multisite network activation more fault tolerant without changing the connector protocol.

= 1.2.0 =

Adds a secure Pulse-first WordPress connection that configures verification, error monitoring, and deployment tracking for the active subscription.

= 1.1.0 =

Adds safe optional capture of PHP warnings, notices, and deprecated messages. Existing 1.0.0 settings are retained automatically as Production or Off.

== Changelog ==

= 1.2.1 =

* Corrected consent copy without a claim about an inventory of installed plugin versions.
* Added a translatable privacy policy suggestion through the WordPress Privacy API.
* Added direct terms and privacy links for the official service and guidance for alternative Pulse installations.
* Added detection and safe failure handling for failed or partial configuration writes during the one-time exchange.
* Added correct non-autoloaded defaults during multisite network activation.
* Expanded regression tests for privacy, fault tolerance, backoff, handlers, migration, and multisite.

= 1.2.0 =

* Added automatic connection initiated from Codegenie Pulse.
* Added an explicit consent screen for WordPress administrators.
* Added an out-of-band site proof based on WordPress authentication salts.
* Added short-lived one-time request tokens without a DSN or ingestion token in the browser URL.
* Added automatic site verification and plan-dependent provisioning.
* Starter supports the site connection without an error DSN; Pro and Agency can receive automatic error monitoring.
* Retained the manual verification token and DSN flow as a fallback.

= 1.1.0 =

* Added four capture modes: Off, Production, Extended, and Debug.
* Added safe capture of PHP warnings, notices, strict, and deprecated messages.
* Retained existing PHP error handlers and normal PHP logging.
* Added cross-request deduplication for identical non-fatal errors.
* Added a configurable limit of ten unique non-fatal events per request.
* Added backward migration of the 1.0.0 automatic error-capture setting.

= 1.0.0 =

* Initial production-focused release.
* Added DSN connection and a manual connection test.
* Added fatal PHP error, WordPress mail failure, and REST 5xx detection.
* Added site verification through a well-known endpoint.
* Added WordPress deployment tracking.
* Added privacy redaction, encrypted token storage, and local backoff.
