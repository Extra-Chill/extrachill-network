# Musician Link Page onboarding: findings

The journey runs on a real, disposable 11-site network boot with the Link
Pages site cutover on, as in production.

**Current result: 11/11 cases pass, 0 pinned findings, 0 regressions**
(`result.json`, screenshots in this directory). A musician can go from
`extrachill.link/join` to a customized, published Link Page that a fan can
subscribe to, with no dead ends.

## What the first runs found, and where each was fixed

| # | What the musician hit | Root cause | Fix |
| --- | --- | --- | --- |
| 1 | After creating an artist: "Link Page management is unavailable." Nothing could create the page. | Link Page migration: #200 (v1.21.0) removed the last self-serve provisioning path | extrachill-artist-platform#246: create-artist provisions the page; new `create-artist-link-page` ability; `/manage-link-page/` offers "Create Link Page" |
| 2 | Even with a page, most "Manage Link Page" links, Analytics, and the extrachill.link editor (`{"available":false}`) said there was no page | Link Page migration: status checked on the artist blog, but pages now live on extrachill.link | extrachill-artist-platform#246: storage-blog status helper; `/manage-link-page/` routes owners to the editor |
| 3 | `/join` → "No, I need to create an account" left them on the Login tab | `activateJoinFlowTab` event with no listener since login-register moved to React | extrachill-artist-platform#247: modal removed; `/join` lands on Register; artist-home Sign Up carries join context |
| 4 | The editor had no way out except "Back to Extra Chill" | missing | extrachill-link-pages#56 + extrachill-artist-platform#248: View page button and owner exit links (Manage Artist, Analytics, Artist Platform) |
| 5 | Nothing on the blog or community linked to the Link Page | missing | extrachill-users#436: "My Link Page" in the avatar menu |
| 6 | `/power/` (the Link Page footer target) didn't pitch the Link Page | copy/CTA | extrachill-blog#115: link-page callout for footer visitors; Artist Platform card leads to `/join` |
| 7 | The public Link Page rendered blank | The public-projection provider read the artist on the storage blog. Latent in production, where artist-platform isn't active on extrachill.link | extrachill-artist-platform#249; the rig's stale activation matrix is corrected here |

## Rig and runtime gaps fixed along the way

- http vs https, and SQLite advisory locks: extrachill-network#303
  (generated `ec-network-sandbox-compat.php`).
- Playground replaces `allowed_redirect_hosts`, which broke every cross-domain
  safe redirect: fixed at the source in WP Codebox's mapped-domain bootstrap
  (Automattic/wp-codebox#2533). The rig keeps a temporary re-merge until its
  pinned WP Codebox includes that fix.
- Redis-less rate limiters returned 503 on every registration
  (`registration_limiter_unavailable`): extrachill-network#304 (closes #299).

## UX notes still open (judgement calls, not filed)

- Onboarding asks "I love music / I am a musician / I work in the music
  industry" even for a visitor who came through `/join`, which is an
  artist-only door. The "I am a musician" box could be pre-checked there.
- The editor's Advanced tab mixes power-user settings (Meta Pixel, Google
  Tag, redirects) with the newsletter settings a first-time musician wants.
  Newsletter could get its own tab.
