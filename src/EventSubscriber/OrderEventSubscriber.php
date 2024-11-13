<?php

namespace Drupal\bo_system\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\DestructableInterface;
use Drupal\node\Entity\Node;

/**
 * Class OrderEventSubscriber.
 */
class OrderEventSubscriber implements EventSubscriberInterface, DestructableInterface {

  protected $order;

  protected $customer;

  protected $email;

  protected $receiver;

  protected $site_mail;

  protected $updateOrder;

  protected $consultingInfo;

  protected $bookingEvent;

  /**
   * {@inheritdoc}
   *
   * @return array
   *   The event names to listen for, and the methods that should be executed.
   */
  public static function getSubscribedEvents() {

    return [
      'commerce_order.place.post_transition' => ['onOrderPlace', 100],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    if ($this->updateOrder) {
      $this->updateOrder();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateOrder() {

    if ($this->order) {
      $order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
      $order = $order_storage->load($this->order->id());
      if ($order) {
        if ($this->consultingInfo) {
          foreach ($this->consultingInfo as $field => $value) {
            if ($order->hasField($field)) {
              $order->set($field, $value);
            }
          }

          if (isset($this->bookingEvent)) {
            if ($order->hasField('field_booking_event')) {
              $order->set('field_booking_event', $this->bookingEvent);
            }
          }
          $order->save();
        }
      }
    }
  }

  /**
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $this->order = $event->getEntity();
    $this->customer = $this->order->getCustomer();
    $this->email = $this->customer->getEmail();
    $this->updateOrder = FALSE;

    //If customer is empty we have a guest user
    if (!$this->email) {
      $this->email = $this->order->getEmail();
    }

    if ($this->order->bundle() == 'booking') {
      $this->setConsultingInfo();
      $this->updateOrder = TRUE;
    }
  }

  /**
   * Get purchased info.
   *
   * @return void
   */
  protected function setConsultingInfo(){

    $items = $this->order->getItems();
    $item = reset($items);

    $data = [];

    if ($item->hasField('field_consulting_date') && !$item->field_consulting_date->isEmpty()) {
      $data['field_consulting_date'] = $item->get('field_consulting_date')->getValue();
    }
    if ($item->hasField('field_consulting_duration') && !$item->field_consulting_duration->isEmpty()) {
      $data['field_consulting_duration'] = $item->get('field_consulting_duration')->getValue();
    }
    //if ($item->hasField('field_guide') && !$item->field_guide->isEmpty()) {
    //  $data['field_guide'] = $item->get('field_guide')->getValue();
    // }
    if ($item->hasField('field_bookable_entity') && !$item->field_bookable_entity->isEmpty()) {
      $data['field_bookable_entity'] = $item->get('field_bookable_entity')->getValue();
    }
    if ($item->hasField('field_notes') && !$item->field_notes->isEmpty()) {
      $data['field_notes'] = $item->get('field_notes')->getValue();
    }
    

    $title = '';
    if ($data['field_bookable_entity'][0]['target_id']) {
      $customer_name = $this->customer->getAccountName();
      if ($this->customer->hasField('field_first_name') && $this->customer->hasField('field_last_name')) {
        $customer_name = $this->customer->get('field_first_name')->value . ' ' . $this->customer->get('field_last_name')->value;
      }

      $title = $customer_name;
    }
    // Creo il nodo.
    $node_values = [
      'type' => 'event',
      'status' => 1,
      'field_order' => $this->order,
      'field_bookable_entity' => $data['field_bookable_entity'],
      'field_customer' => $this->customer->id(),
      'uid' => $this->customer->id(), // ID dell'autore (1 per l'utente amministratore).
      'created' => \Drupal::time()->getRequestTime(),
      'field_state' => 'confirmed',
    ];
    $node = Node::create($node_values);
    
    // Formattiamo le date per Smart Date.
    $consulting_date = $data['field_consulting_date'][0]['value'];
    $duration =  $data['field_consulting_duration'][0]['value'] ?? 60;
    $date = new \DateTime($consulting_date, new \DateTimeZone('UTC'));
    $end = clone($date);
    $end = $end->add(new \DateInterval('PT' . $duration . 'M'));

    // @Todo: corretto mettere il titolo in utc rome?
    // Forse meglio il timezone del customer o owner.
    $europe_date = clone($date);
    $europe_date->setTimezone(new \DateTimeZone('Europe/Rome'));
    if ($europe_date) {
      $title .= ' - ' . $europe_date->format('Y-m-d H:i');
    }

    $node->set('field_when', [
      'value' => $date->format('U'),
      'end_value' => $end->format('U'),
      'duration' => ($end->format('U') - $date->format('U')) / 60
    ]);

    $node->set('title', $title);

    $node->save();

    $this->bookingEvent = $node;

    $this->consultingInfo = [];
    if ($data) {
      $this->consultingInfo = $data;
    }
  }
}