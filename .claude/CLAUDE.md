# Project Instructions

## PHP Coding Standards

- use PHP namespaces
- All APIs and functions should have explicit type hints
- Mark private functions and class members as `protected` instead of `private`
- use inheritance to avoid duplicate code. When adding similar features in a 2nd file, check if refactoring to a parent class makes sense
- use string multibyte functions prefixed with `mb_`, for example `mb_strpos` over `strpos`
- ensure newly created `.php` files have permissions `-rw-rw-rw-` and the same ownership as other project files
- don't use a closing PHP tag `?>` as last line in `.php_` files (if not already present)
- never remove ToDo comments unless fully implemented
- don't use `git commit` and `git add` unless explicitly instructed
- be short and concise in your comments. Prefer typed variables and parameters over `@var` and `@param` annotations.
- use `declare(strict_types=1);` in applicable `.php` files

## JavaScript & TypeScript coding standards
- for TypeScript code indentation use tabs with tab size 4
- if JavaScript is needed: Use modern TypeScript code with classes and typed APIs
- Use strict typing - avoid `any` types when possible
- when installing packages, add TypeScript typings to `package.json` as `devDependencies` where available
- Leverage TypeScript's type inference where appropriate while maintaining clarity
- use PNPM as package manager
