Use `./AGENTS.md` as the source of truth for agents working in this repo, and
`./docs/CODEMAP.md` for where things live.

@AGENTS.md

Claude-specific:

- Project skills in `.claude/skills/`: `verify-media-directory` (how to prove a
  change works in the real admin) and `update-translations` (string → pl_PL).
- The browser pane config is `.claude/launch.json` (`isudev-library`, attaches to
  the Local site). The developer logs in; never type credentials.
- Run `npm run check` before saying a change is done, and report its output.
