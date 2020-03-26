<?php

namespace Drupal\mymodule_external_database;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Class ExternalDatabaseUpdateService.
 */
class ExternalDatabaseUpdateService implements ExternalDatabaseUpdateInterface {


  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Stores logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * State API service.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a new ExternalDatabaseUpdateService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity Type Manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   State API.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time.
   */
  public function __construct(Connection $database, LoggerInterface $logger, EntityTypeManagerInterface $entityTypeManager, StateInterface $state, TimeInterface $time) {
    $this->database = $database;
    $this->logger = $logger;
    $this->entityTypeManager = $entityTypeManager;
    $this->state = $state;
    $this->time = $time;
  }

  /**
   * Create.
   *
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, $time) {
    return new static(
    $container->get('database'),
    $container->get('logger.factory')->get('action'),
    $container->get('entity_type.manager'),
    $container->get('state'),
    $container->get('datetime.time')
    );
  }

  /**
   * Gets and updates articles.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function update() {
    // Get time of last cron run.
    $last_run     = $this->state->get('fsh_external_database.last_check', 1290540978);
    $new_count    = 0;
    $update_count = 0;

    foreach ($this->externalQuery('articles', $last_run, NULL) as $article) {

      $article_id = $article->id;

      $current_article_id = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->condition('type', 'article')
        ->condition('field_article_id', $article_id)
        ->accessCheck(FALSE)
        ->execute();
      if (!empty($current_article_id)) {
        $current_article_id = reset($current_article_id);
        $current_article    = $this->entityTypeManager->getStorage('node')
          ->load($current_article_id);
      }

      if (empty($current_article)) {
        $node = $this->entityTypeManager->getStorage('node')
          ->create([
            'type'             => 'article',
            'title'            => $article_id,
            'field_article_id' => $article_id,
          ]);
        $node->save();
        $result = $this->updateArticle($node, $article, TRUE);
        if ($result) {
          $new_count++;
        }
        else {
          $this->logger->error('There was a problem adding a new article for @article_id.',
             ['@article_id' => $article_id]);
        }
      }
      else {
        $result = $this->updateArticle($current_article, $article, FALSE);
        if ($result) {
          $update_count++;
        }
        else {
          $this->logger->error('There was a problem updating a article for @article_id.',
          ['@article_id' => $article_id]);
        }
      }
    }
    // Save the time of this cron run.
    $request_time = $this->time->getRequestTime();
    $this->state->set('fsh_external_database.last_check', $request_time);
    $this->logger->notice('Added @new_count articles and updated @update_count articles.', [
      '@new_count'    => $new_count,
      '@update_count' => $update_count,
    ]);
  }

  /**
   * Updates the article in the Drupal DB.
   *
   * @param object $node
   *   The node that has been created or loaded.
   * @param array $article
   *   The new article from the External DB.
   * @param bool $is_new
   *   Is the node new or not.
   *
   * @return object
   *   Returns the node object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function updateArticle($node, $article, $is_new) {

    $article_id = $article->id;

    if ($node->get('body')->value !== $article->content) {
      $node->get('body')->setValue($article->content);
    }
    $node->save();
    if (!$node) {
      $this->logger->error('Content for @article_id did not save correctly.', ['@article_id' => $article_id]);
    }

    return $node;
  }

  /**
   * Runs queries against the External Database.
   *
   * @param string $table
   *   The table in the External Database that is being queried.
   * @param string $last_run
   *   The last cron run.
   * @param string $author_id
   *   The id of the author.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The result being returned.
   */
  protected function externalQuery($table, $last_run, $author_id) {

    $database = $this->database;
    $columns  = [];
    $fields   = $database->query("DESCRIBE {$table}")->fetchAll();
    if (empty($fields)) {
      $this->logger->error('Unable to get the structure for @table.', ['@table' => $table]);
    }
    // Get all fields.
    foreach ($fields as $field) {
      $columns[] = $field->Field;
    }
    $result = $database->select($table, 't')
      ->fields('t', $columns)
      ->condition('updated_at', $last_run, '>')
      ->execute()
      ->fetchAll();

    if (empty($result)) {
      $this->logger->warning('The query for @table did not return a result.', ['@table' => $table]);

      return NULL;
    }

    return $result;
  }

}
