<?php
//Initialize the entire PHP side of the system -- should be called before just about anything else runs

namespace KitchenKiosk;

use KitchenKiosk\Utility;
use KitchenKiosk\Database\DB;
use KitchenKiosk\Exception\SystemException;

use DateTime;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;
use Pimple\Container;
use Noodlehaus\Config;

use Monolog\Logger;
use Monolog\Registry as LoggerRegistry;
use Monolog\ErrorHandler as LoggerErrorHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\BufferHandler;
use Monolog\Formatter\LineFormatter;

use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\IntrospectionProcessor;

class Main {

    private $configFile;

    public $c; // a dependency injection container

    public function __construct($configFile=null){
        $this->configFile = $configFile;
        if ( ( !file_exists($this->configFile) ) && ( $this->configFile != NULL ) ){
            throw new \UnexpectedValueException("Config file " . $this->configFile . " does not exist.");
        }
        //create DIC
        $this->c = new Container();

        // load the config file values; later we'll get the rest from the database
        $this->prepareConfig();

        // populate dic with function classes
        $this->prepareDependency();

        $this->prepareLogging();
        //TODO
        /* logging
         * error handling
         * mailer
         * database
         * session
         * config
         */
    }

    public function __call($name, $arguments) {
        throw new \BadMethodCallException("Method " . $name . " does not exist: " . print_r($arguments, 1));
    }

    /*
     * Load the config file and store in DIC
     */
    private function prepareConfig(){
        $this->c['configFile'] = $this->configFile;
        $this->c['config'] = function($c){
            return Config::load($c['configFile']);
        };
    }

    /*
     * Populate dependency injector container with known global dependencies
     */
    private function prepareDependency(){
        // Utility functions
        $this->c['general'] = function($c){
            return new Utility\General();
        };
        $this->c['display'] = function($c){
            return new Utility\Display();
        };
        $this->c['security'] = function($c){
            return new Utility\Security();
        };

    }

    /*
     * Create logger, define channel, set up streams, and store in DIC
     */
    private function prepareLogging(){
        $this->c['logger'] = function ($c) {
            $logger = new Logger($c['config']->get("logs.primary_channel")); # Main channel
            # PSR 3 log message formatting for all handlers
            $logger->pushProcessor(new PsrLogMessageProcessor());
            return $logger;
        }; 
        // display logging output to command line if enabled in config
        if ( (bool)$this->c['config']->get("debug.cli") ){
            $this->c->extend('logger', function ($logger, $c) {
                $display = $c['display'];
                $width = getenv('COLUMNS') ?: 60; # Console width from env, or 60 chars.
                $separator = str_repeat('━', $width); # A nice separator line
                $format  = $display->color("bold");
                $format .= $display->color("green") . "[%datetime%]";
                $format .= $display->color("white") . "[%channel%.";
                $format .= $display->color("yellow") . "%level_name%";
                $format .= $display->color("white") . "]";
                $format .= $display->color("blue") . "[UID:%extra.uid%]";
                $format .= $display->color("purple") . "[PID:%extra.process_id%]";
                $format .= $display->color("reset") . ":".PHP_EOL;
                $format .= "%message%".PHP_EOL;
                $format .= $display->color("gray") . $separator . $display->color("reset") . PHP_EOL;
                $handler = new StreamHandler($c['config']->get("logs.stream_handler"));
                $handler->pushProcessor(new UidProcessor(24));
                $handler->pushProcessor(new ProcessIdProcessor());
                $dateFormat = 'H:i:s'; # Just the time for command line
                $allowInlineLineBreaks = (bool)$c['config']->get("logs.allow_inline_linebreaks");
                $formatter = new LineFormatter($format, $dateFormat, $allowInlineLineBreaks);
                $handler->setFormatter($formatter);
                $logger->pushHandler($handler);
                return $logger;
            });
        }
        // log system condition to syslog if enabled in config
        if ( (bool)$this->c['config']->get("debug.system") ){
            $this->c->extend('logger', function ($logger, $c) {
                $ident = $logger->getName();
                $facility = LOG_USER;
                $option = LOG_PID | LOG_CONS | LOG_ODELAY;
                $handler = new SyslogHandler($ident, $facility, Logger::DEBUG, true, $option);
                $handler->pushProcessor(new UidProcessor(24));
                $handler->pushProcessor(new MemoryUsageProcessor());
                $handler->pushProcessor(new MemoryPeakUsageProcessor());
                $handler->pushProcessor(new ProcessIdProcessor());
                $handler->pushProcessor(new WebProcessor());
                $handler->pushProcessor(new IntrospectionProcessor());
                $logger->pushHandler($handler);
                return $logger;
            });
        }
        // primary logging handler; rotating log file inside BufferHandler
        $this->c->extend('logger', function ($logger, $c) {
            $filename = $c['config']->get("directories.root") . $c['config']->get("directories.log") . $c['config']->get("logs.default_log");
            $handler = new RotatingFileHandler($filename, 24, Logger::NOTICE, true, 0644, true);
            $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
            $format = "[%datetime%][%channel%][%level_name%][%extra.uid%]: %message%\n";
            $handler->setFormatter(new LineFormatter($format, 'U'));
            $handler->pushProcessor(new UidProcessor(24));
            $logger->pushHandler(new BufferHandler($handler));
            return $logger;
        });
        // finally register the loggers
        $this->c->extend('logger', function ($logger, $c) {
            LoggerRegistry::addLogger($logger);
            LoggerErrorHandler::register($logger);
            return $logger;
        });
    }

    //start the session
    private function startSession(){
        session_start();
        if (!session_id()) session_regenerate_id();
    }

    /*
    //load configuration from database
    private function loadConfig(){
        $DB = DB::pass();
        $conf = $DB->loadConfig();
        if ( count($conf) > 0 ){
            foreach($conf as $c){
                $this->config->set($c['cluster'] . "." . $c['name'],$c['value']);
            }
        } else {
            throw new SystemException("No database configuration");
        }
    }

    //init PDO singleton and handle
    private function connectDB(){
        try {
            $DBh = DB::obtain(
                $this->config->get('database.host'),
                $this->config->get('database.database'),
                $this->config->get('database.user'),
                $this->config->get('database.password')
            );
            $DB = DB::pass();
            //clear config data from memory
            $this->config->set('database.host',null);
            $this->config->set('database.database',null);
            $this->config->set('database.user',null);
            $this->config->set('database.password',null);
        } catch ( Exception $e ){
            throw new SystemException($e->getMessage());
        }
    }

    //create main logger
    private function createLogger(){
        $this->log = new Logger($this->config->get("logs.default_log"));
        $file = $this->config->get("directories.root") . $this->config->get("directories.log") . "/" . $this->config->get("logs.default_log");
        $this->log->pushHandler(new StreamHandler($file, Logger::WARNING));
    }
    */
}
