<?php
/**
 * Plugin Name: GTMStack GitHub Deploy Trigger
 * Description: Safely triggers a GitHub Actions workflow_dispatch when WordPress posts are published (headless rebuild for static Next.js exports).
 * Version: 1.1.0
 * Author: GTMStack
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

/**
 * =========================
 * SAFETY-FIRST CONFIG MODEL
 * =========================
 * For best security, store the GitHub token in wp-config.php (NOT in the database):
 *
 *   define('GTMSTACK_GH_TOKEN', 'ghp_...');
 *
 * This plugin will refuse to dispatch if GTMSTACK_GH_TOKEN is not defined.
 */

/** Option keys */
const GTMSTACK_OPT = 'gtmstack_gh_deploy_settings';

/** Defaults */
function gtmstack_default_settings() {
  return [
    'owner'    => 'findlini67',
    'repo'     => 'GTMStack_pro',
    'workflow' => 'deploy.yml',   // filename under .github/workflows/
    'ref'      => 'main',
    'enabled'  => '0',            // start disabled until token present + user enables
    'post_type'=> 'post',         // default: posts only
    'also_on_update' => '0',       // optional: rebuild when published posts are updated
  ];
}

function gtmstack_get_settings() {
  $defaults = gtmstack_default_settings();
  $saved = get_option(GTMSTACK_OPT, []);
  if (!is_array($saved)) $saved = [];
  return array_merge($defaults, $saved);
}

/** Admin notice if token missing */
function gtmstack_admin_notice_missing_token() {
  if (!current_user_can('manage_options')) return;

  $settings = gtmstack_get_settings();
  if ($settings['enabled'] !== '1') return;

  if (!defined('GTMSTACK_GH_TOKEN') || !GTMSTACK_GH_TOKEN) {
    echo '<div class="notice notice-error"><p><strong>GTMStack GitHub Deploy Trigger:</strong> Enabled, but <code>GTMSTACK_GH_TOKEN</code> is missing. No dispatches will run. Add the token to <code>wp-config.php</code> or disable the plugin trigger in Settings.</p></div>';
  }
}
add_action('admin_notices', 'gtmstack_admin_notice_missing_token');

/** Register settings */
function gtmstack_register_settings() {
  register_setting('gtmstack_gh_deploy', GTMSTACK_OPT, [
    'type' => 'array',
    'sanitize_callback' => 'gtmstack_sanitize_settings',
    'default' => gtmstack_default_settings(),
  ]);
}
add_action('admin_init', 'gtmstack_register_settings');

function gtmstack_sanitize_settings($input) {
  $defaults = gtmstack_default_settings();
  $out = [];

  $out['owner'] = isset($input['owner']) ? sanitize_text_field($input['owner']) : $defaults['owner'];
  $out['repo'] = isset($input['repo']) ? sanitize_text_field($input['repo']) : $defaults['repo'];
  $out['workflow'] = isset($input['workflow']) ? sanitize_text_field($input['workflow']) : $defaults['workflow'];
  $out['ref'] = isset($input['ref']) ? sanitize_text_field($input['ref']) : $defaults['ref'];

  $out['post_type'] = isset($input['post_type']) ? sanitize_key($input['post_type']) : $defaults['post_type'];

  $out['enabled'] = (!empty($input['enabled']) && $input['enabled'] === '1') ? '1' : '0';
  $out['also_on_update'] = (!empty($input['also_on_update']) && $input['also_on_update'] === '1') ? '1' : '0';

  // If token missing, force disabled for safety (user sees notice if they re-enable)
  if ($out['enabled'] === '1' && (!defined('GTMSTACK_GH_TOKEN') || !GTMSTACK_GH_TOKEN)) {
    $out['enabled'] = '0';
  }

  return array_merge($defaults, $out);
}

/** Add settings page */
function gtmstack_add_settings_page() {
  add_options_page(
    'GTMStack Deploy Trigger',
    'GTMStack Deploy Trigger',
    'manage_options',
    'gtmstack-gh-deploy',
    'gtmstack_render_settings_page'
  );
}
add_action('admin_menu', 'gtmstack_add_settings_page');

function gtmstack_render_settings_page() {
  if (!current_user_can('manage_options')) return;

  $settings = gtmstack_get_settings();
  $token_ok = (defined('GTMSTACK_GH_TOKEN') && GTMSTACK_GH_TOKEN);

  ?>
  <div class="wrap">
    <h1>GTMStack GitHub Deploy Trigger</h1>

    <p>This plugin triggers a GitHub Actions workflow (<code>workflow_dispatch</code>) whenever you publish a WordPress post, so your static Next.js site rebuilds automatically.</p>

    <h2>1) Safest Token Setup (recommended)</h2>
    <p>Add this line to <code>wp-config.php</code> (above <code>/* That's all, stop editing! */</code>):</p>
    <pre><code>define('GTMSTACK_GH_TOKEN', 'YOUR_GITHUB_TOKEN');</code></pre>
    <p><strong>Status:</strong> <?php echo $token_ok ? '<span style="color:green">Token detected</span>' : '<span style="color:#b00">Token NOT detected</span>'; ?></p>

    <hr />

    <form method="post" action="options.php">
      <?php settings_fields('gtmstack_gh_deploy'); ?>
      <?php $opt = GTMSTACK_OPT; ?>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($opt); ?>[owner]">GitHub owner</label></th>
          <td><input class="regular-text" type="text" name="<?php echo esc_attr($opt); ?>[owner]" value="<?php echo esc_attr($settings['owner']); ?>" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($opt); ?>[repo]">GitHub repo</label></th>
          <td><input class="regular-text" type="text" name="<?php echo esc_attr($opt); ?>[repo]" value="<?php echo esc_attr($settings['repo']); ?>" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($opt); ?>[workflow]">Workflow file</label></th>
          <td>
            <input class="regular-text" type="text" name="<?php echo esc_attr($opt); ?>[workflow]" value="<?php echo esc_attr($settings['workflow']); ?>" />
            <p class="description">Example: <code>deploy.yml</code> (must exist in <code>.github/workflows/</code>).</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($opt); ?>[ref]">Branch/ref</label></th>
          <td><input class="regular-text" type="text" name="<?php echo esc_attr($opt); ?>[ref]" value="<?php echo esc_attr($settings['ref']); ?>" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($opt); ?>[post_type]">Trigger on post type</label></th>
          <td>
            <input class="regular-text" type="text" name="<?php echo esc_attr($opt); ?>[post_type]" value="<?php echo esc_attr($settings['post_type']); ?>" />
            <p class="description">Default: <code>post</code>. (Advanced: use <code>page</code> or a custom post type slug.)</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Enable dispatch on publish</th>
          <td>
            <label>
              <input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked($settings['enabled'], '1'); ?> <?php disabled(!$token_ok); ?> />
              Enabled (requires token in <code>wp-config.php</code>)
            </label>
          </td>
        </tr>
        <tr>
          <th scope="row">Also trigger on updates</th>
          <td>
            <label>
              <input type="checkbox" name="<?php echo esc_attr($opt); ?>[also_on_update]" value="1" <?php checked($settings['also_on_update'], '1'); ?> />
              Trigger rebuild when an already-published post is updated
            </label>
          </td>
        </tr>
      </table>

      <?php submit_button('Save Settings'); ?>
    </form>

    <hr />

    <h2>2) Test Dispatch</h2>
    <p>Use this to verify WordPress can trigger GitHub. It does not publish anything.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="gtmstack_test_dispatch" />
      <?php wp_nonce_field('gtmstack_test_dispatch'); ?>
      <?php submit_button('Send Test Dispatch', 'secondary'); ?>
    </form>

    <h2>3) GitHub Workflow Requirement</h2>
    <p>Your workflow file must include <code>workflow_dispatch</code>, e.g.:</p>
    <pre><code>on:
  push:
    branches: ["main"]
  workflow_dispatch: {}</code></pre>

  </div>
  <?php
}

/** Admin-post handler for test dispatch */
add_action('admin_post_gtmstack_test_dispatch', function() {
  if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
  check_admin_referer('gtmstack_test_dispatch');

  $settings = gtmstack_get_settings();
  $result = gtmstack_dispatch_github($settings);

  $redirect = add_query_arg([
    'page' => 'gtmstack-gh-deploy',
    'gtmstack_test' => $result['ok'] ? '1' : '0',
    'gtmstack_code' => $result['code'],
  ], admin_url('options-general.php'));

  wp_safe_redirect($redirect);
  exit;
});

/** Show test result notice */
add_action('admin_notices', function() {
  if (!current_user_can('manage_options')) return;
  if (!isset($_GET['page']) || $_GET['page'] !== 'gtmstack-gh-deploy') return;
  if (!isset($_GET['gtmstack_test'])) return;

  $ok = ($_GET['gtmstack_test'] === '1');
  $code = isset($_GET['gtmstack_code']) ? intval($_GET['gtmstack_code']) : 0;

  if ($ok) {
    echo '<div class="notice notice-success"><p><strong>GTMStack Deploy Trigger:</strong> Test dispatch sent successfully (HTTP ' . esc_html($code) . '). Check GitHub Actions.</p></div>';
  } else {
    echo '<div class="notice notice-error"><p><strong>GTMStack Deploy Trigger:</strong> Test dispatch failed (HTTP ' . esc_html($code) . '). Enable WP_DEBUG_LOG and check <code>wp-content/debug.log</code>.</p></div>';
  }
});

/** Throttle to avoid double-fires */
function gtmstack_throttle($key, $seconds = 30) {
  $tkey = 'gtmstack_throttle_' . md5($key);
  if (get_transient($tkey)) return true;
  set_transient($tkey, time(), $seconds);
  return false;
}

/** Dispatch helper */
function gtmstack_dispatch_github($settings) {
  if (!defined('GTMSTACK_GH_TOKEN') || !GTMSTACK_GH_TOKEN) {
    error_log('[GTMStack Deploy] Missing GTMSTACK_GH_TOKEN; dispatch aborted.');
    return ['ok' => false, 'code' => 0];
  }

  $owner = $settings['owner'];
  $repo = $settings['repo'];
  $workflow = $settings['workflow'];
  $ref = $settings['ref'];

  $url = sprintf('https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches', rawurlencode($owner), rawurlencode($repo), rawurlencode($workflow));

  $response = wp_remote_post($url, [
    'timeout' => 15,
    'headers' => [
      'Authorization' => 'Bearer ' . GTMSTACK_GH_TOKEN,
      'Accept'        => 'application/vnd.github+json',
      'Content-Type'  => 'application/json',
      'User-Agent'    => 'gtmstack-wp-plugin',
    ],
    'body' => wp_json_encode(['ref' => $ref]),
  ]);

  if (is_wp_error($response)) {
    error_log('[GTMStack Deploy] Dispatch error: ' . $response->get_error_message());
    return ['ok' => false, 'code' => 0];
  }

  $code = wp_remote_retrieve_response_code($response);
  $body = wp_remote_retrieve_body($response);

  // GitHub returns 204 No Content on success for workflow_dispatch.
  $ok = ($code >= 200 && $code < 300);

  if (!$ok) {
    error_log('[GTMStack Deploy] Dispatch failed. HTTP ' . $code . ' body=' . $body);
  }

  return ['ok' => $ok, 'code' => $code];
}

/** Trigger on publish (transition to publish) */
add_action('transition_post_status', function($new_status, $old_status, $post) {
  if ($new_status !== 'publish') return;

  $settings = gtmstack_get_settings();
  if ($settings['enabled'] !== '1') return;

  if (!is_object($post) || empty($post->ID)) return;
  if ($post->post_type !== $settings['post_type']) return;
  if (wp_is_post_revision($post->ID)) return;
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

  // Only when it becomes published
  if ($old_status === 'publish') return;

  // Avoid duplicate calls
  if (gtmstack_throttle('publish_' . $post->ID, 30)) return;

  gtmstack_dispatch_github($settings);
}, 10, 3);

/** Optional: trigger on updates to already published posts */
add_action('post_updated', function($post_id, $post_after, $post_before) {
  if (!is_object($post_after) || !is_object($post_before)) return;

  $settings = gtmstack_get_settings();
  if ($settings['enabled'] !== '1') return;
  if ($settings['also_on_update'] !== '1') return;

  if ($post_after->post_status !== 'publish') return;
  if ($post_before->post_status !== 'publish') return;
  if ($post_after->post_type !== $settings['post_type']) return;
  if (wp_is_post_revision($post_id)) return;

  if (gtmstack_throttle('update_' . $post_id, 30)) return;

  gtmstack_dispatch_github($settings);
}, 10, 3);
