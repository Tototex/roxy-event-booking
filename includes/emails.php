<?php
if (!defined('ABSPATH')) exit;

function roxy_eb_email_internal_booking_confirmed($order, $booking_id) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;

    $subject = 'New Roxy Booking — ' . $booking['doors_open_at'] . ' — ' . $booking['customer_last_name'];
    $body = roxy_eb_render_email_booking_summary($booking, $order, true);

    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_customer_booking_confirmed($order, $booking_id) {
    $booking = roxy_eb_repo_get_booking($booking_id);
    if (!$booking) return;

    $to = $booking['customer_email'];
    $subject = 'Your Newport Roxy booking is confirmed — ' . date_i18n('M j, Y g:i A', strtotime($booking['doors_open_at']));
    $body = roxy_eb_render_email_booking_summary_html($booking, $order);

    wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
}

function roxy_eb_render_email_booking_summary_html($booking, $order) {
    $dur = 2 + intval($booking['extra_hours']);
    $account_url = 'https://newportroxy.com/my-account/roxy-bookings/';
    if (function_exists('wc_get_account_endpoint_url')) {
        $maybe = wc_get_account_endpoint_url('roxy-bookings');
        if (!empty($maybe)) $account_url = $maybe;
    }

    $rows = [];
    $rows[] = ['Doors open', date_i18n('l, F j, Y g:i A', strtotime($booking['doors_open_at']))];
    $rows[] = ['Show starts', date_i18n('l, F j, Y g:i A', strtotime($booking['show_start_at'])) . ' (about 30 minutes after doors)'];
    $rows[] = ['Doors close', date_i18n('l, F j, Y g:i A', strtotime($booking['doors_close_at']))];
    $rows[] = ['Duration', $dur . ' hours'];
    $rows[] = ['Guests', intval($booking['guest_count'])];
    $rows[] = ['Event format', ucfirst($booking['event_format'])];
    if ($booking['event_format'] === 'movie' && !empty($booking['movie_title'])) {
        $rows[] = ['Movie title', $booking['movie_title']];
    }
    if ($booking['event_format'] === 'live' && !empty($booking['live_description'])) {
        $rows[] = ['Live event', $booking['live_description']];
    }
    if (!empty($booking['notes_admin'])) {
        $rows[] = ['Notes', $booking['notes_admin']];
    }
    $rows[] = ['Visibility', ucfirst($booking['visibility'])];

    $base  = (float)($booking['base_price'] ?? 0);
    $extra = (float)($booking['extra_price'] ?? 0);
    $total = (float)($booking['total_price'] ?? 0);

    $pricing = [
        ['Base', '$' . number_format($base, 2)],
        ['Extra hours', '$' . number_format($extra, 2)],
        ['Total paid', '$' . number_format($total, 2)],
    ];

    $html  = '<div style="font-family: Arial, Helvetica, sans-serif; line-height:1.5; color:#111; max-width:640px; margin:0 auto;">';
    $html .= '<h2 style="margin:0 0 10px;">Booking confirmed!</h2>';
    $html .= '<p style="margin:0 0 18px;">Thanks for booking with the Newport Roxy Theater. Here are your details:</p>';

    $html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; margin:0 0 18px;">';
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td style="padding:8px 10px; border:1px solid #e5e5e5; background:#fafafa; width:38%;"><strong>' . esc_html($r[0]) . '</strong></td>'
            . '<td style="padding:8px 10px; border:1px solid #e5e5e5;">' . esc_html($r[1]) . '</td>'
            . '</tr>';
    }
    $html .= '</table>';

    $html .= '<h3 style="margin:18px 0 8px;">Pricing</h3>';
    $html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; margin:0 0 18px;">';
    foreach ($pricing as $p) {
        $html .= '<tr>'
            . '<td style="padding:8px 10px; border:1px solid #e5e5e5; background:#fafafa; width:38%;"><strong>' . esc_html($p[0]) . '</strong></td>'
            . '<td style="padding:8px 10px; border:1px solid #e5e5e5;">' . esc_html($p[1]) . '</td>'
            . '</tr>';
    }
    $html .= '</table>';

    $html .= '<p style="margin:0 0 14px;"><strong>Cancellation</strong><br>'
        . 'Free cancellation up to 7 days before your event. Within 7 days, please contact us to cancel.</p>';

    $html .= '<p style="margin:0 0 18px;">'
        . '<a href="' . esc_url($account_url) . '" style="display:inline-block; padding:10px 14px; background:#111; color:#fff; text-decoration:none; border-radius:6px;">Manage your booking here</a>'
        . '</p>';

    $html .= '<p style="margin:0; color:#555; font-size:12px;">If the button doesn\'t work, copy/paste this link:<br>'
        . '<a href="' . esc_url($account_url) . '">' . esc_html($account_url) . '</a></p>';

    $html .= '</div>';
    return $html;
}

function roxy_eb_email_internal_booking_failed($order, $error) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $subject = 'Roxy Booking ERROR — Order #' . $order->get_id();
    $body = "A booking could not be created for an order.

Order: #" . $order->get_id() . "
Customer: " . $order->get_formatted_billing_full_name() . "
Email: " . $order->get_billing_email() . "

Error:
" . $error . "
";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_internal_booking_conflict($order) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $subject = 'Roxy Booking CONFLICT — Order #' . $order->get_id();
    $body = "A booking payment completed, but the selected time became unavailable before we could create the booking record.

Order: #" . $order->get_id() . "
Customer: " . $order->get_formatted_billing_full_name() . "
Email: " . $order->get_billing_email() . "

A refund was attempted. Please confirm the refund status in WooCommerce.
";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_email_internal_sling_failed($order, $booking_id, $error) {
    $settings = roxy_eb_get_settings();
    $to = $settings['internal_email'] ?: get_option('admin_email');
    $subject = 'Roxy Booking — Sling sync failed — Booking #' . intval($booking_id);
    $body = "Booking #" . intval($booking_id) . " was confirmed, but Sling shift creation failed.

Order: #" . $order->get_id() . "

Error:
" . $error . "
";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}


function roxy_eb_email_customer_booking_updated($booking_before, $booking_after) {
    if (empty($booking_after['customer_email'])) return;
    $to = $booking_after['customer_email'];

    $account_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('roxy-bookings') : home_url('/');
    $subject = 'Your Newport Roxy booking was updated';

    $lines = [];
    $lines[] = "Hi " . ($booking_after['customer_first_name'] ?: 'there') . ",";
    $lines[] = "";
    $lines[] = "Your booking was updated by our staff. Here are the details:";
    $lines[] = "";
    $lines[] = "Before:";
    $lines[] = "Doors open: " . ($booking_before['doors_open_at'] ?? '');
    $lines[] = "Guests: " . intval($booking_before['guest_count'] ?? 0);
    $lines[] = "Duration: " . (2 + intval($booking_before['extra_hours'] ?? 0)) . " hours";
    $lines[] = "Visibility: " . ($booking_before['visibility'] ?? '');
    $lines[] = "";
    $lines[] = "After:";
    $lines[] = "Doors open: " . ($booking_after['doors_open_at'] ?? '');
    $lines[] = "Guests: " . intval($booking_after['guest_count'] ?? 0);
    $lines[] = "Duration: " . (2 + intval($booking_after['extra_hours'] ?? 0)) . " hours";
    $lines[] = "Visibility: " . ($booking_after['visibility'] ?? '');
    $lines[] = "";
    $lines[] = "You can view your bookings here:";
    $lines[] = $account_url;
    $lines[] = "";
    $lines[] = "— Newport Roxy";

    $body = implode("\n", $lines);
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function roxy_eb_render_email_booking_summary($booking, $order, $internal = false) {
    $dur = 2 + intval($booking['extra_hours']);

    $lines = [];
    $lines[] = "Booking confirmed!";
    $lines[] = "";
    $lines[] = "Doors open: " . $booking['doors_open_at'];
    $lines[] = "Show starts: " . $booking['show_start_at'] . " (about 30 minutes after doors)";
    $lines[] = "Doors close: " . $booking['doors_close_at'];
    $lines[] = "Duration: " . $dur . " hours";
    $lines[] = "Guests: " . intval($booking['guest_count']);
    $lines[] = "Event format: " . ucfirst($booking['event_format']);
    if ($booking['event_format'] === 'movie' && !empty($booking['movie_title'])) {
        $lines[] = "Movie title: " . $booking['movie_title'];
    }
    if ($booking['event_format'] === 'live' && !empty($booking['live_description'])) {
        $lines[] = "Live event: " . $booking['live_description'];
    }
    if (!empty($booking['notes_admin'])) {
        $lines[] = "Notes: " . preg_replace('/\s+/', ' ', trim($booking['notes_admin']));
    }
    $lines[] = "Visibility: " . ucfirst($booking['visibility']);
    $lines[] = "";
    $lines[] = "Pricing";
    $lines[] = "Base: $" . number_format(intval($booking['base_price']), 2);
    $lines[] = "Extra hours: $" . number_format(intval($booking['extra_price']), 2);
    $lines[] = "Total paid: $" . number_format(intval($booking['total_price']), 2);
    $lines[] = "";
    $lines[] = "Cancellation";
    $lines[] = "- Free cancellation up to 7 days before your event.";
    $lines[] = "- Within 7 days, please contact us to cancel.";

    if ($internal) {
        $lines[] = "";
        $lines[] = "Customer";
        $lines[] = $booking['customer_first_name'] . " " . $booking['customer_last_name'];
        $lines[] = $booking['customer_email'];
        $lines[] = $booking['customer_phone'];
        $lines[] = "";
        $lines[] = "Order: #" . $order->get_id();
    }

    return implode("\n", $lines);
}
