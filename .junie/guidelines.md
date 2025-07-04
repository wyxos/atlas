General guidelines:

- Always use Laravel ```php artisan``` command to scaffold relevant class for the task.
- Ensure to remove any logging statements you added before completing a task.
- Favor type hints and type declaration for php code.
- Ensure all javascript code contain type hints.
- For any UI alignment, verify if backend alignments are also needed and vice versa.
- This project uses the PEST testing framework for PHP tests, so ensure all tests are written using PEST syntax.
- After completing a task, create a new PEST test case if it doesn't exists, and ensure all tests pass before marking the task as complete. If a test case exists, align it accordingly.
- Run the full ```php artisan test``` after completing a task to ensure all tests pass.
- Never make assumptions or use placeholders. If in doubt, ask for clarification or halt the task with a comment.
- Any created command/logic that deals with looping through a consequent amount of data or performs intensive disk operations should be queued in a job to avoid blocking the main thread.
- Never use Model::all() or similar methods that load entire datasets into memory; always use chunking (Model::chunk()) for processing large collections.
- Always use Inertia router where applicable if present. Otherwise favor axios and never use fetch.
- Never write custom files to test functionalities after a task.

Project specific guidelines:

- Always run `php artisan db:backup` before performing any tasks that involve altering the database. This creates a backup of the database in the storage/backups directory.
- This project has no javascript tests, so do not create any javascript tests.
