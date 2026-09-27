# Anonymous event submission journey

A logged-out visitor opens `events.extrachill.com/submit/`, fills in the
public event submission form, and submits it.

## Why this exists

From March to September 2026, every anonymous submission through this form
failed with:

> Ability "datamachine/execute-workflow" does not have necessary permission

`extrachill/submit-event` executed `datamachine/execute-workflow` as the
visitor, and that ability only admits Data Machine managers
(extrachill-events#910, fixed in #911). Eight real submitters hit it, and
the bug survived several rounds of "fixes" to the same form because every
check ran as an admin or under WP-CLI, where Data Machine's permission gate
always passes. This journey is the check that runs as the audience the form
exists for.

## What it does

- **Seed** recreates production's `/submit/` page on the events site (by
  domain) with the real `extrachill/event-submission` block, and configures
  Cloudflare's documented always-pass Turnstile test site key so the block
  renders its real widget container.
- **Fixture mu-plugin** (`sandbox-fixture.php`) substitutes the dependencies
  the sandbox lacks: it accepts Turnstile through the product's own
  `extrachill_bypass_turnstile_verification` seam and stops live mail. It
  also records what the REST route answered and what the browser saw.
- **Browser step**, anonymous, walks the real form: fill every field, supply
  the solved-widget token Cloudflare's `api.js` would write (it cannot load
  in the egress-blocked sandbox), submit, and read the status line.
- **Grade** checks:

| Case | Expectation |
| --- | --- |
| `visitor-is-anonymous` | The submission came from a logged-out visitor. |
| `rest-submission-accepted` | `/extrachill/v1/event-submissions` answered 200 with a workflow job id. |
| `visitor-sees-confirmation` | The status line shows a confirmation, not an error. |
| `no-internal-error-shown` | No ability or permission error text reaches the visitor. |
| `workflow-job-enqueued` | A Data Machine job carrying the submitted event exists. |
| `submitter-account-attributed` | The submitter got an unclaimed `event_submission` account. |

## Boundary

The workflow's AI step needs a live model provider, which the sandbox does
not have, so the journey stops at "the workflow job exists". That is the
exact point #910 broke: no job was ever created.

## No persona

This is a single-scenario regression journey for a public, logged-out form;
it carries no `persona` key.
