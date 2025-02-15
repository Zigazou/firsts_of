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

    $target_bundles = $this->getFieldSetting('handler_settings')['target_bundles']
                   ?? array_keys($bundles);

    return $target_bundles;
  }

  /**
   * Gets the bundles to keep.
   *
   * @return array
   *   An array of bundles to keep with the view mode.
   */
  protected function getBundles() {
    return self::parseParagraphsAndViews(
      $this->getSetting('firsts_of_bundles')
    );
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
    $items = $this->filterAndSort($items, $langcode);
    $bundles = $this->getBundles();
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      $recursive_render_id = $items->getFieldDefinition()->getTargetEntityTypeId()
        . $items->getFieldDefinition()->getTargetBundle()
        . $items->getName()
        . $items->getEntity()->id()
        . $entity->getEntityTypeId()
        . $entity->id();

      if (isset(static::$recursiveRenderDepth[$recursive_render_id])) {
        static::$recursiveRenderDepth[$recursive_render_id]++;
      }
      else {
        static::$recursiveRenderDepth[$recursive_render_id] = 1;
      }

      // Protect ourselves from recursive rendering.
      if (static::$recursiveRenderDepth[$recursive_render_id] > static::RECURSIVE_RENDER_LIMIT) {
        $this->loggerFactory->get('entity')->error('Recursive rendering detected when rendering entity %entity_type: %entity_id, using the %field_name field on the %parent_entity_type:%parent_bundle %parent_entity_id entity. Aborting rendering.', [
          '%entity_type' => $entity->getEntityTypeId(),
          '%entity_id' => $entity->id(),
          '%field_name' => $items->getName(),
          '%parent_entity_type' => $items->getFieldDefinition()->getTargetEntityTypeId(),
          '%parent_bundle' => $items->getFieldDefinition()->getTargetBundle(),
          '%parent_entity_id' => $items->getEntity()->id(),
        ]);
        return $elements;
      }

      $view_builder = $this->entityTypeManager->getViewBuilder(
        $entity->getEntityTypeId()
      );

      $elements[$delta] = $view_builder->view(
        $entity,
        $bundles[$delta]['view'],
        $entity->language()->getId()
      );

      if (!empty($items[$delta]->_attributes)
      && !$entity->isNew()
      && $entity->hasLinkTemplate('canonical')) {
        $items[$delta]->_attributes += [
          'resource' => $entity->toUrl()->toString(),
        ];
      }
    }

    return $elements;
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

    $paragraphs_views = $this->getBundles();

    // Create an array of paragraph types and their order.
    $paragraph_type_order = [];
    foreach ($paragraphs_views as $delta => $paragraph_view) {
      $paragraph_type_order[$paragraph_view['type']] = $delta;
    }

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

    $items = clone $items;
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
   * Parses the setting into an array of paragraphs and views.
   *
   * If no view is specified, the view will be set to 'default'.
   *
   * @param string $setting
   *   The setting to parse. It should be a list of paragraphs and views
   *   separated by line returns. Paragraph and view are separated by a colon.
   *   There should be no spaces.
   *
   * @return array
   *   An array of paragraphs and views with the following structure:
   *   [
   *     0 => [
   *       'type' => "paragraph type ID",
   *       'view' => "view mode ID",
   *     ],
   *     ...
   *   ]
   */
  protected static function parseParagraphsAndViews(string $setting): array {
    $rows = preg_split('/\r\n|\r|\n/', $setting);
    $paragraphs = [];
    foreach ($rows as $row) {
      $elements = explode(':', $row);
      $paragraphs[] = [
        'type' => $elements[0],
        'view' => $elements[1] ?? 'default',
      ];
    }

    return $paragraphs;
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

    $bundles = self::parseParagraphsAndViews($bundle_list);
    foreach ($bundles as $delta => $bundle) {
      if (preg_match('/^[a-z0-9_]+$/i', $bundle['type']) === 0) {
        $form_state->setError(
          $element,
          t('The "@bundle" bundle is not valid on row @row.', ['@bundle' => $bundle['type'], '@row' => $delta + 1])
        );
      }

      if (preg_match('/^[a-z0-9_]+$/i', $bundle['view']) === 0) {
        $form_state->setError(
          $element,
          t('The "@view" view mode is not valid on row @row.', ['@view' => $bundle['view'], '@row' => $delta + 1])
        );
      }
    }

    $paragraph_types = [];
    foreach ($bundles as $delta => $bundle) {
      $paragraph_types[] = $bundle['type'];
    }

    // The bundle list must not contain duplicates.
    if (count($paragraph_types) !== count(array_unique($paragraph_types))) {
      $form_state->setError(
        $element,
        t('The bundle list must not contain duplicated types.')
      );
      return;
    }

    // The bundle list must contain valid bundles.
    $allowed_bundles = $this->getAllowedBundles();
    foreach ($paragraph_types as $paragraph_type) {
      if (!in_array($paragraph_type, $allowed_bundles)) {
        $form_state->setError(
          $element,
          t('The "@bundle" paragraph type is not valid.', ['@bundle' => $paragraph_type])
        );
        return;
      }
    }
  }

}
