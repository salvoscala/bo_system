<?php

namespace Drupal\bo_system\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Booking' Block.
 *
 * @Block(
 *   id = "bo_system_booking_form",
 *   admin_label = @Translation("Booking Block"),
 *   category = @Translation("BO System")
 * )
 */
class BookingBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      return \Drupal::formBuilder()->getForm('Drupal\bo_system\Form\BookingForm', $node->id());
    }

    return[];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
