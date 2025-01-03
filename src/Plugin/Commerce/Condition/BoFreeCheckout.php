<?php

namespace Drupal\bo_system\Plugin\Commerce\Condition;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides the product category condition for orders.
 *
 * @CommerceCondition(
 *   id = "bo_free_checkout",
 *   label = @Translation("Booking event has a free checkout, for example for 'In person' events payed offline"),
 *   display_label = @Translation("Booking event has a free checkout"),
 *   category = @Translation("Products"),
 *   entity_type = "commerce_order",
 * )
 */
class BoFreeCheckout extends ConditionBase {

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);

    $order = $entity;
    foreach ($order->getItems() as $order_item) {
      if ($order_item->hasField('field_promotion') && !$order_item->field_promotion->isEmpty()) {
        if ($order_item->get('field_promotion')->value == 'free_checkout') {
          return TRUE;
        }
      }
    }
    
    return FALSE;
  }

}
