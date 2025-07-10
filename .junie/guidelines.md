### **General Guidelines**

#### **Code Quality Standards**

* Always use the Laravel `php artisan` command to scaffold relevant classes.
* Favor type hints and type declarations in all PHP code.
* Ensure all JavaScript code contains type hints.
* Never make assumptions or use placeholders. If in doubt, ask for clarification or halt the task with a comment.

#### **Debugging and Logging**

* Use `laravel.log` and the `Log` class to debug issues.
* Remove all logging statements and clear the `laravel.log` file before completing a task.

#### **Testing Guidelines**

* This project uses the **PEST testing framework** for PHP tests. Write all tests using PEST syntax.
* After completing a task involving PHP logic:

    * Create a new PEST test case if it does not exist.
    * Evaluate the test individually to ensure it passes.
    * If a test case exists, align it accordingly.
* Run the full `php artisan test` after completing a task to ensure all tests pass.
* Do not create custom files to test functionalities; use test cases as per the project’s test framework.
* Note: This project has **no JavaScript tests**, so do not create any.

#### **Performance and Data Handling**

* Any created command or logic that deals with looping through large datasets or performs intensive disk operations should be **queued in a job** to avoid blocking the main thread.
* Never use `Model::all()` or similar methods that load entire datasets into memory; always use chunking (`Model::chunk()`) when processing large collections.

#### **UI and Integration**

* For any UI alignment, verify if backend alignments are also needed, and vice versa.
* Always use the **Inertia router** where applicable. Otherwise, favor Axios and never use Fetch.

#### **Task Management**

* If the task completed is listed under `todo.md`, strike it out in the `todo.md` file.

UI and Integration
For any UI alignment, verify if backend alignments are also needed, and vice versa.

Always use the Inertia router where applicable. Otherwise, favor Axios and never use Fetch.

#### **Task Management and Documentation**

* Before starting any task, always read through .junie/feature-documentation.md to understand existing feature flows, context, and implementations.

    * After completing a task, update .junie/feature-documentation.md with relevant information about the feature, including:

    * Feature purpose and flow overview.

    * Any setup, configuration, or business logic specifics.

* Anything future agents or contributors should know to understand or extend it without assumptions.

* If the task completed is listed under todo.md, strike it out in the todo.md file.
