# AI user avatars

The 100 icons an administrator can give an AI user (Admin → AI Users → Avatar). They are
vendored rather than fetched so an air-gapped install has them, and so the wiki never
contacts a third party to draw a chat bubble.

- **Source:** [Noto Emoji](https://github.com/googlefonts/noto-emoji), `2D/svg/`, at commit
  `e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e`. Files are unmodified; only renamed from
  `emoji_u<codepoint>.svg` to the id in `avatars.json`.
- **Licence:** the repository's `LICENSE` is the SIL Open Font License 1.1, copied here
  verbatim. Its README describes image resources as Apache 2.0; either permits
  redistributing these files with this software.
- **The list is `avatars.json`**, and the server accepts only an id that is in it. Adding an
  icon is a file plus an entry; keep the set at 100 or fewer — the picker shows them all.
  Four candidates were left out for size (lizard, rocket, student, koala were 64–218 KB
  each; the rest average 12 KB).
