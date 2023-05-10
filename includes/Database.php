<?php
if (!defined('ABSPATH')) exit;

/**
 * Class to handle plugin database
 *
 * @since 0.1
 */
class WPI_Database
{

  private $table;

  function __construct()
  {
    global $wpdb;

    $this->table = $wpdb->prefix . 'woo_product_identity';
  }

  public function is_db_available()
  {
    global $wpdb;

    return $this->is_table_exists();
  }

  public function is_table_exists()
  {
    global $wpdb;

    $result = $wpdb->query("SHOW TABLES LIKE '{$this->table}'");

    return $result === 1;
  }

  public function create_table()
  {
    global $wpdb;

    $response = $wpdb->query("
      CREATE TABLE {$this->table} (
        id bigint UNSIGNED NOT NULL,
        unique_code char(30) NOT NULL,
        item_id bigint UNSIGNED NOT NULL
        verify_count INT UNSIGNED NOT NULL DEFAULT '0'
      );
    ");

    if (!$response) {
      // Send admin message
      return false;
    }

    $response = $wpdb->query("
      ALTER TABLE {$this->table}
        ADD PRIMARY KEY (id),
        ADD UNIQUE KEY unique_code (unique_code),
        ADD UNIQUE KEY unique_code_2 (unique_code, item_id);
    ");

    if (!$response) {
      $wpdb->query("DROP TABLE {$this->table};");

      // Send admin message
      return false;
    }

    $response = $wpdb->query("
      ALTER TABLE {$this->table}
        MODIFY id bigint UNSIGNED NOT NULL AUTO_INCREMENT;
    ");

    if (!$response) {
      $wpdb->query("DROP TABLE {$this->table};");

      // Send admin message
      return false;
    }

    return true;
  }

  public function create_identity_code($item_id, $code_provided = false)
  {
    global $wpdb;

    $unique_code = '';

    do {
      if (!$code_provided) {
        // Verify and create a unique one instead of letting db throw exception
        $unique_code = $this->get_unique_code();
      } else {
        $unique_code = $code_provided;

        // only one attempt and if for some reason this admin provided doesn't work
        // we start creating a unique randomly
        $code_provided = false;
      }

      $var = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT unique_code from {$this->get_table()} where unique_code = %s;",
          $unique_code
        )
      );
    } while (null != $var);

    // This return either rows created count or false
    $affected_rows = $wpdb->insert(
      $this->get_table(),
      [
        'unique_code' => $unique_code,
        'item_id' => $item_id
      ]
    );

    if (!$affected_rows) {

      error_log('Database error in $db->create_identity_code()');
      return false;
    }

    return $unique_code;
  }

  public function create_identity_codes($identity_codes, $item_id)
  {
    $result = [];

    foreach ($identity_codes as $code) {
      $result[$code] = $this->create_identity_code($item_id, $code);
    }

    error_log('$db::create_identity_codes() : ' . print_r($result, true));
  }

  public function delete_identity_codes($identity_codes)
  {
    global $wpdb;

    $result = $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$this->get_table()} WHERE unique_code IN (%s);",
        implode("','", $identity_codes)
      )
    );

    error_log('Delete result: ' . print_r([$result], true));
  }

  public function get_item_id_by_identity_code($identity_code)
  {
    global $wpdb;

    $item_id = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT item_id from {$this->get_table()} WHERE unique_code = %s",
        $identity_code
      )
    );

    return $item_id;
  }

  public function get_identity_codes_by_item_id($item_id)
  {
    global $wpdb;

    $identity_codes = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT unique_code from {$this->get_table()} WHERE item_id = %s",
        $item_id
      ),
      ARRAY_N
    );

    return $identity_codes;
  }

  public function verify_count($identity_code)
  {
    global $wpdb;

    $affected_rows = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$this->get_table()} SET verify_count = verify_count + 1 WHERE unique_code = %s;",
        $identity_code
      )
    );

    if (!$affected_rows) {

      error_log('Database error in $db->verify_count()');
      return false;
    }
  }

  public function get_verify_count($identity_code)
  {
    global $wpdb;

    $result = $var = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT verify_count from {$this->get_table()} where unique_code = %s;",
        $identity_code
      )
    );

    return null != $result ? $result : 0;
  }

  public function get_table()
  {
    return $this->table;
  }

  /**
   * Unique code for each order item of length 20.
   */
  private function get_unique_code()
  {
    $bytes = random_bytes(30);

    return substr(bin2hex($bytes), rand(0, 29), 30);
  }
}
