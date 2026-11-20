# Largest Images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** List the images that still need optimising, heaviest first, and optimise the top N — from WP-CLI and from a new wp-admin tab — plus fix GIF optimisation, which has never run.

**Architecture:** A new static class `ATR_Largest` weighs attachments using the optimiser's own file list and its own "already done" rule, both made public on `ATR_Optimizer`. WP-CLI (`ATR_CLI`) and a REST route (`/weigh`) are thin layers over it. The wp-admin tab ranks in the browser and hands the selection to the Rebuild tab's existing runner through the `?ids=…&mode=optimize` handoff the media library already uses.

**Tech Stack:** PHP 7.4+, WordPress 5.7+, WP-CLI, plain JS (IIFE, `wp.apiFetch`, `wp.i18n`).

**Spec:** `docs/superpowers/specs/2026-11-20-largest-images-design.md` — read it before any task.

## Global Constraints

- WP floor 5.7, PHP floor 7.4. No new dependencies; no Composer.
- One class per file under `includes/`, static helpers, `require_once` in `ajax-thumbnail-rebuild.php`.
- Every REST route uses `permission_callback => array( $this, 'check_permission' )`.
- Text domain `ajax-thumbnail-rebuild`; comments in the plugin's existing voice (explain *why*).
- Tests and docs never ship: the wordpress.org copy takes only `ajax-thumbnail-rebuild.php`, `readme.txt`, `assets/`, `includes/`, `languages/`.
- Tests must never optimise the site's own library images, and must force `optimize_service => 'none'` so no test can spend a TinyPNG/ShortPixel quota.
- Commits: explicit paths only (never `git add -A`/`.`/`-a`); **no `Co-Authored-By` trailer**.

## How tests run

No unit-test harness exists. Tests are WP-CLI integration scripts against the local Lando site, in the style of `~/Work/morra/tests/*.php`. From `/home/risto/Work/woocommerce`:

```bash
~/.lando/bin/lando wp eval-file wp-content/plugins/ajax-thumbnail-rebuild/tests/<file>.php 2>&1 | grep -v textdomain
```

Each script ends by calling `atr_report()`, which prints `N checks, F failed` and exits 1 when `F > 0`. **Pass = last line reads `… 0 failed`.**

## Review Focus

1. **Several sizes resolving to the same file** — counted once in the weight, as the optimiser optimises it once. Test in Task 2 (`paths()` unique).
2. **An attachment whose metadata lacks `sizes` or is not an array** (old imports, PDFs mis-typed as images) — weighed as its main file only, never a PHP warning. Test in Task 4.
3. **An animated GIF the programs cannot shrink** — byte-for-byte untouched, every frame kept. Test in Task 1.
4. **`/weigh` with ids that are not attachments, or are another post type** — silently left out, not an error. Test in Task 5.
5. **`wp atr optimize` with an id that does not exist** — reported as a warning and skipped; the run continues. Test in Task 6.

---

### Task 1: Test harness, and GIFs optimised without re-encoding

**Files:**
- Create: `tests/bootstrap.php`, `tests/gif-optimise.php`
- Modify: `includes/class-atr-optimizer.php` (`MIMES` line 19; `optimize_file()` lines 240–278)

**Interfaces:**
- Produces (tests/bootstrap.php, used by every later test):
  - `atr_check( string $what, bool $passed, string $detail = '' ): void`
  - `atr_report(): void` — summary line, `exit( 1 )` on any failure
  - `atr_settings( array $overrides ): void` — `add_filter( 'option_ajax_thumbnail_rebuild_settings', … )` merging `$overrides`; **always merges `'optimize_service' => 'none'`**
  - `atr_fixture_jpeg( int $width, int $height ): string` — GD random-noise JPEG at quality 95 in a temp dir (noise keeps it big and shrinkable)
  - `atr_fixture_gif( bool $preoptimised ): string` — two GD frames merged by `gifsicle --loopcount` into an animated GIF; with `$preoptimised`, also passed through `gifsicle -O3` so the programs cannot shrink it further
  - `atr_fixture_svg(): string`
  - `atr_attach( string $source ): int` — copies into `uploads/atr-tests/` as `atr-test-<random>.<ext>` (so `--filename=atr-test-*` selects fixtures only), `wp_insert_attachment`, `wp_generate_attachment_metadata`, stores it; tracks the id
  - `atr_cleanup(): void` — `wp_delete_attachment( $id, true )` for every tracked id; registered with `register_shutdown_function` by the bootstrap
  - `atr_gif_frames( string $path ): int` — `( new Imagick( $path ) )->getNumberImages()`
- Produces (optimizer): `ATR_Optimizer::optimizes( string $mime ): bool` — the one predicate for "formats the optimiser works on"

- [ ] **Step 1: Write `tests/bootstrap.php`** with the helpers above. Guard: `if ( ! defined( 'WP_CLI' ) ) exit( "Run through WP-CLI\n" );`.

- [ ] **Step 2: Write the failing test `tests/gif-optimise.php`**

  Calls `atr_settings( array() )`, then:
  - `ATR_Optimizer::optimizes( 'image/gif' )` is true; `optimizes( 'image/svg+xml' )` is false; jpeg/png/webp true.
  - Unoptimised animated GIF (`atr_fixture_gif( false )`), `$before = filesize()`: `ATR_Optimizer::optimize_file( $path )` returns `> 0`; `filesize( $path ) < $before`; `atr_gif_frames( $path ) === 2`.
  - Pre-optimised animated GIF (`atr_fixture_gif( true )`), `$hash = md5_file()`: `optimize_file( $path )` returns `0`; `md5_file( $path ) === $hash`; `atr_gif_frames( $path ) === 2`.

- [ ] **Step 3: Run it — expect FAIL** (`optimizes()` undefined, and `optimize_file()` returns 0 for GIFs).

- [ ] **Step 4: Implement**
  - Add `'image/gif'` to `MIMES`.
  - `public static function optimizes( string $mime ): bool` — `in_array( $mime, self::MIMES, true )`; `optimize_file()` uses it instead of its inline `in_array`.
  - New `const NEVER_REENCODE = array( 'image/gif' );` with a comment on why (the image editor keeps only the first frame of an animation). In `optimize_file()`, the early return before `reencode()` becomes `if ( $gain > 0 || $lossless_only || in_array( $mime, self::NEVER_REENCODE, true ) ) return $gain;`.

- [ ] **Step 5: Run it — expect `… 0 failed`.**

- [ ] **Step 6: Commit**

```bash
git add tests/bootstrap.php tests/gif-optimise.php includes/class-atr-optimizer.php
git commit -m "Optimise GIFs, and never write one out again" -m "<body: gifsicle was defined but MIMES kept GIFs out, so it never ran; the re-encode fallback would flatten an animation to its first frame, so GIFs skip it>"
```

---

### Task 2: The optimiser's file list and its "already done" rule, made public

**Files:**
- Create: `tests/optimizer-api.php`
- Modify: `includes/class-atr-optimizer.php` (`optimize_attachment()` lines 72–167), `includes/class-atr-rest-controller.php` (`optimize()` lines 295–322)

**Interfaces:**
- Produces:
  - `ATR_Optimizer::paths( string $file, array $metadata ): string[]` — unique absolute paths in this order: `$file`, each `sizes[*].file` in `dirname( $file )`, then the untouched original only when `ATR_Settings::get( 'optimize_original' )` and `original_image` exists on disk. Paths are not checked for existence except the original (unchanged behaviour).
  - `ATR_Optimizer::needs_optimizing( array $metadata ): bool` — `! self::already_done( $metadata, (int) ATR_Settings::get( 'optimize_quality', ATR_Uploads::CORE_QUALITY ) )`
  - `ATR_Optimizer::optimize_and_store( int $attachment_id ): array` — `optimize_attachment( $attachment_id )`, then `wp_update_attachment_metadata()` when `'' === $result['message']`; returns the result. The REST `optimize()` handler switches to it (its response shape unchanged).
- `optimize_attachment()` builds its list with `paths()` and identifies the archive with a private `archive_path( string $file, array $metadata ): string` that `paths()` also uses. No behaviour change.

- [ ] **Step 1: Write the failing test `tests/optimizer-api.php`**
  - JPEG fixture 2400×1600 attached: `paths( $file, $meta )` equals `array_values( array_unique( [ $file, …each size file… ] ) )`; first element is `$file`.
  - Same with `atr_settings( [ 'optimize_original' => true ] )` and a fixture big enough to be scaled, so `original_image` exists — force scaling with `add_filter( 'big_image_size_threshold', fn() => 1000 )` rather than relying on the site's own limit: last element is the original.
  - Metadata with two sizes pointing at the same `file`: that file appears once.
  - `needs_optimizing()` is true on fresh metadata; after `optimize_and_store( $id )`, `needs_optimizing( wp_get_attachment_metadata( $id, true ) )` is false.
  - After `atr_settings( [ 'optimize_quality' => <stored quality + 1> ] )`, it is true again.

- [ ] **Step 2: Run — expect FAIL** (methods undefined).

- [ ] **Step 3: Implement** the three public methods and `archive_path()`; refactor `optimize_attachment()` and the REST handler onto them.

- [ ] **Step 4: Run `tests/optimizer-api.php` and `tests/gif-optimise.php` — both `… 0 failed`.**

- [ ] **Step 5: Commit** — `git add tests/optimizer-api.php includes/class-atr-optimizer.php includes/class-atr-rest-controller.php`, subject e.g. "Let the optimiser say what it would work on, and whether it would".

---

### Task 3: `query()` keeps the order of an `include` list

**Files:**
- Create: `tests/attachments-order.php`
- Modify: `includes/class-atr-attachments.php` (line 78)

**Interfaces:**
- `ATR_Attachments::query( array( 'include' => int[] ) )` returns rows in the order of `include`. Without `include`, order stays `p.post_date DESC`.

- [ ] **Step 1: Failing test** — three JPEG fixtures `$a, $b, $c` (inserted in that order); `wp_list_pluck( query( [ 'include' => [ $b, $c, $a ] ] ), 'id' ) === [ $b, $c, $a ]`; with `[ $c, $a, $b ]` likewise.
- [ ] **Step 2: Run — expect FAIL** (comes back newest first).
- [ ] **Step 3: Implement** — when `$include` is non-empty, `ORDER BY FIELD( p.ID, <same absint list> )`; a comment saying the media library and the Largest tab hand over a selection in an order that matters.
- [ ] **Step 4: Run — `… 0 failed`.**
- [ ] **Step 5: Commit** — `tests/attachments-order.php includes/class-atr-attachments.php`.

---

### Task 4: `ATR_Largest` — weighing and ranking

**Files:**
- Create: `includes/class-atr-largest.php`, `tests/largest-images.php`
- Modify: `ajax-thumbnail-rebuild.php` (`require_once` beside the other includes)

**Interfaces:**
- Consumes: `ATR_Optimizer::paths()`, `::needs_optimizing()`, `::optimizes()` (Tasks 1–2); `ATR_Attachments::query()`.
- Produces:
  - Row shape `array{id:int, file:string, bytes:int, files:int, done:bool}` — `file` is the basename of the attached file; `files` counts paths that exist; `bytes` sums their `filesize()`.
  - `ATR_Largest::weigh( int[] $ids, bool $all = false ): array` — rows in input order for each id that is an `attachment`, whose `post_mime_type` passes `optimizes()`, whose attached file exists, and (when `! $all`) that `needs_optimizing()`. Done and mime checks happen **before** any `filesize()`. Primes post and meta caches for `$ids` first.
  - `ATR_Largest::rank( array $rows, int $number = 0 ): array` — `bytes` descending, ties by `id` ascending; cut to `$number` when `> 0`.
  - `ATR_Largest::find( array $filters = array(), bool $all = false, int $number = 0 ): array` — ids from `ATR_Attachments::query( $filters )`, weighed in chunks of `ATR_Largest::CHUNK = 200`, then ranked. `$filters` accepts `only_featured` and `filename` exactly as `query()` does.

- [ ] **Step 1: Failing test `tests/largest-images.php`** (`atr_settings( [] )`):
  - Fixtures: JPEG `$big` 3000×2000, JPEG `$small` 800×600, JPEG `$done` 2000×1500 put through `optimize_and_store()`, SVG `$svg`, JPEG `$gone` whose attached file is then `unlink`ed.
  - `weigh( [ all five ] )` ids are exactly `[ $big, $small ]`.
  - `weigh( [ all five ], true )` contains `$done` with `done === true`; still no `$svg`, no `$gone`.
  - For `$big`, `bytes === array_sum( array_map( 'filesize', paths( … ) ) )` and `files === count( paths( … ) )`.
  - `rank( weigh( … ) )` puts `$big` first; `rank( $rows, 1 )` has one row.
  - Ties: `rank( [ [ 'id' => 9, 'bytes' => 5 ], [ 'id' => 3, 'bytes' => 5 ] ] )` → ids `[ 3, 9 ]`.
  - Review Focus 2: attachment with `_wp_attachment_metadata` set to a string and one with no `sizes` key — no warning (`error_get_last()` unchanged), weighed as the main file only.
  - `find( [ 'include' => <fixture ids> ], false, 1 )` returns one row; `optimize_and_store()` on that id returns `reason !== 'already'`.
- [ ] **Step 2: Run — FAIL** (class missing).
- [ ] **Step 3: Implement** `ATR_Largest` per the interfaces; class docblock says why weight = the optimiser's own file list.
- [ ] **Step 4: Run all `tests/*.php` (except bootstrap) — each `… 0 failed`.**
- [ ] **Step 5: Commit** — `includes/class-atr-largest.php tests/largest-images.php ajax-thumbnail-rebuild.php`.

---

### Task 5: REST route `POST /weigh`

**Files:**
- Create: `tests/rest-weigh.php`
- Modify: `includes/class-atr-rest-controller.php` (`register_routes()`; new `weigh()` callback)

**Interfaces:**
- `POST /ajax-thumbnail-rebuild/v1/weigh` — args `ids` (array of integers, required, `maxItems` 500, items `minimum` 1) and `all` (boolean, default false). Response `{ "items": ATR_Largest::weigh( ids, all ) }`.

- [ ] **Step 1: Failing test** using `rest_do_request( new WP_REST_Request( 'POST', '/ajax-thumbnail-rebuild/v1/weigh' ) )`:
  - As user 0: status 401.
  - As an administrator (`wp_set_current_user()`), `ids` = two fixture JPEGs plus a regular post id plus `999999999`: status 200, `items` ids are exactly the two fixtures (Review Focus 4).
  - 501 ids: status 400.
- [ ] **Step 2: Run — FAIL** (404, no route).
- [ ] **Step 3: Implement** the route and callback.
- [ ] **Step 4: Run — `… 0 failed`.**
- [ ] **Step 5: Commit** — `tests/rest-weigh.php includes/class-atr-rest-controller.php`.

---

### Task 6: WP-CLI — `wp atr largest` and `wp atr optimize`

**Files:**
- Create: `includes/class-atr-cli.php`, `tests/cli.php`
- Modify: `ajax-thumbnail-rebuild.php` — `if ( defined( 'WP_CLI' ) && WP_CLI ) { require_once …class-atr-cli.php; WP_CLI::add_command( 'atr', 'ATR_CLI' ); }`

**Interfaces:**
- Consumes: `ATR_Largest::find()`, `::weigh()`; `ATR_Optimizer::optimize_and_store()`; `ATR_Tools::available()`; `ATR_Service::chosen()` / `::services()`.
- `ATR_CLI::largest( $args, $assoc_args )` — synopsis exactly as the spec: `[--number=<n>]` (default 20), `[--all]`, `[--featured]`, `[--filename=<glob>]`, `[--format=<table|csv|json|ids|count>]` (default `table`). `table`/`csv` columns: `id, file, size, files` with `size_format()`; `json` gives raw `bytes`; `ids` prints ids space-separated on one line; `count` prints the number of rows.
- `ATR_CLI::optimize( $args, $assoc_args )` — `[<id>...]`, `[--largest=<n>]`, `[--featured]`, `[--filename=<glob>]`, `[--dry-run]`. No `--all`.
  - Ids given: in that order; a non-attachment id → `WP_CLI::warning()` and left out (Review Focus 5).
  - Else `find( filters, false, largest ?: 0 )` ids.
  - Header line before any work: the installed programs (names from `ATR_Tools::available()`, or "none — files can only be written out again"), and when `ATR_Service::chosen()` is set, `"Up to N images will be sent to <label>."`.
  - `--dry-run`: header, then the rows as a table, then stop. Writes nothing.
  - Run: progress bar (`WP_CLI\Utils\make_progress_bar`), `optimize_and_store()` per id; then `WP_CLI::success()` with images optimised (`saved > 0`), total saved (`size_format`), and skipped counts by `reason` (`already`, `missing`, others as "nothing gained").

- [ ] **Step 1: Failing test `tests/cli.php`** — commands via `WP_CLI::runcommand( '…', [ 'launch' => false, 'return' => 'all', 'exit_error' => false ] )` so the test's `atr_settings()` filter applies:
  - `atr largest --format=ids --number=2` with fixtures `$big`, `$small` restricted by `--filename=<fixture pattern>`: stdout `"$big $small"`.
  - `atr largest --format=count --all --filename=<pattern>` counts the done fixture too.
  - `atr optimize --dry-run --largest=1 --filename=<pattern>`: stdout contains `$big`'s file name; `$big` metadata unchanged afterwards (still `needs_optimizing()`).
  - `atr optimize <$big> 999999999`: stderr has a warning naming `999999999`; return code 0; `$big` no longer `needs_optimizing()`; stdout contains `Success`.
- [ ] **Step 2: Run — FAIL** (`'atr' is not a registered wp command`).
- [ ] **Step 3: Implement** `ATR_CLI` with WP-CLI docblock synopses (they are the help text).
- [ ] **Step 4: Run — `… 0 failed`. Then by hand, read-only, on the real library:**
  ```bash
  ~/.lando/bin/lando wp atr largest --number=5
  ~/.lando/bin/lando wp atr largest --all --number=5
  ~/.lando/bin/lando wp atr optimize --largest=3 --dry-run
  ```
  Expected: the first lists the ~13 images not yet optimised; the second lists the heaviest overall; the third prints the header and three rows and changes nothing.
- [ ] **Step 5: Commit** — `includes/class-atr-cli.php tests/cli.php ajax-thumbnail-rebuild.php`.

---

### Task 7: The "Largest images" tab

**Files:**
- Create: `assets/js/largest.js`
- Modify: `includes/class-atr-admin-page.php` (`tabs()` line 52, the tab dispatch ~line 102, new `render_largest_tab()`, the notice at line 189), `includes/class-atr-plugin.php` (`enqueue_page()` ~line 117), `assets/css/admin.css`

**Interfaces:**
- Consumes: `GET /attachments` (existing), `POST /weigh` (Task 5), `ATRSettings` (existing localized object).
- `tabs()` gains `'largest' => __( 'Largest images', 'ajax-thumbnail-rebuild' )` after `'cleanup'`.
- `ATRSettings.rebuildUrl` = `ATR_Admin_Page::url( 'rebuild' )`.
- Script handle `ajax-thumbnail-rebuild-largest`, deps as `cleanup.js`, `wp_set_script_translations()` like the others.
- Markup ids the JS relies on: `#atr-largest-all` (checkbox), `#atr-largest-scan` (button), `#atr-largest-progress`, `#atr-largest-results` (table body), `#atr-largest-summary`, `#atr-largest-optimize` (button).

Behaviour (`largest.js`, in `cleanup.js`'s IIFE style; reuse its `formatBytes` approach):
- Scan: `/attachments` → ids → chunks of 200 → `POST /weigh { ids, all }` one after another; progress "measured X / Y images"; rows accumulated.
- Sort `bytes` desc, ties `id` asc; show top **100**; tick the first **20** that are not `done`.
- Done rows: labelled "Optimised", checkbox disabled.
- Summary: "Selected: N images, <size>"; button disabled at 0.
- "Optimise selected": `window.location = settings.rebuildUrl + '&ids=' + selected.join( ',' ) + '&mode=optimize'` — selected ids in table order (heaviest first).
- Empty result: a notice "Every image is already optimised." (or, with `all`, "No images found.").
- Landing notice in `class-atr-admin-page.php:189`: `_n( 'Working on the %s image you picked.', 'Working on the %s images you picked.', … )`.

- [ ] **Step 1: Implement** the PHP tab, enqueue, CSS and JS.
- [ ] **Step 2: `php -l` every changed PHP file; `node --check assets/js/largest.js`.** Expected: no errors.
- [ ] **Step 3: Browser check** on the local site (`Tools → AJAX Thumbnail Rebuild → Largest images`): scan completes; ~13 rows by default, heaviest first; ticking "include optimised" rescans and shows done rows disabled; "Optimise selected" lands on the Rebuild tab with **Optimise** chosen, the neutral notice, and — after Start — the images processed **in the order of the Largest tab**. Screenshot before (Rebuild tab, no Largest tab) and after.
- [ ] **Step 4: Run all `tests/*.php` — each `… 0 failed`.**
- [ ] **Step 5: Commit** — `assets/js/largest.js assets/css/admin.css includes/class-atr-admin-page.php includes/class-atr-plugin.php`.

---

### Task 8: Readme, version 2.3.0, translations template

**Files:**
- Modify: `readme.txt`, `ajax-thumbnail-rebuild.php` (header `Version`, `ATR_VERSION`), `languages/ajax-thumbnail-rebuild.pot`

- [ ] **Step 1: Readme** — `Stable tag: 2.3.0`; `= 2.3.0 =` changelog: the Largest images tab, the two CLI commands, GIFs now optimised (gifsicle / service, never re-encoded — a change on sites with gifsicle), a selection handed to the Rebuild tab now runs in the order it was handed over; Upgrade Notice line for 2.3.0; a short **WP-CLI** section in the description with both commands and one example (`wp atr optimize --largest=50`); FAQ entry on images optimised by another plugin (appear in the list; lossless programs save ~nothing; lossy re-encode degrades them again).
- [ ] **Step 2: Version** — header and `ATR_VERSION` to `2.3.0`.
- [ ] **Step 3: POT** — from `/home/risto/Work/woocommerce`: `~/.lando/bin/lando wp i18n make-pot wp-content/plugins/ajax-thumbnail-rebuild wp-content/plugins/ajax-thumbnail-rebuild/languages/ajax-thumbnail-rebuild.pot --exclude=tests,docs`. Expected: new strings ("Largest images", "Working on the %s image you picked.") present.
- [ ] **Step 4: Run all `tests/*.php` once more — each `… 0 failed`.**
- [ ] **Step 5: Commit** — `readme.txt ajax-thumbnail-rebuild.php languages/ajax-thumbnail-rebuild.pot`, subject "Say what 2.3.0 changes on the Plugins screen".
