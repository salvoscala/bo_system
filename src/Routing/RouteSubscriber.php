<?php

namespace Drupal\bo_system\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {
  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {

    if ($route = $collection->get('private_message.private_message_create')) {
      $route->setRequirement('_permission', 'use private messaging system');
    }

    if ($route = $collection->get('entity.user.canonical')) {
     // $route->setOption('_admin_route', '\Drupal\bo_system\Controller\Dashboard::isAdminRoute');
     // $route->setDefault('_controller', '\Drupal\bo_system\Controller\Dashboard::pageContent');
     // $route->setDefault('_title_callback', '\Drupal\bo_system\Controller\Dashboard::addTitle');
    }
  }

}



