<?php

namespace Drupal\bo_system\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for BO System settings.
 */
class SettingsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bo_system_platform_percentage_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $settings = \Drupal::state()->get('bo_system.settings');

    $form['platform_percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Platform percentage'),
      '#description' => $this->t('Enter the platform percentage as an integer value.'),
      '#default_value' => $settings['platform_percentage'] ?? 0,
      '#required' => TRUE,
    ];

    $form['inperson_online_payment'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow online payment for "in-person" events.'),
      '#description' => $this->t('If you uncheck this option, payment for "in-person" events will be processed with a Free checkout'),
      '#default_value' => $settings['inperson_online_payment'] ?? 0,
    ];

    $form['inperson_online_payment_discount'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage discount for online payments of "in-person" events'),
      '#description' => $this->t('Enter a percentage for a discount of "in-person" events paid online. Leave empty for no discounts and users will pay the whole price online.'),
      '#default_value' => $settings['inperson_online_payment_discount'] ?? 0,
      '#required' => FALSE,
      // Usa #states per controllare la visibilità basata sul valore del checkbox.
      '#states' => [
        'visible' => [
          ':input[name="inperson_online_payment"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = \Drupal::state()->get('bo_system.settings');

    $settings['platform_percentage'] = $form_state->getValue('platform_percentage');
    $settings['inperson_online_payment'] = $form_state->getValue('inperson_online_payment');
    $settings['inperson_online_payment_discount'] = $form_state->getValue('inperson_online_payment_discount');

    // Salva il valore nel sistema di state.
    \Drupal::state()->set('bo_system.settings', $settings);

    $this->messenger()->addMessage($this->t('Settings have been saved.'));
  }

}
