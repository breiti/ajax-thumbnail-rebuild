# Largest images: find the heaviest, optimise them first

## Why

A client's media library has grown heavy and they want the weight down. Optimising
everything is slow, and where a TinyPNG or ShortPixel key is set up it spends a
monthly quota. What they actually want is the most saving for the least work:
**the heaviest images first**. Today the plugin can optimise all of them, a
filename pattern, the featured ones, or a hand-picked selection from the media
library — but it cannot tell you which ones weigh the most.

This is for every user of the plugin, not one site. Two kinds of people run it:
an agency with SSH, and a shop owner with only wp-admin. Both get it.

## Goals

- List the images that **still need optimising**, heaviest first.
- Optimise the top N of that list in one go, from the CLI or from wp-admin.
- Reuse the optimiser's own rules, so the list and the optimiser can never
  disagree about what "needs optimising" means.

## Non-goals

- A WP-CLI `rebuild` command. `wp media regenerate` exists; this is about weight.
- A stored size index. Measured fresh every time (see Approaches).
- Estimating how much each image *would* save. Unknowable without trying; raw
  weight is the honest proxy.
- Detecting images optimised by another plugin (Smush, EWWW, ShortPixel's own).

## Anti-goals

- A list whose top entry the optimiser then refuses as "already done". That
  would be the feature failing at its one job.
- Any change to how the Rebuild tab's runner works. It is the runner; this only
  hands it a selection. (Two small changes around it are in scope, see below:
  `ATR_Attachments::query()` keeping the order of an `include` list, and the
  landing notice no longer saying the images came from the media library.)
- Re-optimising images that are already done. No force mode: it would re-encode
  lossy files a second time.
- A new code path that has to be remembered on upload, rebuild, replace, cleanup
  or on-demand cutting. That is the scattered-special-cases shape 2.2.1's review
  removed.

## Approaches

How images are ranked by weight was the decision that shaped everything else.

1. **Measure on every scan, no index** — `filesize()` on each file of each image,
   sum, sort. Always correct, no state to drift. Costs a few minutes on a
   50 000-image library. **Chosen.**
2. **A persistent index in postmeta**, kept up to date by hooks — instant
   `ORDER BY`, but six code paths would have to remember to update it, and one
   that forgets makes the ranking lie silently. Still needs a full scan to seed.
   Rejected.
3. **Core's own `filesize` in the attachment metadata** — serialised, so SQL
   cannot sort on it and PHP has to unpack every row; absent on everything
   uploaded before WP 6.0, which is exactly the old heavy library this targets;
   absent entirely on WP 5.7–5.9. Rejected.

Scope was chosen separately: **CLI and a wp-admin tab** (not CLI alone), because
the shop owner without SSH is a large share of the people this is for.

## Decided

### What "needs optimising" means

Exactly what the optimiser already decides: `ATR_Optimizer::already_done()`. An
image counts as done when it was optimised at **at least the current quality**,
with **the same set of optimiser programs** (`ATR_Tools::signature()`), and —
if lossy is now on — with lossy. Change a setting or install a new program and
images come back into the list on their own.

`already_done()` is private today. It becomes reachable through one public
method (e.g. `ATR_Optimizer::needs_optimizing( array $metadata ): bool`) that
reads the current quality itself. No second rule is written anywhere.

The check reads only metadata, so done images are skipped **before** any file is
measured: on a well-optimised library the scan gets faster, not slower.

### What "weight" means

The bytes the optimiser would actually work on — **the same file list
`optimize_attachment()` builds**: the attached file, every size in the
metadata, and the untouched original only when `optimize_original` is on.
That list is built inline in `optimize_attachment()` today; it is extracted
into one public method that both the optimiser and the weighing call. WebP/AVIF
copies are **not** counted: the optimiser does not touch them, so counting them
would rank images by weight that optimising cannot remove.

Files that are listed in the metadata but missing on disk count as 0 bytes. An
image whose attached file is missing is left out of the list (the optimiser
would report it as "missing" anyway).

Only formats the optimiser actually works on are listed — judged by **the same
predicate `optimize_file()` uses** (today the `ATR_Optimizer::MIMES` constant),
exposed as one public method that both call. Otherwise a heavy SVG, AVIF or HEIC
would top the list and then save nothing. With the GIF fix below, GIFs qualify.
The mime is taken from the attachment's `post_mime_type`, so the format check
also happens before any file is measured.

### GIFs (an existing bug, fixed in the same release)

`ATR_Tools` defines a gifsicle chain for `image/gif` (`-O3`, lossless) and
ShortPixel accepts GIFs, but `ATR_Optimizer::MIMES` leaves `image/gif` out, so
`optimize_file()` returns 0 before either is tried: gifsicle has never run.
Adding GIF to the list naively is unsafe: when the programs save nothing,
`optimize_file()` falls through to `reencode()`, which writes the image out again
through the image editor — and an animated GIF loses every frame but the first.

Fix: GIF becomes a handled format, but **never re-encoded**. It goes through the
service (where one is set up and handles GIF) and the local programs only. Made
as its own commit, before the feature, so the change in behaviour is visible on
its own in the history. It is a change on every site with gifsicle installed;
the changelog says so.

### Showing already-optimised images anyway

Off by default. A switch shows every image regardless — `--all` on
`wp atr largest`, a checkbox in wp-admin — for someone who simply wants to see
the heaviest, e.g. to decide the upload size limit. Toggling it rescans; it is a
scan parameter, not a client-side filter (keeping the skip-before-measuring
speed-up for the default).

It is a way of **looking**, not of optimising: rows for done images carry a
`done` flag, are shown marked as optimised, and cannot be selected in wp-admin.
`wp atr optimize` has no `--all`.

### WP-CLI

Registered only when `WP_CLI` is defined. Command name: `wp atr` *(builder's
call to keep or change; short, matches the code prefix)*.

```
wp atr largest [--number=<n>] [--all] [--featured] [--filename=<glob>]
               [--format=<table|csv|json|ids|count>]
```
Lists the N heaviest images (default 20) that still need optimising: id, title,
total bytes (human-readable in `table`), number of files. `--format=ids` prints
space-separated ids so it pipes into the next command.

```
wp atr optimize [<id>...] [--largest=<n>] [--featured]
                [--filename=<glob>] [--dry-run]
```
Optimises the given ids, or the N heaviest that need it (`--largest`), or — with
neither — every image that needs it, heaviest first. Ids given by hand run in the
order given; one that is already done is skipped as `already`, exactly as the
wp-admin runner skips it. Progress bar, then a summary:
images optimised, bytes saved, skipped by reason (`already`, `missing`, nothing
gained). Before starting it prints one line naming the optimiser programs and,
if one is set up, the **service** and how many images will be sent, so quota use
is never a surprise. `--dry-run` prints that line and the list, and stops.

Writes metadata exactly the way the REST `/optimize` route does today (update
only when the result carries no message).

`--featured` and `--filename` map onto `ATR_Attachments::query()`'s existing
`only_featured` and `filename` filters.

### wp-admin: a "Largest images" tab

A new tab on the plugin's Tools screen, after Cleanup.

```
 Largest images
 ───────────────────────────────────────────────────────────────
 Images that still need optimising, heaviest first.

 [ ] Include images that are already optimised      [ Scan ]

 ████████████░░░░░░░░  4 200 / 10 431 images measured

 ☑  #8812  hero-banner-2019.jpg          18.4 MB   14 files
 ☑  #3301  lookbook-cover.png            11.2 MB   13 files
 ☐  #  77  team-photo.jpg                 9.8 MB   13 files
 …                                       (top 100)

 Selected: 2 images, 29.6 MB          [ Optimise selected ]
```

- The browser gets the id list from the existing `/attachments` route, sends it
  in chunks to one new route that weighs them, and keeps the rows itself;
  sorting and the top-100 cut happen in the browser. No server-side cache.
- New route: `POST /ajax-thumbnail-rebuild/v1/weigh` — body `ids` (array, at most
  a few hundred per request — builder's call on the cap) and `all` (bool).
  Returns one row per image that qualifies: `id`, `bytes`, `files`. Same
  permission check as every other route.
- **"Optimise selected" hands the selection to the Rebuild tab** by going to
  `ATR_Admin_Page::url( 'rebuild' )` with `ids=…&mode=optimize` — the exact
  handoff the media library's bulk action already uses
  (`ATR_Media_Library::handle_bulk_action()`). The existing runner does the work,
  one image at a time, with its progress bar, Stop button and skip list.
  Nothing new runs optimisation in the browser.
- **The heaviest-first order survives the handoff.** Today it would not: on
  Start, `admin.js` re-fetches `/attachments?include=…` and
  `ATR_Attachments::query()` always sorts `ORDER BY p.post_date DESC`
  (`class-atr-attachments.php:78`), so the selection would run newest first —
  and a Stop or an exhausted quota halfway could leave the heaviest undone.
  Fix: when `include` is given, `query()` returns rows in the order of the
  `include` list (`ORDER BY FIELD( p.ID, … )`). The media library's bulk action
  benefits too: it then runs in the order of the selection.
- The landing notice on the Rebuild tab says "Working on the N images you picked
  in the media library" (`class-atr-admin-page.php:189`). It becomes neutral —
  the images now come from two places.
- The user still presses Start on the Rebuild tab; that is the confirmation step,
  and it is where the quota line is shown.
- Top 100 rows shown; the first 20 ticked by default *(builder's call on both
  numbers)*. A selection is capped so the handoff URL stays well under 8 KB.

### Readme

- Changelog entry for the new version; this is a feature, so **2.3.0**. Says
  separately that GIFs are now optimised (gifsicle / service), never re-encoded.
- FAQ: images optimised by another plugin carry no mark and will appear in the
  list. Lossless programs then save almost nothing (harmless); lossy
  re-encoding would degrade them a second time.
- Short CLI section in the description listing both commands.

## Constraints

- WP 5.7, PHP 7.4 — the plugin's declared floors.
- Same capability (`ATR_Plugin::capability()`) for the CLI-free parts as every
  existing route.
- No new dependency. The plugin ships without Composer.
- Match the codebase: static helper classes, one class per file under
  `includes/`, plain JS in the existing IIFE style using `wp.apiFetch` and
  `wp.i18n` (see `assets/js/cleanup.js`), comments in the plugin's voice.
- Development files (specs, tests) do not ship: the wordpress.org release copies
  only `ajax-thumbnail-rebuild.php`, `readme.txt`, `assets/`, `includes/`,
  `languages/`.

## Verification

There is no unit-test harness in this repository. Verification follows the
integration-script pattern of the sibling `morra` repo (`tests/*.php`, run with
`lando wp eval-file`): a `tests/largest-images.php` in this repo, run against the
local Lando site (`~/Work/woocommerce`, 1051 images, 1038 already optimised,
jpegoptim/optipng/pngquant/gifsicle installed). It should cover at least: done
images excluded by default and included with `all`; ranking order; weight equals
the sum of the optimiser's own file list; an image whose top-of-list position
leads to a real optimisation (never `already`); formats the optimiser does not
handle never listed; `query()` with `include` keeps the given order; a GIF goes
through gifsicle and is never re-encoded (an animated GIF keeps its frames).
Plus running both CLI commands by hand and a browser check of the tab.

## Left to the builder

- Class and file names; whether the weighing lives in its own class
  (e.g. `ATR_Largest`) or as a method beside the optimiser.
- Exact shape of the extracted file-list method and of `needs_optimizing()`.
- Chunk size per `/weigh` request; the per-request id cap.
- Default `--number`, the number of rows shown and pre-ticked in wp-admin.
- CLI output formatting details beyond what is listed above.
- New translatable strings; regenerating `languages/ajax-thumbnail-rebuild.pot`.
- Deduplicating size files that share one file name (several sizes can resolve
  to the same file); the optimiser's own `array_unique()` already does this, and
  the weighing must count each file once to match.

## Decisions log

Decided with the user: CLI **and** wp-admin; measure on every scan, no index;
exclude done images using the optimiser's own rule, with a switch to show them;
fix the GIF bug in this release.

Decided by the designer after the builder check (the user can overrule):
`include` order kept in `query()`; neutral landing notice; no `--all` and no
force on `optimize`; done rows visible but not selectable; only handled formats
listed, through a shared predicate.
