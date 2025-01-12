<?php

namespace Drupal\bo_system\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
use Drupal\user\Entity\User;

class CustomerCancelBookingForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Costruttore per Dependency Injection.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   L'utente corrente.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   Il servizio di rendering.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   Il servizio per ottenere i parametri di route.
   */
  public function __construct(AccountInterface $current_user, RendererInterface $renderer, RouteMatchInterface $route_match) {
    $this->currentUser = $current_user;
    $this->renderer = $renderer;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'customer_cancel_booking_form';
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
    if ($customer && $customer->id() === $currentUser->id()) {
      return AccessResult::allowed();
    }

    // Se nessuna delle condizioni è soddisfatta, nega l'accesso.
    return AccessResult::forbidden();
  }

  /**
   * Costruisci il form di cancellazione dell'evento.
   *
   * @param array $form
   *   Il form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Lo stato del form.
   * @param \Drupal\node\Entity\Node $node
   *   Il nodo dell'evento.
   *
   * @return array
   *   Il form costruito.
   */
  public function buildForm(array $form, FormStateInterface $form_state, Node $node = NULL) {
    // Verifica se l'evento esiste e se il field_state è "confirmed".
    if ($node->getType() !== 'event' || $node->get('field_state')->value !== 'confirmed') {
      $this->messenger()->addError($this->t('L\'evento è già stato cancellato o non è confermato.'));
      return $form;
    }

    // @todo: Aggiungere un numero di giorni entro cui l'evento puo' essere cancellato
    // Non dovremmo per esempio dare la possibilita' di cancellare il giorno prima..


    // Stampa il valore del campo "field_when".
    $when_output = '';
    if (!$node->field_when->isEmpty()) {
      $when_render_array = $node->get('field_when')->view('full');
      $when_output = $this->renderer->render($when_render_array);
    }

    // Stampa il valore del campo "field_customer".
    $customer_output = '';
    if (!$node->field_customer->isEmpty()) {
      $customer_render_array = $node->get('field_customer')->view('full');
      $customer_output = $this->renderer->render($customer_render_array);
    }

    // Mostra i dettagli dell'evento.
    $form['details'] = [
      '#markup' => '<p>' . $when_output . '</p>' . '<p>' . $customer_output . '</p>',
    ];

    // Campo motivazione.
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Add some notes. This information will be sent to host via mail.'),
      '#required' => TRUE,
    ];

    // Pulsanti di submit e cancel.
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete event'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Ottieni il nodo dalla route.
    $node = $this->routeMatch->getParameter('node');

    // Esegui la logica di cancellazione dell'evento.
    $node->set('field_state', 'cancelled_by_user'); // Imposta lo stato su "cancelled_by_user".
    $notes = $form_state->getValue('notes');
    \Drupal::logger('italia_locals')->notice('Event @title cancelled: @notes', [
      '@title' => $node->label(),
      '@notes' => $notes,
    ]);
    $node->set('field_cancellation_notes', $notes);
    $node->save();

    // Messaggio di conferma e redirect.
    $this->messenger()->addStatus($this->t('The event has been cancelled with success.'));

    // Inviamo delle mail.
    $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['absolute' => TRUE])->toString();

    // Email Customer.
    $site_mail = \Drupal::config('system.site')->get('mail');
    $subject = 'Event cancelled by Customer';
    $mail_body = '<p>The event has been cancelled by customer.</p>';
    if ($notes) {
      $mail_body .= '<p>The customer left a cancellation message:</p>';
      $mail_body .= '<p>' . $notes . '</p>';
    }

    $mail_body .= '<p>You may view more <a href="' . $url . '" target="_blank">details here</a></p>';
    $host_email = $node->get('field_bookable_entity')->entity->getOwner()->getEmail();
    simple_mail_send($site_mail, $host_email, $subject, Markup::create($mail_body));

    // Email administration for refund.
    $site_mail = \Drupal::config('system.site')->get('mail');
    $subject = 'Refund needed - Event cancelled by Customer';
    simple_mail_send($site_mail, $site_mail, $subject, Markup::create($mail_body));

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

}
