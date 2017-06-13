#Totalize worklog for JIRA servers between two dates given a project

##Setup
Install dependencies
```bash
composer install
```

Start development server
```bash
php bin/console server:start
```

...or make public `web` folder.


###Login
The server baseUrl, user and password are requested.

###Projects page
Select the project to filter worklogs.

###Worklogs page
Filter by date the worklogs and show totals.

##Know issues
* The max issues to be retrieved are 1000.
* The max logs per issue are 20. **This may affect the final result and obtain undesired behaviours**.
* The credentials are stored in session, serialized.
