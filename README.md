# YiiPress extension catalog

The catalog of [YiiPress](https://github.com/yiipress/engine) themes and content processors, and the site that
publishes it on GitHub Pages.

This repository keeps only what an extension's own manifest cannot say: where the extension lives, its categories
and its catalog status. Type, name, description, authors, license, compatible YiiPress versions, screenshots and
examples are read from the `extension.yaml` of each GitHub release when the site is built.

## Layout

```
extensions/<vendor>/<name>.yaml   one file per extension; the path is the extension ID
categories.yaml                   the allowed categories of each extension type
.github/CODEOWNERS                catalog maintainers
generator/                        PHP 8.5 command line tool: checks the metadata, reads releases, writes site content
site/                             the YiiPress site: config, hand-written pages, the catalog theme
.github/workflows/check.yml       pull requests: metadata rules, generator tests, a site build
.github/workflows/pages.yml       master, daily and manual runs: generate, build, deploy to GitHub Pages
```

## Entry files

```yaml
# extensions/yiipress/theme-sample.yaml
repository: https://github.com/yiipress/theme-sample
categories: [starter, minimal]
official: true
```

| Field | Who sets it | Rules |
| --- | --- | --- |
| `repository` | author | `https://github.com/<owner>/<repo>`. One entry per repository. |
| `categories` | author | 1 to 5 categories of the extension's type, from `categories.yaml`. |
| `status` | maintainers | `listed` (default), `abandoned` or `withdrawn`. |
| `official` | maintainers | `true` shows the «maintained by the YiiPress project» badge. It is not a security review. |
| `ownership.reviewed` | maintainers | Link to the reviewed record when the ID vendor is not the repository owner. |
| `history` | maintainers | Repository moves, newest first: `{date, from, record}`. |
| `withdrawn` | maintainers | `{date, reason}`, only with `status: withdrawn`. |

- The path is the ID (`extensions/acme/dusk.yaml` is `acme/dusk`), so the file has no `id` field. IDs are unique
  across all types, also when they differ only in case. The ID must equal the `id` in the release manifests.
- The ID vendor must be the GitHub owner of the repository (case-insensitive), unless `ownership.reviewed` says
  otherwise.
- There is no pending status: an open pull request is pending, the merge is the approval.

### Rules for changes

`check.yml` compares a pull request with its base branch. The maintainers are the users in `.github/CODEOWNERS` of
the base branch.

- An entry file is never deleted (`entry_removed`). An extension leaves the catalog as a tombstone: `status:
  withdrawn` with `withdrawn.date` and `withdrawn.reason`. A withdrawn ID is never listed again (`tombstone_reused`);
  it stays on the site's «Withdrawn IDs» page.
- `abandoned` entries stay listed with a notice and their last data.
- Only maintainers may change `official`, `status`, `ownership` and `history` (`maintainer_only`).
- A change of `repository` needs a new `history` row in the same pull request (`transfer_unrecorded`). Sites that
  installed the extension keep their old source in their lock file; nothing is redirected.

## What the build reads from GitHub

For each listed entry the generator:

1. lists the repository's releases (one API call; drafts, pre-releases and non-SemVer tags are skipped);
2. reads `extension.yaml` at the tag of the newest 10 releases and validates it with the engine's manifest parser;
3. checks each release against the newest patch of the last 5 YiiPress minor lines, for the release × YiiPress
   table and «latest compatible» line on the extension page;
4. copies theme screenshots and processor examples of the current release (5 MiB per file at most) into the site,
   so the published site makes no request to GitHub.

The newest release with a valid manifest is the one the page describes. A release with a missing or invalid
manifest is shown as not installable with its problems. An entry with no valid release fails its pull request check;
on the deploy build it keeps its last cached data.

Manifests and files are cached per repository and tag, since tags are treated as immutable; only the release lists
are read again on every build. In Actions the cache (`generator/var/cache`) is kept with `actions/cache` and never
committed. When GitHub cannot be read for an entry, its last cached release list is used and the page says «data as
of». A re-tagged release keeps its old data until the cache is dropped.

`GITHUB_TOKEN` is sent only to `api.github.com` over HTTPS and never across a redirect. Without it the generator
runs anonymously (60 API requests per hour).

The `check.yml` workflow runs on `pull_request`, never `pull_request_target`: it has a read-only token, no secrets,
and it reads YAML and fetches files only. It never runs extension code.

## Running locally

The generator runs in Docker; `make help` lists the targets.

```bash
make build                 # the generator image (PHP 8.5 with curl and yaml)
make composer install
make test                  # PHPUnit
make phpstan
make cs-fix
make check                 # validate every entry, reading releases from GitHub
make generate              # write site/content/data/catalog.yaml, the extension pages and assets
```

Pass a token to raise the GitHub limit: `GITHUB_TOKEN=... make generate`. `make generate CLI_ARGS=--offline` reads
only the cache. `make check CLI_ARGS="--base=<base branch checkout> --author=<login>"` also applies the change rules;
the base checkout must be inside this directory, which is what the container sees.

Then build the site with YiiPress 0.1.13 (the version the workflows pin):

```bash
cd site
yiipress build --output-dir=_site
yiipress serve             # a preview with live reload
```

Everything the generator writes into `site/content/` (`data/catalog.yaml`, `themes/`, `processors/`, `importers/`,
`plugins/`, `assets/extensions/`) is git-ignored. The hand-written pages are `index.md`, `submit.md` and
`withdrawn.md`; the theme is `site/themes/catalog/` over the bundled `minimal` theme.

## Engine dependency

The generator requires `yiipress/engine` from GitHub (Composer `vcs`), pinned to a commit: today
`dev-extension-manifest#4aaa392` of [yiipress/engine#189](https://github.com/yiipress/engine/pull/189). After that
pull request merges it moves to the merge commit, then to a release tag.

It uses these engine classes. They read YAML and talk HTTP only; none runs package code. The `@internal` ones are not
a promised API, so an engine change can break the generator: the pin keeps that visible.

| Class | Used for |
| --- | --- |
| `Extension\Manifest\YamlDocumentLoader` (`@internal`) | Loading entry files and `categories.yaml` with the engine's YAML limits |
| `Extension\Manifest\FieldReader` (`@internal`) | Field rules and error messages of entry files |
| `Extension\Manifest\ManifestParser`, `ExtensionManifest`, `Author`, `ManifestError`, `InvalidManifestException` | Release manifests |
| `Extension\ExtensionId`, `ExtensionType`, `EngineVersion`, `Platform`, `EngineCompatibility` | IDs, types and the compatibility table |
| `Extension\Release\GitHubRepository`, `GitHubReleaseSource`, `Release`, `ReleaseSelector` | Repository URLs, tag rules, manifests at a tag |
| `Extension\Http\HttpClientInterface`, `HttpResponse`, `RetryingHttpClient`, `UrlPolicy` | HTTP with retries, GitHub hosts only |
| `Extension\Archive\InstallLimits`, `Extension\Install\InstallException` | Limits and error codes shared with `get:*` |

The tests under `generator/tests/` are the reference for every rule above.

## License

BSD-3-Clause, see [LICENSE.md](LICENSE.md).
