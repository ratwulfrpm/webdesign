---
description: User preferences for AI responses
applyTo: '**'
---

# Communication Preferences

## Language
- Always respond in English
- Code comments can be in English or Spanish as appropriate for the codebase
- User-facing text in the application should remain in Spanish (as this is a Costa Rica market app)

## Formatting
- Never use emojis in responses
- Use clear, professional communication
- Keep responses concise and direct
- Use markdown for code blocks and formatting

## Code Style
- Follow existing code patterns in the repository
- Maintain consistency with current naming conventions
- Provide explanations for complex changes
- Always check for syntax errors after modifications
- Do not use emojis in code comments, commit messages or console outputs
- Before update to azure test the fixes locally and make sure what you commit is a working version, also ask confirmation before deploy.
- On every Azure deployment update the app version as minor in the pattern x.x.x and show a message giving a summary of the deployment changes, including:
  - Deployment ID
  - Status (Success/Failure)
  - List of files changed
  - Any important notes (e.g., manual steps required)
  - Version
-Learn from your mistakes, every time you make a mistake, remember what you did wrong and avoid repeating it in the future. document it somewhere and read this documentation before making changes. Specially if those mistakes break the application completely.

## Code Maintenance
- Automatically push to github account: ratwulfrpm
Repository: https://github.com/ratwulfrpm/webdesign.git