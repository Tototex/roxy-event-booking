<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_booking_meta_key() { return '_roxy_eb_booking'; }

/**
 * Ensure WooCommerce core and cart/session are available for frontend AJAX flows.
 * Some setups do not initialize the cart on admin-ajax requests by default.
 */
function roxy_eb_wc_ready(): bool {
    // Detect Woo in a resilient way
    if (!function_exists('WC') && !class_exists('WooCommerce') && !defined('WC_VERSION')) {
        return false;
    }
    if (!function_exists('WC')) {
        // Woo should define WC(); if not, we can't reliably proceed.
        return false;
    }

    // Load cart helpers if missing
    if (!function_exists('wc_load_cart') && defined('WC_ABSPATH')) {
        $cartFns = WC_ABSPATH . 'includes/wc-cart-functions.php';
        $noticeFns = WC_ABSPATH . 'includes/wc-notice-functions.php';
        if (file_exists($cartFns)) include_once $cartFns;
        if (file_exists($noticeFns)) include_once $noticeFns;
    }

    // Ensure session/cart exist
    try {
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
    } catch (Throwable $e) {
        // Fall through and let checks below decide
    }

    $wc = WC();
    if (!$wc) return false;

    // Some environments may still not create cart; try initialize_cart if available.
    if (empty($wc->cart) && method_exists($wc, 'initialize_cart')) {
        try { $wc->initialize_cart(); } catch (Throwable $e) {}
    }

    return !empty(WC()->cart);
}


function roxy_eb_register_woo_hooks() {
    // Frontend submit handler
    add_action('wp_ajax_roxy_eb_start_booking', 'roxy_eb_ajax_start_booking');
    add_action('wp_ajax_nopriv_roxy_eb_start_booking', 'roxy_eb_ajax_start_booking');

    // Validate cart item
    add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id) {
        // We add cart item directly in AJAX; nothing here.
        return $cart_item_data;
    }, 10, 3);

    add_filter('woocommerce_get_item_data', 'roxy_eb_display_cart_item_meta', 10, 2);

    add_action('woocommerce_checkout_create_order_line_item', 'roxy_eb_add_order_item_meta', 10, 4);

    add_action('woocommerce_before_calculate_totals', 'roxy_eb_apply_dynamic_price', 20, 1);

    add_action('woocommerce_checkout_process', 'roxy_eb_validate_checkout_slot');

    add_action('woocommerce_payment_complete', 'roxy_eb_on_payment_complete', 10, 1);

    add_action('woocommerce_thankyou', 'roxy_eb_thankyou_booking_details', 20, 1);

    // If an order is refunded, cancel the related booking so the slot opens up again.
    add_action('woocommerce_order_refunded', 'roxy_eb_on_order_refunded', 10, 2);
    add_action('woocommerce_order_status_refunded', 'roxy_eb_on_order_status_refunded', 10, 2);

    // Hide internal/legacy booking JSON from item meta display.
    add_filter('woocommerce_order_item_get_formatted_meta_data', 'roxy_eb_filter_formatted_item_meta', 10, 2);
}

function roxy_eb_filter_formatted_item_meta($formatted_meta, $item) {
    if (empty($formatted_meta) || !is_array($formatted_meta)) return $formatted_meta;

    foreach ($formatted_meta as $k => $meta) {
        $key = is_object($meta) && isset($meta->key) ? (string)$meta->key : '';
        if ($key === 'Roxy Booking Data' || $key === '_roxy_eb_booking') {
            unset($formatted_meta[$k]);
        }
    }
    return $formatted_meta;
}

function roxy_eb_on_order_status_refunded($order_id, $order = null) {
    roxy_eb_maybe_cancel_booking_for_order($order_id);
}

function roxy_eb_on_order_refunded($order_id, $refund_id) {
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (!$order) return;

    // Only cancel when the order is effectively fully refunded.
    // (Avoid canceling reservations on partial refunds.)
    $total          = (float) $order->get_total();
    $refunded_total = (float) $order->get_total_refunded();
    $is_fully_refunded = ($order->has_status('refunded') || ($total > 0 && ($refunded_total + 0.0001) >= $total));
    if (!$is_fully_refunded) return;

    roxy_eb_maybe_cancel_booking_for_order($order_id);
}

function roxy_eb_maybe_cancel_booking_for_order($order_id) {
    $order_id = intval($order_id);
    if ($order_id <= 0) return;

    if (!function_exists('roxy_eb_repo_get_booking_by_order')) return;
    $booking = roxy_eb_repo_get_booking_by_order($order_id);
    if (!$booking) return;
    if (($booking['status'] ?? '') !== 'confirmed') return;

    // Cancel without enforcing customer lead time (this is tied to payment/refund state).
    if (function_exists('roxy_eb_cancel_booking')) {
        roxy_eb_cancel_booking(intval($booking['id']), 'admin');
    } else {
        roxy_eb_repo_update_booking(intval($booking['id']), ['status' => 'cancelled']);
    }
}

function roxy_eb_display_cart_item_meta($item_data, $cart_item) {
    if (empty($cart_item[roxy_eb_booking_meta_key()])) return $item_data;
    $b = $cart_item[roxy_eb_booking_meta_key()];
    $item_data[] = ['name' => 'Doors open', 'value' => esc_html($b['doors_open_local'] ?? '')];
    $item_data[] = ['name' => 'Duration', 'value' => esc_html(($b['guest_hours'] ?? '') . ' hours')];
    $item_data[] = ['name' => 'Guests', 'value' => esc_html($b['guest_count'] ?? '')];
    $item_data[] = ['name' => 'Type', 'value' => esc_html(ucfirst($b['event_format'] ?? ''))];
    $item_data[] = ['name' => 'Visibility', 'value' => esc_html(ucfirst($b['visibility'] ?? ''))];
    if (!empty($b['notes'])) {
        $item_data[] = ['name' => 'Notes', 'value' => esc_html($b['notes'])];
    }
    return $item_data;
}

function roxy_eb_add_order_item_meta($item, $cart_item_key, $values, $order) {
    if (empty($values[roxy_eb_booking_meta_key()])) return;
    $b = $values[roxy_eb_booking_meta_key()];

    // Store full booking payload under a hidden meta key (used for processing/integrations).
    // Leading underscore keeps it hidden from most customer-facing views.
    $item->add_meta_data('_roxy_eb_booking', wp_json_encode($b), true);

    // Customer/admin-friendly receipt fields (no JSON blob).
    $doors_open = $b['doors_open_local'] ?? '';
    $show_start = !empty($b['show_start_at'])
        ? date_i18n('l, F j, Y g:i A', strtotime($b['show_start_at']))
        : '';
    $duration = !empty($b['guest_hours']) ? intval($b['guest_hours']) . ' hours' : '';
    $guests = isset($b['guest_count']) ? intval($b['guest_count']) : '';
    $visibility = !empty($b['visibility']) ? ucfirst(sanitize_text_field($b['visibility'])) : '';
    $type = !empty($b['event_format']) ? ucfirst(sanitize_text_field($b['event_format'])) : '';
    $movie = !empty($b['movie_title']) ? sanitize_text_field($b['movie_title']) : '';
    $notes = !empty($b['notes']) ? sanitize_textarea_field($b['notes']) : '';

    $item->add_meta_data('Doors open', $doors_open, true);
    if (!empty($show_start)) $item->add_meta_data('Show starts', $show_start, true);
    if (!empty($duration)) $item->add_meta_data('Duration', $duration, true);
    if ($guests !== '') $item->add_meta_data('Guests', (string)$guests, true);
    if (!empty($type)) $item->add_meta_data('Type', $type, true);
    if (!empty($visibility)) $item->add_meta_data('Visibility', $visibility, true);
    if (!empty($movie) && strtolower($type) === 'movie') $item->add_meta_data('Movie title', $movie, true);
    if (!empty($notes)) $item->add_meta_data('Notes', $notes, true);
}

function roxy_eb_apply_dynamic_price($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!$cart || $cart->is_empty()) return;

    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    foreach ($cart->get_cart() as $key => $item) {
        if (intval($item['product_id']) !== $pid) continue;
        if (empty($item[roxy_eb_booking_meta_key()])) continue;
        $b = $item[roxy_eb_booking_meta_key()];
        $total = floatval($b['total_price'] ?? 0);
        $item['data']->set_price($total);
    }
}

function roxy_eb_validate_checkout_slot() {
    if (!roxy_eb_wc_ready()) return;
    $cart = WC()->cart;
    if (!$cart || $cart->is_empty()) return;

    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    foreach ($cart->get_cart() as $item) {
        if (intval($item['product_id']) !== $pid) continue;
        $b = $item[roxy_eb_booking_meta_key()] ?? null;
        if (!$b) {
            wc_add_notice('Booking data missing. Please start your booking again.', 'error');
            return;
        }

        $tz = wp_timezone();
        try {
            $doorsOpen = new DateTimeImmutable($b['doors_open_at'], $tz);
            $extraHours = intval($b['extra_hours']);
        } catch (Exception $e) {
            wc_add_notice('Invalid booking time. Please start your booking again.', 'error');
            return;
        }

        $calc = roxy_eb_calc_times($doorsOpen, $extraHours);
        if (!roxy_eb_check_lead_time($doorsOpen)) {
            wc_add_notice('Bookings must be made at least 48 hours in advance. Please contact us for sooner bookings.', 'error');
            return;
        }
        if (!roxy_eb_time_within_operating_hours($doorsOpen, intval($calc['guest_hours']))) {
            wc_add_notice('Selected time is outside operating hours.', 'error');
            return;
        }

        if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'])) {
            wc_add_notice('Sorry — that time is no longer available. Please pick another slot.', 'error');
            return;
        }

        // Validate required contact fields are present in checkout billing
        $email = WC()->checkout()->get_value('billing_email');
        $phone = WC()->checkout()->get_value('billing_phone');
        if (empty($email) || empty($phone)) {
            wc_add_notice('Email and phone are required for event bookings.', 'error');
            return;
        }
    }
}

function roxy_eb_on_payment_complete($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    // Avoid duplicate creation
    $existing = roxy_eb_repo_get_booking_by_order($order_id);
    if ($existing) return;

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if (intval($product_id) !== $pid) continue;

        // New versions store booking JSON in a hidden key; keep a fallback for older orders.
        $raw = $item->get_meta('_roxy_eb_booking', true);
        if (empty($raw)) {
            $raw = $item->get_meta('Roxy Booking Data', true);
        }
        $b = json_decode((string)$raw, true);
        if (!is_array($b)) continue;

        $tz = wp_timezone();
        $doorsOpen = new DateTimeImmutable($b['doors_open_at'], $tz);
        $extraHours = intval($b['extra_hours']);
        $calc = roxy_eb_calc_times($doorsOpen, $extraHours);

        // One last availability check (atomic-ish). If conflict, refund + notify.
        if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'])) {
            // Attempt refund
            roxy_eb_handle_conflict_refund($order, $b);
            return;
        }

        $guestCount = intval($b['guest_count']);
        $tier = ($guestCount <= 25) ? 'under_26' : 'over_25';
        $shifts = ($guestCount <= 25) ? 1 : 2;

        $booking_id = roxy_eb_repo_insert_booking([
            'status' => 'confirmed',
            'wp_user_id' => $order->get_user_id() ?: null,
            'customer_first_name' => sanitize_text_field($b['first_name'] ?? $order->get_billing_first_name()),
            'customer_last_name' => sanitize_text_field($b['last_name'] ?? $order->get_billing_last_name()),
            'customer_email' => sanitize_email($order->get_billing_email()),
            'customer_phone' => sanitize_text_field($order->get_billing_phone()),
            'guest_count' => $guestCount,
            'tier' => $tier,
            'staff_shifts_required' => $shifts,
            'event_format' => sanitize_text_field($b['event_format']),
            'movie_title' => !empty($b['movie_title']) ? sanitize_text_field($b['movie_title']) : null,
            'live_description' => !empty($b['live_description']) ? sanitize_textarea_field($b['live_description']) : null,
            'visibility' => sanitize_text_field($b['visibility']),
            // Shared customer/admin notes (also sent to Sling)
            'notes_admin' => !empty($b['notes']) ? sanitize_textarea_field($b['notes']) : null,
            'doors_open_at' => roxy_eb_datetime_to_mysql($doorsOpen),
            'show_start_at' => roxy_eb_datetime_to_mysql($calc['show_start']),
            'doors_close_at' => roxy_eb_datetime_to_mysql($calc['doors_close']),
            'reserved_start_at' => roxy_eb_datetime_to_mysql($calc['reserved_start']),
            'reserved_end_at' => roxy_eb_datetime_to_mysql($calc['reserved_end']),
            'extra_hours' => $extraHours,
            'base_price' => intval($b['base_price']),
            'extra_price' => intval($b['extra_price']),
            'total_price' => intval($b['total_price']),
            'woo_order_id' => $order_id,
            // Default to UNSCHEDULED until we actually create a Sling shift.
            'sling_status' => 'unscheduled',
        ]);

        if (is_wp_error($booking_id)) {
            $order->add_order_note('Roxy booking creation failed: ' . $booking_id->get_error_message());
            roxy_eb_email_internal_booking_failed($order, $booking_id->get_error_message());
            return;
        }

        // Send internal confirmation email + customer confirmation email
        roxy_eb_email_internal_booking_confirmed($order, $booking_id);
        roxy_eb_email_customer_booking_confirmed($order, $booking_id);

        // Sling automation (async best-effort)
        $settings = roxy_eb_get_settings();
        $sling_mode = $settings['sling_mode'] ?? 'disabled';
        if ($sling_mode !== 'disabled' && function_exists('roxy_eb_sling_enqueue_sync')) {
            // Booking remains "unscheduled" until the async job succeeds.
            roxy_eb_sling_enqueue_sync($booking_id, 'payment_complete');
        }

        return; // only one booking item expected
    }
}

function roxy_eb_handle_conflict_refund($order, $b) {
    // Mark note, attempt refund, email internal.
    $order->add_order_note('Roxy booking conflict: slot became unavailable at payment completion.');
    try {
        $amount = floatval($b['total_price'] ?? $order->get_total());
        wc_create_refund([
            'amount'   => $amount,
            'reason'   => 'Booking time no longer available',
            'order_id' => $order->get_id(),
        ]);
        $order->add_order_note('Refunded due to booking conflict.');
    } catch (Exception $e) {
        $order->add_order_note('Refund attempt failed: ' . $e->getMessage());
    }
    roxy_eb_email_internal_booking_conflict($order);
}

function roxy_eb_thankyou_booking_details($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) return;

    $has = false;
    foreach ($order->get_items() as $item) {
        if (intval($item->get_product_id()) === $pid) { $has = true; break; }
    }
    if (!$has) return;

    $booking = roxy_eb_repo_get_booking_by_order($order_id);
    echo '<section class="roxy-eb-thankyou" style="margin-top:24px;">';
    echo '<h2>Your Roxy Booking</h2>';

    if (!$booking) {
        echo '<p>Your booking is processing. If you do not receive a confirmation email shortly, please contact us.</p>';
        echo '</section>';
        return;
    }

    $doors = esc_html($booking['doors_open_at']);
    $show  = esc_html($booking['show_start_at']);
    $duration = intval($booking['extra_hours']) + 2;
    $guests = intval($booking['guest_count']);
    $type = esc_html(ucfirst($booking['event_format']));
    $vis  = esc_html(ucfirst($booking['visibility']));
    echo '<ul>';
    echo '<li><strong>Doors open:</strong> ' . $doors . '</li>';
    echo '<li><strong>Show starts:</strong> ' . $show . ' (about 30 minutes after doors)</li>';
    echo '<li><strong>Duration:</strong> ' . esc_html($duration) . ' hours</li>';
    echo '<li><strong>Guests:</strong> ' . esc_html($guests) . '</li>';
    echo '<li><strong>Type:</strong> ' . $type . '</li>';
    echo '<li><strong>Visibility:</strong> ' . $vis . '</li>';
    if ($booking['event_format'] === 'movie' && !empty($booking['movie_title'])) {
        echo '<li><strong>Movie title:</strong> ' . esc_html($booking['movie_title']) . '</li>';
    }
    if ($booking['event_format'] === 'live' && !empty($booking['live_description'])) {
        echo '<li><strong>Live event details:</strong> ' . esc_html($booking['live_description']) . '</li>';
    }
    if (!empty($booking['notes_admin'])) {
        echo '<li><strong>Notes:</strong> ' . nl2br(esc_html($booking['notes_admin'])) . '</li>';
    }
    echo '</ul>';
    echo '<p><a href="' . esc_url(wc_get_account_endpoint_url('roxy-bookings')) . '">View or manage your bookings</a></p>';
    echo '</section>';
}

function roxy_eb_ajax_start_booking() {
    if (!roxy_eb_wc_ready()) wp_send_json_error(['message' => 'WooCommerce is required.']);
$settings = roxy_eb_get_settings();
    $pid = intval($settings['booking_product_id'] ?? 0);
    if ($pid <= 0) wp_send_json_error(['message' => 'Booking product not configured.']);

    $payload = isset($_POST['booking']) ? (array) $_POST['booking'] : [];
    $errors = [];

    $first = sanitize_text_field($payload['first_name'] ?? '');
    $last  = sanitize_text_field($payload['last_name'] ?? '');
    $email = sanitize_email($payload['email'] ?? '');
    $phone = sanitize_text_field($payload['phone'] ?? '');

    if (empty($first)) $errors[] = 'First name is required.';
    if (empty($last))  $errors[] = 'Last name is required.';
    if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required.';
    if (empty($phone)) $errors[] = 'Phone number is required.';

    $guest_count = intval($payload['guest_count'] ?? 0);
    $guest_cap = intval($settings['guest_cap'] ?? 250);
    if ($guest_count < 1) $errors[] = 'Guest count is required.';
    if ($guest_count > $guest_cap) $errors[] = 'Guest count is invalid.';

    $event_format = sanitize_text_field($payload['event_format'] ?? 'movie');
    if (!in_array($event_format, ['movie','live'], true)) $errors[] = 'Invalid event format.';

    $visibility = sanitize_text_field($payload['visibility'] ?? 'private');
    if (!in_array($visibility, ['private','public'], true)) $errors[] = 'Invalid visibility setting.';

    $movie_title = sanitize_text_field($payload['movie_title'] ?? '');
    $live_desc   = sanitize_textarea_field($payload['live_description'] ?? '');
    $notes       = sanitize_textarea_field($payload['notes'] ?? '');

    if ($event_format === 'movie' && empty($movie_title)) $errors[] = 'Movie title is required.';
    if ($event_format === 'live' && empty($live_desc)) $errors[] = 'Live event description is required.';

    $extra_hours = max(0, intval($payload['extra_hours'] ?? 0));

    // Doors open datetime is provided as ISO local string
    $doors_open = sanitize_text_field($payload['doors_open_at'] ?? '');
    $tz = wp_timezone();
    try {
        $doorsOpen = new DateTimeImmutable($doors_open, $tz);
    } catch (Exception $e) {
        $errors[] = 'Invalid start time.';
        $doorsOpen = null;
    }

    if (!empty($errors)) wp_send_json_error(['message' => implode(' ', $errors)]);

    $calc = roxy_eb_calc_times($doorsOpen, $extra_hours);

    // Enforce 15-min increments
    $inc = intval($settings['time_increment_minutes'] ?? 15);
    $minute = intval($doorsOpen->format('i'));
    if (($minute % $inc) !== 0) wp_send_json_error(['message' => 'Start times must be in ' . $inc . '-minute increments.']);

    if (!roxy_eb_check_lead_time($doorsOpen)) {
        wp_send_json_error(['message' => 'Bookings must be made at least ' . intval($settings['lead_time_hours']) . ' hours in advance. Please contact us for sooner bookings.']);
    }
    if (!roxy_eb_time_within_operating_hours($doorsOpen, intval($calc['guest_hours']))) {
        wp_send_json_error(['message' => 'Selected time is outside operating hours.']);
    }
    if (!roxy_eb_is_slot_available($calc['reserved_start'], $calc['reserved_end'])) {
        wp_send_json_error(['message' => 'That time is not available.']);
    }

    // Price calc
    $base = ($guest_count <= 25) ? intval($settings['base_price_under']) : intval($settings['base_price_over']);
    $extra_price = $extra_hours * intval($settings['extra_hour_price']);
    $total = $base + $extra_price;

    // Clear existing booking product lines (sold individually, but just in case)
    $cart = WC()->cart;
    foreach ($cart->get_cart() as $key => $item) {
        if (intval($item['product_id']) === $pid) $cart->remove_cart_item($key);
    }

    $booking_data = [
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'phone' => $phone,
        'guest_count' => $guest_count,
        'event_format' => $event_format,
        'movie_title' => $event_format === 'movie' ? $movie_title : '',
        'live_description' => $event_format === 'live' ? $live_desc : '',
        'notes' => $notes,
        'visibility' => $visibility,
        'extra_hours' => $extra_hours,
        'guest_hours' => intval($calc['guest_hours']),
        'doors_open_at' => $doorsOpen->format('Y-m-d H:i:s'),
        'doors_open_local' => $doorsOpen->format('l, F j, Y g:i A'),
        'show_start_at' => $calc['show_start']->format('Y-m-d H:i:s'),
        'doors_close_at' => $calc['doors_close']->format('Y-m-d H:i:s'),
        'reserved_start_at' => $calc['reserved_start']->format('Y-m-d H:i:s'),
        'reserved_end_at' => $calc['reserved_end']->format('Y-m-d H:i:s'),
        'base_price' => $base,
        'extra_price' => $extra_price,
        'total_price' => $total,
    ];

    $cart_item_data = [ roxy_eb_booking_meta_key() => $booking_data ];
    $added = $cart->add_to_cart($pid, 1, 0, [], $cart_item_data);
    if (!$added) {
        wp_send_json_error(['message' => 'Could not start checkout. Please try again.']);
    }

    // Pre-fill checkout fields
    WC()->customer->set_billing_first_name($first);
    WC()->customer->set_billing_last_name($last);
    WC()->customer->set_billing_email($email);
    WC()->customer->set_billing_phone($phone);

    wp_send_json_success(['redirect' => wc_get_checkout_url()]);
}
