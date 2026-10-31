# Project workspace acceptance

This suite exercises the real Phorge HTTP application with synthetic users and
native project/task transactions. It creates a new internal Docker network,
tmpfs MySQL and file-storage containers, and an Apache application from a
read-only snapshot of the checkout's versioned files. It uses no existing site
configuration, database, data volume, identity gateway or user session.

Prerequisites are Python 3, Docker Engine on Linux, Node.js 20+, local images
built from this fork's Dockerfile and its paired Gorge file-storage service,
and a local MySQL 8 image. All image names are explicit; the runner uses
`--pull=never`. The file service is needed by the fork's native avatar and
project-icon generation. It stores only temporary acceptance data.

Install the pinned browser dependency and Chromium explicitly:

```sh
cd tests/project-workspace
npm ci
npx playwright install chromium
cd ../..
```

Run from the repository root, substituting your existing local image tags:

```sh
python3 tests/project-workspace/run.py \
  --phorge-image phorge-workspace:local \
  --mysql-image mysql:8.0 \
  --gorge-image gorge-file-storage:local \
  --output /tmp/phorge-workspace-acceptance
```

The output directory must not already exist. Without `--output`, the runner
creates a private temporary artifact directory and prints its path. The runner
first bootstraps a fresh schema, runs the 18 native `TeamProjectProgressTestCase`
cases, creates synthetic browser fixtures, exercises HTTP flows, and verifies
the resulting transaction/task rows. `--skip-native` only omits the repeated
unit suite; the final browser-persistence verification still runs. Existing
Playwright installations can be selected with `--playwright-module` pointing at
the installed package's `index.mjs`. The PHP container source path can be changed
with `--container-root`.

The browser uses only a random host-loopback port and blocks requests to other
origins. Fixtures require explicit opt-in environment variables, a generated
acceptance namespace and its matching database hostname. Synthetic session
cookies exist only in a private temporary fixture file, which is removed with
the generated configuration. Containers and the internal network are removed on
success, failure and handled interruption. Browser reports, database assertions,
command logs and desktop/mobile screenshots remain in the private output
directory. A successful report also requires cleanup to succeed.

Covered flows include:

- Editor publication of nodes and comments through the real form; participants,
  attribution, percentage preservation and append-only stable record links.
- Read-only project members and nonmembers: visible history, no publication,
  direct-POST rejection, private-project and private-task isolation.
- Canonical and historical node types, fixed legacy links beyond the first
  history page, paging, native mentions, transaction details and filtered feed.
- One task/history entry, native state badges, native sort/filter/default/advanced
  menus, and actual state/time/author index changes.
- Unsaved blank and historical-record task drafts: GET writes zero tasks,
  source attribution is retained, native policies/default status are preserved,
  and exactly one task is created by explicit Save.
- Real mouse dragging from the bottom of a vertically scrolled long column into
  a horizontally scrolled fourth column, AJAX card rendering and persistence
  after reload; ordinary column movement retains the task's completed state.
- Native fullscreen and desktop/mobile workspace layout, visible toolbar labels,
  working history navigation and 390px history/form rendering.

The acceptance fixture intentionally seeds a few pre-version-2 transaction
payloads inside the guarded disposable database. The production write path
continues to normalize all new node payloads and is never relaxed for tests.
The suite does not cover external authentication, real email delivery or
production performance. People-profile and custom-policy-option regressions
belong to their separate feature suites.
