---
title: Submit an extension
permalink: /submit/
---
The catalog lists extensions published as GitHub releases with an `extension.yaml` manifest. It stores only what the manifest cannot say: the repository, the categories and the catalog status. Everything else (type, name, description, authors, license, compatible YiiPress versions, screenshots) is read from your releases.

## Before you submit

1. Your repository is public on GitHub and has at least one stable release (a SemVer tag such as `v1.0.0`; drafts and pre-releases are skipped).
2. The release has `extension.yaml` at its root, and its `version` matches the tag. Check it with `yiipress get:theme --url=<repository>` or `yiipress get:processor --url=<repository>` on a test site.
3. The manifest `id` is `<vendor>/<name>`, and the vendor is your GitHub user or organization.

## The pull request

Open a pull request against [yiipress/catalog](https://github.com/yiipress/catalog) that adds one file, `extensions/<vendor>/<name>.yaml`:

```yaml
repository: https://github.com/<vendor>/<repository>
categories: [blog, minimal]
```

- The file path is the extension ID. It must match the manifest `id`.
- `categories`: one to five from [`categories.yaml`](https://github.com/yiipress/catalog/blob/master/categories.yaml) for your extension type. A new category needs its own pull request.
- Leave out `official`, `status`, `ownership`, `history` and `withdrawn`: only the catalog maintainers set them.

The pull request check reads your releases and manifests and reports every problem at once. A maintainer reviews and merges it; the merge is the approval. The site picks up new releases once a day.

## After listing

- New releases appear on their own; there is nothing to update here.
- A vendor that is not the repository owner needs a maintainer-reviewed ownership record.
- Moving the repository keeps the ID. Open an issue or a pull request that changes `repository`; a maintainer adds the `history` row that records the move. Installed sites are not redirected: their lock file keeps the old source.
- An extension is never deleted. It can be marked abandoned (it stays listed with a notice) or withdrawn (its page goes away, its ID stays reserved on the [withdrawn IDs](../withdrawn/) page).

The «maintained by the YiiPress project» badge marks extensions the YiiPress maintainers look after. It is not a security review of any extension: read the code of what you install.
