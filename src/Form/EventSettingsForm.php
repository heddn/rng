<?php

/**
 * @file
 * Contains \Drupal\rng\Form\EventSettingsForm.
 */

namespace Drupal\rng\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Entity\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Configure event settings.
 */
class EventSettingsForm extends FormBase {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Constructs a new MessageActionForm object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   */
  public function __construct(EntityManagerInterface $entity_manager) {
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rng_event_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, RouteMatchInterface $route_match = NULL, $event = NULL) {
    $entity = clone $route_match->getParameter($event);
    $form_state->set('event', $entity);

    $fields = array(
      RNG_FIELD_EVENT_TYPE_STATUS,
      RNG_FIELD_EVENT_TYPE_ALLOW_DUPLICATE_REGISTRANTS,
      RNG_FIELD_EVENT_TYPE_CAPACITY,
      RNG_FIELD_EVENT_TYPE_EMAIL_REPLY_TO,
      RNG_FIELD_EVENT_TYPE_REGISTRATION_TYPE,
      RNG_FIELD_EVENT_TYPE_REGISTRATION_GROUPS,
    );

    $display = EntityFormDisplay::collectRenderDisplay($entity, 'default');
    $form_state->set('form_display', $display);
    module_load_include('inc', 'rng', 'rng.field.defaults');

    $components = array_keys($display->getComponents());
    foreach ($components as $field_name) {
      if (!in_array($field_name, $fields)) {
        $display->removeComponent($field_name);
      }
    }

    // Add widget settings if field is hidden on default view.
    foreach ($fields as $field_name) {
      if (!in_array($field_name, $components)) {
        rng_add_event_form_display_defaults($display, $field_name);
      }
    }

    $form['event'] = array(
      '#weight' => 0,
    );

    $form['advanced'] = array(
      '#type' => 'vertical_tabs',
      '#weight' => 100,
    );

    $display->buildForm($entity, $form['event'], $form_state);

    foreach ($fields as $weight => $field_name) {
      $form['event'][$field_name]['#weight'] = $weight * 10;
    }

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Save'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $event = $form_state->get('event');
    $form_state->get('form_display')->extractFormValues($event, $form, $form_state);
    $event->save();

    // Create base register rules if none exist.
    $rule_count = \Drupal::entityQuery('rng_rule')
      ->condition('event__target_type', $event->getEntityTypeId(), '=')
      ->condition('event__target_id', $event->id(), '=')
      ->condition('trigger_id', 'rng_event.register', '=')
      ->count()
      ->execute();

    if (!$rule_count) {
      $rule = $this->entityManager->getStorage('rng_rule')->create(array(
        'event' => array('entity' => $event),
        'trigger_id' => 'rng_event.register',
      ));
      $rule->save();

      $condition = $this->entityManager->getStorage('rng_action')->create(array(
        'rule' => array('entity' => $rule),
        'action' => 'rng_user_role',
        'configuration' => ['roles' => ['authenticated' => 'authenticated']],
      ));
      $condition->setType('condition');
      $condition->save();

      // Allow any user to create a registration on the event.
      $action = $this->entityManager->getStorage('rng_action')->create(array(
        'rule' => array('entity' => $rule),
        'action' => 'registration_operations',
        'configuration' => ['operations' => ['create' => TRUE]],
      ));
      $action->setType('action');
      $action->save();
    }

    $t_args = array('%event_label' => $event->label());
    drupal_set_message(t('Event settings updated.', $t_args));
  }
}