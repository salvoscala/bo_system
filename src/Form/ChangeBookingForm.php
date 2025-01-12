<?php

namespace Drupal\bo_system\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\bo_system\Services\BookingService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\office_hours\OfficeHoursDateHelper;
use Drupal\Core\Access\AccessResult;
use Drupal\user\Entity\User;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;

class ChangeBookingForm extends FormBase {

  /**
   * \Drupal\Core\Session\AccountProxyInterface definition.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Logger
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The booking service
   *
   * @var \Drupal\bo_system\Services\BookingService
   */
  protected $bookingService;

  /**
   * The cart manager.
   *
   * @var \Drupal\commerce_cart\CartManagerInterface
   */
  protected $cartManager;
  

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructor.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    LoggerChannelFactoryinterface $logger,
    RouteMatchInterface $routeMatch,
    BookingService $bookingService,
    EntityTypeManagerInterface $entityTypeManager,
    RendererInterface $renderer
  ) {
    $this->currentUser = $currentUser;
    $this->logger = $logger->get('bo_system');
    $this->routeMatch = $routeMatch;
    $this->bookingService = $bookingService;
    $this->entityTypeManager = $entityTypeManager;
    $this->renderer = $renderer;
  }

  /**
   * Factory method for dependency injection container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('logger.factory'),
      $container->get('current_route_match'),
      $container->get('bo_system.booking_utility'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bo_system_change_booking_form';
  }


  /**
   * Verifica l'accesso al form.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Il nodo evento.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Restituisce AccessResult::allowed() se l'utente può accedere, altrimenti AccessResult::forbidden().
   */
  public function access(Node $node) {
    $currentUser = User::load($this->currentUser->id());
    // Se l'utente è amministratore, concedi l'accesso.
    if ($currentUser->hasRole('administrator') || $currentUser->id() == 1) {
      return AccessResult::allowed();
    }

    // Verifica se l'utente è quello referenziato da "field_customer".
    $customer = $node->get('field_customer')->entity;

    if ($customer && $customer->id() == $currentUser->id()) {
      return AccessResult::allowed();
    }

    // Se nessuna delle condizioni è soddisfatta, nega l'accesso.
    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Node $node = NULL) {

    if ($event = $this->routeMatch->getParameter('node')) {
      if ($event->hasField('field_bookable_entity') && !$event->field_bookable_entity->isEmpty()) {

        $bookableEntity = $event->get('field_bookable_entity')->entity;
        if ($bookableEntity instanceof \Drupal\node\NodeInterface) {
          $bookableWrapperTimezone = $bookableEntity->getOwner()->getTimeZone();
          $userTimezone = $this->bookingService->getUserTimezone();
          $open_hours = $bookableEntity->get('field_open_hours')->getValue();

          $max_bookable_interval = 365;
          if ($bookableEntity->hasField('field_max_bookable_interval')) {
            $max_bookable_interval = $bookableEntity->get('field_max_bookable_interval')->value ?? 365;
          }
          $exclude_date = [];

          if ($bookableEntity->hasField('field_closed_on_holidays') && $bookableEntity->get('field_closed_on_holidays')->value) {
            $exclude_date = array_merge($exclude_date, $this->giorniFestivi());
          }

          // Ordiniamo i giorni chiusi. Questo ordinamento è necessario altrimenti nel
          // calendario non vengono rispettati.
          ksort($exclude_date, SORT_NUMERIC);

          $form['#prefix'] = '<div id="booking-form">';
          $form['#suffix'] = '</div>';

          $reservation_notice = 48;
          if ($bookableEntity->hasField('field_reservation_notice') && $bookableEntity->get('field_reservation_notice')->value) {
            // Aggiungiamo un giorno. In questo modo viene rispettata la data minima di prenotazione.
            $reservation_notice = ($bookableEntity->get('field_reservation_notice')->value ?? 24) + 24;
          }

          $form['event'] = [
            '#type' => 'hidden',
            '#value' => $event->id(),
          ];

          $when_render_array = $event->get('field_when')->view('full');
          $when_output = $this->renderer->render($when_render_array);
          $form['original_event_date'] = [
            '#type' => 'hidden',
            '#value' => $when_output,
          ];

          $form['bookable_entity'] = [
            '#type' => 'hidden',
            '#value' => $bookableEntity->id(),
          ];

          $disabled_week_days = array_combine(range(1, 7), range(1, 7));
          foreach ($open_hours as $open_day) {
            unset($disabled_week_days[$open_day['day']]);
          }

          if ($bookableEntity->hasField('field_unavailable_periods') && !$bookableEntity->field_unavailable_periods->isEmpty()) {
            foreach ($bookableEntity->field_unavailable_periods->getValue() as $item) {
              $duration = $item['end_value'] - $item['value'];
              if ($duration > 86400) {
                // Agiamo solo quando si tratta di una durata maggiore di un giorno.
                // Converto i timestamp in DateTime per manipolazione.
                $startItem = new \DateTime('@' . $item['value']);
                $endItem = new \DateTime('@' . $item['end_value']);

                // Imposto i fusi orari (se necessario).
                $startItem->setTimezone(new \DateTimeZone('UTC'));
                $endItem->setTimezone(new \DateTimeZone('UTC'));
                // Controllo che il giorno sia completamente compreso.
                $current = clone $startItem;

                // Itero su tutti i giorni compresi tra inizio e fine.
                while ($current <= $endItem) {
                  $exclude_date[] = $current->format('d.m.Y'); // Formato richiesto.
                  $current->modify('+1 day');
                }
              }
            }
          }

          $form['date'] = [
            '#type' => 'single_date_time',
            '#allow_times' => 60,
            '#inline' => TRUE,
            '#start_date' => FALSE,
            '#min_date' => date('d.m.Y', time() + 60 * 60 * $reservation_notice),
            '#max_date' => date('d.m.Y', time() + 60 * 60 * 24 * $max_bookable_interval),
            '#year_start' => date('Y'),
            '#year_end' => date('Y', time() + 60 * 60 * 24 * 365),
            '#exclude_date' => implode("\n", $exclude_date),
            '#disable_days' => $disabled_week_days,
            '#datetimepicker_theme' => 'default',
            '#date_type' => 'date',
            '#hour_format' => 24,
            '#scroll_month' => FALSE,
            '#default_select' => FALSE,
            '#ajax' => [
              'callback' => [get_class($this), 'ajaxRefresh'],
              'wrapper' => 'booking-form',
              'progress' => [
                'type' => 'throbber',
                'message' => $this->t('Wait...'),
              ]
            ],
          ];

          $time_options = [];
          if ($date = $form_state->getValue('date')) {
            // Consulting duration.
            $duration = $bookableEntity->get('field_consulting_duration')->value ?? 60;
            $interval = new \DateInterval('PT' . $duration . 'M');
            $start_date = new \DateTime($date);
            $day_of_week = $start_date->format('N');

            $date_helper = new OfficeHoursDateHelper();
            
            foreach ($bookableEntity->get('field_open_hours')->getValue() as $open_day) {
              if ($open_day['day'] == $day_of_week) {

                $starthours = $date_helper->format($open_day['starthours'], 'H:i');
                $endhours = $date_helper->format($open_day['endhours'], 'H:i');

                $interval_start_date = new \DateTime($start_date->format('Y-m-d') . ' ' . $starthours, new \DateTimeZone($bookableWrapperTimezone));
                $interval_end_date = new \DateTime($start_date->format('Y-m-d') . ' ' . $endhours, new \DateTimeZone($bookableWrapperTimezone));

                $daterange = new \DatePeriod($interval_start_date, $interval, $interval_end_date);
                foreach ($daterange as $date) {
                  $start = clone($date);
                  $start->setTimezone(new \DateTimeZone($userTimezone));

                  $end = (clone($date)->add($interval));
                  $end->setTimezone(new \DateTimeZone($userTimezone));
                  $start_utc = clone($start);
                  $end_utc = clone($end);

                  $start_utc->setTimezone(new \DateTimeZone('UTC'));
                  $end_utc->setTimezone(new \DateTimeZone('UTC'));

                  $availability =  $this->bookingService->isAvailable($bookableEntity->id(), $start_utc->format('U'), $end_utc->format('U'));
                  unset($disabled_week_days[$day_of_week]);
                  if ($availability['status']) {
                    if ($date->format('Y-m-d') == $start->format('Y-m-d')) {
                      $time_options[$start_utc->format('H:i')] = $start->format('H:i') . ' - ' . $end->format('H:i');
                    }
                  }
                }
              }
            }
          }

          $form['time'] = [
            '#type' => 'select',
            '#title' => $this->t('Select a time slot (your timezone)'),
            '#options' => $time_options,
            '#disabled' => TRUE,
            '#required' => TRUE,
            '#empty_option' => $this->t('- Select time slot -'),
            '#ajax' => [
              'callback' => [get_class($this), 'ajaxRefresh'],
              'wrapper' => 'booking-form',
            ],
          ];

          if (!empty($form_state->getValue('date'))) {
            $form['time']['#disabled'] = FALSE;
          }

          $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Change booking date'),
            '#attributes' => ['class' => ['btn-primary']],
          ];

          if (!empty($form_state->getValue('date')) && !empty($form_state->getValue('time'))) {
            $form['submit']['#disabled'] = FALSE;
          }

          if ($date != NULL && !$time_options) {
            // Se abbiamo una data e non ci sono slot per questa data allora mostriamo
            // un messaggio.
            $form['time']['#empty_option'] = $this->t('All time slots have been booked for this day!');
            $form['time']['#disabled'] = TRUE;
            $form['submit']['#value'] = $this->t('All time slots have been booked for this day!');
            $form['submit']['#disabled'] = TRUE;
          }

          return $form;
        }
      }
    }
    return [];
  }

  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $values = $form_state->getValues();

    $event = Node::load($values['event']);
    $bookable_entity = Node::load($values['bookable_entity']);

    if ( !$event ) {
      $this->messenger()->addMessage($this->t('Something wrong.'), 'error');
      return;
    }

    $start_date = new \DateTime($values['date'] . ' ' . $values['time'], new \DateTimeZone('UTC'));

    $duration = 60;
    if ($bookable_entity->hasField('field_consulting_duration') && !$bookable_entity->field_consulting_duration->isEmpty()) {
      $duration = $bookable_entity->get('field_consulting_duration')->value ?? 60;
    }

    $end = clone($start_date);
    $end = $end->add(new \DateInterval('PT' . $duration . 'M'));

    $event->save();

    // @TODO: Controllare se siamo nei termini per cambiare la data.
    $event->set('field_when', [
      'value' => $start_date->format('U'),
      'end_value' => $end->format('U'),
      'duration' => ($end->format('U') - $start_date->format('U')) / 60
    ]);
    $event->save();
    $this->messenger()->addMessage($this->t('Date changed successfully'));

    // Inviamo delle mail all'host.
    $url = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], ['absolute' => TRUE])->toString();
    // Email Host.
    $site_mail = \Drupal::config('system.site')->get('mail');
    $subject = 'Event modified by customer';
    $mail_body = '<p>The event has been modified by customer.</p>';

    $mail_body .= '<style>.field--name-field-when.field--type-smartdate .field__label {display: none !important;}</style>';
    $when_render_array = $event->get('field_when')->view('full');
    $when_output = $this->renderer->render($when_render_array);
    $mail_body .= '<p class="event-date"><span><strong>Original date: </strong></span><span>' . $values['original_event_date'] .'</span></p>';
    $mail_body .= '<p class="event-date"><span><strong>New date: </strong></span><span>' . $when_output .'</span></p>';

    $mail_body .= '<p>You may view more <a href="' . $url . '" target="_blank">details here</a></p>';


    $this->messenger()->addMessage($mail_body);
    $host_email = $event->get('field_bookable_entity')->entity->getOwner()->getEmail();
    simple_mail_send($site_mail, $host_email, $subject, Markup::create($mail_body));
    $form_state->setRedirect('bo_system.dashboard');
  }

  /**
   * Ritorna i giorni festivi dei prossimi 3 anni (anno corrente compreso).
   */
  private function giorniFestivi() {

    $anni = [];
    $anno_corrente = date('Y');

    $anni[] = $anno_corrente;
    $anni[] = $anno_corrente+1;
    $anni[] = $anno_corrente+2;

    $giorni_festivi = [];

    foreach ($anni as $anno) {
      $giorni_festivi[$anno . '0101'] = '01.01.' . $anno;
      $giorni_festivi[$anno . '0106'] = '06.01.' . $anno;
      $giorni_festivi[$anno . '0425'] = '25.04.' . $anno;
      $giorni_festivi[$anno . '0501'] = '01.05.' . $anno;
      $giorni_festivi[$anno . '0602'] = '02.06.' . $anno;
      $giorni_festivi[$anno . '0815'] = '15.08.' . $anno;
      $giorni_festivi[$anno . '1101'] = '01.11.' . $anno;
      $giorni_festivi[$anno . '0812'] = '08.12.' . $anno;
      $giorni_festivi[$anno . '1225'] = '25.12.' . $anno;
      $giorni_festivi[$anno . '1226'] = '26.12.' . $anno;
      // calcolo le date di Pasqua e Pasquetta
      $gg_pasqua = easter_days($anno);
      $gg_pasquetta = $gg_pasqua+1;
      $tmp = date('Y-m-d', strtotime('21 march ' . $anno));
      $data_pasqua = date('d.m.Y', strtotime($tmp . ' +' . $gg_pasqua . 'day'));
      $data_pasquetta = date('d.m.Y', strtotime($tmp . ' +' . $gg_pasquetta . 'day'));

      $giorni_festivi[date('Ymd', strtotime($tmp . ' +' . $gg_pasqua . 'day'))] = $data_pasqua;
      $giorni_festivi[date('Ymd', strtotime($tmp . ' +' . $gg_pasquetta . 'day'))] = $data_pasquetta;
    }

    return $giorni_festivi;
  }
}
