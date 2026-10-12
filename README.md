# Quiz

Quiz, proctoring, and online test management plugin for Jyavani CMS.

Current release: `1.8.2`.

Quiz `1.8.2` keeps confirmation UI aligned with per-Quiz OTP overrides and makes
an administrator reset release the previous browser/device binding so the
participant can start again from their current browser. Question navigation now
also turns a category range green as soon as every question in it is answered,
and each Quiz can hide the floating exam-mode warning badge independently.

Quiz `1.8.1` fixes participant question loading by rendering public API URLs as
JSON before composing the take-page JavaScript. PHP tags can no longer leak
literally into the browser script and stop execution at the first question.

Quiz `1.8.0` adds nullable per-Quiz overrides for OTP verification and the
fullscreen, focus, context-menu, shortcut, and DevTools proctor controls. A
Quiz can inherit the secure defaults or explicitly force each control on or
off, including direct TPA startup without OTP.

## Admin Experience

Quiz `1.6.0` redesigns the dashboard, quiz management, students, attempts, and
settings as a responsive administration console with consistent English copy,
keyboard-friendly action menus, improved focus handling, dark mode, and
content-hashed admin assets. Master Builder and its category, passage, question,
and quality-control workflows retain their existing interface and behavior.

## Requirements

- Jyavani Core 2.3.91 or newer
- PHP 8.3 or newer
- PDO, JSON, and mbstring PHP extensions

Install the plugin as `plugins/quiz`. Jyavani copies the declared assets to
`public/static/plugins/quiz/` and runs the idempotent schema installer on the
next validated Quiz admin request. Public requests fail closed until that
installation succeeds.

The release bundle includes PhpSpreadsheet 5.8 and its optimized Composer
autoload tree, so production and shared-hosting servers do not need Composer or
shell access. Excel export additionally needs the PHP extensions required by
PhpSpreadsheet on 64-bit PHP (`ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`,
`simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, and `zlib`). Attempt Manager
shows Excel as the primary export when those extensions are available and keeps
a semicolon-delimited CSV export as the fallback.

Public quiz routes use a global prefix configured in Quiz Settings, defaulting
to `quiz`. `/quiz/` remains available as the compatibility namespace when the
global prefix changes. Each Quiz can also claim one unique public prefix, such
as `pmb` or `tpa`, producing routes like `/pmb/register/` and `/tpa/register/`.
Only explicit Quiz endpoints are claimed; unknown descendants continue to
Jyavani's normal content and 404 resolver.

## Authorization Model

Quiz `1.4.0` separates dashboard entry, question-bank collaboration, quiz
management, attempt monitoring, OTP visibility, exports, operations, and
deletion. The compatibility roles in `plugin.json` provide system-role defaults;
custom roles can receive permissions marked as delegable.

Every account using Quiz in the dashboard also needs Core's
`core.dashboard.access` permission.

### Attempt Monitor

A read-only quiz operator should receive:

- `plugin.quiz.dashboard.access`
- `plugin.quiz.attempts.view`
- `plugin.quiz.attempts.export`
- `plugin.quiz.attempt-otp.view` when manual OTP delivery is required

This role can monitor participant identity, test number, status, timestamps,
and answered progress across quizzes. It cannot view participant responses,
operate or delete attempts, edit quiz content, or manage the CMS unless those
permissions are granted separately.

### Question Contributor

A lecturer contributing question-bank content should receive:

- `plugin.quiz.dashboard.access`
- `plugin.quiz.workspace.access`

An administrator must then assign the lecturer as a collaborator on each quiz
they are responsible for. `quiz_collaborators` is keyed by quiz and user, so a
lecturer assigned to Quiz A receives no access to Quiz B unless assigned there
too. A quiz may have multiple collaborators simultaneously.

Collaborators can read the assigned quiz's shared bank, create categories,
passages, questions, and answers, and mutate only resources they own. Answers
inherit ownership from their question. The quiz owner and users with
`plugin.quiz.content.manage-any` may manage all resources in the quiz.

Quiz creation, configuration, duplication, status changes, and deletion require
the nondelegable `plugin.quiz.quizzes.manage` permission. Collaborators do not
receive it through workspace access.

Existing category, passage, and question rows are assigned to their quiz owner
when the `1.4.0` ownership migration first runs.

## TPA Readiness

Quiz `1.5.0` adds opt-in answer ordering, timer warnings, mark-for-review, and
participant result pages. Upgrade defaults deliberately preserve `1.4.13`
behavior: answers remain shuffled, timer warnings are empty, review marking is
off, participant results are hidden, and proctor behavior is unchanged. Quiz
add/edit pages configure warning thresholds in whole minutes; they are stored
as normalized remaining seconds.

Submissions and timer expiry share one idempotent finalizer. It records elapsed
seconds and category/domain aggregates while preserving the legacy
`quiz_attempts.total_score` and dashboard score-out-of-100 calculations. Answer
changes are append-only revisions; identical save retries do not create a
revision. Questions and options referenced by attempts are protected by
application-level deletion guards.

### Delivery Snapshots

For packages prepared by `1.5.0`, each assigned question freezes its text, type,
media and file references, passage, effective category, package limits, active
answer IDs and labels, option scores, delivery order, and available score before
responses are accepted. Participant delivery, scoring, navigation, and admin
response history use those snapshots rather than mutable bank presentation.
This lets lecturers continue non-destructive PMB authoring after a package is
fully snapshotted.

During the `1.5.0` admin-side schema upgrade, Quiz backfills every legacy
assignment, including assignments on submitted attempts. Attempts are processed
in ID order with one transaction per attempt. Existing complete snapshot fields
are never rewritten, so an interrupted migration can safely retry after MySQL
DDL commits. The backfill freezes the question-bank state available at migration
time without changing existing response records. The schema revision and plugin
version are recorded only after a global completeness check succeeds. A failed
assignment leaves the revision pending and public Quiz routes unavailable until
an administrator retries the upgrade. Attempt status, scores, answers, ordering,
PMB settings/content, and participant identity are not changed by this migration.

This release intentionally retains the legacy foreign keys and does not perform
a destructive rewrite of old attempts. Question deletion therefore remains
blocked while any assignment references it, and answer deletion remains blocked
while current or revision history references it. Direct database writes and
external writers can still bypass application-level guards or trigger legacy
foreign-key cascades. Participant result links are withheld unless delivery,
option, denominator, and final category snapshots are internally complete;
older or ambiguous submissions remain unavailable rather than exposing a raw
score.

## Development

Run the compatibility contract against the local canonical Core checkout:

```bash
QUIZ_CORE_ROOT=/path/to/jyavani php tests/contract.php
```

When updating spreadsheet dependencies, run Composer in the development
checkout and include the resulting `composer.lock` and `vendor/` directory in
the plugin release:

```bash
composer install --no-dev --optimize-autoloader
```

Set `QUIZ_CORE_ROOT` when the Core checkout cannot be detected from the plugin
location.

Source repository: <https://github.com/adammuizweb/quiz-system>

## Extension API

Sibling plugins may declare a manifest dependency on Quiz and use the stable
`quiz_extension_api_version()` capability check plus the callable
`quiz_extension_context()` metadata API. API `1.1.0` also provides
`quiz_extension_subscribe()` for the post-commit `attempt.submitted`,
`answer.saved`, and `question.review_changed` events. Listener failures are
logged and isolated. Event payloads contain identifiers and non-sensitive
state only; they exclude tokens, OTP/contact data, IP/UA data, raw answers,
selected options, scores, and correctness.
This contract does not expose Quiz tables, public entrypoints, proctoring state,
or private request helpers; extensions must own their own data and lifecycle.

## License

MIT. See `LICENSE`.
