# Pollinate
Pollinate is a command for `artisan` for use in Laravel projects that creates database seed files from your database. If you have data in a database and want to turn it into seeds, pollinate is here.

## Install
To install manually, download the the `pollinate.php` file to `app/Console/Commands` then register in `Commands\Pollinate::class` in the `commands` array in `app/Console/Kernel.php`.

For a quicker install, run:

```shell
php artisan make:command pollinate && curl -L https://raw.githubusercontent.com/gbhorwood/pollinate/master/src/pollinate.php -o app/Console/Commands/pollinate.php
```

## Prerequisites
Pollinate requires the following:

* PHP 7.4 or higher
* The `pdo` extension
* The composer package `doctrine/dbal`

## Usage
The simplest usage of pollinate is:

```shell
php artisan gbhorwood:pollinate
```

This will create seed files for _all_ the tables in your database, and all seed files and classnames will be prefixed with the default `pollinate_` prefix.

**note:** some tables are automatically excluded from pollinations, such as passport tables and job tables. these are defined in the script in the `ignoreTables` array.

### pollinating specific tables
If you only wish to create seed files for specified tables, you can provide a comma-separated list of table names as an argument:

```shell
php artisan gbhorwood:pollinate users,pets,user_pet
```

### specifying a prefix
By default, pollinate prefixes all seed file and class names with `pollinate_`. You can specify your own prefix with the `--prefix=` option:

```shell
php artisan gbhorwood:pollinate --prefix=mydevbox
```

this will create seed files and classes with names like `mydevbox_Pets.php`.

### overwriting existing seed files
Pollinate's default behaviour is to not overwrite existing seed files. If one or more seed files exist that would be overwritten, pollinate will error and quit before doing any work.

If you wish to override this behaviour so that existing seed files are overwritten, you can pass the `--overwrite` option:

```shell
php artisan gbhorwood:pollinate --overwrite
```

### missing or empty tables
Pollinate does not write seed files for missing or empty tables.

### output
All non-error output can be suppressed by passing the `--silent` option.

If output is not suppressed, pollinate will output a list of classes that can be pasted into your `DatabaseSeeder.php` file.
