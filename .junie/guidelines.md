**GENERAL GUIDELINES**
Code Quality:

- Use Laravel `php artisan` to scaffold classes.
- Use type hints and declarations in PHP code.
- All JS code must contain type hints.
- Do not assume or use placeholders; ask for clarification or halt with a comment if unsure.
- All new logic must have test coverage within the appropriate test class unless explicitly stated otherwise.
- Avoid redundant test scaffolding.
- When adding a new test, ensure the test name and structure aligns with the surrounding style and naming.

Debugging:

- Use `laravel.log` and the `Log` class for debugging.
- Remove logging statements and clear `laravel.log` before task completion.
- Use Log:: only when explicitly instructed or when debugging is needed for complex, failing behavior.
- All Log statements must be removed before completing a task.
- Do not leave Log:: calls in production/test-ready code.
- Do not create ad hoc debug files (e.g., `debug_-.php`) for evaluating existing logic. Instead, use or extend existing test cases.

Testing:

- All new logic must be covered by a proper test class created with `php artisan make:test`.
- Respect and follow the testing framework already in place (e.g., PHPUnit or PEST).
- After PHP logic tasks: create or update a test case if missing, evaluate individually, align existing tests, run `php artisan test` to ensure all pass.
- Tests must be written in a way that allows them to run in parallel. Ensure they do not share state or rely on global/shared data.
- Always verify test success using `php artisan test --compact --parallel`.
- Do not create JS tests.
- Match the style of the existing test suite (e.g., `test` for PEST, method-based structure for PHPUnit).
- Do not create standalone test files.
- Instead, extend or improve the appropriate existing test class that covers the feature or logic under review.

    - Only create a new test case if:

        - No relevant test class exists.
        - The logic is isolated and cannot logically belong to any current test suites.
    - Always attempt to reproduce and debug issues through the most relevant existing test class.

Performance:

- Queue jobs for large dataset loops or intensive disk operations.
- Do not use `Model::all()` on large datasets; use `Model::chunk()`.

UI/Integration:

- Check if UI alignments need backend alignments and vice versa.
- Use Inertia router if applicable; otherwise, Axios; never Fetch.
