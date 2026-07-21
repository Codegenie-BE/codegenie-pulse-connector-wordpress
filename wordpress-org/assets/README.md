# WordPress.org asset sources

This directory is a source and approval area. Its image files are never included in the WordPress installation ZIP. Approved images are mapped later to the top-level WordPress.org SVN `assets/` directory, never to `trunk/` or `tags/`.

No approved Codegenie brand artwork or publication screenshot was present during release preparation. Do not generate or infer a logo, colour system, product dashboard, or customer data. A human brand owner must supply every required file in `manifest.json`. A second human privacy review must approve the exact file before `status` and `privacy_review` can both be changed to `approved`.

The manifest is source metadata with schema version 1. Every entry records its exact filename, type, dimensions, required state, source category, publication status and privacy-review state. Only `approved` and `missing` are valid publication statuses. A required `missing` entry is valid in the source repository but blocks WordPress.org publication.

## Required review

- Use the exact lowercase filename and dimensions in `manifest.json`.
- Icons must remain below 1 MB, banners below 4 MB, and screenshots below 10 MB.
- The 1544x500 banner supplements, and does not replace, the 772x250 banner.
- Screenshot dimensions are this repository's planned capture size; WordPress.org prescribes the filename and size limit rather than a fixed screenshot dimension.
- Use only a local synthetic WordPress test site with an example domain and synthetic labels.
- Show no DSN, ingestion token, verification token, nonce, cookie, authorization header, email address, customer name, customer URL, or production dashboard.
- Keep the three numbered screenshot captions in `readme.txt` aligned with the connector settings, explicit approval and successful connection screens.
- Review right-to-left suitability of the banner composition; add localized assets only when they contain real localization work.

Run the network-free mapping check after approval:

```sh
php scripts/prepare-wordpress-org.php dist/codegenie-pulse-connector-1.2.1.zip
```

The command copies only manifest entries marked `approved` with an approved privacy review. It validates filenames, types, dimensions, PNG content and maximum sizes. While required entries remain `missing`, it still writes the review report and exits with publication-blocker status 3.
