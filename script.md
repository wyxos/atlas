To facilitate installing Atlas on server, we are to create an sh and ps1 script. Both will perform the following:

- verify that docker is installed and running
- if not, install docker
- clone the repository where the user ran the script
- copy .env.example to .env
- setup the relevant parameters and prompt the user for the app url/port ()
