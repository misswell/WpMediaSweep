# WordPress.org submission

Prepared on 2026-09-30 for MediaSweep 0.3.5.

## Artifacts

- GitHub install package: `dist/mediasweep-0.3.5.zip` (existing `wp-mediasweep` directory layout).
- Initial WordPress.org submission package: `dist/mediasweep-wordpressorg-0.3.5.zip` (top-level `mediasweep` directory, matching the proposed slug and text domain).
- Plugin readme: `wp-mediasweep/readme.txt`.
- License: `wp-mediasweep/license.txt` (GNU GPL version 2; the plugin is GPL version 2 or later).

The assigned slug must be confirmed by WordPress.org before any SVN deployment. If the review team assigns another slug, update the text domain and directory packaging together.

## Verified

- Official readme validator: no errors reported. Notes: missing Contributors, optional Screenshots section, optional donate link.
- Tested-up-to field uses WordPress 7.1, matching the production WordPress 7.1.2 on which the 0.3.4 runtime was verified. Version 0.3.5 changes documentation, licensing, and version metadata only.
- Readme stable tag and PHP plugin version both use 0.3.5.
- Public source/build link points to the immutable GitHub v0.3.5 tag.
- Plugin PHP contains no external compression/analysis API calls. Its file_get_contents calls read local manifests and local theme/plugin files.

## Outstanding before submission

1. Obtain the actual WordPress.org account username and add it to Contributors.
2. Log in to https://wordpress.org/plugins/developers/add/ and check whether an existing MediaSweep submission is pending. Do not create a duplicate submission.
3. Run the official Plugin Check tool, including its Plugin repo category, in an isolated WordPress test environment. This check has not been run; the project regressions and readme validator are not a substitute.
4. Upload the WordPress.org ZIP through the logged-in submission page and record the confirmation. Current browser access is unauthenticated; no plugin has been submitted.
5. Wait for the manual review. After approval, use the assigned SVN repository and its authorized credentials to publish trunk and the version tag.

## Official references

- https://wordpress.org/plugins/developers/add/
- https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- https://wordpress.org/plugins/plugin-check/
