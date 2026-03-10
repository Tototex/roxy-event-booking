<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_register_admin_pages() {
    add_action('admin_menu', function () {
        add_menu_page(
            'Roxy Bookings',
            'Roxy Bookings',
            'manage_options',
            'roxy-eb',
            'roxy_eb_admin_bookings_page',
            'dashicons-calendar-alt',
            56
        );

        add_submenu_page('roxy-eb', 'Bookings', 'Bookings', 'manage_options', 'roxy-eb', 'roxy_eb_admin_bookings_page');
        add_submenu_page('roxy-eb', 'Calendar Blocks', 'Calendar Blocks', 'manage_options', 'roxy-eb-blocks', 'roxy_eb_admin_blocks_page');
        add_submenu_page('roxy-eb', 'Settings', 'Settings', 'manage_options', 'roxy-eb-settings', 'roxy_eb_admin_settings_page');
        add_submenu_page('roxy-eb', 'Sling Logs', 'Sling Logs', 'manage_options', 'roxy-eb-sling-logs', 'roxy_eb_admin_sling_logs_page');
    });
}

function roxy_eb_admin_settings_page() {
    if (!current_user_can('manage_options')) return;

    $settings = roxy_eb_get_settings();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['roxy_eb_save_settings'])) {
            check_admin_referer('roxy_eb_save_settings');
            $incoming = $_POST['settings'] ?? [];
            // Handle Sling token (stored encrypted). Field is blank to keep existing.
            if (!empty($incoming['_sling_token_plain'])) {
                $incoming['sling_auth_token_enc'] = roxy_eb_sling_encrypt_secret($incoming['_sling_token_plain']);
            }
            unset($incoming['_sling_token_plain']);
            $settings = roxy_eb_update_settings($incoming);
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        if (isset($_POST['roxy_eb_sling_test_connection'])) {
            check_admin_referer('roxy_eb_save_settings');
            $incoming = $_POST['settings'] ?? [];
            if (!empty($incoming['_sling_token_plain'])) {
                $incoming['sling_auth_token_enc'] = roxy_eb_sling_encrypt_secret($incoming['_sling_token_plain']);
            }
            unset($incoming['_sling_token_plain']);
            // Save the settings first (so email / mapping values persist)
            $settings = roxy_eb_update_settings($incoming);
            $result = roxy_eb_sling_admin_test_and_resolve($settings);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p><strong>Sling test failed:</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $settings = roxy_eb_get_settings();
                $msg = 'Token stored.';
                if (is_array($result) && isset($result['message']) && $result['message']) {
                    $msg = $result['message'];
                }
                echo '<div class="notice notice-success"><p><strong>Sling connected.</strong> ' . esc_html($msg) . '</p></div>';
            }
        }

        if (isset($_POST['roxy_eb_sling_create_test_shift'])) {
            check_admin_referer('roxy_eb_save_settings');
            $incoming = $_POST['settings'] ?? [];
            if (!empty($incoming['_sling_token_plain'])) {
                $incoming['sling_auth_token_enc'] = roxy_eb_sling_encrypt_secret($incoming['_sling_token_plain']);
            }
            unset($incoming['_sling_token_plain']);
            $settings = roxy_eb_update_settings($incoming);
            $result = roxy_eb_sling_admin_create_test_shift($settings);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p><strong>Test shift failed:</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p><strong>Test shift created.</strong> ' . esc_html($result) . '</p></div>';
            }
        }

    }

    ?>
    <div class="wrap">
        <h1>Roxy Event Booking — Settings</h1>

        <form method="post">
            <?php wp_nonce_field('roxy_eb_save_settings'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Internal notification email</th>
                    <td><input type="email" name="settings[internal_email]" value="<?php echo esc_attr($settings['internal_email']); ?>" class="regular-text" /></td>
                </tr>
                <tr><th scope="row">Lead time (hours)</th>
                    <td><input type="number" name="settings[lead_time_hours]" value="<?php echo esc_attr($settings['lead_time_hours']); ?>" min="0" /></td>
                </tr>
                <tr><th scope="row">Free cancellation window (days)</th>
                    <td><input type="number" name="settings[cancel_free_days]" value="<?php echo esc_attr($settings['cancel_free_days']); ?>" min="0" /></td>
                </tr>
                <tr><th scope="row">Guest cap (hidden)</th>
                    <td><input type="number" name="settings[guest_cap]" value="<?php echo esc_attr($settings['guest_cap']); ?>" min="1" /></td>
                </tr>

                <tr><th scope="row">Base price (≤ 25 guests)</th>
                    <td>$ <input type="number" name="settings[base_price_under]" value="<?php echo esc_attr($settings['base_price_under']); ?>" min="0" /></td>
                </tr>
                <tr><th scope="row">Base price (≥ 26 guests)</th>
                    <td>$ <input type="number" name="settings[base_price_over]" value="<?php echo esc_attr($settings['base_price_over']); ?>" min="0" /></td>
                </tr>
                <tr><th scope="row">Extra hour price</th>
                    <td>$ <input type="number" name="settings[extra_hour_price]" value="<?php echo esc_attr($settings['extra_hour_price']); ?>" min="0" /></td>
                </tr>

                <tr><th scope="row">Operating hours</th>
                    <td>
                        Open <input type="text" name="settings[open_time]" value="<?php echo esc_attr($settings['open_time']); ?>" placeholder="08:00" />
                        Close <input type="text" name="settings[close_time]" value="<?php echo esc_attr($settings['close_time']); ?>" placeholder="24:00" />
                        <p class="description">Close of <code>24:00</code> means end-of-day (midnight).</p>
                    </td>
                </tr>

                <tr><th scope="row">Time increments</th>
                    <td>
                        <select name="settings[time_increment_minutes]">
                            <?php foreach ([5,10,15,20,30,60] as $m): ?>
                                <option value="<?php echo esc_attr($m); ?>" <?php selected(intval($settings['time_increment_minutes']), $m); ?>><?php echo esc_html($m); ?> minutes</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr><th scope="row">Booking product</th>
                    <td>
                        <input type="number" name="settings[booking_product_id]" value="<?php echo esc_attr($settings['booking_product_id']); ?>" min="0" />
                        <p class="description">Auto-created on plugin activation. If you deleted it, save settings with 0 then re-activate plugin.</p>
                    </td>
                </tr>
            </table>

            <h2>Fixed Showtime Blocks</h2>
            <p class="description">These are always blocked for normal movie showings.</p>

            <table class="widefat striped">
                <thead><tr><th>Weekday</th><th>Start</th><th>End</th><th>Label</th><th style="width:90px;">Delete</th></tr></thead>
                <tbody>
                    <?php foreach ($settings['showtime_blocks'] as $i => $b): ?>
                        <tr>
                            <td>
                                <select name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][weekday]">
                                    <?php
                                    $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                                    foreach ($days as $dIdx => $dName) {
                                        echo '<option value="' . esc_attr($dIdx) . '"' . selected(intval($b['weekday']), $dIdx, false) . '>' . esc_html($dName) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                            <td><input type="text" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][start]" value="<?php echo esc_attr($b['start']); ?>" /></td>
                            <td><input type="text" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][end]" value="<?php echo esc_attr($b['end']); ?>" /></td>
                            <td><input type="text" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($b['label']); ?>" class="regular-text"/></td>
                            <td><label><input type="checkbox" name="settings[showtime_blocks][<?php echo esc_attr($i); ?>][_delete]" value="1" /> Remove</label></td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Add one blank row for convenience -->
                    <tr>
                        <td>
                            <select name="settings[showtime_blocks][new][weekday]">
                                <?php
                                $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                                foreach ($days as $dIdx => $dName) {
                                    echo '<option value="' . esc_attr($dIdx) . '">' . esc_html($dName) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                        <td><input type="text" name="settings[showtime_blocks][new][start]" value="" placeholder="18:00"/></td>
                        <td><input type="text" name="settings[showtime_blocks][new][end]" value="" placeholder="22:00"/></td>
                        <td><input type="text" name="settings[showtime_blocks][new][label]" value="" placeholder="Regular Showing"/></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <p class="description">Tip: check “Remove” and click “Save Changes” to delete a block.</p>

            <h2>Sling Integration</h2>
            <p class="description">
                For Direct API mode, the plugin will use the Authorization token you paste below (no password login, avoids captcha).
                Use “Test Connection” to verify connectivity and token validity (mapping is manual).
            </p>

            <table class="form-table" role="presentation">
                <tr><th scope="row">Mode</th>
                    <td>
                        <select name="settings[sling_mode]">
                            <option value="disabled" <?php selected($settings['sling_mode'], 'disabled'); ?>>Disabled</option>
                            <option value="webhook" <?php selected($settings['sling_mode'], 'webhook'); ?>>Webhook (recommended)</option>
                            <option value="direct" <?php selected($settings['sling_mode'], 'direct'); ?>>Direct API (advanced)</option>
                        </select>
                    </td>
                </tr>
                <tr><th scope="row">Webhook URL</th>
                    <td>
                        <input type="url" name="settings[sling_webhook_url]" value="<?php echo esc_attr($settings['sling_webhook_url']); ?>" class="regular-text" />
                        <p class="description">Plugin will POST booking JSON to this URL on confirm/cancel. Use this to call Sling from n8n or your own server.</p>
                    </td>
                </tr>
                <tr><th scope="row">Direct API Base URL</th>
                    <td><input type="url" name="settings[sling_base_url]" value="<?php echo esc_attr($settings['sling_base_url']); ?>" class="regular-text" /></td>
                </tr>
                <tr><th scope="row">Sling Authorization token</th>
                    <td>
                        <input type="password" name="settings[_sling_token_plain]" value="" class="large-text" autocomplete="off" />
                        <p class="description">Paste the <code>Authorization</code> header value from Sling (browser DevTools → Network). Leave blank to keep the stored token.</p>
                    </td>
                </tr>
                <tr><th scope="row">Auth failure email</th>
                    <td>
                        <input type="email" name="settings[sling_auth_fail_email]" value="<?php echo esc_attr($settings['sling_auth_fail_email'] ?? 'info@newportroxy.com'); ?>" class="regular-text" />
                        <p class="description">We’ll email this address if Sling returns 401/403 (expired/invalid token).</p>
                    </td>
                </tr>
                <tr><th scope="row">Publish shifts</th>
                    <td>
                        <label><input type="checkbox" name="settings[sling_publish_shifts]" value="1" <?php checked(!empty($settings['sling_publish_shifts'])); ?> /> Publish shifts so employees can see them</label>
                        <p class="description">OFF = create unpublished (planning) shifts for testing. ON = publish immediately after create/update.</p>
                    </td>
                </tr>


                <tr><th scope="row">Location label / External ID</th>
                    <td>
                        <input type="text" name="settings[sling_location_label]" value="<?php echo esc_attr($settings['sling_location_label'] ?? ''); ?>" class="regular-text" />
                        <p class="description">Example: Newport Roxy Theater (we will match by name or External ID).</p>
                    </td>
                </tr>
                <tr><th scope="row">Position label: Private Show</th>
                    <td><input type="text" name="settings[sling_position_private_show_label]" value="<?php echo esc_attr($settings['sling_position_private_show_label'] ?? 'Private Show'); ?>" class="regular-text" /></td>
                </tr>
                <tr><th scope="row">Position label: Concessionist</th>
                    <td><input type="text" name="settings[sling_position_concessionist_label]" value="<?php echo esc_attr($settings['sling_position_concessionist_label'] ?? 'Concessionist'); ?>" class="regular-text" /></td>
                </tr>

                <tr><th scope="row">Manual numeric IDs (optional)</th>
                    <td>
                        <p class="description">If Sling list endpoints are restricted for your account, paste numeric IDs here and skip resolving by label.</p>
                        <div style="display:grid; grid-template-columns: 180px 1fr; gap:8px; max-width:700px;">
                            <div>Location ID</div><div><input type="text" name="settings[sling_location_id]" value="<?php echo esc_attr($settings['sling_location_id'] ?? ''); ?>" class="regular-text" /></div>
                            <div>Private Show Position ID</div><div><input type="text" name="settings[sling_position_private_show_id]" value="<?php echo esc_attr($settings['sling_position_private_show_id'] ?? ''); ?>" class="regular-text" /></div>
                            <div>Concessionist Position ID</div><div><input type="text" name="settings[sling_position_concessionist_id]" value="<?php echo esc_attr($settings['sling_position_concessionist_id'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                    </td>
                </tr>

                <tr><th scope="row">Resolved IDs (read-only)</th>
                    <td>
                        <code>location: <?php echo esc_html($settings['sling_location_id_resolved'] ?? ''); ?></code><br/>
                        <code>private_show: <?php echo esc_html($settings['sling_position_private_show_id_resolved'] ?? ''); ?></code><br/>
                        <code>concessionist: <?php echo esc_html($settings['sling_position_concessionist_id_resolved'] ?? ''); ?></code>
                    </td>
                </tr>
                <tr><th scope="row">Shift title template</th>
                    <td><input type="text" name="settings[sling_shift_title_template]" value="<?php echo esc_attr($settings['sling_shift_title_template']); ?>" class="regular-text" /></td>
                </tr>
                <tr><th scope="row">Shift notes template</th>
                    <td><textarea name="settings[sling_shift_notes_template]" rows="5" class="large-text"><?php echo esc_textarea($settings['sling_shift_notes_template']); ?></textarea></td>
                </tr>
            </table>

            <p class="submit">
                <button class="button button-primary" type="submit" name="roxy_eb_save_settings" value="1">Save Settings</button>
                <button class="button" type="submit" name="roxy_eb_sling_test_connection" value="1" style="margin-left:8px;">Test Connection</button>
                <button class="button" type="submit" name="roxy_eb_sling_create_test_shift" value="1" style="margin-left:8px;">Create Test Sling Shift</button>
            </p>
        </form>

        <hr />
        <h2>Shortcode</h2>
        <p>Add this shortcode where you want the calendar to appear (e.g., under your rental details section):</p>
        <code>[roxy_booking_calendar]</code>

        <h2>Important</h2>
        <ul>
            <li>After enabling the plugin, visit <strong>Settings → Permalinks</strong> and click <strong>Save</strong> once (to enable the My Account “My Bookings” tab).</li>
            <li>Only one booking can exist at a time (theater-wide), enforced by blocked windows.</li>
        </ul>
    </div>
    <?php
}

function roxy_eb_admin_blocks_page() {
    if (!current_user_can('manage_options')) return;

    // Handle create block
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['roxy_eb_add_block'])) {
        check_admin_referer('roxy_eb_add_block');

        $title = sanitize_text_field($_POST['title'] ?? 'Blocked');
        $type  = sanitize_text_field($_POST['type'] ?? 'manual_event');
        $visibility = sanitize_text_field($_POST['visibility'] ?? 'private');
        if (!in_array($visibility, ['private','public'], true)) $visibility = 'private';
        $start = sanitize_text_field($_POST['start_at'] ?? '');
        $end   = sanitize_text_field($_POST['end_at'] ?? '');
        $note  = sanitize_textarea_field($_POST['note'] ?? '');

        $tz = wp_timezone();
        try {
            $startDt = new DateTimeImmutable($start, $tz);
            $endDt = new DateTimeImmutable($end, $tz);
        } catch (Exception $e) {
            echo '<div class="error"><p>Invalid dates.</p></div>';
            $startDt = null; $endDt = null;
        }

        if ($startDt && $endDt && $endDt > $startDt) {
            $res = roxy_eb_repo_insert_block([
                'title' => $title,
                'type' => $type,
                'visibility' => $visibility,
                'note' => $note ?: null,
                'start_at' => roxy_eb_datetime_to_mysql($startDt),
                'end_at' => roxy_eb_datetime_to_mysql($endDt),
                'created_by' => get_current_user_id() ?: null,
            ]);
            if (is_wp_error($res)) {
                echo '<div class="error"><p>Could not create block: ' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="updated"><p>Block added.</p></div>';
            }
        }
    }

    // Handle delete block
    if (isset($_GET['delete']) && isset($_GET['_wpnonce'])) {
        $id = intval($_GET['delete']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'roxy_eb_del_block_' . $id)) {
            $res = roxy_eb_repo_delete_block($id);
            if (is_wp_error($res)) {
                echo '<div class="error"><p>Could not delete: ' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="updated"><p>Block deleted.</p></div>';
            }
        }
    }

    // List blocks upcoming 90 days
    $tz = wp_timezone();
    $start = (new DateTimeImmutable('now', $tz))->modify('-7 days');
    $end   = (new DateTimeImmutable('now', $tz))->modify('+90 days');

    $rows = roxy_eb_repo_list_blocks_in_range(roxy_eb_datetime_to_mysql($start), roxy_eb_datetime_to_mysql($end));
    ?>
    <div class="wrap">
        <h1>Calendar Blocks</h1>
        <p>Add manual blocks for scheduled theater events (these block bookings). This is separate from the fixed Friday/Saturday/Sunday showtime blocks.</p>

        <h2>Add block</h2>
        <form method="post">
            <?php wp_nonce_field('roxy_eb_add_block'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Title</th><td><input type="text" name="title" class="regular-text" required /></td></tr>
                <tr><th scope="row">Type</th>
                    <td>
                        <select name="type">
                            <option value="manual_event">Scheduled Event</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="hold">Hold</option>
                        </select>
                    </td>
                </tr>
                <tr><th scope="row">Visibility</th>
                    <td>
                        <select name="visibility">
                            <option value="private">Private</option>
                            <option value="public">Public</option>
                        </select>
                    </td>
                </tr>
                <tr><th scope="row">Start</th><td><input type="datetime-local" name="start_at" required /></td></tr>
                <tr><th scope="row">End</th><td><input type="datetime-local" name="end_at" required /></td></tr>
                <tr><th scope="row">Note (optional)</th><td><textarea name="note" rows="3" class="large-text"></textarea></td></tr>
            </table>
            <p class="submit"><button class="button button-primary" type="submit" name="roxy_eb_add_block" value="1">Add Block</button></p>
        </form>

        <h2>Upcoming blocks</h2>
        <table class="widefat striped">
            <thead><tr><th>Title</th><th>Start</th><th>End</th><th>Type</th><th>Visibility</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6">No blocks found.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r['title']); ?></td>
                        <td><?php echo esc_html($r['start_at']); ?></td>
                        <td><?php echo esc_html($r['end_at']); ?></td>
                        <td><?php echo esc_html($r['type']); ?></td>
                        <td><?php echo esc_html($r['visibility'] ?? 'private'); ?></td>
                        <td>
                            <?php
                                $url = add_query_arg([
                                    'page' => 'roxy-eb-blocks',
                                    'delete' => intval($r['id']),
                                    '_wpnonce' => wp_create_nonce('roxy_eb_del_block_' . intval($r['id'])),
                                ], admin_url('admin.php'));
                            ?>
                            <a class="button" href="<?php echo esc_url($url); ?>" onclick="return confirm('Delete this block?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function roxy_eb_admin_bookings_page() {
    if (!current_user_can('manage_options')) return;


    // Admin retry Sling sync action
    if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'retry_sling' && isset($_GET['booking_id'])) {
        $booking_id = intval($_GET['booking_id']);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'roxy_eb_admin_retry_sling_' . $booking_id)) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        } else {
            roxy_eb_sling_enqueue_sync($booking_id, 'manual_retry');
            echo '<div class="notice notice-success"><p>Sling sync queued. Refresh this page in a few seconds and check Sling Logs.</p></div>';
        }
    }

    // Admin cancel action
    if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'cancel' && isset($_GET['booking_id'])) {
        $booking_id = intval($_GET['booking_id']);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'roxy_eb_admin_cancel_' . $booking_id)) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        } else {
            $res = roxy_eb_cancel_booking($booking_id, 'admin');
            if (is_wp_error($res)) {
                echo '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Booking cancelled.</p></div>';
            }
        }
    }

    
// Admin edit/update action
if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'update' && isset($_GET['booking_id'])) {
    $booking_id = intval($_GET['booking_id']);
    $nonce = sanitize_text_field($_POST['_wpnonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'roxy_eb_admin_edit_' . $booking_id)) {
        echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
    } else {
        $booking_before = roxy_eb_repo_get_booking($booking_id);
        if (!$booking_before) {
            echo '<div class="notice notice-error"><p>Booking not found.</p></div>';
        } else if (($booking_before['status'] ?? '') === 'cancelled') {
            echo '<div class="notice notice-error"><p>Cancelled bookings cannot be edited.</p></div>';
        } else {
            $tz = wp_timezone();
            $date = sanitize_text_field($_POST['doors_open_date'] ?? '');
            $time = sanitize_text_field($_POST['doors_open_time'] ?? '');
            $guest_count = max(1, intval($_POST['guest_count'] ?? 1));
            $duration_hours = max(2, intval($_POST['duration_hours'] ?? (2 + intval($booking_before['extra_hours']))));
            $sling_status = sanitize_text_field($_POST['sling_status'] ?? ($booking_before['sling_status'] ?? 'unscheduled'));
            if (!in_array($sling_status, ['unscheduled','scheduled','manual','error'], true)) {
                $sling_status = 'unscheduled';
            }
            $notes_admin = sanitize_textarea_field($_POST['notes_admin'] ?? ($booking_before['notes_admin'] ?? ''));
            $send_email = !empty($_POST['email_customer']);

            // Parse doors open datetime
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
            if (!$dt) {
                echo '<div class="notice notice-error"><p>Invalid date/time.</p></div>';
            } else {
                $extra_hours = max(0, $duration_hours - 2);
                $times = roxy_eb_calc_times($dt, $extra_hours);

                // Conflict check (ignore current booking)
                $ok = roxy_eb_is_slot_available($times['reserved_start'], $times['reserved_end'], $booking_id);
                if (!$ok) {
                    echo '<div class="notice notice-error"><p>That time conflicts with another booking or blocked event.</p></div>';
                } else {
                    // Tier + staff shifts based on guest count
                    $tier = ($guest_count <= 25) ? 'under_25' : 'over_26';
                    $shifts = ($guest_count <= 25) ? 1 : 2;

                    $update = [
                        'guest_count' => $guest_count,
                        'tier' => $tier,
                        'staff_shifts_required' => $shifts,
                        'extra_hours' => $extra_hours,
                        'doors_open_at' => roxy_eb_datetime_to_mysql($dt),
                        'show_start_at' => roxy_eb_datetime_to_mysql($times['show_start']),
                        'doors_close_at' => roxy_eb_datetime_to_mysql($times['doors_close']),
                        'reserved_start_at' => roxy_eb_datetime_to_mysql($times['reserved_start']),
                        'reserved_end_at' => roxy_eb_datetime_to_mysql($times['reserved_end']),
                        'sling_status' => $sling_status,
                        'notes_admin' => $notes_admin,
                    ];

                    $res = roxy_eb_repo_update_booking($booking_id, $update);
                    if (is_wp_error($res)) {
                        echo '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
                    } else {
                        $booking_after = roxy_eb_repo_get_booking($booking_id);
                        echo '<div class="notice notice-success"><p>Booking updated.</p></div>';

                        // If Sling automation is enabled and this booking isn't manual, enqueue a sync.
                        if ($booking_after && ($booking_after['sling_status'] ?? '') !== 'manual' && function_exists('roxy_eb_sling_enqueue_sync')) {
                            $settings = roxy_eb_get_settings();
                            if (($settings['sling_mode'] ?? 'disabled') !== 'disabled') {
                                roxy_eb_sling_enqueue_sync($booking_id, 'admin_edit');
                            }
                        }

                        if ($send_email && $booking_after) {
                            roxy_eb_email_customer_booking_updated($booking_before, $booking_after);
                            echo '<div class="notice notice-info"><p>Email sent to customer.</p></div>';
                        }
                    }
                }
            }
        }
    }
}

// Simple list of upcoming bookings
    $tz = wp_timezone();
    $start = (new DateTimeImmutable('now', $tz))->modify('-30 days');
    // Expanded to 1 year forward so far-future bookings (e.g., November) show up here.
    $end   = (new DateTimeImmutable('now', $tz))->modify('+365 days');

    $rows = roxy_eb_repo_list_bookings_in_range(roxy_eb_datetime_to_mysql($start), roxy_eb_datetime_to_mysql($end));

    ?>
    <div class="wrap">
        <h1>Bookings</h1>
        <p>Confirmed bookings and reserved windows (the backend reserved time is what blocks conflicts).</p>
<?php
// Edit form
if (isset($_GET['roxy_eb_action']) && $_GET['roxy_eb_action'] === 'edit' && isset($_GET['booking_id'])):
    $edit_id = intval($_GET['booking_id']);
    $b = roxy_eb_repo_get_booking($edit_id);
    if ($b):
        $tz = wp_timezone();
        $doorsOpen = roxy_eb_mysql_to_dt($b['doors_open_at']);
        $dateVal = $doorsOpen ? $doorsOpen->setTimezone($tz)->format('Y-m-d') : '';
        $timeVal = $doorsOpen ? $doorsOpen->setTimezone($tz)->format('H:i') : '';
        $durVal = 2 + intval($b['extra_hours']);
?>
    <div class="card" style="max-width:900px; padding:16px; margin:12px 0;">
        <h2 style="margin-top:0;">Edit Booking #<?php echo esc_html($edit_id); ?></h2>
        <form method="post" action="<?php echo esc_url(add_query_arg(['page'=>'roxy-eb','roxy_eb_action'=>'update','booking_id'=>$edit_id], admin_url('admin.php'))); ?>">
            <?php wp_nonce_field('roxy_eb_admin_edit_' . $edit_id); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="doors_open_date">Date</label></th>
                    <td><input type="date" id="doors_open_date" name="doors_open_date" value="<?php echo esc_attr($dateVal); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="doors_open_time">Doors open time</label></th>
                    <td><input type="time" id="doors_open_time" name="doors_open_time" value="<?php echo esc_attr($timeVal); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="guest_count">Guests</label></th>
                    <td><input type="number" min="1" max="250" id="guest_count" name="guest_count" value="<?php echo esc_attr(intval($b['guest_count'])); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="duration_hours">Duration (hours)</label></th>
                    <td>
                        <select id="duration_hours" name="duration_hours">
                            <?php for ($h=2; $h<=8; $h++): ?>
                                <option value="<?php echo esc_attr($h); ?>" <?php selected($durVal, $h); ?>><?php echo esc_html($h); ?> hours</option>
                            <?php endfor; ?>
                        </select>
                        <p class="description">This updates doors close time and the backend reserved window.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sling_status">Sling status</label></th>
                    <td>
                        <select id="sling_status" name="sling_status">
                            <?php
                                $cur = $b['sling_status'] ?: 'unscheduled';
                                // Back-compat: older builds used 'ok'.
                                if ($cur === 'ok') { $cur = 'scheduled'; }
                                $opts = ['unscheduled'=>'Unscheduled','scheduled'=>'Scheduled','manual'=>'Manual','error'=>'Error'];
                                foreach ($opts as $k=>$label):
                            ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($cur, $k); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Default is “scheduled” when automation creates shifts, otherwise “unscheduled”. Set “manual” if you handled Sling outside automation.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="notes_admin">Event notes</label></th>
                    <td>
                        <textarea id="notes_admin" name="notes_admin" rows="3" style="width: 420px; max-width: 100%;"><?php echo esc_textarea($b['notes_admin'] ?? ''); ?></textarea>
                        <p class="description">Shared notes (included on the Sling shift and customer confirmation).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Notify customer</th>
                    <td>
                        <label><input type="checkbox" name="email_customer" value="1"> Email customer about this change</label>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary">Save changes</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=roxy-eb')); ?>">Done</a>
            </p>
        </form>
    </div>
<?php endif; endif; ?>


        <table class="widefat striped">
            <thead><tr>
                <th>Doors Open</th>
                <th>Customer</th>
                <th>Guests</th>
                <th>Duration</th>
                <th>Status</th>
                <th>Order</th>
                <th>Sling</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8">No bookings found.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <?php
                        $booking = roxy_eb_repo_get_booking($r['id']);
                        $dur = 2 + intval($booking['extra_hours']);
                        $orderLink = $booking['woo_order_id'] ? admin_url('post.php?post=' . intval($booking['woo_order_id']) . '&action=edit') : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html($booking['doors_open_at']); ?></td>
                        <td><?php echo esc_html($booking['customer_first_name'] . ' ' . $booking['customer_last_name']); ?><br/><small><?php echo esc_html($booking['customer_email']); ?></small></td>
                        <td><?php echo esc_html(intval($booking['guest_count'])); ?></td>
                        <td><?php echo esc_html($dur . 'h'); ?></td>
                        <td><?php echo esc_html($booking['status']); ?></td>
                        <td><?php if ($orderLink): ?><a href="<?php echo esc_url($orderLink); ?>">#<?php echo esc_html(intval($booking['woo_order_id'])); ?></a><?php else: ?>—<?php endif; ?></td>
                        <td>
                          <?php
                            $ss = $booking['sling_status'] ?? '';
                            // Back-compat: older builds used 'ok'.
                            if ($ss === 'ok') { $ss = 'scheduled'; }
                            $label = '—';
                            if ($ss === 'unscheduled') $label = 'Unscheduled';
                            elseif ($ss === 'scheduled') $label = 'Scheduled';
                            elseif ($ss === 'manual') $label = 'Manual';
                            elseif ($ss === 'failed') $label = 'Failed';
                            elseif ($ss) $label = $ss;
                            echo esc_html($label);
                          ?>
                        </td>
                        <td>
                            <?php if ($booking['status'] === 'confirmed'): ?>
                                <?php
                                    $editUrl = add_query_arg([
                                        'page' => 'roxy-eb',
                                        'roxy_eb_action' => 'edit',
                                        'booking_id' => intval($booking['id']),
                                    ], admin_url('admin.php'));
                                ?>
                                <a class="button" href="<?php echo esc_url($editUrl); ?>">Edit</a>

                                <?php
                                    // NOTE: The admin menu slug for this plugin is `roxy-eb`.
                                    // If we link to a non-existent slug, WordPress will show
                                    // "Sorry, you are not allowed to access this page." even
                                    // for Administrators.
                                    $cancelUrl = add_query_arg([
                                        'page' => 'roxy-eb',
                                        'roxy_eb_action' => 'cancel',
                                        'booking_id' => intval($booking['id']),
                                        '_wpnonce' => wp_create_nonce('roxy_eb_admin_cancel_' . intval($booking['id'])),
                                    ], admin_url('admin.php'));
                                ?>
                                <a class="button" href="<?php echo esc_url($cancelUrl); ?>" onclick="return confirm('Cancel this booking? This will also attempt a refund if possible.');">Cancel</a>
                                <?php if (($ss === 'error' || $ss === 'failed')): ?>
                                <?php
                                    $retryUrl = add_query_arg([
                                        'page' => 'roxy-eb',
                                        'roxy_eb_action' => 'retry_sling',
                                        'booking_id' => intval($booking['id']),
                                        '_wpnonce' => wp_create_nonce('roxy_eb_admin_retry_sling_' . intval($booking['id'])),
                                    ], admin_url('admin.php'));
                                ?>
                                <a class="button" href="<?php echo esc_url($retryUrl); ?>">Retry Sling Sync</a>
                                <?php endif; ?>

                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <p style="margin-top:16px;">
            Need to block out time for a special event? Use <a href="<?php echo esc_url(admin_url('admin.php?page=roxy-eb-blocks')); ?>">Calendar Blocks</a>.
        </p>
    </div>
    <?php
}


function roxy_eb_admin_sling_logs_page() {
    if (!current_user_can('manage_options')) return;
    $booking_filter = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    $rows = roxy_eb_repo_list_sling_logs(200, $booking_filter);
    ?>
    <div class="wrap">
        <h1>Sling Logs</h1>
        <p class="description">Most recent Sling API attempts from Roxy Event Booking. Use this to troubleshoot shift automation.</p>
        <form method="get" style="margin: 12px 0;">
            <input type="hidden" name="page" value="roxy-eb-sling-logs" />
            <label>Booking ID: <input type="number" name="booking_id" value="<?php echo esc_attr($booking_filter ?: ''); ?>" min="0" /></label>
            <button class="button">Filter</button>
            <?php if ($booking_filter): ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=roxy-eb-sling-logs')); ?>">Clear</a>
            <?php endif; ?>
        </form>
        <table class="widefat striped">
            <thead><tr>
                <th>Time</th>
                <th>Booking</th>
                <th>Action</th>
                <th>Endpoint</th>
                <th>HTTP</th>
                <th>Message</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6">No logs found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><code><?php echo esc_html($r['created_at']); ?></code></td>
                    <td><?php echo $r['booking_id'] ? '<a href="' . esc_url(admin_url('admin.php?page=roxy-eb&booking_id=' . intval($r['booking_id']))) . '">' . intval($r['booking_id']) . '</a>' : ''; ?></td>
                    <td><?php echo esc_html($r['action']); ?></td>
                    <td><code><?php echo esc_html($r['endpoint']); ?></code></td>
                    <td><?php echo esc_html($r['http_code']); ?></td>
                    <td><?php echo esc_html($r['message']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p class="description">Note: request/response bodies are stored (sanitized) in the database for deeper debugging.</p>
    </div>
    <?php
}
