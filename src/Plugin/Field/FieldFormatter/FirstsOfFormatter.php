<?php

namespace Drupal\firsts_of\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceEntityFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Plugin de formateur de champ pour afficher les Paragraphs différemment.
 *
 * @FieldFormatter(
 *   id = "firsts_of",
 *   label = @Translation("Firsts of"),
 *   field_types = {
 *     "entity_reference_revisions"
 *   }
 * )
 */
class FirstsOfFormatter extends EntityReferenceEntityFormatter {
  /**
   * The order of paragraph types.
   *
   * @var array
   */
  protected $paragraphTypeOrder;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'firsts_of_bundles' => [],
    ] + parent::defaultSettings();
  }

  /**
   * Gets the allowed bundles for the field.
   *
   * @return array
   *   An array of allowed bundle names.
   */
  protected function getAllowedBundles() {
    $target_type = $this->getFieldSetting('target_type');
    $bundles = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo($target_type);

    $target_bundles = $this->getFieldSetting('handler_settings')['target_bundles'];
    if (empty($target_bundles)) {
      $target_bundles = array_keys($bundles);
    }

    return $target_bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $target_bundles = $this->getAllowedBundles();

    $elements['firsts_of_bundles'] = [
      '#title' => t("Bundles to keep"),
      '#type' => 'textarea',
      '#default_value' => $this->getSetting('firsts_of_bundles'),
      '#element_validate' => [[$this, 'validateBundleList']],
      '#description' => t('Specify which bundles should be kept and their order. Allowed bundles: @bundles', [
        '@bundles' => implode(', ', $target_bundles),
      ]),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    return parent::viewElements(
      $this->filterAndSort($items, $langcode),
      $langcode
    );
  }

  /**
   * Filters and sorts the entities based on paragraph type order.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items to filter and sort.
   * @param string $langcode
   *   The language code to use.
   *
   * @return \Drupal\Core\Field\FieldItemList
   *   The filtered and sorted entities.
   */
  protected function filterAndSort(FieldItemListInterface $items, $langcode) {
    $already_seen = [];
    $unique_items = [];
    $paragraph_type_order = array_flip(
      preg_split('/\r\n|\r|\n/', $this->getSetting('firsts_of_bundles'))
    );

    foreach ($items as $item) {
      if (!($item->entity instanceof ParagraphInterface)) {
        continue;
      }

      $type = $item->entity->getParagraphType()->id;

      if (isset($already_seen[$type])
        || !isset($paragraph_type_order[$type])
      ) {
        continue;
      }

      $order = $paragraph_type_order[$type];
      $unique_items[$order] = $item;

      $already_seen[$type] = TRUE;
    }

    foreach ($unique_items as $index => $item) {
      $items->set($index, $item);
    }

    $top_index = count($unique_items);
    while ($items->count() > $top_index) {
      $items->removeItem($top_index);
    }

    return $items;
  }

  /**
   * Validates the list of bundles.
   *
   * @param array[] $element
   *   The numeric element to be validated.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array[] $complete_form
   *   The complete form structure.
   */
  public function validateBundleList(
    array &$element,
    FormStateInterface &$form_state,
    array &$complete_form,
  ): void {
    $bundle_list = $element['#value'];

    // The bundle list cannot be empty.
    if (empty($bundle_list)) {
      $form_state->setError($element, t('The bundle list cannot be empty'));
      return;
    }

    // The bundle list can only use allowed characters.
    if (preg_match('/^[a-z0-9_]+(\n[a-z0-9_]+)*$/gi', $bundle_list) === 0) {
      $form_state->setError(
        $element,
        t('The bundle list must be a list of alphanumeric values separated by line returns.')
      );
      return;
    }

    // The bundle list must not contain duplicates.
    $bundles = preg_split('/\r\n|\r|\n/', $bundle_list);
    if (count($bundles) !== count(array_unique($bundles))) {
      $form_state->setError(
        $element,
        t('The bundle list must not contain duplicates.')
      );
      return;
    }

    // The bundle list must contain valid bundles.
    $allowed_bundles = $this->getAllowedBundles();
    foreach ($bundles as $bundle) {
      if (!in_array($bundle, $allowed_bundles)) {
        $form_state->setError(
          $element,
          t('The "@bundle" bundle is not valid.', ['@bundle' => $bundle])
        );
        return;
      }
    }
  }

}
