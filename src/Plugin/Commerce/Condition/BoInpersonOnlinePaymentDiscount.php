<?php

namespace Drupal\bo_system\Plugin\Commerce\Condition;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides the product category condition for orders.
 *
 * @CommerceCondition(
 *   id = "bo_inperson_online_payment_discount",
 *   label = @Translation("Booking event has a discount, for example for 'In person' events payed online"),
 *   display_label = @Translation("Booking event has a discount, for example for 'In person' events payed online"),
 *   category = @Translation("Products"),
 *   entity_type = "commerce_order",
 * )
 */
class BoInpersonOnlinePaymentDiscount extends ConditionBase {

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);

    $order = $entity;
    foreach ($order->getItems() as $order_item) {
      if ($order_item->hasField('field_promotion') && !$order_item->field_promotion->isEmpty()) {
        if ($order_item->get('field_promotion')->value == 'inperson_online_payment_discount') {
          return TRUE;
        }
      }
    }
    
    return FALSE;
  }

}
