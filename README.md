# Quiz

Quiz, proctoring, and online test management plugin for Jyavani CMS.

## Requirements

- Jyavani Core 2.3.74 or newer
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

Public quiz routes are served below `/quiz/`.

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
`quiz_extension_context()` metadata API. This contract does not expose Quiz
tables, public entrypoints, proctoring state, or private request helpers;
extensions must own their own data and lifecycle. The API is callable instead
of event-based so dependency-first plugin loading cannot miss a lifecycle event.

## License

MIT. See `LICENSE`.
