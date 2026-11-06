# FrontLabs Mail Catcher

Records every message sent through `wp_mail()` and lists it under
**Mail Catcher** in wp-admin: a list in the shape of Flamingo's inbound
messages, and a details screen with every field, the headers, and a sandboxed
HTML preview.

Requires WordPress 6.2 (`%i` in `$wpdb->prepare()`) and PHP 8.0.

## Installation

With Composer, from a WordPress root that uses `composer/installers`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/emendelski/wp-mail-catcher" }
  ],
  "require": {
    "emendelski/wp-mail-catcher": "^1.0"
  }
}
```

The package installs to `wp-content/plugins/frontlabs-mail-catcher/`. The
directory name is fixed by `extra.installer-name`, which keeps the plugin's
basename the same on every site. You can also copy the repository into
`wp-content/plugins/frontlabs-mail-catcher/` by hand. Then activate it under
**Plugins**.

## Modes

Every mode records the message. They differ in what is sent.

| Mode | Sent to | Default on |
| --- | --- | --- |
| **Block** | nobody | `local`, `development` |
| **Redirect** | only the test addresses from the settings (To/Cc/Bcc removed, originals kept in `X-FMC-Original-*` headers) | — |
| **Off** | the original recipients | `staging`, `production` |

The default applies until the settings are saved. Redirect with no test address
falls back to Block.

On `staging` and `production` the plugin's screens and the plugin list show a
warning: the table holds form data and password reset links, and it ends up in
every backup.

## How it hooks in

| Hook | Priority | Function | Job |
| --- | --- | --- | --- |
| `wp_mail` | `PHP_INT_MAX` | `fmc_capture()` | Record the original; rewrite recipients for Redirect |
| `pre_wp_mail` | `PHP_INT_MAX` | `fmc_maybe_block()` | Block: end `wp_mail()` before PHPMailer |
| `phpmailer_init` | `PHP_INT_MAX` | `fmc_guard_phpmailer()` | Enforce the allowed recipients on PHPMailer itself |
| `wp_mail_succeeded` / `wp_mail_failed` | 10 | `fmc_on_succeeded()` / `fmc_on_failed()` | Record the outcome |

`phpmailer_init` is the backstop. It still fires when a plugin removes the
`wp_mail` filters (the WP Mail SMTP queue does that when it sends a queued
message), and it also removes recipients that other plugins add directly to
PHPMailer. In Block mode it leaves no recipient at all, so PHPMailer refuses to
send.

The plugin cannot catch mail that never goes through `wp_mail()`, for example
Mailchimp for WP talking to the Mailchimp API. If another plugin replaces
`wp_mail()` itself, the plugin's screens show an error.

## Security notes

- Every stored value is escaped as text. The HTML body is rendered only inside
  `<iframe sandbox="">`, with no `allow-scripts` and no `allow-same-origin`.
  A Content-Security-Policy placed at the top of the document blocks remote
  resources until **Show remote images** is clicked.
- The `srcdoc` attribute is escaped with `fmc_srcdoc_attr()`, not `esc_attr()`.
  `esc_attr()` does not re-encode entities that are already there.
- **Forward to my address** sends only to one of the configured test addresses.
  The address is checked on the server, and the bypass covers exactly one
  `wp_mail()` call.
- Every action checks `manage_options` and a nonce. Every query uses
  `$wpdb->prepare()`, and the ordering and filter values come from fixed lists.

## Retention

A daily WP-Cron event (`fmc_prune`) deletes messages older than the retention
setting: 30 days on local/development and 7 days elsewhere by default. Set it to
0 to keep messages forever. Uninstalling the plugin drops the table, removes the
options and unschedules the event.

## Development

```bash
composer install
composer test   # PHPUnit + Brain Monkey, no WordPress needed
composer lint   # WordPress Coding Standards
```

PHPUnit 10 needs PHP 8.1 or newer for development. The plugin itself still runs
on PHP 8.0.

To release, tag a version (`git tag v1.0.1 && git push --tags`). Then run
`composer update emendelski/wp-mail-catcher` in each project that uses it.
