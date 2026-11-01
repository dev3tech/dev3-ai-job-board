=== DEV3 AI Job Board & Resume Matcher for WordPress ===
Contributors: dev3tech
Tags: job board, jobs, careers, resume, recruitment
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete job board: front-end posting with paid packages, employer and candidate dashboards, a hiring pipeline, job alerts and Google for Jobs markup. DEV3 AI writes job ads, reads resumes and explains match scores.

== Description ==

DEV3 AI Job Board & Resume Matcher turns WordPress into a job board or a careers site.

**Job board**

* Jobs with companies, categories, job types, locations, skills, workplace (on-site, hybrid, remote) and salary ranges.
* Search with keyword, location, category, type, workplace, minimum salary and "date posted" filters; AJAX results with shareable URLs; featured jobs first.
* Single job pages with an apply box, company box and similar jobs; company pages with all open jobs.
* Google for Jobs: JobPosting structured data on every live job.
* Job alerts: daily or weekly emails for saved searches (guests confirm by email).

**Employers**

* Front-end "Post a job" wizard: details, preview, package, checkout.
* Packages with job credits, duration and featured placement. Pay by Stripe Checkout, WooCommerce (HPOS and block checkout compatible) or offline.
* Employer dashboard: jobs (edit, mark filled, relist), companies, packages and payments.
* Applications pipeline: a kanban board (drag and drop, or "Move to" on any device) that becomes a list on phones, with notes, ratings and optional emails to candidates.

**Candidates**

* Candidate dashboard: profile, applications with clear status, saved jobs, job alerts and recommended jobs.
* Resume upload (PDF, Word or text) stored privately: never reachable by URL, only by the candidate, the employers they apply to and site managers.

**DEV3 AI (optional, uses your own Google Gemini API key)**

* Job description writer with tone presets, and an inclusive-language check.
* Resume parsing: a resume becomes a structured profile that the candidate reviews before saving.
* Match scores with explanations (skills, experience, role and location fit, strengths and gaps), shown to employers as decision support only.
* Everything works without AI: rule-based outlines, a built-in resume reader and transparent rule-based scores.

**Fairness**

Matching never uses names, contact details, photos, age or date of birth, gender, marital or family status, nationality, ethnicity, religion, disability, health, sexual orientation, union membership, political views or street addresses. These are removed before scoring and never sent to AI. A score never moves, hides or rejects a candidate.

**Privacy**

AI features send data to Google Gemini only when you add an API key. The plugin adds text to your privacy policy, and supports the WordPress personal data exporter and eraser.

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload, then activate it.
2. The plugin creates five pages: Jobs, Post a Job, Employer Dashboard, Candidate Dashboard and Companies.
3. Open DEV3 AI Job Board > Settings to review pages, moderation and emails.
4. Optional: add a Google Gemini API key in DEV3 AI Job Board > API Config.
5. Optional: set up packages and a payment gateway in Packages & payments.

== Frequently Asked Questions ==

= Do I need an AI key? =
No. Every feature works without AI. The key adds AI writing, AI resume parsing and explained scores.

= Can guests apply? =
Yes, if "Allow applying without an account" is on. Logged-in candidates apply in one click with their profile.

= Where are resumes stored? =
In wp-content/uploads/dev3jb-private with deny rules and random names. On Nginx, add `location ~* /uploads/dev3jb-private/ { deny all; }`.

= Does it work with my theme? =
It inherits your theme's fonts and works with block themes (Twenty Twenty-Five) and classic themes (Twenty Twenty-One). Use the blocks or shortcodes on any page.

== Shortcodes ==

`[dev3jb_jobs]`, `[dev3jb_job_search]`, `[dev3jb_recent_jobs]`, `[dev3jb_companies]`, `[dev3jb_submit_job]`, `[dev3jb_employer_dashboard]`, `[dev3jb_candidate_dashboard]`. Each has a matching block.

== Screenshots ==

1. Job board with filters (Twenty Twenty-Five).
2. Applications pipeline.
3. Match score with explanation and the decision-support notice.
4. Resume parsing in the candidate dashboard.
5. Admin dashboard.
6. Pipeline on a phone.

== Changelog ==

= 1.0.0 =
* Initial release.
