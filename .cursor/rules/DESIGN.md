# Design System

> DESIGN.md = **HOW IT SHOULD LOOK AND FEEL**. Every screen must follow this document.

## 1. Style

Modern · Minimal · Professional · Trustworthy.
A university portal handles money and academic records, so the interface is calm, uncluttered and predictable. Clarity beats decoration. Generous whitespace, clear hierarchy, one primary action per view.

## 2. Typography

- Font: **Inter**, self-hosted and loaded through the Vite build (fallback `system-ui, sans-serif`).
- Numerals: use tabular numbers (`font-variant-numeric: tabular-nums`) for money, matric numbers, scores and tables.

| Token | Size / line height | Weight | Use |
|---|---|---|---|
| Display | 30 / 38 | 700 | Page titles on dashboards |
| H1 | 24 / 32 | 600 | Page headings |
| H2 | 20 / 28 | 600 | Section headings |
| H3 | 16 / 24 | 600 | Card titles |
| Body | 14 / 22 | 400 | Default text (16px on mobile inputs to prevent zoom) |
| Small | 12 / 18 | 400 | Captions, helper text |
| Mono | 13 / 20 | 500 | References, matric numbers (optional) |

## 3. Colours

| Token | Value | Use |
|---|---|---|
| Primary (brand) | `#6366F1` | Brand accents, focus ring, icons, charts |
| Primary-600 (action) | `#4F46E5` | Primary buttons, links (meets AA contrast with white text) |
| Primary-700 | `#4338CA` | Button hover/pressed |
| Primary-50 | `#EEF2FF` | Selected rows, soft highlights |
| Background | `#F8FAFC` | Page background |
| Surface | `#FFFFFF` | Cards, dialogs, tables |
| Text | `#0F172A` | Main text |
| Muted | `#64748B` | Secondary text, placeholders (not for essential small text on tinted backgrounds) |
| Border | `#E2E8F0` | Dividers, card and input borders |
| Success | text `#166534` on `#DCFCE7` | Paid, approved, published |
| Warning | text `#92400E` on `#FEF3C7` | Pending, part-paid, near deadline |
| Danger | text `#991B1B` on `#FEE2E2` | Failed, rejected, destructive |
| Info | text `#3730A3` on `#E0E7FF` | Submitted, informational |
| Neutral | text `#334155` on `#F1F5F9` | Draft, inactive |

Notes:
- Do not use `#6366F1` as a background for white body text; use Primary-600.
- Never rely on colour alone; badges always include text (and an icon where helpful).
- Light theme only for the prototype. Define colours as CSS variables / Tailwind tokens so dark mode can be added later.

### Status colour mapping
| Domain | Status → badge |
|---|---|
| Invoice | Unpaid → Warning · Part-paid → Info · Paid → Success · Cancelled → Neutral |
| Payment | Pending → Warning · Successful → Success · Failed → Danger · Cancelled → Neutral · Reversed → Danger |
| Course registration | Draft → Neutral · Submitted → Info · Approved → Success · Rejected → Danger |
| Result | Draft → Neutral · Submitted → Info · Approved → Warning · Published → Success |
| Student | Active → Success · Suspended → Danger · Deferred → Warning · Graduated → Info · Withdrawn → Neutral |
| Applicant | Applied → Neutral · Under review → Info · Admitted → Success · Rejected → Danger · Converted → Success |
| Email outbox | Queued → Warning · Sent → Success · Failed → Danger |
| Attendance | Present → Success · Absent → Danger · Late → Warning · Excused → Info |

## 4. Spacing, radius, elevation

- Spacing scale: 4, 8, 12, 16, 24, 32, 48 px (Tailwind defaults).
- Page padding: 16px mobile, 24px tablet, 32px desktop. Max content width 1280px.
- Radius: **Cards 12px**, buttons and inputs 8px, badges full pill, dialogs 12px.
- Elevation: cards use `1px` border + very subtle shadow (`shadow-sm`). Dialogs and menus use `shadow-lg`. No heavy shadows.

## 5. Components

### Buttons
| Variant | Look | Use |
|---|---|---|
| Primary | Primary-600 fill, white text | Main action (one per view): Pay now, Submit registration, Import |
| Secondary | White fill, border, Text colour | Alternative actions: Cancel, Back, Export |
| Ghost | No fill, hover Primary-50 | Table row actions, toolbars |
| Destructive | Red `#DC2626` fill (hover `#B91C1C`), white text | Delete, deactivate, reject. Always behind a confirm dialog |
| Link | Primary-600 text, underline on hover | Inline navigation |

Sizes: small 32px, default 40px, large 48px (mobile primary actions). Minimum touch target 44×44px on mobile. Buttons show a spinner and become disabled while submitting; the label changes to a progress form ("Paying…").

### Inputs and forms
- Label above field, always visible (no placeholder-only labels). Required fields marked with a visible indicator and `aria-required`.
- Helper text below in Muted; error text below in Danger with an icon, linked via `aria-describedby`.
- Validate on blur and on submit; show a summary at the top for long forms with links to fields.
- Inputs 40px high, 8px radius, 1px Border, focus ring 2px `#6366F1` with offset.
- Password fields have a show/hide toggle. Phone, matric, amount fields use appropriate `inputmode`.
- Long forms are split into sections inside cards; multi-step flows (import wizard) use a stepper.

### Cards
- White surface, 1px Border, **12px radius**, padding 16–24px, optional header with title and action.
- Stat cards: label (Small, Muted), value (H1/Display, tabular), optional trend or sub-label.

### Tables
- Sticky header, 48px rows, zebra not required, hover Primary-50.
- Toolbar above: search, filters, export. Pagination below (25 per page default).
- Sortable columns indicated with icons and `aria-sort`.
- **Below 768px, tables become stacked cards** showing the 3–4 most important fields with a "View" action.

### Badges
Pill, 12px text, semibold, coloured per the status mapping above.

### Navigation and layout
- **Staff shell:** left sidebar (240px, collapsible to icons), top bar with global search, notification bell and user menu. Sidebar items are filtered by role. Below 1024px the sidebar becomes a slide-over drawer.
- **Student shell:** simpler sidebar on desktop; on mobile a **bottom tab bar** (Home, Courses, Fees, Results, More) with a top bar for notifications and profile.
- Breadcrumbs on detail pages. Page header = title + short description + primary action on the right.

### Dialogs, toasts, menus
- Confirm dialogs for destructive or irreversible actions (publish results, deactivate user, re-issue credentials), stating the consequence plainly.
- Toasts for success and non-blocking errors, auto-dismiss after 5s, dismissible, `aria-live="polite"`.

### Money, dates and numbers
- Currency: `₦125,000.00` (Naira sign, thousands separators, two decimals). Convert from kobo only in the display helper.
- Dates: `05 Oct 2026`; date-time `05 Oct 2026, 14:30`. Timezone **Africa/Lagos** (WAT).
- Scores: integers or one decimal; GPA/CGPA two decimals.
- Matric numbers and references are copyable.

### Icons and charts
- Icons: lucide-react, 16/20px, outline style, paired with text labels in navigation.
- Charts (Filament chart widgets / Chart.js): primary `#6366F1`, secondary `#14B8A6`, tertiary `#F59E0B`; always include axis labels, tooltips and an accessible text summary or table alternative.

## 6. Key screen patterns

| Screen | Pattern |
|---|---|
| Login | Centred card on Background, university logo and name, identifier field labelled "Matric number or email", password with toggle, "Forgot password" link. No sign-up link. |
| Student dashboard | Greeting + status strip (registration, fees due), stat cards (outstanding fees, CGPA, courses), "Pay now" and "Register courses" quick actions, latest announcements. |
| Staff dashboard | Role-aware stat cards, chart (payments by week), recent activity lists, pending approvals panel. |
| Import wizard | Stepper: Source → Preview → Map columns → Validate → Import → Report. Validation shows a table with row-level errors highlighted; commit shows a progress bar with live counts. |
| Course registration | Two-column on desktop: eligible courses list with checkboxes; sticky summary card showing selected courses, unit total vs min/max, deadline countdown, Submit. Summary pinned to bottom on mobile. |
| Fees and payment | Invoice cards with status badge and balance; "Pay" opens an amount dialog (full or partial); after gateway return show a clear status page (verifying → success/failure) with receipt link. |
| Result statement | A4 print layout with university header, student details, results table, GPA/CGPA, signature line. |
| Receipt / slip | A4 print layout, logo, reference, amount, status, QR optional. |

## 7. UX requirements

- **Mobile responsive:** tested at 375px, 768px and 1440px; no horizontal page scroll (tables scroll inside their container only if cards are not suitable).
- **Loading states:** skeletons for pages, tables and cards; spinners inside buttons; progress bars for imports and bulk actions.
- **Empty states:** illustration or icon, one-line explanation, and a primary action ("No invoices yet", "Import students").
- **Error states:** friendly message, what happened, what to do, and a retry action. No stack traces or raw error codes.
- **Success feedback:** toast plus visible state change (badge, balance, list update).
- **Accessible forms:** labels, descriptions, error association, logical tab order, visible focus, keyboard-operable dialogs and menus, Escape closes.
- **Contrast:** WCAG 2.1 AA (4.5:1 body text, 3:1 large text and UI components).
- **Motion:** subtle, 150–200ms; respect `prefers-reduced-motion`.
- **Confirmation for risky actions:** publish results, bulk admit, bulk invoice, re-issue credentials, deactivate.
- **Print:** receipts, slips and result statements have dedicated print styles/PDFs; navigation hidden when printing.
- **Language and tone:** plain, polite, specific. Use "Pay ₦50,000" rather than "Submit". Avoid jargon. Error example: "We could not verify this payment yet. Your account has not been charged twice. Try again in a minute."

## 8. Implementation notes (Laravel)

- **Student portal:** Blade components + Livewire styled with Tailwind tokens; fully follows this document.
- **Staff panel (Filament):** themed with the same colour tokens, Inter, radius and logo. Filament's built-in table, form and modal patterns are accepted where they differ slightly from the custom components above; status badges, empty states and money/date formats must still match the mappings in this document.
- Keep tokens in one place (Tailwind config / CSS variables) so both areas stay consistent.

## 9. Branding

University name, short code and logo come from institution settings, never hard-coded. Default demo identity is a fictional university with a simple logo placeholder. Email templates and PDFs reuse the same logo and the colour tokens above.
