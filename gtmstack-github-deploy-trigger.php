<?php
/**
 * Plugin Name: WP GitHub Deploy Trigger
 * Description: Triggers a GitHub Actions workflow_dispatch when a WordPress post is published/updated. Useful for static site rebuilds.
 * Version: 1.0.0
 * Author: Your Name (Template)
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class WP_GitHub_Deploy_Trigger {
  const OPTION_KEY = 'wpgdt_settings';
  const NONCE_ACTION = 'wpgdt_save_settings';

  public function __construct() {
    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);

    // Publish + optional update triggers
    add_action('transition_post_status', [$this, 'maybe_trigger_on_publish'], 10, 3);
    add_action('post_updated', [$this, 'maybe_trigger_on_update'], 10, 3);

    // Manual test button
    add_action('admin_post_wpgdt_test_dispatch', [$this, 'handle_test_dispatch']);
  }

  /** ---------------------------
   *  Settings / Admin UI
   *  --------------------------*/
  public function add_admin_menu() {
    add_options_page(
      'GitHub Deploy Trigger',
      'GitHub Deploy Trigger',
      'manage_options',
      'wpgdt',
      [$this, 'render_settings_page']
    );
  }

  public function register_settings() {
    register_setting('wpgdt_group', self::OPTION_KEY, [$this, 'sanitize_settings']);
  }

  public function sanitize_settings($input) {
    $out = [];

    $out['github_owner']  = sanitize_text_field($input['github_owner'] ?? '');
    $out['github_repo']   = sanitize_text_field($input['github_repo'] ?? '');
    $out['workflow_file'] = sanitize_text_field($input['workflow_file'] ?? 'deploy.yml');
    $out['ref']           = sanitize_text_field($input['ref'] ?? 'main');

    $out['post_type'] = sanitize_text_field($input['post_type'] ?? 'post');

    $out['enable_publish'] = !empty($input['enable_publish']) ? 1 : 0;
    $out['enable_update']  = !empty($input['enable_update']) ? 1 : 0;

    // Optional: Only fire when post is publicly visible
    $out['only_public'] = !empty($input['only_public']) ? 1 : 0;

    // Optional: rate-limit (seconds). Prevent accidental rapid triggers.
    $out['min_interval_sec'] = max(0, intval($input['min_interval_sec'] ?? 0));

    return $out;
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $settings = get_option(self::OPTION_KEY, []);
    $token_present = $this->get_github_token() ? true : false;

    ?>
    <div class="wrap">
      <h1>WP GitHub Deploy Trigger</h1>
      <p>This plugin triggers a <code>workflow_dispatch</code> on GitHub Actions when posts are published (and optionally updated).</p>

      <h2>1) Safest token setup (recommended)</h2>
      <p>Add this to <code>wp-config.php</code> (above <code>/* That's all, stop editing */</code>):</p>
      <pre><code>define('WPGDT_GITHUB_TOKEN', 'YOUR_GITHUB_TOKEN');</code></pre>
      <p><strong>Status:</strong> <?php echo $token_present ? '<span style="color:green;">Token detected</span>' : '<span style="color:#b00;">Token NOT detected</span>'; ?></p>

      <hr />

      <form method="post" action="options.php">
        <?php settings_fields('wpgdt_group'); ?>
        <?php $val = function($k, $default='') use ($settings) { return esc_attr($settings[$k] ?? $default); }; ?>
        <?php $chk = function($k) use ($settings) { return !empty($settings[$k]) ? 'checked' : ''; }; ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">GitHub owner</th>
            <td><input name="<?php echo self::OPTION_KEY; ?>[github_owner]" value="<?php echo $val('github_owner'); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row">GitHub repo</th>
            <td><input name="<?php echo self::OPTION_KEY; ?>[github_repo]" value="<?php echo $val('github_repo'); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row">Workflow file</th>
            <td>
              <input name="<?php echo self::OPTION_KEY; ?>[workflow_file]" value="<?php echo $val('workflow_file','deploy.yml'); ?>" class="regular-text" />
              <p class="description">Example: <code>deploy.yml</code> (file must exist in <code>.github/workflows/</code>)</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Branch/ref</th>
            <td><input name="<?php echo self::OPTION_KEY; ?>[ref]" value="<?php echo $val('ref','main'); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row">Trigger on post type</th>
            <td>
              <input name="<?php echo self::OPTION_KEY; ?>[post_type]" value="<?php echo $val('post_type','post'); ?>" class="regular-text" />
              <p class="description">Use <code>post</code>, <code>page</code>, or a custom post type slug.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Enable dispatch on publish</th>
            <td><label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[enable_publish]" <?php echo $chk('enable_publish'); ?> /> Enabled</label></td>
          </tr>
          <tr>
            <th scope="row">Also trigger on updates</th>
            <td><label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[enable_update]" <?php echo $chk('enable_update'); ?> /> Trigger rebuild when already-published post is updated</label></td>
          </tr>
          <tr>
            <th scope="row">Only for public posts</th>
            <td><label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[only_public]" <?php echo $chk('only_public'); ?> /> Only trigger when status is <code>publish</code></label></td>
          </tr>
          <tr>
            <th scope="row">Minimum interval (seconds)</th>
            <td>
              <input name="<?php echo self::OPTION_KEY; ?>[min_interval_sec]" value="<?php echo $val('min_interval_sec','0'); ?>" class="small-text" />
              <p class="description">Optional safety: prevents repeated triggers within N seconds.</p>
            </td>
          </tr>
        </table>

        <?php submit_button('Save Settings'); ?>
      </form>

      <hr />

      <h2>2) Test Dispatch</h2>
      <p>This sends a manual <code>workflow_dispatch</code> to verify GitHub connectivity. It does not publish anything.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wpgdt_test_dispatch'); ?>
        <input type="hidden" name="action" value="wpgdt_test_dispatch" />
        <?php submit_button('Send Test Dispatch', 'secondary'); ?>
      </form>

      <hr />

      <h2>3) GitHub Workflow Requirement</h2>
      <p>Your workflow file must include <code>workflow_dispatch</code>, e.g.:</p>
      <pre><code>on:
  push:
    branches: ["main"]
  workflow_dispatch: {}</code></pre>

    </div>
    <?php
  }

  /** ---------------------------
   *  Triggers
   *  --------------------------*/
  public function maybe_trigger_on_publish($new_status, $old_status, $post) {
    if (!is_object($post) || empty($post->ID)) return;

    $settings = get_option(self::OPTION_KEY, []);
    if (empty($settings['enable_publish'])) return;

    // only trigger when transitioning to publish
    if ($old_status === 'publish' || $new_status !== 'publish') return;

    if (!$this->post_matches_settings($post, $settings)) return;
    if (!$this->passes_rate_limit($post->ID, $settings)) return;

    $this->dispatch_workflow($post, 'publish');
  }

  public function maybe_trigger_on_update($post_ID, $post_after, $post_before) {
    $settings = get_option(self::OPTION_KEY, []);
    if (empty($settings['enable_update'])) return;

    if (!is_object($post_after)) return;
    if (!is_object($post_before)) return;

    // Only if already published and still published
    if ($post_before->post_status !== 'publish' || $post_after->post_status !== 'publish') return;

    if (!$this->post_matches_settings($post_after, $settings)) return;
    if (!empty($settings['only_public'])) {
      if ($post_after->post_status !== 'publish') return;
    }

    if (!$this->passes_rate_limit($post_ID, $settings)) return;

    $this->dispatch_workflow($post_after, 'update');
  }

  private function post_matches_settings($post, $settings) {
    $wanted_type = $settings['post_type'] ?? 'post';
    if (!empty($wanted_type) && $post->post_type !== $wanted_type) return false;

    if (!empty($settings['only_public']) && $post->post_status !== 'publish') return false;

    return true;
  }

  /** ---------------------------
   *  Manual test
   *  --------------------------*/
  public function handle_test_dispatch() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('wpgdt_test_dispatch');

    $ok = $this->dispatch_workflow(null, 'test');

    $redirect = add_query_arg([
      'page' => 'wpgdt',
      'wpgdt_test' => $ok ? 'ok' : 'fail',
    ], admin_url('options-general.php'));

    wp_safe_redirect($redirect);
    exit;
  }

  /** ---------------------------
   *  GitHub Dispatch
   *  --------------------------*/
  private function dispatch_workflow($post, $reason = 'publish') {
    $token = $this->get_github_token();
    if (!$token) {
      $this->log('Missing GitHub token. Define WPGDT_GITHUB_TOKEN in wp-config.php.');
      return false;
    }

    $settings = get_option(self::OPTION_KEY, []);
    $owner = $settings['github_owner'] ?? '';
    $repo  = $settings['github_repo'] ?? '';
    $workflow_file = $settings['workflow_file'] ?? 'deploy.yml';
    $ref = $settings['ref'] ?? 'main';

    if (!$owner || !$repo || !$workflow_file) {
      $this->log('Missing GitHub owner/repo/workflow_file settings.');
      return false;
    }

    // GitHub API: Create a workflow dispatch event
    $url = "https://api.github.com/repos/{$owner}/{$repo}/actions/workflows/{$workflow_file}/dispatches";

    // Optional inputs (useful for debugging later)
    $inputs = [
      'reason' => $reason,
    ];

    if ($post && is_object($post)) {
      $inputs['wp_post_id'] = strval($post->ID);
      $inputs['wp_post_type'] = strval($post->post_type);
      $inputs['wp_post_status'] = strval($post->post_status);
      $inputs['wp_post_slug'] = strval($post->post_name);
    }

    $body = wp_json_encode([
      'ref' => $ref,
      'inputs' => $inputs,
    ]);

    $args = [
      'method'  => 'POST',
      'timeout' => 15,
      'headers' => [
        'Accept'        => 'application/vnd.github+json',
        'Authorization
