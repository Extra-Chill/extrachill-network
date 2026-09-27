# returning-artist-link-page

An artist who already has a Link Page comes back and has to reach their
editor, with their existing links, from everywhere they might start. Each
"return" is a fresh browser with no stored editor token, so the extrachill.link
bearer handoff runs every time. The Link Pages migration moved every one of
these paths, and none of them were tested before.

| Path | Start | How |
| --- | --- | --- |
| setup | `/create-artist/` | Create "Night Shift Radio", add a "Tour Dates" link, save (first session) |
| blog | `extrachill.com` | Account menu → **My Link Page** |
| community | `community.extrachill.com` | Account menu → **My Link Page** |
| phone | `extrachill.com` at 390px | Account menu → **My Link Page** |
| dashboard | `artist.extrachill.com` | **Manage Link Page** |
| public page | `extrachill.link/night-shift-radio` | Owner edit pencil |
| edit | editor | Rename the link, save, see it on the public page |
| analytics | `/analytics/` | Recognises the page (no "Create a link page") |

Each browser path ends by posting a marker to `reached-fixture.php` only after
all its assertions pass; `grade.php` turns each marker into a case, and adds
persisted checks: one artist, one Link Page, and the edit saved. The fixture
musician (user 701) is a single-scenario fixture, not a persona.

Regenerate `journey.json` with `node build-journey.mjs`.
