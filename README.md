# Totalize worklog for JIRA servers between two dates given a project

## Setup
Install dependencies
```bash
composer install
cd web && bower install
sudo apt install php-curl
```

Start development server
```bash
php bin/console server:start
```

...or make public `web` folder.


###Login
The server baseUrl, user and password are requested.

### Projects page
Select the project to filter worklogs.

### Worklogs page
Filter by date the worklogs and show totals.

## Known issues
* The max issues to be retrieved are 1000.
* The max logs per issue are 20. **This may affect the final result and obtain undesired behaviours**.
* The credentials are stored in session, serialized.
* Issue with PHP v7.0.18, after composer install the following exception get's fired 
```php
Script Sensio\Bundle\DistributionBundle\Composer\ScriptHandler::clearCache handling the symfony-scripts event terminated wit


  [RuntimeException]
  An error occurred when executing the "'cache:clear --no-warmup'" command:
  PHP Fatal error:  Uncaught Symfony\Component\Debug\Exception\ClassNotFoundException: Attempted to load class "DOMDocument" 
  ```
  The solution that worked out was the following:
  ``` bash
  sudo apt-get install php7.0-xml
  ```
  Thanks to [Stackoverflow](https://stackoverflow.com/questions/36646207/attempted-to-load-class-domdocument-from-the-global-namespace)

## Mantainers
* Valentin Mari (valen.mari@ubykuo.com)