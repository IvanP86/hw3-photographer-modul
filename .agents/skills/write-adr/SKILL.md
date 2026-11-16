---
name: write-adr
description: 'Create an ADR for an accepted architecture decision. Use when recording a design choice in docs/adr, including its context, consequences, alternatives, and review conditions.'
---

# Writing an ADR

1. Read `copilot-instructions.md` and the relevant existing ADRs.
2. Identify the next available ADR number and choose a short, descriptive filename.
3. Create `docs/adr/ADR-XXXX-short-title.md` with the required sections: Context, Decision, Consequences, and Alternatives. Add a review condition when useful.
4. Set the new ADR status to `Proposed`. Never overwrite an existing ADR. When a new decision replaces an accepted one, link the new ADR to the old one and, after the new decision is accepted, mark the old ADR `Superseded` and link it to the new ADR.
5. Report the created file and any checks performed. If the user asks only for a proposal or draft, return the text without creating a file.

Use the repository's ADR language and conventions.