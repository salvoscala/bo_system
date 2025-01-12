<?php

namespace Drupal\bo_system\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides route responses for Change Booking page.
 */
class ChangeBooking extends ControllerBase {

  /**
   *
   */
  public function content() {
    return [
      '#theme' => 'bo_system_change_booking',
    ];
  }

}
