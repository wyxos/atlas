General guidelines:

- Always use Laravel ```php artisan``` command to scaffold relevant class for the task.
- Ensure to remove any logging statements you added before completing a task.
- Favor type hints and type declaration for php code.
- Ensure all javascript code contain type hints.
- For any UI alignment, verify if backend alignments are also needed and vice versa.
- This project uses PEST framework for php testing, so ensure to create tests using PEST syntax.
- Create a PEST test case or update existing test case after completing a task and ensure it passes before completing the
  task.
- Run the full ```php artisan test``` after completing a task to ensure all tests pass.
- Never make assumptions or use placeholders. If in doubt, ask for clarification or halt the task with a comment.

Project specific guidelines:

- Always run `php artisan db:backup` before performing any tasks that involve altering the database. This creates a backup of the database in the storage/backups directory.
