# Quiz

Quiz, proctoring, and online test management plugin for Jyavani CMS.

Current release: `1.12.0`.

Quiz `1.12.0` adds a simple, print-friendly Word question-bank export alongside
the complete Excel workbook. Both formats support all-category and
category-specific scopes, preserve question, passage, answer, and score order,
and run as protected pre-layout downloads. Quality Check now presents the
format and category controls in a dedicated responsive export panel so they do
not collide with navigation actions on narrow screens.

Quiz `1.11.1` declares the question-bank export as a pre-layout download and
retains the legacy action marker, preventing XLSX bytes from being rendered
inside the Adiwira dashboard shell on older Core releases.

Quiz `1.11.0` adds a category-aware Excel export to Quality Check for academic
review and accreditation. Staff can export the complete question bank or one
category in stable category, passage, question, and answer order. The workbook
includes readable question/answer summaries, normalized answer rows, scoring
keys, statuses, and public attachment references. It also updates the bundled
PhpSpreadsheet dependency to the security-patched `5.10.0` release.

Quiz `1.10.1` allows Site Owners to grant each separate Quiz capability to
purpose-built custom operational roles without assigning CMS Administrator.

Quiz `1.10.0` redesigns Master Editor as a responsive question-authoring
workspace. Desktop uses a bounded, independently scrolling question bank beside
full-width answer cards, while mobile switches deliberately between the question
list and editor. Participant previews are collapsed by default, autosave state is
visible and protected during navigation, and question selection is keyboard
accessible. The release also standardizes the complete Quiz administration UI
in English and improves dark-mode contrast throughout the editor and bulk-import
workflow.

Quiz `1.9.0` adds audited corrections for submitted responses, including an
immutable answer-key snapshot, required reason, revision conflict protection,
retained prior versions, and transactional score/category recalculation. It also
refreshes the Questions, Students, Attempts, and Attempt Detail administration
surfaces with responsive tables, Core action menus, and improved mobile layouts.

Quiz `1.8.3` adds a validated Global Title setting, defaulting to `Quiz`, so
sites can use a participant-facing label such as `Exam` or `Test` throughout the
English public journey without renaming stored Quizzes or technical routes. It
also extends each Quiz visual-theme override across its dedicated registration,
confirmation, and OTP verification journey. Global registration and multi-Quiz
selection retain the global theme, while a selected Quiz carries its own theme
and streamlined steps through the rest of the participant flow.

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
off, including a Quiz that starts directly without OTP.

## Admin Experience

Quiz `1.10.0` brings Master Builder and its category, passage, question, and
quality-control workflows into the same responsive, English-only administration
experience introduced for the rest of the plugin in Quiz `1.6.0`.

## Requirements

- Jyavani Core 2.3.91 or newer
- PHP 8.3 or newer
- PDO, JSON, and mbstring PHP extensions

Install the plugin as `plugins/quiz`. Jyavani copies the declared assets to
`public/static/plugins/quiz/` and runs the idempotent schema installer on the
next validated Quiz admin request. Public requests fail closed until that
installation succeeds.

The release bundle includes PhpSpreadsheet 5.10 and its optimized Composer
autoload tree, so production and shared-hosting servers do not need Composer or
shell access. Excel export additionally needs the PHP extensions required by
PhpSpreadsheet on 64-bit PHP (`ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`,
`simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, and `zlib`). Attempt Manager
shows Excel as the primary export when those extensions are available and keeps
a semicolon-delimited CSV export as the fallback.

Public quiz routes use a global prefix configured in Quiz Settings, defaulting
to `quiz`. `/quiz/` remains available as the compatibility namespace when the
global prefix changes. Each Quiz can also claim one unique public prefix, such
as `assessment` or `training`, producing routes like `/assessment/register/`
and `/training/register/`.
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

The Quality Check page can export the complete question bank to Excel for
review and accreditation. Staff may export all categories in category/passage
order or select one category. The workbook contains a readable Questions sheet
with answer options and scoring keys plus a normalized Answers sheet with each
answer's status, order, and score. Active and inactive records are retained so
the export is an auditable representation of the bank.

Quiz creation, configuration, duplication, status changes, and deletion require
the dedicated `plugin.quiz.quizzes.manage` permission. Collaborators do not
receive it through workspace access.

Existing category, passage, and question rows are assigned to their quiz owner
when the `1.4.0` ownership migration first runs.

## Advanced Assessment Readiness

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
This lets lecturers continue non-destructive Quiz authoring after a package is
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
Quiz settings/content, and participant identity are not changed by this migration.

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
