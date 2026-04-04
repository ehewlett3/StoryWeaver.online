## Project: StoryWeaver

This is a PHP-based choose-your-own-adventure application. The full specification
is in `StoryWeaver-Spec.md` at the root of this repository. Read it before
starting any task.

Key rules:
- PHP 8.x only. No frameworks, no Composer, no npm, no build step.
- No third-party PHP libraries unless unavoidable.
- Vanilla JS only in `_assets/sw.js`. No frameworks.
- All file writes must be atomic (write to .tmp, then rename()).
- Passwords use password_hash() / password_verify() with BCRYPT.
- Follow the phase structure in §11 of the spec. Do not skip phases.
- Coding standards are in §12 of the spec. Follow them strictly.
