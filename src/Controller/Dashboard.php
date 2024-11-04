<?php

namespace Drupal\bo_system\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the Dashboard class.
 */
class Dashboard extends ControllerBase {

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The page cache kill switch service.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new Dashboard object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match service.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   The page cache kill switch service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(RouteMatchInterface $route_match, KillSwitch $kill_switch, EntityTypeManagerInterface $entity_type_manager) {
    $this->routeMatch = $route_match;
    $this->killSwitch = $kill_switch;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('page_cache_kill_switch'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Checks access for a specific request.
   */
  public function isAdminRoute(AccountInterface $account) {

    $user = \Drupal::routeMatch()->getParameter('user');
    if (isset($user) && is_object($user)) {
      $roles = $user->getRoles();

      if (count($roles) > 1) {
        return TRUE;
      }
      return FALSE;
    }

    return FALSE;
  }

  /**
   * Provides the add title callback for user page.
   */
  public function addTitle() {
    if ($user = \Drupal::routeMatch()->getParameter('user')) {
      if ($user instanceof User) {
        return $user->getAccountName();
      }
    }

    return '';
  }

  /**
   * Displays the dashboard content with a list of nodes created by the user.
   *
   * @return array
   *   Render array for the dashboard page.
   */
  public function pageContent() {
    // Disabilita la cache della pagina.
    $this->killSwitch->trigger();

    // Recupera l'utente dalla route.
    $user = User::load($this->currentUser()->id());
    if ($user instanceof User) {
      $current_user = \Drupal::currentUser();
      $logged = User::load($current_user->id());

      $node_storage = $this->entityTypeManager->getStorage('node');
  
      // Crea una tabella per i nodi dell'utente se ha il ruolo "bookable".
      if ($user->hasRole('bookable')) {

        // Carica i nodi creati dall'utente.
        $nids = $node_storage->getQuery()
          ->condition('uid', $user->id())
          ->accessCheck(TRUE)
          ->execute();

        // Carica i nodi.
        $nodes = $node_storage->loadMultiple($nids);

        // Crea i dati della tabella con i titoli dei nodi.
        $rows = [];
        foreach ($nodes as $node) {
          $rows[] = [
            'title' => $node->toLink($node->getTitle()), // Link al nodo
          ];
        }

        // Passa i dati della tabella come variabile al tema Twig.
        return [
          '#theme' => 'bo_system_dashboard_bookable',
          '#uid' => $user->id(),
        ];
      }

      // Caso dashboard utente.
      // Altrimenti, mostra la dashboard dell'utente.
      return [
        '#theme' => 'bo_system_dashboard_user',
        '#uid' => $user->id(),
      ];
    }

    // Ritorna un array vuoto se l'utente non è trovato.
    return [];
  }

  /**
   * Prevent caching for this controller.
   *
   * @return int
   *   The max cache age, which is 0 to disable caching.
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
