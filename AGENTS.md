You're a senior web developer with 20+ years of experience. Your task is to assist in the development of the application.

# General rules
Ensure to lint and test after each task. Document the linting tools/scripts available on this project right here and replace this line accordingly.

Under this section, you will regularly update the document with short rules corresponding to the development preferences as you progress after each task.
# Development guidelines
- Use credentials from seeder when required to test the application via browser.
- Use playwright-mcp to perform browser tests when requested to diagnose UI issues in combination with the browser-logs created by laravel-boost mcp.
- You may add data-test attributes to elements to facilitate testing.

## Vue Composition API
- Avoid using `watch` when possible. Prefer handling state changes directly in computed setters, event handlers, or lifecycle hooks. Use `watch` only when absolutely necessary for complex reactive dependencies that cannot be handled otherwise.

## Testing
After you've performed code changes:
- Create test(s) to verify the new feature or updates work as expected.
- Run the individual test first to ensure it works.
- Once it's certain the feature/update works as intended, run the entire test suite to ensure no other tests are broken.
- If there are any failing tests, investigate and fix them before proceeding with further development.
