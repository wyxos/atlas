**GENERAL GUIDELINES**
Code Quality:
* Use Laravel `php artisan` to scaffold classes.
* Use type hints and declarations in PHP code.
* All JS code must contain type hints.
* Do not assume or use placeholders; ask for clarification or halt with a comment if unsure.

Debugging:
* Use `laravel.log` and the `Log` class for debugging.
* Remove logging statements and clear `laravel.log` before task completion.

Testing:
* Use PEST for PHP tests only.
* After PHP logic tasks: create a PEST test case if missing, evaluate individually, align existing tests, run `php artisan test` to ensure all pass.
* Do not create JS tests.
* Use ```test``` and not ```it``` in PEST tests.

Performance:
* Queue jobs for large dataset loops or intensive disk operations.
* Do not use `Model::all()` on large datasets; use `Model::chunk()`.

UI/Integration:
* Check if UI alignments need backend alignments and vice versa.
* Use Inertia router if applicable; otherwise, Axios; never Fetch.
