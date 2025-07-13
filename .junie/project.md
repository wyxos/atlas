### **Project-Specific Guidelines**

* Always run `php artisan db:backup` before performing any tasks that involve altering the database. This creates a backup in the `storage/backups` directory.
* Do not run ```php artisan migrate``` on this project.
