<?php
namespace Gbhorwood\Pollinate;
/**
 * MIT License
 * 
 * Copyright (c) 2019 grant horwood
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */


use DB;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

/**
 * Default page size for database selects
 */
define('DEFAULT_PAGE_SIZE', 5);


class Pollinate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gbhorwood:pollinate
        {--prefix= : Prefix to file and class names. Default \'pollinate\'}
        {--pagesize= : Number of records per insert}
        {--overwrite : Overwrite existing seeder files of the same name}
        {--silent : Suppress non-error output}
        {--show-tables : Show tables that can be seeded}
        {--show-ignored : Show tables that will be ignored unless explicitly requested}
        {tables?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create seed files from the database
  Accepts an optional comma-separated list of tables to seed.
  If no tables list is provided, all tables seeded.';

    /**
     * Array of table names we do not create seeds for
     * Modify as desired.
     *
     * @var Array
     */
    protected Array $ignoreTables = [
        'jobs',
        'failed_jobs',
        'oauth_access_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_personal_access_clients',
        'oauth_refresh_tokens',
        'password_resets',
        'personal_access_tokens',
    ];

    /**
     * Display non-error output
     *
     * @var bool
     */
    protected bool $showOutput = true;

    /**
     * Prefix for seeder file and class names
     *
     * @var String
     */
    protected String $prefix = "pollinate";

    /**
     * Overwrite seed files if true
     *
     * @var bool
     */
    protected bool $overwrite = false;

    /**
     * Number of records to select and write per page
     *
     * @var Int
     */
    protected Int $pageSize;

    /**
     * The full path of the directory where seeder files 
     * will be written
     *
     * @var String
     */
    protected String $seedsDirectory;

    /**
     * The namespace of seed files
     *
     * @var String
     */
    protected String $seedsNamespace;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle():Int
    {
        /**
         * Set and handle command line options
         */
        $this->handleArgs();

        /**
         * Deduce target directory for writing seeds
         */
        $dirNamespaceArray = $this->getSeedsDirectory();
        $this->seedsDirectory = $dirNamespaceArray[0];
        $this->seedsNamespace = $dirNamespaceArray[1];

        /**
         * Validate we can run this script
         */
        $this->preflight();

        /**
         * Create list of tables to build seeds for
         */
        $tableNames = $this->getTableNames();

        /**
         * Handle desired overwrite behaviour
         * If overwriting, delete existing target seed files and proceed
         * If not overwriting, display existing files list as error and remove tables
         * from tableNames.
         */
        $tableNames = $this->handleOverwrite($tableNames);

        /**
         * Write seed file for each table
         */
        $seedClasses = array_map(fn($tn) => $this->writeSeed($tn), $tableNames);

        /**
         * Output content for DatabaseSeeder.php if any
         */
        if($this->showOutput && count($seedClasses) > 0) {
            $this->info(PHP_EOL."Add this to DatabaseSeeder.php");
            array_map(fn($sc) => print $sc.','.PHP_EOL, array_filter($seedClasses));
        }

        return 0;
    }


    /**
     * Write one seed file for table $tablename
     *
     * @param  String $tablename
     * @return String The class name to add to DatabaseSeeder.php
     * @throws Exception
     */
    private function writeSeed(String $tablename)
    {
        $fileName = $this->getFileName($tablename);
        $className = $this->getClassName($tablename);
        $seederFileHead = $this->getSeederFileHead($tablename, $className);
        $seederFileFoot = $this->getSeederFileFoot();

        try{
            /**
             * Write head of seed file
             */
            $fp = fopen($fileName, 'a');
            fwrite($fp, $seederFileHead);

            /**
             * Write records by page
             */
            foreach($this->select($tablename) as $recordSet) {
                if(count($recordSet) > 0) {
                    $insertBlockHead = $this->getInsertBlockHead($tablename);
                    $insertBlockFoot = $this->getInsertBlockFoot($tablename);
                    $formattedRecordSet = $this->formatRecordSet($recordSet);
                    fwrite($fp, $insertBlockHead.$formattedRecordSet.$insertBlockFoot);
                }
            }

            /**
             * Write foot of seed file and close
             */
            fwrite($fp, $seederFileFoot);
            fclose($fp);

            $this->doInfo("Seeded table '$tablename'");

            /**
             * Return class name for DatabaseSeeder.php display
             */
            return $className.'::class';
        }
        /**
         * Any error writing the seed file results in deletion of the file
         */
        catch(\Exception $e) {
            fclose($fp);
            unlink($fileName);
        }
    }


    /**
     * Confirm the system can run this script. Exit on
     * any failure.
     *
     * Composer already enforces these prerequisites, but we check them
     * here in case someone did the old-fashioned manual install.
     * 
     * @return void
     */
    private function preflight():void
    {
        /**
         * Confrim minimum PHP version
         */
        $phpversion_array = explode('.', phpversion());
        if ((int)$phpversion_array[0].$phpversion_array[1] < 74) {
            $this->error("PHP must be 7.4 or higher");
            die();
        }

        /**
         * Confirm necessary extensions
         */
        if(!extension_loaded('pdo')) {
            $this->error("pdo PHP extension must be loaded");
            die();
        }

        /**
         * Confirm target directory is writeable
         */
        if(!is_writeable($this->seedsDirectory)) {
            $this->error("Cannot write to ".$this->seedsDirectory);
            die();
        }

        /**
         * Confirm doctrine/dbal is installed
         */
        if(!in_array('doctrine/dbal', \Composer\InstalledVersions::getInstalledPackages())) {
            $this->error("The doctrine/dbal package must be installed");
            fwrite(STDERR, "run:".PHP_EOL);
            fwrite(STDERR, "composer require doctrine/dbal");
            die();
        }
        
    }


    /**
     * Get the seeder stub from Laravel as array keyed by 'head' and 'foot'.
     * Head is everything above where the seed records go, foot is everything below
     *
     * @return Array
     */
    private function getStubParts():Array
    {
        $stubPath = $this->laravel->basePath()."/vendor/laravel/framework/src/Illuminate/Database/Console/Seeds/stubs/seeder.stub";
        return array_combine(['head', 'foot'], explode('//', file_get_contents($stubPath)));
    }


    /**
     * Get the directory where the seeders live. One of:
     *  - database/seeders
     *  - database/seeds
     * And it's corresponding namespace.
     * If neither directory exists, error and die.
     *
     * @return Array
     */
    private function getSeedsDirectory():Array
    {
        /**
         * Potential valid seeder directories
         */
        $possibleDirectories = [
            'seeders',
            'seeds',
        ];

        /**
         * Return the first valid directory
         */
        foreach($possibleDirectories as $possibleDirectory) {
            $path = $this->laravel->basePath().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.$possibleDirectory;
            if(file_exists($path) && is_dir($path)) {
                return [$path, 'Database\\'.ucfirst($possibleDirectory)];
            }
        }

        $errorMessage = PHP_EOL."No valid seeder directory at:".join(array_map(fn($p) => PHP_EOL."* $p ", $possibleDirectories));
        $this->error($errorMessage);
        die();
    }


    /**
     * Get the modified seed file stub above the seed array
     * 
     * @param  String $tablename
     * @param  String $className
     * @return String
     */
    private function getSeederFileHead(String $tablename, String $className):String
    {
        $stubHead = trim($this->getStubParts()['head']).PHP_EOL.PHP_EOL;
        $stubHead = str_replace("{{ class }}", $className, $stubHead);
        $stubHead = str_replace("{{ namespace }}", $this->seedsNamespace, $stubHead);
        $stubHead = $stubHead.$this->getDocblock($tablename);
        $stubHead = $stubHead.PHP_EOL.$this->indent(2)."\Schema::disableForeignKeyConstraints();".PHP_EOL;
        $stubHead = $stubHead.PHP_EOL.$this->indent(2)."\DB::table('$tablename')->delete();".PHP_EOL;
        return $stubHead;
    }


    /**
     * Get the modified seed file stub below the seed array
     *
     * @return String
     */
    private function getSeederFileFoot():String
    {
        $stubFoot = "";
        $stubFoot .= PHP_EOL.$this->indent(2)."\Schema::enableForeignKeyConstraints();";
        $stubFoot .= PHP_EOL.$this->getStubParts()['foot'];
        return $stubFoot;
    }


    /**
     * Return head of query builder command
     *
     * @param  String $tablename
     * @return String
     */
    private function getInsertBlockHead(String $tablename):String
    {
        return PHP_EOL.$this->indent(2)."\DB::table('$tablename')->insert([".PHP_EOL;
    }


    /**
     * Return foot of query builder command
     *
     * @param  String $tablename
     * @return String
     */
    private function getInsertBlockFoot(String $tablename):String
    {
        return $this->indent(2)."]);".PHP_EOL;
    }


    /**
     * Handle overwrite option. If overwriting, delete all existing files
     * we will be creating. If not overwriting, test for existing files
     * we will be creating and error if existing.
     *
     * Returns an updated list of table names, with tables that cannot be
     * pollinated due to overwrite removed.
     *
     * @param  Array $tableNames
     * @return Array
     */
    private function handleOverwrite(Array $tableNames):Array
    {
        /**
         * Overwrite existing seeder files
         * Pre delete all files we will be creating later, if any
         */
        if($this->overwrite) {
            $handledTableNames = array_map(function($t) {
                $path = $this->getFileName($t);
                if(file_exists($path)) {
                    if(unlink($path)) {
                        $this->doInfo("deleted $path");
                        return $t;
                    }
                    else {
                        $this->error("could not delete $path");
                        return null;
                    }
                }
                return $t;
            }, $tableNames);
            return array_filter($handledTableNames);
        }

        /**
         * Do not overwrite existing seeder files
         * If any files we will be creating later exist, error and die
         */
        if(!$this->overwrite) {
            $result = array_map(function($t) {
                $path = $this->getFileName($t);
                if(file_exists($path)) {
                    return [$path, $t];
                }
            }, $tableNames);

            $existingFiles = array_map(fn($f) => $f[0], array_filter($result));
            $removeTableNames = array_map(fn($f) => $f[1], array_filter($result));

            if(count($existingFiles)) {
                $errorMessage = PHP_EOL."Cannot overwrite the following files:".join(array_map(fn($t) => PHP_EOL."* $t ", $existingFiles));
                $this->error($errorMessage);
                fwrite(STDERR, "You can force overwrite by passing the --overwrite option.".PHP_EOL);
            }

            return array_diff($tableNames, $removeTableNames);
        }
    }


    /**
     * Format an array of records selected from a table into php array 
     * notation, indented properly for a seeder file.
     *
     * @param  Array $recordSet The array from a DB::select()
     * @return String
     */
    private function formatRecordSet(Array $recordSet):String
    {
        /**
         * Function to format value as number, null or string
         */
        $formatValue = function($value) {
            if($value == null) {
                return 'null';
            }
            return is_numeric($value) ? $value : "'".addslashes($value)."'";
        };

        /**
         * For each record set, create one record block and return as
         * array element
         */
        $formattedRecordSetArray = array_map(function($r) use($formatValue) {
            $buffer = null;
            foreach($r as $k => $v) {
                $buffer .= $this->indent(4)."'$k' => ".$formatValue($v).",".PHP_EOL;
            }
            return $this->indent(3).'['.PHP_EOL.$buffer.$this->indent(3).'],'.PHP_EOL;
        }, $recordSet);

        /**
         * Return array of formatted record blocks as string
         */
        return join($formattedRecordSetArray);
    }


    /**
     * Generator to yield one page of records from the specified
     * table. Terminates when no more records.
     *
     * @param  String $tablename
     */
    private function select(String $tablename)
    {
        $page = 0;

        do{
            $limit = $this->pageSize;
            $offset = $this->pageSize * $page;

            $sql =<<<SQL
                SELECT  *
                FROM    $tablename
                LIMIT   $limit
                OFFSET  $offset
            SQL;

            try {
                $result = DB::select($sql);
            }
            catch(\Exception $e) {
                $this->error("Table '$tablename' does not exist or is empty. Seed not written.");
                throw new \Exception();
            }
            yield $result;

            $page++;

        }while(count($result) > 0);
    }


    /**
     * Get array of all table names
     * Requires doctrine/dbal
     *
     * @return Array
     */
    private function getTableNames():Array
    {
        /**
         * Table names provided as argument
         */
        if($this->argument('tables')) {
            return explode(',', $this->argument('tables'));
        }

        /**
         * Get all table names through DB for portability
         */
        $tablesAll = \DB::connection()
            ->getDoctrineSchemaManager()
            ->listTableNames();

        /**
         * Filter ignored tables
         */
        return array_values(array_filter($tablesAll, fn($t) => !in_array($t, $this->ignoreTables)));
    }


    /**
     * Returns the doc block to put at the top of the seeder file
     *
     * @param  String $tablename
     * @return String
     */
    private function getDocblock(String $tablename):String
    {
        return $this->indent(2).'/**'.PHP_EOL.
               $this->indent(2).' * Created by pollinate.'.PHP_EOL.
               $this->indent(2).' * '.PHP_EOL.
               $this->indent(2).' * Table: '.$this->getDatabaseName().'.'.$tablename.PHP_EOL.
               $this->indent(2).' * User:  '.$this->getUsername().PHP_EOL.
               $this->indent(2).' * Host:  '.$this->getHostname().PHP_EOL.
               $this->indent(2).' * Date:  '.$this->getNow().PHP_EOL.
               $this->indent(2).' * Env:   '.$this->getEnvironment().PHP_EOL.
               $this->indent(2).' */'.PHP_EOL;
    }


    /**
     * Get indentation string of spaces to prepend for indentation.
     * 'Tab stops'[sic.] are four spaces.
     *
     * @param  Int $tabStops The number of 'tabs' to indent, ie four-space strings
     * @return String The string of spaces to prepend
     */
    private function indent(Int $tabStops):String
    {
        return str_repeat(' ', $tabStops * 4);
    }


    /**
     * Get username of executing user
     * 
     * @return String
     */
    private function getUsername():String
    {
        return extension_loaded('posix') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
    }


    /**
     * Get hostname
     * 
     * @return String
     */
    private function getHostname():String
    {
        return gethostname() ?? '';
    }


    /**
     * Get the name of the database for the default db
     * as set in config.database 
     * 
     * @return String
     */
    private function getDatabaseName():String
    {
        return config('database.connections.'.config('database.default').'.database');
    }


    /**
     * Get the environment, ie. 'local' or 'production'
     *
     * @return String
     */
    private function getEnvironment():String
    {
        return config('app.env') ?? 'local';
    }


    /**
     * Get current date and time with timezone in format
     * YYYY-MM-DD HH:ii:ss TZ
     *
     * @return String
     */
    private function getNow():String
    {
        return date("Y-m-d H:i:s e");
    }


    /**
     * Get the name of the class for a tablename's seeder
     *
     * @param  String $tablename
     * @return String
     */
    private function getClassName(String $tablename):String
    {
        return $this->prefix.'_'.Str::ucfirst(Str::camel($tablename));
    }


    /**
     * Get the name of the file for a tablename's seeder
     *
     * @param  String $tablename
     * @return String
     */
    private function getFileName(String $tablename):String
    {
        return $this->seedsDirectory.DIRECTORY_SEPARATOR.$this->getClassName($tablename).".php";
    }


    /**
     * Call info(). Suppress if --silent or --quiet is set
     *
     * @param  String $message
     * @return void
     */
    private function doInfo(String $message):void
    {
        $this->showOutput ? $this->info($message) : null;
    }


    /**
     * Output list of the ignored tables and exit.
     *
     * @return bool
     */
    private function showIgnored():bool
    {
        $this->info("Ignored tables:");
        fwrite(STDOUT, join(array_map(fn($i) => "* $i ".PHP_EOL, $this->ignoreTables)));
        return true;
    }


    /**
     * Output list of the table.
     *
     * @return bool
     */
    private function showTables():bool
    {
        $this->info("Tables:");
        fwrite(STDOUT, join(array_map(fn($i) => "* $i ".PHP_EOL, $this->getTableNames())));
        return true;
    }


    /**
     * Parse and handle arguments
     * 
     * @return void
     */
    private function handleArgs():void
    {
        $die[] = (bool)$this->option('show-tables') ? $this->showTables() : false;
        $die[] = (bool)$this->option('show-ignored') ? $this->showIgnored() : false;
        if(in_array(true,$die)) {
            die();
        }

        $this->showOutput = (bool)$this->option('silent') || (bool)$this->option('quiet') ? false : true;
        $this->prefix = $this->option('prefix') ?? 'pollinate';
        $this->overwrite = (bool)$this->option('overwrite') ? true : false;
        $this->pageSize = is_numeric($this->option('pagesize')) ? floor((int)$this->option('pagesize')) : DEFAULT_PAGE_SIZE;
    }
}
