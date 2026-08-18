# Copilot Repository Instructions

Always treat the repository guidance in AGENTS.md as the base operating rules for this project.

Always read and follow LOCAL_DEV.md as the project-specific override for this workspace. If any rule conflicts, LOCAL_DEV.md takes precedence for local workflow behavior.

Before making any code, config, migration, test, or asset change, review AGENTS.md and LOCAL_DEV.md and apply them to the task.

Mandatory workflow:
- Use the repo rules as the source of truth for coding sessions.
- Follow the local development command requirements from LOCAL_DEV.md.
- Run the buildapp sequence after approved changes as specified in LOCAL_DEV.md.
- Do not proceed with commits without checking branch state as required by AGENTS.md.

This repository must use the instructions in these files automatically for every session, without needing to paste them into the chat prompt area each time.
