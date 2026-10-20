# Repository instructions for coding agents

- Before committing any change, run the relevant validation for the files changed.
  - At minimum, lint the touched PHP files with `php -l`.
  - If the project dependencies are available, run the smallest relevant PHPUnit target that covers the changed behavior.
  - If the repo’s test setup is blocked by missing dependencies or external authentication, note the blocker clearly and do not commit without reporting it.
- Keep patches surgical and targeted to the requested issue.
- Do not add unrelated files or formatting churn.
- Record the validation outcome in the commit/PR notes when the environment prevents full verification.
