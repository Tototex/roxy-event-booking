# Roxy Event Booking

WordPress + WooCommerce booking system for the Newport Roxy Theater.

This plugin allows customers to book private events, field trips, and business rentals with optional concessions, pizza ordering, and invoice support.

---

# Key Features

## Booking System

Customers can:

- Select date and time
- Choose guest count
- Add extra hours
- Choose Movie or Live Event
- Choose Private or Public event

The system automatically:

- Blocks conflicts
- Calculates staffing requirements
- Creates Sling shifts
- Handles WooCommerce checkout (if paying now)

---

# Pricing

Default pricing:

- $250 — 25 guests or fewer
- $300 — 26+ guests
- $100/hour — Additional hours

Pricing is configurable in settings.

---

# Payment Options

Customers can choose:

## Personal Booking

- Pay Now (credit card)

## Business Booking

- Pay Now
- Invoice Me

Invoice bookings:

- Reserve time immediately
- Do not require credit card
- Send internal notification
- Added to admin bookings
- Included in Sling scheduling

---

# Pizza Ordering

Customers may add pizza:

- Large pizzas only
- 2 toppings or less
- Recommended: 1 pizza per 4 guests

Features:

- Pizza quantity
- Pizza order notes
- Configurable pizza pricing (default $18 each)

Pizza orders:

- Included in booking
- Included in Sling notes
- Added to invoice if applicable
- Trigger reminder system

---

# Pizza Reminder System

If pizza is ordered:

- Admin checkbox: "Pizza Ordered"
- If not checked within 4 hours of event:
- Email sent to:

- jason@newportroxy.com  
- info@newportroxy.com  

Emails repeat every 30 minutes until:

- Pizza marked ordered
- Event begins

---

# Bulk Concessions

Designed for field trips and schools.

Customers may select:

Bulk Concessions: Yes / No

If Yes:

- Bulk Popcorn quantity
- Bulk Soda quantity

Rules:

- 0 or 25–250 items
- Minimum bulk size: 25

Pricing:

- Default: $3 per item
- Configurable in settings

Bulk concessions:

- Included in totals
- Included in invoice
- Included in Sling notes
- Included in admin view

---

# Sling Integration

Automatically creates staff shifts:

- 25 guests or fewer → 1 shift
- 26+ guests → 2 shifts

Sling notes include:

- Customer info
- Event details
- Pizza orders
- Bulk concessions
- Special instructions

---

# Admin Features

Admin Menu: **Roxy Bookings**

Features:

- Booking list
- Calendar view
- Edit bookings
- Pizza ordered checkbox
- Bulk concessions view
- Invoice status

---

# Booking Restrictions

- Must book 48 hours in advance
- One event at a time
- Friday/Saturday showtimes blocked
- Sunday showtimes blocked

Configurable in settings.

---

# Cancellation

Customers can:

My Account → My Bookings

- Cancel free up to 7 days prior
- Within 7 days must contact theater

---

# Shortcode

Place booking calendar:
[roxy_booking_calendar]


---

# Activation Notes

After activating:

1. Go to Settings → Permalinks
2. Click Save Changes

---

# Database Tables

Creates:

- wp_roxy_event_bookings
- wp_roxy_event_blocks

---

# Version 1.4.2

New Features

- Business invoice bookings
- Pizza ordering
- Pizza reminders
- Bulk concessions
- Bulk concession pricing
- Editable pizza pricing
- Editable bulk pricing
- Improved Sling notes
- Booking success modal

---

# GitHub Updates

Supports GitHub auto-updates:

Tototex/roxy-event-booking

---

# License

GPLv2 or later