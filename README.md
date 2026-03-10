# Roxy Event Booking (MVP)

This WordPress plugin adds a customer-facing booking calendar + checkout flow for Newport Roxy private/public events.

## Key behavior (MVP)

- Customer chooses a date and a **doors-open start time** (15-min increments).
- Customer chooses **guest count** and **additional hours** (beyond the standard 2 hours).
- System blocks the theater for:
  - **1 hour before doors open**
  - the **guest-facing duration** (2h + extra hours)
  - **1 hour after doors close**
- Prices:
  - **$250** for 25 guests or fewer (1 staff shift)
  - **$300** for 26+ guests (2 staff shifts)
  - **$100/hour** for added hours
- Booking restrictions:
  - Must be booked **48 hours** in advance.
  - Friday/Saturday **6pm–10pm** and Sunday **1pm–5pm** are blocked as regular showings (configurable).
  - Only **one event at a time** (theater-wide conflict enforcement).
- Cancellation:
  - Customers can cancel for free up to **7 days** before event via **My Account → My Bookings**.
  - Within 7 days, customers are told to contact you.

## Admin features

- Admin menu: **Roxy Bookings**
  - Bookings list
  - Calendar Blocks (create special-event blocks)
  - Settings

## Sling integration (best-effort)

- Disabled by default.
- Recommended: **Webhook mode** — the plugin POSTs booking JSON to your webhook, and you call Sling from n8n/Make.
- Direct API mode exists as a best-effort implementation, but Sling endpoint details may vary by account.

## Shortcode

Place this where you want the calendar:

`[roxy_booking_calendar]`

## Activation notes

After activating:
1. Go to **Settings → Permalinks**
2. Click **Save Changes** once  
(This enables the My Account endpoint.)

## Uninstall / rollback

- Deactivate the plugin.
- Delete the plugin folder.
- Optionally remove DB tables:
  - `wp_roxy_event_bookings`
  - `wp_roxy_event_blocks`

(Replace `wp_` with your table prefix.)
