<?php
/*
Plugin Name: DW Semaphore
Description: Provide semaphore functionality in wordpress
Version: 0.0.1
Author: Daan Wilmer
Author URI: https://daanwilmer.nl
License: Apache license
*/
class DW_Semaphore {
  protected const OPTION_DB_VERSION = 'dw_semaphore_db_version';
  protected const TABLE_NAME = 'dw_semaphores';
  public const DB_VERSION = '0.0.2';
  public const DEFAULT_OPTIONS = [
    'validity' => 10, // 10 seconds before expiry, to avoid waiting too long
    'refresh_interval' => 50000, // 50 milliseconds or 1/20 of a seconds before a recheck
  ];

  /**
   * @var string
   */
  protected $name;

  /**
   * @var int
   */
  protected $index;

  /**
   * @var int
   */
  protected $validity;

  /**
   * @var int
   */
  protected $expirationTime;

  /**
   * Initialize: check db version and ensure it exists
   */
  public static function init(): void
  {
    $current_db_version = get_option(self::OPTION_DB_VERSION);
    if ($current_db_version !== self::DB_VERSION) {
      self::ensureTable();
    }
  }

  /**
   * Create a semaphore with the given name and wait until it is ready
   * 
   * The $options array can have the following keys (others will be ignored):
   *  - 'validity' (default 10): denotes the validity in seconds, after which other processes can capture the semaphore. This is to prevent crashed processes to stop all execution.
   *  - 'refreshInterval' (default 50000): denotes the refresh interval in microseconds. Set lower for more frequent checking.
   * 
   * @param string name The name of the semaphore
   * @param array options A key=>value array of options to override the default options
   * @return DW_Semaphore
   * @throws Exception if a semaphore could not be acquired
   */
  public static function wait(string $name, array $options = array()): DW_Semaphore
  {
    $options = self::getOptions($options);

    $validity = $options['validity'];
    $refreshInterval = $options['refreshInterval'];

    $semaphore = self::getSemaphore($name, $validity);

    while (!$semaphore->isReady()) {
      usleep($refreshInterval);
    }

    return $semaphore;
  }

  /**
   * Signal that this semaphore can be released, allowing other processes to capture it
   * @throws Exception if this semaphore has expired
   */
  public function signal(): void
  {
    global $wpdb;
    if (time() > $this->expirationTime) {
      throw new Exception(sprintf('Semaphore \'%s\' expired', $this->name));
    }
    $tableName = self::getTableName();
    $wpdb->delete($tableName, ['semaphore_name' => $this->name, 'semaphore_index' => $this->index], ['%s', '%d']);
  }

  /**
   * Get the table name
   */
  protected static function getTableName(): string
  {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_NAME;
  }

  /**
   * Ensure that the table exists in its correct form
   */
  protected static function ensureTable(): void
  {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();
    $tableName = self::getTableName();

    $sql = "CREATE TABLE $tableName (
      semaphore_name varchar(64) NOT NULL,
      semaphore_index int(11) NOT NULL,
      expiration_time int(11) NOT NULL,
      PRIMARY KEY  (semaphore_name, semaphore_index)
    ) $charsetCollate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
  }

  /**
   * Create a new semaphore and store it in the database
   * 
   * @param string $name The name of the semaphore
   * @param int $index The index of the semaphore, the number it has in the line
   * @param int $validity The validity in seconds
   * @throws DW_Semaphore_Exception When a semaphore could not be created
   */
  protected function __construct(string $name, int $index, int $validity)
  {
    global $wpdb;
    $tableName = self::getTableName();

    // calculate expiration time as unix timestamp
    $expirationTime = time() + $validity;

    // insert in database
    $result = $wpdb->insert(
      $tableName,
      ['semaphore_name' => $name, 'semaphore_index' => $index, 'expiration_time' => $expirationTime],
      ['%s', '%d', '%d']
    );

    // if it could not be inserted, throw an exception
    if ($result === false) {
      require_once(dirname(__FILE__) . '/exception.php');
      throw new DW_Semaphore_Exception($wpdb->last_error);
    }

    // store all information
    $this->name = $name;
    $this->index = $index;
    $this->validity = $validity;
    $this->expirationTime = $expirationTime;
  }

  /**
   * Get the next index for a semaphore with the given name
   */
  protected static function getIndex(string $name): int
  {
    global $wpdb;
    $tableName = $wpdb->prefix . self::TABLE_NAME;
    $query = $wpdb->prepare("SELECT COALESCE(MAX(semaphore_index) + 1, 0) FROM $tableName WHERE semaphore_name = %s", $name);
    $index = $wpdb->get_var($query);
    return $index;
  }

  /**
   * Get the options from the default options and the given options
   */
  protected static function getOptions(array $givenOptions): array
  {
    $options = self::DEFAULT_OPTIONS;
    foreach ($givenOptions as $key => $value) {
      if (array_key_exists($key, $options)) {
        $options[$key] = $value;
      }
    }
    return $options;
  }

  /**
   * Get a semaphore with the given name and validity
   * @param string $name
   * @param int validity
   * @return DW_Semaphore
   * @throws Exception if a Semaphore could not be created
   */
  protected static function getSemaphore(string $name, int $validity): DW_Semaphore
  {
    $semaphore = null;
    $triesLeft = 10;

    while($semaphore === null && $triesLeft > 0) {
      try {
        $index = self::getIndex($name);
        $semaphore = new DW_Semaphore($name, $index, $validity);
      } catch (DW_Semaphore_Exception $e) {
        $triesLeft--;
      }
    }

    if ($semaphore === null) {
      throw new Exception('Could not acquire semaphore lock');
    }
    
    return $semaphore;
  }

  

  /**
   * Returns true if this semaphore is considered ready to go, or false if we should wait
   */
  protected function isReady(): bool
  {
    global $wpdb;
    // check if we have the lowest index
    $tableName = self::getTableName();
    $query = $wpdb->prepare(
      "SELECT COUNT(*) FROM $tableName WHERE semaphore_name = %s AND semaphore_index < %d AND expiration_time >= %d",
      $this->name,
      $this->index,
      time()
    );
    $count = $wpdb->get_var($query);
    return ($count == 0);
  }

  
}

// initialize the semaphore functionality
add_action('init', [DW_Semaphore::class, 'init']);

// test code
$add_manual_test = false;
if ($add_manual_test) {
  require_once(dirname(__FILE__) . '/tests.php');
}
