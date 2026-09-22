# `package-check` fixtures (SPEC-023 AC1)

One tree per finding, the smallest that triggers it. Each holds nothing
but a `.gitattributes`; the top-level paths and the shipped list are
passed to `packageCheck()` by the test, the way `git ls-files` would
supply them in the repository. That is what makes the criterion testable
without a git repository per case.

| tree | what it triggers |
|---|---|
| `clean` | nothing: every path given is either shipped or `export-ignore` |
| `unclassified` | a path in neither — the failure this criterion exists for: a directory added without anyone deciding whether it ships |
| `contradiction` | a path both on the shipped list and `export-ignore` — the two statements disagree, and neither may win silently |
| `no-gitattributes` | the file itself is missing, so nothing is ignored and the dist is whatever the repository happens to hold |

`no-gitattributes/` is empty on purpose and carries a `.gitkeep`, because
git does not track an empty directory.
