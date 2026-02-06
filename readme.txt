=== GTMStack GitHub Deploy Trigger ===
Contributors: gtmstack
Tags: github, actions, webhook, headless, nextjs, static
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.1.0
License: GPLv2 or later

Triggers a GitHub Actions workflow_dispatch when WordPress posts are published, so your static Next.js site rebuilds automatically.

== Installation ==
1. Upload the ZIP via WordPress Admin → Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Add your GitHub token to wp-config.php:
   define('GTMSTACK_GH_TOKEN', 'YOUR_TOKEN');
4. Go to Settings → GTMStack Deploy Trigger, confirm token detected, then enable.

== GitHub Requirements ==
Your workflow must include workflow_dispatch, e.g.:

on:
  push:
    branches: ["main"]
  workflow_dispatch: {}

== Notes ==
- Token is stored in wp-config.php for best security (not in DB).
- Includes a test dispatch button.
