# University Management Portal — System Overview, Hosting Options and Production Pricing

**Prepared:** 06 October 2026 (revised for the 1,000-student target and Remita/Interswitch payments) · **Audience:** project owner and university decision-makers
**Status of figures:** list prices and fees gathered from public provider pages on the preparation date. Providers change prices, plans and regions without notice, and some third-party pages disagree with each other, so every figure below is a **planning estimate to be confirmed** on the provider's site before budgeting or signing anything. Currency conversion to Naira is deliberately left out because rates move.

---

## 1. What the system is

A web-based university portal where administrators and academic staff run core student and academic operations, and students receive accounts by email, register courses, pay fees online, view results and receive notifications.

Key characteristics:
- **Admin-led onboarding.** Students are imported (CSV, Excel, Google Sheet/Form responses) or added by staff. There is no public sign-up. Each student gets a matric number and a generated password by email.
- **School-configurable rules.** Grading, fees, matric format, credit limits and deadlines are set by the institution; the system performs the calculations.
- **Online payments with verification.** Remita or Interswitch (Quickteller), chosen with the institution's bursary, plus a built-in demo gateway for testing. Every payment is confirmed with the provider before it is credited; receipts and reconciliation included.
- **Right-sized.** The institution has about 150 students today. The launch infrastructure is sized for up to 1,000 students, with an expansion planned beyond about 2,000.
- **Independent of the CBT engine.** No connection of any kind.
- **Mobile responsive** and accessible.

## 2. Who uses it

| Role | Main uses |
|---|---|
| Super Administrator | Configuration, users and roles, grading rules, audit logs |
| Registrar / Admin Officer | Student onboarding and records, applicants, sessions, registration oversight, announcements |
| Bursary / Finance Officer | Fee structures, invoices, discounts/waivers, payments, reconciliation, finance reports |
| Faculty Administrator / Department Officer | Scoped student lists, course and result approvals |
| Lecturer | Attendance, continuous assessment and exam scores for assigned courses |
| Exam Officer | Exam timetable, result review and publication |
| Student | Profile, course registration, fees and payments, receipts, results, timetable, attendance, announcements, documents |

## 3. Feature summary

| Module | What it does |
|---|---|
| Authentication | Login by matric number or email; forced password change on first login; password reset by email; account activation/deactivation; login throttling; optional two-factor for staff (stretch) |
| University setup | University profile and logo, sessions, semesters, faculties, departments, programmes, courses with prerequisites, matric-number pattern, credit limits |
| Grading configuration | Admin defines score components, grade bands and points, pass mark, classification, standing thresholds and repeat-course policy; system computes grades, GPA and CGPA; preview tool |
| Student onboarding | Single add, CSV, Excel, Google Sheet/Form import with mapping, validation preview, per-row errors, chunked commit, failed-rows report, credential emails, resend credentials |
| Applicants | Import or enter applicants, review, admit, convert to student |
| Student records | Searchable list, full record page, status changes, level promotion, guardians, documents |
| Course registration | Eligible courses, credit-unit limits, prerequisites, deadlines, approval workflow, add/drop, slip PDF |
| Fees and invoices | Fee categories and structures, automatic and bulk invoices, scholarships/discounts/waivers, balances |
| Payments | Remita or Interswitch checkout (plus demo gateway), partial payments, pending/expired handling, scheduled status checks, server-side verification, receipts (PDF), reconciliation, finance dashboard |
| Results | Lecturer entry or CSV upload, approval and publication workflow, locking and audited amendments, GPA/CGPA, result statement PDF |
| Attendance | Class sessions, marking, student view, threshold warnings, export |
| Exam timetable | Timetable with clash detection, student view, PDF (informational only) |
| Staff management | Staff accounts and import, role assignment with scope, lecturer–course assignment |
| Announcements and notifications | Audience targeting, optional email, in-app notifications |
| Email | Branded transactional emails for every key event; outbox with status and retry |
| Reports | Eight operational and financial reports with filters, CSV and PDF export |
| Administration | Audit log, user management, settings, backup export, system status |

## 4. Technology summary

| Layer | Choice |
|---|---|
| Application | Laravel (PHP 8.3+), Filament staff panel, Blade + Livewire student portal, Tailwind CSS |
| Database | MySQL 8 (managed) |
| Email | Brevo |
| Payments | Remita and/or Interswitch (Quickteller Web Checkout), plus Demo Gateway |
| Packaging | Docker container (web server, queue worker, scheduler) |
| Testing and CI | Pest, static analysis, GitHub Actions |

Details: ARCHITECTURE.md and DECISIONS.md.

## 5. Demo and test hosting (low cost)

| Component | Choice | Cost expectation |
|---|---|---|
| Application | DigitalOcean App Platform (Docker); fallback Render free web service | App Platform dynamic apps are listed from about $5 per month; new DigitalOcean accounts may have promotional credit (verify). Render free is $0 but sleeps when idle |
| Database | Aiven free MySQL on DigitalOcean | $0, capped resources, may be powered off if unused |
| Email | Brevo free | $0, 300 emails/day |
| Payments | Demo Gateway; provider sandbox/demo environment if available | $0 |

This stack is for demonstration and testing with fictional data only.

## 6. Production hosting: which option fits up to 1,000 students and beyond

### 6.1 Can DigitalOcean be used for production?

**Yes.** 150 students today, 1,000 as the committed ceiling and about 2,000 as the expansion point are all modest workloads for a Laravel application and a managed MySQL database. DigitalOcean can host both. The decision is less about capacity and more about **latency, data location, cost and operations effort**.

### 6.2 Options compared

| | Option 1: DigitalOcean (Europe region) | Option 2: Cloud region in South Africa (AWS, Azure or Google Cloud) | Option 3: Nigerian data centre (Lagos) |
|---|---|---|---|
| Fit | Simple, predictable pricing, easy operations | Closest major-cloud regions to Nigeria | Best for local data residency and latency |
| Africa presence | **No African data centre** (last confirmed by DigitalOcean staff in 2022; re-check) | AWS, Azure and Google Cloud operate South African regions; confirm which regions Aiven supports | Lagos facilities exist (for example Africa Data Centres and Open Access Data Centres) |
| Cost | Lowest of the three at this scale | Higher, more complex pricing | Quote-based; often needs more self-management |
| Operations effort | Low | Medium | Highest (patching, backups, monitoring are yours unless the provider manages them) |
| Managed MySQL | DigitalOcean Managed MySQL, or Aiven on DigitalOcean | RDS-type services, or Aiven on that cloud | Typically self-managed or provider-managed; confirm |
| Data-residency position | Data stored outside Nigeria | Data stored outside Nigeria (still in Africa) | Data stored in Nigeria |

### 6.3 Recommendation

1. **Start with Option 1: DigitalOcean**, using the Europe region with the best measured latency from your campus (compare London, Frankfurt and Amsterdam with a simple ping and page-load test from a Lagos connection). A server-rendered portal is tolerant of the extra distance, and DigitalOcean keeps cost and effort lowest.
2. **Keep the application and the database in the same region and cloud.** Splitting them (for example app in one country, database in another) adds delay to every query and noticeably slows pages.
3. **Use a high-availability database plan** (a standby node), automated backups and an always-on application host. Free plans are not acceptable for production.
4. **Get data-residency advice before go-live.** If the institution or regulator requires data to stay in Nigeria, move to Option 3 (or Option 2 if acceptable). Because the app is a standard Docker image with MySQL, moving later is a planned migration (backup and restore, DNS change), not a rewrite.
5. **Database provider on DigitalOcean:** use DigitalOcean Managed MySQL (high availability) if lowest cost matters; use Aiven (Business plan) if you want Aiven's portability and tooling and accept the higher price. Both are MySQL, so switching is a dump-and-restore. This is my judgement from published prices, not a benchmark.

### 6.4 Aiven versus DigitalOcean's own managed MySQL

| | Aiven for MySQL | DigitalOcean Managed MySQL |
|---|---|---|
| Free option | Yes, free plan (capped, may power off if unused) | No free plan |
| Entry production plan | Startup from about $75 per month (single node) | Single node from $15 per month (1 GiB) |
| High availability | Business from about $180 per month | HA from $30 per node plus a matching standby (about $60 for the smallest) |
| Extra storage | Included per plan, scales by plan | $0.21 per GiB per month |
| Multi-cloud portability | Strong (same service across clouds) | DigitalOcean only |

Aiven's own pages show different figures for the same plans in different places (an older page listed Startup $110, Business $290, Premium $435), so confirm prices in the Aiven console before deciding.

## 7. Production cost estimates (infrastructure only)

### 7.1 Published list prices used

| Item | Price used |
|---|---|
| DigitalOcean Basic Droplet 4 GiB / 2 vCPU | $24 per month (includes about 4 TB outbound transfer); 2 GiB / 1 vCPU about $12 |
| Droplet backups | About 20% of the Droplet price |
| DigitalOcean load balancer | $12 per month |
| DigitalOcean Managed MySQL | Single node from $15; HA from $30 per node plus standby. A 4 GiB-class HA cluster is **estimated** at about $120 (derived from DigitalOcean's 4 GiB Postgres price of about $61; confirm for MySQL) |
| DigitalOcean Spaces (object storage) | $5 per month including 250 GiB storage and 1 TiB transfer |
| Aiven for MySQL | Developer $5, Hobbyist from $19, Startup from $75, Business (HA) from $180, Premium from $270 per month |
| Brevo email | Free: 300 emails/day. Paid plans from about $9 per month remove the daily cap; a published figure of about $15 per month for 20,000 emails also exists |
| Domain name | Roughly $10–$20 per year (varies by registrar and extension) |
| TLS certificate, basic uptime/error monitoring | Free tiers are usually sufficient initially |

### 7.2 Sizing tiers and monthly cost (USD, estimates)

Tiers match ARCHITECTURE.md §12. The institution has about 150 students; the commitment is **up to 1,000 students without problems**, with expansion beyond about 2,000.

| Component | Tier 0: cost-saving start (≈150 students) | **Tier 1: Launch (to 1,000)** | Tier 2: Growth (1,000–2,000) | Tier 3: Expansion (2,000+) |
|---|---|---|---|---|
| Application server | 1 × $12 (2 GiB / 1 vCPU) | 1 × $24 (4 GiB / 2 vCPU) | 1 × ~$48 (8 GiB / 4 vCPU, estimate) or 2 × $24 + $12 load balancer | 2–3 × $24–$48 + $12 load balancer |
| Server backups | $2 | $5 | $10 | $10–$15 |
| Managed MySQL | ~$30 single node (estimate) | **~$60 high availability** (budget alternative: ~$30 single node) | ~$120 HA, 4 GiB class (estimate) | $120–$270+ (DigitalOcean HA or Aiven Business/Premium) |
| Object storage | $5 | $5 | $5 | $5 |
| Email (Brevo) | $0 (free tier) | $9 | $15 | $15–$25 |
| Managed Redis/Valkey | — | — | — | extra; price to confirm |
| **Approx. per month** | **≈ $50** | **≈ $105** (≈ $75 with single-node database) | **≈ $200** | **≈ $210–$400+** |
| **Approx. per year** | ≈ $600 | ≈ $1,260 | ≈ $2,400 | ≈ $2,500–$4,800+ |

Notes:
- **Tier 1 is what to promise against.** Tier 0 is a way to save money in the first months; resize to Tier 1 before the student count approaches about 500, and before you describe the system as ready for 1,000.
- Droplet resizing is straightforward; moving the database to a bigger plan needs a short maintenance window, so schedule it outside deadlines.
- The 8 GiB droplet price, the single-node 2 GiB MySQL price and the 4 GiB MySQL HA price are inferred from DigitalOcean's published price ladder; confirm them in the DigitalOcean control panel.
- If you prefer DigitalOcean App Platform to Droplets for easier operation, check its container pricing, which may be higher than Droplets for the same CPU and memory.
- Costs exclude staff time, support, the one-off security review, domain renewal and optional services such as SMS.

### 7.3 Cost per student (infrastructure)

| Students | Tier 0 / Tier 1 cost per student per year |
|---|---|
| 150 (today) | Tier 0 ≈ $4.00; Tier 1 ≈ $8.40 |
| 500 | Tier 1 ≈ $2.52 |
| 1,000 | Tier 1 ≈ $1.26 |
| 2,000 | Tier 2 ≈ $1.20 |

At 150 students the fixed hosting cost dominates, so a flat annual fee suits the early period better than a per-student fee (see §9).

### 7.4 Payment processing fees (paid by the institution or passed to students)

Paystack is not used. Neither Remita nor Interswitch publishes a single authoritative fee table that I could confirm, and the public sources disagree, so treat the figures below as indicative and **get a written fee schedule from the provider** (including any education or institutional rate, whether VAT applies, any one-time integration fee, and settlement time).

| Item | Remita | Interswitch (Web Checkout / Quickteller Business) |
|---|---|---|
| Setup | Reported as free | Account setup reported as free; one 2021 report mentions a one-time integration fee of ₦150,000, which needs confirming |
| Local card/transaction fee | Sources conflict: about 2% (2021 report) versus ₦150 + 0.5% capped at ₦2,000 (2026 blog) | About 1.5% capped at ₦2,000 (published by a news site) |
| International cards | About 4% (older sources) | About 3.8% (older sources) |
| Settlement | Local card settlement is commonly T+1 across gateways; confirm | Same |
| Strengths for an institution | Used by many universities; invoice/RRR flow; split payments; pay at banks or by USSD with the RRR | Largest payments processor in Nigeria; simple checkout; cards, transfer, USSD, wallet options |

Illustration using a hypothetical ₦150,000 fee per student (one payment each):

| Students paying | Interswitch at 1.5%, capped ₦2,000 (₦2,000 each) | Remita at ₦150 + 0.5%, capped ₦2,000 (₦900 each) | Remita at 2% (₦3,000 each, if that 2021 figure still applied) |
|---|---|---|---|
| 150 | ₦300,000 | ₦135,000 | ₦450,000 |
| 1,000 | ₦2,000,000 | ₦900,000 | ₦3,000,000 |

Decide early who bears the fee (the institution or the payer) and which provider the bursary already has a settlement agreement with. At these volumes, payment fees can exceed the yearly hosting cost.

### 7.5 One-off costs to plan for

Production hardening and security review or penetration test, load testing, data migration from existing records, staff training, legal advice on data residency and data protection, domain and sender-domain setup, and live payment-provider onboarding (Remita or Interswitch). These are quote-based and are not estimated here.

## 8. Capacity and scaling guidance

**Commitment to the institution:** supports **up to 1,000 students** on the Tier 1 launch infrastructure; plan an infrastructure expansion once enrolment passes about **2,000**.

How to make that promise safely:
1. Agree the measurable targets with the institution (proposed: 300 users active at once at deadline peaks; 95% of pages under 2 seconds excluding payment-provider pages).
2. Run the load test in TEST_PLAN.md §17 on a Tier 1 staging copy seeded with 1,000 students at 1.5 times the peak assumption, and keep the results.
3. Describe the capacity as "tested up to X users on date Y", not as an unlimited guarantee.
4. Set up monitoring from the first day and act on the upgrade triggers in ARCHITECTURE.md §12 (CPU above 70%, p95 above 2 seconds, database load or connection limits, queue backlog, storage above 80%).
5. Review capacity at 1,000 students and expand before 2,000 (Tier 2, then Tier 3 with a second server, load balancer and managed Redis/Valkey).

Deadline-day behaviour matters more than the student count: stagger registration and fee deadlines by faculty or level where possible, and expect many students to use payment-provider pages at the same time (their capacity is the provider's, not yours).

## 9. Commercial pricing models (illustrative, for you to set)

I have no market data on what institutions pay for a system like this, so the structure below is a framework, not a quote.

| Model | How it works | Suits |
|---|---|---|
| One-time licence + annual maintenance | Institution pays an up-front fee and a yearly support/maintenance fee | Institutions that prefer capital spending and hosting themselves |
| Per-student per-year subscription | Fee × active students each year; includes hosting, support and updates | Predictable growth with enrolment; recurring revenue |
| Managed service (flat annual fee) | Fixed yearly price including hosting, support and updates up to a student cap | Institutions that want one invoice and no technical staff |
| Hybrid | Setup/onboarding fee plus per-student annual fee | Covers data migration and training up front |

**Cost-recovery floor (per year):**
`Infrastructure + payment/email fees you bear + support and maintenance labour + backups and monitoring + security reviews + contingency`

Worked example using this document's numbers: Tier 1 infrastructure is about $1,260 per year. That is about $8.40 per student at today's 150 students and about $1.26 per student at 1,000. Because hosting is mostly a fixed cost, a flat annual fee (or a minimum annual fee plus a per-student component) protects you in the early years when the student count is small. Anything you charge must cover infrastructure plus your labour, support and risk, so the price is mainly determined by the support level, customisation and the value to the institution. Decide who bears payment-provider fees, who owns the production accounts (institution or you), and what the support/response times are before quoting.

## 10. Assumptions and caveats

- Prices were taken from provider pages and third-party summaries on 06 October 2026; some sources disagreed (noted above).
- Payment provider fees and onboarding terms were not confirmed with Remita or Interswitch; sources conflict. Get written terms.
- Region availability (for example which Aiven clouds offer African regions) was **not** verified; check the Aiven console.
- DigitalOcean's statement about having no African data centre dates from 2022; re-check.
- Capacity numbers are planning assumptions until the load test in TEST_PLAN.md §17 has been run.
- Hosting student data outside Nigeria may have legal implications under the Nigeria Data Protection Act 2023; obtain advice before choosing the production region.
- Free tiers (Aiven free, Render free, promotional credits) are for demo and testing only.

## 11. Roadmap after the demo

1. Close any gaps found in the demo and user testing.
2. Decide the production region and host with the institution; obtain data-residency advice.
3. Provision production (HA database, always-on host, object storage, domain, verified email domain, monitoring); test backup and restore.
4. Security review and load test.
5. Onboard real data in a controlled pilot (one faculty), then scale to all.
6. Optional later work: standalone public application form, template editor, additional payment gateways, two-factor authentication, mobile app.
