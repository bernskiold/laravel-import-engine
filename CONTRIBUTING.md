# Contributing

We accept contributions as pull requests. Read these guidelines before you open a pull request.

## Procedure

1. Fork the project.
2. Make a new branch.
3. Write the code and the tests. Then commit and push.
4. Open a pull request. Tell what you changed and why.

## Guidelines

- Format the code with `composer format`.
- Make each commit in the pull request a meaningful change.
- If there is a merge conflict, [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) your branch.
- We use [SemVer](https://semver.org/).
- Write the documentation and the comments in ASD-STE100 Simplified Technical English.

## Setup

Clone your fork. Then install the dependencies:

```bash
composer install
```

## Tests

Run the tests:

```bash
composer test
```

Run the static analysis:

```bash
composer phpstan
```

## Code style

Format the code with [Pint](https://laravel.com/docs/pint):

```bash
composer format
```
