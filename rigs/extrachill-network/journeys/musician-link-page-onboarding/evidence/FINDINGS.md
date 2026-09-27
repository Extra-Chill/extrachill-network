# Musician Link Page onboarding: findings

Five runs on a real, disposable 11-site network boot, each component from
its latest release, with the Link Pages site cutover on (as in production).
`result.json` is the graded result of the last run. The screenshots come
from that run too.

**Bottom line:** a brand-new musician cannot currently get from "sign up" to
"editing my Link Page" without hitting a dead end. The editor half (colors,
newsletter, links, fan signup) could not be exercised yet, because the two
bugs below stop it. They are pinned in `grade.php`, so this journey fails
loudly when they are fixed (unpin) or when anything else breaks.

## Findings

| # | What the musician experiences | Severity | Issue |
| --- | --- | --- | --- |
| 1 | Creates an artist profile, then lands on **"Link Page management is unavailable."** No button, no next step. The nav says "Create Link Page", which goes back to the same dead end. | Blocker. The core product promise fails. | [artist-platform#243](https://github.com/Extra-Chill/extrachill-artist-platform/issues/243) |
| 2 | Even once a Link Page exists, 3 of the 4 "Manage Link Page" links, the Analytics page, and the extrachill.link editor itself (`{"available":false}`) all say they have no page. Only the artist-home card links to the working editor. | Blocker after cutover | [artist-platform#245](https://github.com/Extra-Chill/extrachill-artist-platform/issues/245) |
| 3 | On extrachill.link/join, clicking **"No, I need to create an account"** closes the modal and leaves them on the **Login** tab. | High. It is the first click of the funnel. | [artist-platform#244](https://github.com/Extra-Chill/extrachill-artist-platform/issues/244) |
| 4 | The Register button hangs on "Creating account…" in the sandbox. | Rig gap, not confirmed in production | [network#299](https://github.com/Extra-Chill/extrachill-network/issues/299) (the same public-write 503 as auth-multisite) |

Why production hasn't hit 1 and 2 yet: #200 removed the last self-serve
provisioning path in artist-platform v1.21.0 (2026-09-19). Every existing
artist predates that release, and each still has a legacy Link Page copy on
the artist blog, which is what the wrong-blog checks happen to find. No
musician has signed up since. The next one will hit both bugs.

## Navigation map (where "the Link Page" is reachable from)

Measured with a musician who has one artist and one Link Page:

| Surface | Link to the editor? |
| --- | --- |
| Avatar menu (every site) | **No.** Items are View/Edit Profile, Manage Artist, Settings, Log Out. |
| Main site home (logged in) | No direct link. The "Artist Platform" card has "Manage Artists". |
| Community home | "Manage Artists" button only. |
| Artist home, "Your Artist Profiles" card | Yes. It goes to `extrachill.link/edit?link_page=…` (works). |
| Artist home / secondary nav / Manage Artist | "Manage Link Page" goes to `/manage-link-page/` (dead end, #245). |
| Analytics | "Create a link page to start tracking analytics." (wrong, #245) |
| Public Link Page (owner) | Pencil edit button via token handoff (not reached this run) |
| Editor back to anywhere | Theme chrome only ("← Back to Extra Chill"). There is no "View my page", "Manage artist", or "Analytics" link near the Save button. |

Two different URLs both labelled "Manage Link Page" sit on the same artist
home page.

## UX notes (not filed; judgement calls for Chris)

- **/power/ doesn't pitch the Link Page.** The Link Page footer's "Powered by
  Extra Chill" lands on a network explorer ("Pick a door"). The Artist
  Platform card says "Free link pages…" but its CTA is "Explore the
  platform", and it goes to the artist home, where the musician clicks "Sign
  Up". That path drops the join context (`from_join`), so the /join routing,
  the modal, and the artist-first onboarding never apply. A musician who came
  from someone else's Link Page is the most qualified lead there is, and they
  get the generic path.
- **The join modal is a detour.** "Do you already have an Extra Chill
  Community account?" asks a first-time musician to understand the network's
  internal structure before they've done anything. The Login/Register tabs
  already answer that question.
- **"Community account"** is jargon for someone who came for a link page.
- **Create Artist Profile** asks only for a name and an optional photo. Good,
  short.
- **The artist home** shows "Discover Artists: No artists have joined the
  platform yet. Create the First Artist Profile" next to the musician's own
  new profile. (That is sandbox-only emptiness, but the copy contradicts the
  card above it once they exist.)

## What could not be judged yet

Colors, the newsletter subscription display with a custom description, adding
a link, save persistence, the public page, and the fan's inline subscribe all
depend on the editor loading on extrachill.link, which #245 blocks. The
browser steps for all of these are already written. They start producing
evidence as soon as #243/#245 land, and the grade's pins then flip to "unpin".
