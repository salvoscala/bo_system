<?php

namespace Drupal\bo_system\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\office_hours\OfficeHoursDateHelper;
use Drupal\Component\Datetime\DateTimePlus;

class BookingService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * \Drupal\Core\Session\AccountProxyInterface definition.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new ConfigurableFieldManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity query factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $currentUser) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $currentUser;
  }

  /**
   * Get current user timezone.
   * @return string
   */
  public function getUserTimezone() {
    if ($this->currentUser->isAnonymous()) {
      if (isset($_COOKIE['il-user-timezone'])) {
        return $_COOKIE['il-user-timezone'];
      }
    }
    else {
      return $this->currentUser->getTimeZone() ?? 'Europe/Rome';
    }

    return 'UTC';
  }

  /**
   * Controlla se l'utente è disponibile.
   *
   * @param int $nodeId
   *   Il nodo ID della bookable entity.
   * @param int $start
   *   Timestamp di inizio (UTC).
   * @param int $end
   *   Timestamp di fine (UTC).
   *
   * @return array
   *   Un array che indica la disponibilità (status) e la ragione.
   */
  public function isAvailable($nodeId, $start, $end) {
    if (!$start || !$end || !$nodeId) {
      \Drupal::logger('bo_sytem')->warning('Data o booking_entity mancante nel controllo disponibilità');
      return [
        'status' => FALSE,
        'reason' => $this->t('Data o booking_entity mancante nel controllo disponibilità'),
      ];
    }

    // 1. Controlliamo le sovrapposizioni con eventi già esistenti.
    // Caso 1: Eventi che iniziano all'interno dello slot.
    $starts_in_range = \Drupal::entityQuery('node')
      ->condition('type', 'event')
      ->condition('field_when.value', $start, '>')
      ->condition('field_when.value', $end, '<')
      ->condition('field_bookable_entity', $nodeId)
      ->condition('field_state', 'confirmed')
      ->accessCheck(FALSE)
      ->execute();

    if ($starts_in_range) {
      return [
        'status' => FALSE,
        'reason' => 'CASO 1: Slot non disponibile (eventi che iniziano all\'interno dello slot).',
      ];
    }

    // Caso 2: Eventi che finiscono all'interno dello slot.
    $ends_in_range = \Drupal::entityQuery('node')
      ->condition('type', 'event')
      ->condition('field_when.end_value', $start, '>')
      ->condition('field_when.end_value', $end, '<=')
      ->condition('field_bookable_entity', $nodeId)
      ->condition('field_state', 'confirmed')
      ->accessCheck(FALSE)
      ->execute();

    if ($ends_in_range) {
      return [
        'status' => FALSE,
        'reason' => 'CASO 2: Slot non disponibile (eventi che finiscono all\'interno dello slot).',
      ];
    }

    // Caso 3: Eventi che iniziano prima e finiscono dopo lo slot.
    $overlapping_events = \Drupal::entityQuery('node')
      ->condition('type', 'event')
      ->condition('field_when.value', $start, '<=')
      ->condition('field_when.end_value', $end, '>')
      ->condition('field_bookable_entity', $nodeId)
      ->condition('field_state', 'confirmed')
      ->accessCheck(FALSE)
      ->execute();

    if ($overlapping_events) {
      return [
        'status' => FALSE,
        'reason' => 'CASO 3: Slot non disponibile (eventi che coprono tutto lo slot).',
      ];
    }

    // 2. Recuperiamo il nodo e controlliamo i periodi non disponibili.
    $node = Node::load($nodeId);
    if ($node && $node->hasField('field_unavailable_periods')) {
      $unavailable_periods = $node->get('field_unavailable_periods')->getValue();
      $start_datetime = new \DateTime('@' . $start);
      $end_datetime = new \DateTime('@' . $end);

      // Iteriamo attraverso i periodi non disponibili e controlliamo sovrapposizioni.
      foreach ($unavailable_periods as $period) {
        $unavailable_start = DateTimePlus::createFromTimestamp($period['value']);
        $unavailable_end = DateTimePlus::createFromTimestamp($period['end_value']);

        $unavailable_start = new \DateTime($unavailable_start->format('Y-m-d H:i:s'));
        $unavailable_end = new \DateTime($unavailable_end->format('Y-m-d H:i:s'));
        // Controllo se lo slot richiesto si sovrappone a un periodo non disponibile.
        if ($this->areTimePeriodsOverlapping($start_datetime, $end_datetime, $unavailable_start, $unavailable_end)) {
          return [
            'status' => FALSE,
            'reason' => 'Slot non disponibile (sovrapposizione con un periodo non disponibile).',
          ];
        }
      }
    }

    // 3. Se nessun evento o periodo non disponibile sovrappone lo slot, l'utente è disponibile.
    return [
      'status' => TRUE,
      'reason' => [],
    ];
  }

/**
 * Helper per verificare se due intervalli di tempo si sovrappongono.
 *
 * @param \DateTime|\Drupal\Component\Datetime\DateTimePlus $start1
 *   Inizio del primo intervallo.
 * @param \DateTime|\Drupal\Component\Datetime\DateTimePlus $end1
 *   Fine del primo intervallo.
 * @param \DateTime|\Drupal\Component\Datetime\DateTimePlus $start2
 *   Inizio del secondo intervallo.
 * @param \DateTime|\Drupal\Component\Datetime\DateTimePlus $end2
 *   Fine del secondo intervallo.
 *
 * @return bool
 *   TRUE se gli intervalli si sovrappongono, FALSE altrimenti.
 */
private function areTimePeriodsOverlapping($start1, $end1, $start2, $end2) {
  return ($start1 < $end2 && $start2 < $end1);
}

  /**
   * Calcola gli slot settimanali di disponibilità della bookable entity basati sugli orari di apertura.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Il nodo della bookable_entity che contiene gli orari di apertura.
   *
   * @return array
   *   Un array associativo dove la chiave è il giorno della settimana e il valore
   *   è un array di slot di tempo disponibili (ad es. '08:00 - 09:00').
   */
  function getWeeklySlots($node) {
    // Recupera il fuso orario della bookable_entity dal proprietario del nodo.
    $bookableWrapperTimezone = $node->getOwner()->getTimeZone();

    // Array per salvare gli slot disponibili.
    $time_options = [];

    // Recupera il fuso orario dell'utente corrente (visitore).
    $userTimezone = $this->getUserTimezone();

    // Durata delle consultazioni (default a 60 minuti se non specificata).
    $duration = $node->get('field_consulting_duration')->value ?? 60;

    // Crea un intervallo di tempo basato sulla durata.
    $interval = new \DateInterval('PT' . $duration . 'M');

    // Imposta la data di inizio corrente.
    $start_date = new \DateTime();

    // Giorno della settimana attuale (1 = Lunedì, 7 = Domenica).
    $day_of_week = $start_date->format('N');

    // Helper per la gestione delle date (ipotizzando che la classe sia definita da te).
    $date_helper = new OfficeHoursDateHelper();

    // Recupera gli orari di apertura dal campo 'field_open_hours'.
    $open_hours = $node->get('field_open_hours')->getValue();

    // Disabilita tutti i giorni della settimana all'inizio.
    $disabled_week_days = array_combine(range(1, 7), range(1, 7));

    // Rimuovi i giorni della settimana abilitati (quelli in cui ci sono orari di apertura).
    foreach ($open_hours as $open_day) {
      unset($disabled_week_days[$open_day['day']]);
    }

    // Cicla attraverso i giorni di apertura definiti.
    foreach ($open_hours as $open_day) {
      $week_day = $open_day['day']; // Giorno della settimana.

      // Formatta gli orari di inizio e fine (es. 800 -> 08:00).
      $starthours = $date_helper->format($open_day['starthours'], 'H:i');
      $endhours = $date_helper->format($open_day['endhours'], 'H:i');

      // Crea oggetti DateTime per l'inizio e la fine dell'orario, usando il fuso orario della bookable_entity.
      $interval_start_date = new \DateTime($start_date->format('Y-m-d') . ' ' . $starthours, new \DateTimeZone($bookableWrapperTimezone));
      $interval_end_date = new \DateTime($start_date->format('Y-m-d') . ' ' . $endhours, new \DateTimeZone($bookableWrapperTimezone));

      // Crea un intervallo di tempo basato sugli orari di apertura e la durata.
      $daterange = new \DatePeriod($interval_start_date, $interval, $interval_end_date);

      // Itera sugli intervalli di tempo creati.
      foreach ($daterange as $date) {
        // Clona la data di inizio e convertila nel fuso orario dell'utente.
        $start = clone($date);
        $start->setTimezone(new \DateTimeZone($userTimezone));

        // Calcola l'ora di fine per lo slot e convertila nel fuso orario dell'utente.
        $end = (clone($date)->add($interval));
        $end->setTimezone(new \DateTimeZone($userTimezone));

        // Crea una copia degli orari in UTC (per scopi di salvataggio/compatibilità).
        $start_utc = clone($start);
        $end_utc = clone($end);
        $start_utc->setTimezone(new \DateTimeZone('UTC'));
        $end_utc->setTimezone(new \DateTimeZone('UTC'));

        // Rimuovi il giorno corrente dai giorni disabilitati, poiché ha disponibilità.
        unset($disabled_week_days[$day_of_week]);

        // Aggiungi l'orario se l'ora di fine è nello stesso giorno dell'ora di inizio.
        if ($end->format('Y-m-d') == $start->format('Y-m-d')) {
          // Memorizza lo slot di tempo disponibile per quel giorno della settimana.
          $time_options[$week_day][$start_utc->format('H:i')] = $start->format('H:i') . ' - ' . $end->format('H:i');
        }
      }
    }

    // Ritorna l'array di opzioni di tempo disponibili.
    return $time_options;
  }

  /**
   * Controlla se l'utente è disponibile.
   *
   * @param int $rate
   *   The rate set by the user
   *
   * @return int $real_rate
   *   The rounded rate with the platform percentage
   */
  public function getRealPrice($price) {
    $settings = \Drupal::state()->get('bo_system.settings');

    if ($settings['percentage'] > 0) {
      $price = $price + ($price * $settings['percentage'] / 100);
    }
    return round($price);
  }
  
}
