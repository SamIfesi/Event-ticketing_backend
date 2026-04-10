# Event Ticketing System — Project Summary
## For use in a new chat to continue frontend development

---

## What this project is

A fullstack Event Ticketing System built as a learning/portfolio project.

- **Frontend:** React + Tailwind CSS (NOT YET STARTED — this is next)
- **Backend:** Plain PHP REST API (COMPLETE)
- **Database:** MySQL
- **Local stack:** XAMPP (Apache + MySQL)
- **Deployment:** Railway (PHP + MySQL) + Vercel (React)

---

## Backend — fully complete

**Location:** `localhost/event_ticketing/` in XAMPP  
**Base API URL (local):** `http://localhost/event_ticketing/api`

### Folder structure

```
backend/
├── index.php                  # Single entry point — all requests go here
├── .htaccess                  # Rewrites all URLs to index.php
├── worker.php                 # Background queue worker — run separately
├── .env                       # Environment variables (never commit)
├── .env.example               # Safe template to commit
├── composer.json              # Composer dependencies
│
├── config/
│   ├── Environment.php        # Loads .env file
│   ├── Database.php           # PDO singleton connection
│   └── Constants.php          # Role names, statuses, storage paths
│
├── core/
│   ├── Router.php             # Registers + matches routes
│   ├── Request.php            # Wraps incoming HTTP request
│   └── Response.php           # Sends JSON responses
│
├── middleware/
│   ├── AuthMiddleware.php     # Verifies JWT token
│   ├── RoleMiddleware.php     # Checks user role
│   └── DevMiddleware.php      # Returns 404 to non-dev users (backdoor guard)
│
├── controllers/
│   ├── AuthController.php     # register, login, logout, me, verify-email, resend-otp
│   ├── ProfileController.php  # show, update, change-password, change-email, bookings, tickets, activity
│   ├── EventController.php    # index, show, store, update, destroy, myEvents
│   ├── BookingController.php  # store, verify, myBookings, show, eventBookings
│   ├── TicketController.php   # show, byBooking, checkin, checkinList
│   ├── CategoryController.php # index, show, store, update, destroy
│   ├── AdminController.php    # users, showUser, updateRole, updateStatus, events, updateEventStatus, stats
│   └── DevController.php      # overview, users, logs, showLog, clearLogs, forceRole, failedBookings, forcePay
│
├── services/
│   ├── JWTService.php         # Signs + verifies JWT tokens (no library)
│   ├── PaystackService.php    # Paystack payment init + verification
│   ├── QRCodeService.php      # Generates SVG QR codes (bacon/bacon-qr-code)
│   ├── MailService.php        # Sends emails via PHPMailer (OTP, ticket, password)
│   ├── QueueService.php       # Pushes email jobs to jobs table (non-blocking)
│   └── LogService.php         # Logs requests to dev_logs (dev mode only)
│
├── helpers/
│   ├── ValidationHelper.php   # Reusable input validation rules
│   └── TokenHelper.php        # Generates QR tokens + Paystack references
│
├── routes/
│   ├── auth.php
│   ├── profile.php
│   ├── events.php
│   ├── bookings.php
│   ├── tickets.php
│   ├── categories.php
│   ├── admin.php
│   └── dev.php                # Secret routes — return 404 to non-dev
│
└── storage/
    ├── qrcodes/               # Generated SVG QR code files
    ├── tickets/               # PDF tickets (future)
    └── banners/               # Event banner uploads (future)
```

---

## Database

**Name:** `event_ticketing`

### Tables

| Table | Purpose |
|---|---|
| `users` | All accounts. Roles: attendee, organizer, admin, dev |
| `events` | Event listings created by organizers |
| `ticket_types` | Ticket tiers per event (Regular, VIP etc.) |
| `bookings` | Payment records — pending until Paystack confirms |
| `tickets` | Issued tickets with unique QR tokens |
| `categories` | Event categories (Music, Tech, Sports etc.) |
| `email_verifications` | OTP codes for email verify + email change |
| `activity_logs` | User activity history (logins, password changes etc.) |
| `jobs` | Background email queue |
| `dev_logs` | API request logs (dev mode only) |

### .env variable names

```
DATABASE_HOST=localhost
DATABASE_NAME=event_ticketing
DATABASE_USER=root
DATABASE_PASS=

JWT_SECRET=your_random_string
JWT_EXPIRY=604800

PAYSTACK_SECRET_KEY=sk_test_xxx
PAYSTACK_PUBLIC_KEY=pk_test_xxx

APP_URL=http://localhost/event_ticketing
APP_ENV=development

MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=xxx
MAIL_PASSWORD=xxx
MAIL_FROM_ADDRESS=no-reply@eventticketing.com
MAIL_FROM_NAME=Event Ticketing
```

---

## 4 User Roles

| Role | Access | Visible to admins? |
|---|---|---|
| attendee | Buy tickets, view bookings | Yes |
| organizer | Create events, manage sales, check in | Yes |
| admin | Manage users + events platform-wide | Yes |
| dev | Everything + secret /api/dev/* routes |

Dev accounts are filtered out of every admin query using `WHERE role != 'dev'`. The `/api/dev/*` routes return 404 to anyone without a dev token.

---

## Key API Endpoints

### Auth
```
POST /api/auth/register        → creates account, queues OTP email, returns JWT
POST /api/auth/login           → returns JWT
POST /api/auth/logout          → logs activity
GET  /api/auth/me              → returns current user (requires JWT)
POST /api/auth/verify-email    → submits OTP to verify email
POST /api/auth/resend-otp      → sends fresh OTP
```

### Profile
```
GET  /api/profile                      → full profile + stats
PUT  /api/profile                      → update name/avatar
POST /api/profile/change-password      → requires current password
POST /api/profile/change-email         → step 1: sends OTP to new email
POST /api/profile/confirm-email-change → step 2: verifies OTP, updates email
GET  /api/profile/bookings             → booking history
GET  /api/profile/tickets              → all tickets (?filter=upcoming|past)
GET  /api/profile/activity             → activity log
```

### Events
```
GET    /api/events                     → list all published (?search=&category=&date=&page=)
GET    /api/events/:id                 → single event + ticket types
POST   /api/events                     → create (organizer/dev)
PUT    /api/events/:id                 → update (organizer/dev)
DELETE /api/events/:id                 → cancel (organizer/admin/dev)
GET    /api/organizer/events           → organizer's own events
```

### Bookings
```
POST /api/bookings                          → initiate payment, returns Paystack ref
POST /api/bookings/verify                   → verify Paystack payment, issues tickets
GET  /api/bookings/mine                     → user's booking history
GET  /api/bookings/:id                      → single booking with tickets
GET  /api/organizer/events/:id/bookings     → all paid bookings for an event
```

### Tickets
```
GET  /api/tickets/:id                       → single ticket + QR code URL
GET  /api/tickets/booking/:bookingId        → all tickets for a booking
POST /api/tickets/checkin                   → scan QR token at gate
GET  /api/organizer/events/:id/checkins     → full check-in list + summary
```

### Categories
```
GET    /api/categories       → all categories with event counts
GET    /api/categories/:id   → single category + its events
POST   /api/categories       → create (admin/dev)
PUT    /api/categories/:id   → update (admin/dev)
DELETE /api/categories/:id   → delete if no events linked (admin/dev)
```

### Admin
```
GET /api/admin/stats                    → platform stats + 7-day activity
GET /api/admin/users                    → all users except dev
GET /api/admin/users/:id                → user profile + booking summary
PUT /api/admin/users/:id/role           → change role (cannot assign dev)
PUT /api/admin/users/:id/status         → activate/deactivate account
GET /api/admin/events                   → all events
PUT /api/admin/events/:id/status        → force change event status
```

### Dev (secret — returns 404 to non-dev)
```
GET    /api/dev/overview                → full platform stats including dev accounts
GET    /api/dev/users                   → all users including dev accounts
GET    /api/dev/logs                    → API request logs
GET    /api/dev/logs/:id                → single log with payload
DELETE /api/dev/logs                    → clear all logs
POST   /api/dev/users/:id/role          → force any role including dev
GET    /api/dev/bookings/failed         → failed + pending payments
POST   /api/dev/bookings/:id/force-pay  → manually mark booking as paid + issue tickets
```

---

## Payment Flow (Paystack)

```
1. React calls POST /api/bookings → PHP creates pending booking → returns Paystack reference
2. React opens Paystack popup using public key + reference
3. User pays → Paystack closes popup → gives React the reference
4. React calls POST /api/bookings/verify → PHP verifies with Paystack server
5. PHP confirms amount matches → marks booking paid → creates ticket rows → queues confirmation email
```

Test card: `4084 0840 8408 4081` | Expiry: any future | CVV: any | PIN: 0000 | OTP: 123456

---

## Email Queue System

Emails are never sent directly during a request. They are pushed to the `jobs` table and processed by `worker.php` running separately.

**Run the worker locally:**
```bash
php worker.php
```

**On Railway:** add a cron job running `php worker.php` every minute.

**Job types:**
- `send_otp` — OTP for register or email change
- `send_ticket_confirmation` — after successful payment
- `send_password_changed` — security notification

---

## Composer Dependencies

```bash
composer require phpmailer/phpmailer bacon/bacon-qr-code
```

`vendor/` is gitignored. Railway runs `composer install` automatically on deploy.

---

## .gitignore

```
.env
vendor/
storage/tickets/
storage/qrcodes/
storage/banners/
.DS_Store
Thumbs.db
.vscode/
.idea/
```

---

## What's next — React Frontend

Pages to build:
- `/` Homepage — featured events
- `/events` Browse all events (search, filter by category/date)
- `/events/:id` Single event + buy ticket button
- `/login` and `/register` with OTP verification step
- `/dashboard` Attendee: my tickets, upcoming events
- `/tickets/:id` Single ticket with QR code display
- `/checkout/:eventId` Paystack payment page
- `/organizer/dashboard` Sales overview, event management
- `/organizer/events/create` Create event form
- `/organizer/events/:id/checkin` QR scanner for gate check-in
- `/admin/dashboard` Platform stats
- `/profile` User profile, password/email change

**Key React packages needed:**
```bash
npm install react-router-dom axios react-paystack html5-qrcode lucide-react
```

**Auth flow in React:**
- Store JWT in `localStorage`
- Attach to every request: `Authorization: Bearer <token>`
- Create a `ProtectedRoute` component that checks for token + role
- After login, check `email_verified` — if false, redirect to OTP verification page
