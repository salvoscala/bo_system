<?php

namespace Drupal\bo_system\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\bo_system\Services\BookingService;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\office_hours\OfficeHoursDateHelper;

class BookingForm extends FormBase {

  /**
   * \Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * \Drupal\Core\Session\AccountProxyInterface definition.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;

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
   * The cart provider.
   *
   * @var \Drupal\commerce_cart\CartProviderInterface
   */
  protected $cartProvider;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * Constructor.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryinterface $logger,
    TwigEnvironment $twig,
    RouteMatchInterface $routeMatch,
    BookingService $bookingService,
    CartManagerInterface $cart_manager,
    CartProviderInterface $cart_provider,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->currentUser = $currentUser;
    $this->configFactory = $configFactory;
    $this->logger = $logger->get('bo_system');
    $this->twig = $twig;
    $this->routeMatch = $routeMatch;
    $this->bookingService = $bookingService;
    $this->cartManager = $cart_manager;
    $this->cartProvider = $cart_provider;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Factory method for dependency injection container.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('twig'),
      $container->get('current_route_match'),
      $container->get('bo_system.booking_utility'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bo_system_booking_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $nid = FALSE) {
    // Bookable wrapper e' il contenuto che gestisce la bookable_entity.
    // Puo' essere una guida, o un ufficio (con N scrivanie).
    if ($bookable_wrapper = Node::load($nid)) {
      if ($bookable_wrapper->hasField('field_bookable_entity') && !$bookable_wrapper->field_bookable_entity->isEmpty()) {
        
        $bookableEntity = $bookable_wrapper->get('field_bookable_entity')->entity;
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

          $form['bookable_wrapper'] = [
            '#type' => 'hidden',
            '#value' => $nid,
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


          $consulting_types = [];
          $currency_repository = \Drupal::service('commerce_price.currency_repository');
          
          if ($bookableEntity->hasField('field_rate_online') && !$bookableEntity->field_rate_online->isEmpty()) {
            $online_rate = $bookableEntity->get('field_rate_online')->getValue();
            $currency = $currency_repository->get($online_rate[0]['currency_code']);
            // Ottiene la definizione della valuta.
            $formatted_price = $this->bookingService->getRealPrice(number_format($online_rate[0]['number'], 0, ',', '.'));
            $consulting_types['online'] = $this->t('Online at @price @symbol', ['@price' => $formatted_price, '@symbol' => $currency->getSymbol()]);
          }
          if ($bookableEntity->hasField('field_rate_in_person') && !$bookableEntity->field_rate_in_person->isEmpty()) {
            $inperson_rate = $bookableEntity->get('field_rate_in_person')->getValue();
            $currency = $currency_repository->get($inperson_rate[0]['currency_code']);
            $formatted_price = $this->bookingService->getRealPrice(number_format($inperson_rate[0]['number'], 0, ',', '.'));
            $consulting_types['in_person'] = $this->t('In person at @price @symbol', ['@price' => $formatted_price, '@symbol' => $currency->getSymbol()]);
          }

          $form['consulting_type'] = [
            '#type' => 'select',
            '#title' => $this->t('Consulting type'),
            '#options' => $consulting_types,
            '#empty_option' => $this->t('- Select an option -'),
            '#required' => TRUE,
          ];

          if (count($consulting_types) == 1) {
            $form['consulting_type']['#default_value'] = array_key_first($consulting_types);
            $form['consulting_type']['#attributes']['disabled'] = 'disabled';
          }
          /*$form['first_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('First Name'),
            '#required' => TRUE,
          ];

          $form['last_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Last Name'),
            '#required' => TRUE,
          ];

          $form['email'] = [
            '#type' => 'email',
            '#title' => $this->t('Email'),
            //'#required' => TRUE,
          ];

          $form['telephone'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Telephone'),
            //'#required' => TRUE,
          ];

          $form['note'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Notes'),
            '#required' => FALSE,
          ];*/

          if (!empty($form_state->getValue('date'))) {
            $form['time']['#disabled'] = FALSE;
          }

          $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Book now'),
            '#attributes' => ['class' => ['btn-primary']],
            '#disabled' => TRUE,
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

    $bookable_wrapper = Node::load($values['bookable_wrapper']);
    $bookable_entity = Node::load($values['bookable_entity']);

    if ( !$bookable_wrapper ) {
      $this->messenger()->addMessage($this->t('Something wrong.'), 'error');
      return;
    }

    // @todo: Load the real duration.
    $duration = 60;

    $start_date = new \DateTime($values['date'] . ' ' . $values['time']);

    if ($values['consulting_type'] == 'online') {
      $rate = $bookable_entity->get('field_rate_online')->getValue();
    }
    if ($values['consulting_type'] == 'in_person') {
      $rate = $bookable_entity->get('field_rate_in_person')->getValue();
    }
    if ($this->addToCart($bookable_entity, $start_date, $duration, $rate, $values)) {
      $form_state->setRedirect('commerce_cart.page');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addToCart($bookable_entity, $start_date, $duration, $rate, $values = []) {
    // @todo: Load the real product.
    $product = Product::load(1);
  
    $product_variation_id = $product->get('variations')
      ->getValue()[0]['target_id'];
    $storeId = $product->get('stores')->getValue()[0]['target_id'];
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')
      ->load($product_variation_id);
    $store = $this->entityTypeManager->getStorage('commerce_store')
      ->load($storeId);
  
    $cart = $this->cartProvider->getCart('booking', $store);
  
    if (!$cart) {
     $cart = $this->cartProvider->createCart('booking', $store);
    }

    $order_item = $this->entityTypeManager->getStorage('commerce_order_item')->create([
      'type' => 'booking',
      'purchased_entity' => $product_variation_id,
      'quantity' => 1,
      'unit_price' => $variation->getPrice(),
    ]);


    // Aggiungiamo all'item le informazioni.
    $order_item->set('field_consulting_date', $start_date->format('Y-m-d\TH:i:s'));
    $order_item->set('field_consulting_duration', $duration);
    $order_item->set('field_bookable_entity', $bookable_entity->id());
    $order_item->set('field_consulting_type', $bookable_entity->id());
    if (isset($values['consulting_type']) && $values['consulting_type']) {
      if ($order_item->hasField('field_consulting_type')) {
        $order_item->set('field_consulting_type', $values['consulting_type']);
      }
    }
    // @TODO: Ci serve un campo per il wrapper?
    if (isset($values['note'])) {
      $order_item->set('field_notes', $values['note']);
    }

    // Price with platform percentage.
    $price_number = $this->bookingService->getRealPrice($rate[0]['number']);

    $consulting_price = $variation->getPrice()->multiply($price_number);
    $order_item->set('field_consulting_price', $consulting_price);
    $order_item->save();

    $this->cartManager->addOrderItem($cart, $order_item);

    return TRUE;

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

   /**
   * Ritorna i giorni di chiusura dei prossimi 3 anni (anno corrente compreso).
   */
  private function giorniChiusura($giorni) {

    $anni = [];
    $anno_corrente = date('Y');

    $anni[] = $anno_corrente;
    $anni[] = $anno_corrente+1;
    $anni[] = $anno_corrente+2;

    $giorni_festivi = [];
    foreach ($anni as $anno) {
      foreach ($giorni as $giorno) {
        $string = explode('.', $giorno);
        $giorni_festivi[date('Ymd', strtotime($anno . '-' . $string[1] . '-' .  $string[0]))] = date('d.m.Y', strtotime($anno . '-' . $string[1] . '-' .  $string[0]));
      }
    }

    return $giorni_festivi;
  }
}
