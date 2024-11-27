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
      '#default_value' => $settings['percentage'] ?? 0,
      '#required' => TRUE,
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

    $settings['percentage'] = $form_state->getValue('platform_percentage');
    // Salva il valore nel sistema di state.
    \Drupal::state()->set('bo_system.settings', $settings);
    $this->messenger()->addMessage($this->t('Platform percentage has been saved.'));
  }

}
