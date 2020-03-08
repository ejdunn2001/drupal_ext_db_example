<?php

namespace Drupal\mymodule_external_database;

/**
 * Interface ExternalDatabaseUpdate.
 */
interface ExternalDatabaseUpdateInterface {

  /**
   * Adds or updates articles.
   */
  public function update();

}
